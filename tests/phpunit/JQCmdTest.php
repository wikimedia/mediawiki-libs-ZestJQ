<?php
declare( strict_types = 1 );

namespace Wikimedia\Zest\Tests;

use Wikimedia\Zest\JQCmd;

/**
 * @covers \Wikimedia\Zest\JQCmd
 */
class JQCmdTest extends \PHPUnit\Framework\TestCase {

	/**
	 * Call JQCmd::main() and return [exitCode, stdout, stderr].
	 * Use -n or pass a file argument; don't rely on STDIN.
	 */
	private function runMain( array $args ): array {
		JQCmd::$runningTests = true;
		JQCmd::$err = '';
		ob_start();
		try {
			$exitCode = JQCmd::main(
				count( $args ) + 1, [ 'zestjq', ...$args ]
			);
		} finally {
			$stdout = ob_get_clean();
			$stderr = JQCmd::$err;
			JQCmd::$err = '';
			JQCmd::$runningTests = false;
		}
		return [ $exitCode, $stdout, $stderr ];
	}

	/** Write JSON to a temp file, run cmd with it appended to $args, return [exitCode, stdout, stderr]. */
	private function runWithJson( array $args, string ...$json ): array {
		$files = array_map( static function ( $data ) {
			$file = tempnam( sys_get_temp_dir(), 'jqcmd_' );
			file_put_contents( $file, $data );
			return $file;
		}, $json );
		try {
			[ $code, $out, $err ] = $this->runMain( [ ...$args, ...$files ] );
		} finally {
			foreach ( $files as $file ) {
				unlink( $file );
			}
		}
		return [ $code, $out, $err ];
	}

	/**
	 * Run with -c (compact output) and return each output line decoded as a PHP value.
	 * @return list<mixed>
	 */
	private function runCompact( array $args, string $jsonInput ): array {
		[ $code, $out, $err ] = $this->runWithJson( [ '-c', ...$args ], $jsonInput );
		$this->assertSame( 0, $code );
		$this->assertSame( '', $err );
		return array_map( json_decode( ... ), array_filter( explode( "\n", $out ) ) );
	}

	/** Assert that $actualOutput (raw CLI stdout) decodes to the same JSON value as $expectedJson. */
	private function assertSameJson( string $expectedJson, string $actualOutput ): void {
		$this->assertEquals( json_decode( $expectedJson ), json_decode( trim( $actualOutput ) ) );
	}

	// -----------------------------------------------------------------------
	// Basic evaluation
	// -----------------------------------------------------------------------

	public function testNullInputSimpleExpr(): void {
		[ $code, $out, $err ] = $this->runMain( [ '-n', '1 + 1' ] );
		$this->assertSame( 0, $code );
		$this->assertSame( "2\n", $out );
		$this->assertSame( '', $err );
	}

	public function testFileInput(): void {
		[ $code, $out, $err ] = $this->runWithJson( [ '.a' ], '{"a":1}' );
		$this->assertSame( 0, $code );
		$this->assertSame( "1\n", $out );
		$this->assertSame( '', $err );
	}

	public function testMultipleOutputs(): void {
		[ $code, $out, $err ] = $this->runMain( [ '-n', '1, 2, 3' ] );
		$this->assertSame( 0, $code );
		$this->assertSame( "1\n2\n3\n", $out );
		$this->assertSame( '', $err );
	}

	public function testMultipleFiles(): void {
		[ $code, $out, $err ] = $this->runWithJson( [ '. + 10' ], '1', '2' );
		$this->assertSame( 0, $code );
		$this->assertSame( "11\n12\n", $out );
		$this->assertSame( '', $err );
	}

	public function testPrettyPrintedOutput(): void {
		[ $code, $out, $err ] = $this->runWithJson( [ '.' ], '{"a":1}' );
		$this->assertSame( 0, $code );
		$this->assertSame( "{\n    \"a\": 1\n}\n", $out );
		$this->assertSame( '', $err );
	}

