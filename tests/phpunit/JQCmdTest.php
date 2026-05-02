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
		$this->assertSame( "Usage: zestjq [-n] [-r] [--ast] <filter> [file...]\n", $err );
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

}
