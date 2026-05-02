<?php
declare( strict_types = 1 );

namespace Wikimedia\Zest\Tests;

use Wikimedia\Zest\JQCmd;

/**
 * @covers \Wikimedia\Zest\JQCmd
 */
class JQCmdTest extends \PHPUnit\Framework\TestCase {

	/**
	 * Call JQCmd::main() and capture stdout, returning [exitCode, stdout].
	 * Use -n or pass a file argument; don't rely on STDIN.
	 */
	private function runMain( array $args ): array {
		ob_start();
		$exitCode = JQCmd::main( count( $args ) + 1, array_merge( [ 'zestjq' ], $args ) );
		return [ $exitCode, ob_get_clean() ];
	}

	/** Write JSON to a temp file, run cmd with it appended to $args, return [exitCode, stdout]. */
	private function runWithJson( string $json, array $args ): array {
		$file = tempnam( sys_get_temp_dir(), 'jqcmd_' );
		file_put_contents( $file, $json );
		[ $code, $out ] = $this->runMain( array_merge( $args, [ $file ] ) );
		unlink( $file );
		return [ $code, $out ];
	}

	// -----------------------------------------------------------------------
	// Basic evaluation
	// -----------------------------------------------------------------------

	public function testNullInputSimpleExpr(): void {
		[ $code, $out ] = $this->runMain( [ '-n', '1 + 1' ] );
		$this->assertSame( 0, $code );
		$this->assertSame( "2\n", $out );
	}

	public function testFileInput(): void {
		[ $code, $out ] = $this->runWithJson( '{"a":1}', [ '.a' ] );
		$this->assertSame( 0, $code );
		$this->assertSame( "1\n", $out );
	}

	public function testMultipleOutputs(): void {
		[ $code, $out ] = $this->runMain( [ '-n', '1, 2, 3' ] );
		$this->assertSame( 0, $code );
		$this->assertSame( "1\n2\n3\n", $out );
	}

	public function testMultipleFiles(): void {
		$f1 = tempnam( sys_get_temp_dir(), 'jqcmd_' );
		$f2 = tempnam( sys_get_temp_dir(), 'jqcmd_' );
		file_put_contents( $f1, '1' );
		file_put_contents( $f2, '2' );
		ob_start();
		$code = JQCmd::main( 4, [ 'zestjq', '. + 10', $f1, $f2 ] );
		$out = ob_get_clean();
		unlink( $f1 );
		unlink( $f2 );
		$this->assertSame( 0, $code );
		$this->assertSame( "11\n12\n", $out );
	}

	public function testPrettyPrintedOutput(): void {
		[ $code, $out ] = $this->runWithJson( '{"a":1}', [ '.' ] );
		$this->assertSame( 0, $code );
		$this->assertSame( "{\n    \"a\": 1\n}\n", $out );
	}

	public function testStdinInput(): void {
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
		[ $code, $out ] = $this->runMain( [ '-n', '-r', '"hello"' ] );
		$this->assertSame( 0, $code );
		$this->assertSame( "hello\n", $out );
	}

	public function testRawOutputNonStringStillJson(): void {
		[ $code, $out ] = $this->runMain( [ '-n', '-r', '42' ] );
		$this->assertSame( 0, $code );
		$this->assertSame( "42\n", $out );
	}

	public function testAstOutput(): void {
		[ $code, $out ] = $this->runMain( [ '-n', '--ast', '.' ] );
		$this->assertSame( 0, $code );
		$this->assertSame( 'identity', json_decode( $out, true )['type'] );
	}

	public function testDoubleDashSeparator(): void {
		[ $code, $out ] = $this->runMain( [ '-n', '--', '1 + 1' ] );
		$this->assertSame( 0, $code );
		$this->assertSame( "2\n", $out );
	}

	// -----------------------------------------------------------------------
	// halt / halt_error
	// -----------------------------------------------------------------------

	public function testHalt(): void {
		[ $code, $out ] = $this->runMain( [ '-n', 'halt' ] );
		$this->assertSame( 0, $code );
		$this->assertSame( '', $out );
	}

	public function testHaltError(): void {
		[ $code, $out ] = $this->runMain( [ '-n', 'halt_error(3)' ] );
		$this->assertSame( 3, $code );
		$this->assertSame( '', $out );
	}

	public function testHaltErrorFloatTruncated(): void {
		[ $code, ] = $this->runMain( [ '-n', 'halt_error(2.9)' ] );
		$this->assertSame( 2, $code );
	}

	public function testHaltErrorNonNumberIsCatchable(): void {
		[ $code, $out ] = $this->runMain( [ '-n', 'try halt_error("bad") catch "caught"' ] );
		$this->assertSame( 0, $code );
		$this->assertSame( "\"caught\"\n", $out );
	}

	// -----------------------------------------------------------------------
	// Error cases
	// -----------------------------------------------------------------------

	public function testJQErrorExitCode(): void {
		[ $code, ] = $this->runMain( [ '-n', '"msg" | error' ] );
		$this->assertSame( 5, $code );
	}

	public function testSyntaxErrorExitCode(): void {
		[ $code, ] = $this->runMain( [ '-n', '}' ] );
		$this->assertSame( 3, $code );
	}

	public function testUnknownOptionExitCode(): void {
		[ $code, ] = $this->runMain( [ '--unknown' ] );
		$this->assertSame( 2, $code );
	}

	public function testNoFilterGivenExitCode(): void {
		[ $code, ] = $this->runMain( [] );
		$this->assertSame( 2, $code );
	}

	public function testInvalidJsonInFile(): void {
		$file = tempnam( sys_get_temp_dir(), 'jqcmd_' );
		file_put_contents( $file, 'not json' );
		ob_start();
		$code = JQCmd::main( 3, [ 'zestjq', '.', $file ] );
		ob_end_clean();
		unlink( $file );
		$this->assertSame( 2, $code );
	}

}
