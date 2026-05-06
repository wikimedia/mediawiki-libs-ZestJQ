<?php
declare( strict_types = 1 );

namespace Wikimedia\ZestJQ\Tests;

use Wikimedia\ZestJQ\JQCmd;

/**
 * @covers \Wikimedia\ZestJQ\JQCmd
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
				'.[-1] |= . + 1', '"hello"', 5, null, "zestjq: Cannot index string with number (-1)\n",
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

	/**
	 * Verify that trim/ltrim/rtrim strip exactly the Unicode whitespace
	 * characters defined by jq's jvp_codepoint_is_whitespace():
	 *   U+0009–U+000D, U+0020, U+0085, U+00A0, U+1680,
	 *   U+2000–U+200A, U+2028, U+2029, U+202F, U+205F, U+3000
	 * (notably NOT U+0000 NUL, which PHP's trim() would strip but jq does not).
	 *
	 * @covers \Wikimedia\ZestJQ\JQTopLevelEnv
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