	public function testStdinInput(): void {
		// phpcs:ignore MediaWiki.Usage.ForbiddenFunctions.proc_open
		$proc = proc_open(
			[ PHP_BINARY, 'bin/zestjq', '. + 1' ],
			[ 0 => [ 'pipe', 'r' ], 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ],
			$pipes,
			dirname( dirname( __DIR__ ) )
		);
		fwrite( $pipes[0], '41' );
		fclose( $pipes[0] );
		$out = stream_get_contents( $pipes[1] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$this->assertSame( 0, proc_close( $proc ) );
		$this->assertSame( "42\n", $out );
	}

	// -----------------------------------------------------------------------
	// Flags
	// -----------------------------------------------------------------------

	public function testRawOutputString(): void {
		[ $code, $out, $err ] = $this->runMain( [ '-n', '-r', '"hello"' ] );
		$this->assertSame( 0, $code );
		$this->assertSame( "hello\n", $out );
		$this->assertSame( '', $err );
	}

	public function testRawOutputNonStringStillJson(): void {
		[ $code, $out, $err ] = $this->runMain( [ '-n', '-r', '42' ] );
		$this->assertSame( 0, $code );
		$this->assertSame( "42\n", $out );
		$this->assertSame( '', $err );
	}

	public function testAstOutput(): void {
		[ $code, $out, $err ] = $this->runMain( [ '-n', '--ast', '.' ] );
		$this->assertSame( 0, $code );
		$this->assertSame( 'identity', json_decode( $out, true )['type'] );
		$this->assertSame( '', $err );
	}

	public function testDoubleDashSeparator(): void {
		[ $code, $out, $err ] = $this->runMain( [ '-n', '--', '1 + 1' ] );
		$this->assertSame( 0, $code );
		$this->assertSame( "2\n", $out );
		$this->assertSame( '', $err );
	}

	// -----------------------------------------------------------------------
	// halt / halt_error
	// -----------------------------------------------------------------------

	public function testHalt(): void {
		[ $code, $out, $err ] = $this->runMain( [ '-n', 'halt' ] );
		$this->assertSame( 0, $code );
		$this->assertSame( '', $out );
		$this->assertSame( '', $err );
	}

	public function testHaltError(): void {
		[ $code, $out, $err ] = $this->runMain( [ '-n', 'halt_error(3)' ] );
		$this->assertSame( 3, $code );
		$this->assertSame( '', $out );
		$this->assertSame( '', $err );
	}

	public function testHaltErrorFloatTruncated(): void {
		[ $code, $out, $err ] = $this->runMain( [ '-n', 'halt_error(2.9)' ] );
		$this->assertSame( 2, $code );
		$this->assertSame( '', $out );
		$this->assertSame( '', $err );
	}

	public function testHaltErrorNonNumberIsCatchable(): void {
		[ $code, $out, $err ] = $this->runMain( [ '-n', 'try halt_error("bad") catch "caught"' ] );
		$this->assertSame( 0, $code );
		$this->assertSame( "\"caught\"\n", $out );
		$this->assertSame( '', $err );
	}

	// -----------------------------------------------------------------------
	// Error cases
	// -----------------------------------------------------------------------

	public function testJQError(): void {
		[ $code, $out, $err ] = $this->runMain( [ '-n', '"msg" | error' ] );
		$this->assertSame( 5, $code );
		$this->assertSame( '', $out );
		$this->assertSame( "zestjq: msg\n", $err );
	}

	public function testSyntaxError(): void {
		[ $code, $out, $err ] = $this->runMain( [ '-n', '}' ] );
		$this->assertSame( 3, $code );
		$this->assertSame( '', $out );
		$this->assertStringStartsWith( 'zestjq: syntax error in filter:', $err );
	}

	public function testUnknownOption(): void {
		[ $code, $out, $err ] = $this->runMain( [ '--unknown' ] );
		$this->assertSame( 2, $code );
		$this->assertSame( '', $out );
		$this->assertSame( "zestjq: unknown option: --unknown\n", $err );
	}

	public function testNoFilterGiven(): void {
		[ $code, $out, $err ] = $this->runMain( [] );
		$this->assertSame( 2, $code );
		$this->assertSame( '', $out );
		$this->assertSame( "Usage: zestjq [-n] [-r] [-c] [--ast] <filter> [file...]\n", $err );
	}

	public function testInvalidJsonInFile(): void {
		[ $code, $out, $err ] = $this->runWithJson( [ '.' ], 'not json' );
		$this->assertSame( 2, $code );
		$this->assertSame( '', $out );
		$this->assertStringStartsWith( 'zestjq: invalid JSON in file:', $err );
	}

	public function testHaltErrorWithMessage(): void {
		[ $code, $out, $err ] = $this->runWithJson( [ 'halt_error(2)' ], '"fatal\n"' );
		$this->assertSame( 2, $code );
		$this->assertSame( '', $out );
		$this->assertSame( "fatal\n", $err );
	}

	// -----------------------------------------------------------------------
	// setAtPath null container promotion
	// -----------------------------------------------------------------------

	public static function setAtPathNullContainerProvider(): array {
		return [
			'int index promotes null to array'         => [ '.[0] = "val"', 'null', 0, '["val"]', '' ],
			'int index pads gaps with null'            => [ '.[3] = "val"', 'null', 0, '[null,null,null,"val"]', '' ],
			'string key promotes null to object'       => [ '.foo = "val"', 'null', 0, '{"foo":"val"}', '' ],
			'nested string path cascades null objects' => [ '.a.b = "val"', 'null', 0, '{"a":{"b":"val"}}', '' ],
			'int then string cascades null'            => [ '.[0].foo = "val"', 'null', 0, '[{"foo":"val"}]', '' ],
			'string then int cascades null'            => [ '.foo[0] = "val"', 'null', 0, '{"foo":["val"]}', '' ],
			'update-assign promotes null array'        => [ '.[0] |= . + 1', 'null', 0, '[1]', '' ],
			'update-assign promotes null object'       => [ '.foo |= . + 1', 'null', 0, '{"foo":1}', '' ],
			// Float indices are truncated toward zero (1.9 → 1), not rounded
			'float index truncated toward zero'        => [ '.[1.9] = "val"', 'null', 0, '[null,"val"]', '' ],
		];
	}

	/**
	 * @dataProvider setAtPathNullContainerProvider
	 */
	public function testSetAtPathNullContainer(
		string $filter, string $jsonInput,
		int $expectedCode, ?string $expectedJsonOut, string $expectedErr
	): void {
		[ $code, $out, $err ] = $this->runWithJson( [ $filter ], $jsonInput );
		$this->assertSame( $expectedCode, $code );
		$this->assertSame( $expectedErr, $err );
		if ( $expectedJsonOut !== null ) {
			$this->assertSameJson( $expectedJsonOut, $out );
		}
	}

	// -----------------------------------------------------------------------
	// deleteAtPath array splicing (via |= empty)
	// -----------------------------------------------------------------------

	public static function deleteAtPathArrayProvider(): array {
		// jq's del/|= empty always splices the element out (shifts remaining
		// elements left, reducing length by 1); it never sets a slot to null.
		// Out-of-bounds indices (positive or negative) are silently ignored.
		// Float indices are truncated toward zero before resolving.
		return [
			'delete middle element'             => [ '.[2]  |= empty', '[1,2,3,4,5]', '[1,2,4,5]' ],
			'delete last element (positive)'    => [ '.[4]  |= empty', '[1,2,3,4,5]', '[1,2,3,4]' ],
			'delete last element (negative)'    => [ '.[-1] |= empty', '[1,2,3,4,5]', '[1,2,3,4]' ],
			'delete middle element (negative)'  => [ '.[-3] |= empty', '[1,2,3,4,5]', '[1,2,4,5]' ],
			'delete first element'              => [ '.[0]  |= empty', '[1,2,3,4,5]', '[2,3,4,5]' ],
			'delete negative that resolves to middle' => [ '.[-4] |= empty', '[1,2,3,4,5]', '[1,3,4,5]' ],
			'out-of-bounds positive is no-op'   => [ '.[10] |= empty', '[1,2,3]', '[1,2,3]' ],
			'out-of-bounds negative is no-op'   => [ '.[-4] |= empty', '[1,2,3]', '[1,2,3]' ],
			'float index truncated toward zero' => [ '.[1.9] |= empty', '[1,2,3]', '[1,3]' ],
			'negative float truncated toward zero' => [ '.[-1.9] |= empty', '[1,2,3]', '[1,2]' ],
			// null containers: all deletions are no-ops, returning null
			'null with int index'      => [ '.[0]  |= empty', 'null', 'null' ],
			'null with string key'     => [ '.foo  |= empty', 'null', 'null' ],
			'null with negative index' => [ '.[-1] |= empty', 'null', 'null' ],
			'null with large index'    => [ '.[5]  |= empty', 'null', 'null' ],
		];
	}

	/**
	 * @dataProvider deleteAtPathArrayProvider
	 */
	public function testDeleteAtPathArray(
		string $filter, string $jsonInput, string $expectedJsonOut
	): void {
		[ $code, $out, $err ] = $this->runWithJson( [ $filter ], $jsonInput );
		$this->assertSame( 0, $code );
		$this->assertSame( '', $err );
		$this->assertSameJson( $expectedJsonOut, $out );
	}

	// -----------------------------------------------------------------------
	// setAtPath negative indices
	// -----------------------------------------------------------------------

	public static function setAtPathNegativeIndicesProvider(): array {
		return [
			'out-of-bounds negative on array' => [
				'.[-4] |= . + 1', '[1,2,3]', 5, null, "zestjq: Out of bounds negative array index\n",
			],
			'negative on null' => [
				'.[-1] |= . + 1', 'null', 5, null, "zestjq: Out of bounds negative array index\n",
			],
			'negative on object' => [
				'.[-1] |= . + 1', '{}', 5, null, "zestjq: index requires string inputs, got number\n",
			],
			'negative on string' => [
				'.[-1] |= . + 1', '"hello"', 5, null, "zestjq: Cannot index string with number\n",
			],
			// Floats are truncated toward zero, matching jq's (int) cast
			'float truncated toward zero (negative)' => [
				'.[-1.9] |= . + 10', '[1,2,3]', 0, '[1,2,13]', '',
			],
		];
	}

	/**
	 * @dataProvider setAtPathNegativeIndicesProvider
	 */
	public function testSetAtPathNegativeIndices(
		string $filter, string $jsonInput,
		int $expectedCode, ?string $expectedJsonOut, string $expectedErr
	): void {
		[ $code, $out, $err ] = $this->runWithJson( [ $filter ], $jsonInput );
		$this->assertSame( $expectedCode, $code );
		$this->assertSame( $expectedErr, $err );
		if ( $expectedJsonOut !== null ) {
			$this->assertSameJson( $expectedJsonOut, $out );
		}
	}

	// -----------------------------------------------------------------------
	// Multi-path assignment (lhs = rhs where lhs produces multiple paths)
	// -----------------------------------------------------------------------

	public static function assignMultiPathProvider(): array {
		return [
			// .[] produces one path per element; all must be set (from jq.test line 1306)
			'.[] = 1 sets all elements'      => [ '.[] = 1', '[1,2,3]', [ '[1,1,1]' ] ],
			// comma expression on lhs produces two paths; both must be set
			'(.a,.b) = 1 sets both keys'     => [ '(.a,.b) = 1', '{"a":0,"b":0,"c":0}', [ '{"a":1,"b":1,"c":0}' ] ],
			// multi-value rhs × multi-path lhs: one output per rhs value, all paths set each time
			'.[] = (1,2) yields two outputs' => [ '.[] = (1,2)', '[0,0,0]', [ '[1,1,1]', '[2,2,2]' ] ],
		];
	}

	/**
	 * @dataProvider assignMultiPathProvider
	 * @covers \Wikimedia\Zest\JQCompile
	 */
	public function testAssignMultiPath( string $filter, string $jsonInput, array $expectedJsonOutputs ): void {
		$actual = $this->runCompact( [ $filter ], $jsonInput );
		$expected = array_map( json_decode( ... ), $expectedJsonOutputs );
		$this->assertEquals( $expected, $actual );
	}

	// -----------------------------------------------------------------------
	// Destructuring patterns: multi-valued key produces multiple env bindings
	// -----------------------------------------------------------------------

	public static function patternMultiEnvProvider(): array {
		return [
			// obj_pattern with expression key yields one env per key value
			'obj_pattern multi-key, array' => [
				'. as {("a","b"): $x} | $x',
				'{"a":1,"b":2}',
				[ '1', '2' ],
			],
			// same key repeated in obj_pattern yields two envs (same field, different iteration)
			'array_pattern with multi-key elem' => [
				'. as [{("a","b"): $x}, $y] | [$x, $y]',
				'[{"a":1,"b":2}, 3]',
				[ '[1,3]', '[2,3]' ],
			],
			// and_pattern ({$b: subpat}): $b binds once; subpat with multi-key yields two envs
			'and_pattern with multi-key subpat' => [
				'. as {$b: {("c","d"): $x}} | [$b, $x]',
				'{"b":{"c":10,"d":20}}',
				[ '[{"c":10,"d":20},10]', '[{"c":10,"d":20},20]' ],
			],
		];
	}

	/**
	 * @dataProvider patternMultiEnvProvider
	 * @covers \Wikimedia\Zest\JQCompile
	 */
	public function testPatternMultiEnv( string $filter, string $jsonInput, array $expectedJsonOutputs ): void {
		$actual = $this->runCompact( [ $filter ], $jsonInput );
		$expected = array_map( json_decode( ... ), $expectedJsonOutputs );
		$this->assertEquals( $expected, $actual );
	}

	/**
	 * Verify that trim/ltrim/rtrim strip exactly the Unicode whitespace
	 * characters defined by jq's jvp_codepoint_is_whitespace():
	 *   U+0009–U+000D, U+0020, U+0085, U+00A0, U+1680,
	 *   U+2000–U+200A, U+2028, U+2029, U+202F, U+205F, U+3000
	 * (notably NOT U+0000 NUL, which PHP's trim() would strip but jq does not).
	 *
	 * @covers \Wikimedia\Zest\JQTopLevelEnv
	 */
	public function testTrimUnicodeWhitespace(): void {
		// One copy of every character in jvp_codepoint_is_whitespace, in order.
		$ws = "\u{0009}\u{000A}\u{000B}\u{000C}\u{000D}\u{0020}"
			. "\u{0085}\u{00A0}\u{1680}"
			. "\u{2000}\u{2001}\u{2002}\u{2003}\u{2004}\u{2005}"
			. "\u{2006}\u{2007}\u{2008}\u{2009}\u{200A}"
			. "\u{2028}\u{2029}\u{202F}\u{205F}\u{3000}";
		$input = json_encode( $ws . 'x' . $ws );
		$this->assertEquals( [ 'x' ], $this->runCompact( [ 'trim' ], $input ) );
		$this->assertEquals( [ 'x' . $ws ], $this->runCompact( [ 'ltrim' ], $input ) );
		$this->assertEquals( [ $ws . 'x' ], $this->runCompact( [ 'rtrim' ], $input ) );
	}

}
