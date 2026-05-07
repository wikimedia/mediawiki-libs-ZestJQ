<?php
declare( strict_types = 1 );

namespace Wikimedia\ZestJQ\Tests;

use Wikimedia\ZestJQ\JQ;
use Wikimedia\ZestJQ\JQEnv;
use Wikimedia\ZestJQ\JQError;
use Wikimedia\ZestJQ\JQUtils;

/**
 * Tests that the README.md library-usage examples work as documented.
 *
 * @covers \Wikimedia\ZestJQ\JQ
 * @covers \Wikimedia\ZestJQ\JQEnv
 */
class JQTest extends \PHPUnit\Framework\TestCase {

	/** Collect all Generator outputs into a plain list. */
	private function collect( \Generator $gen ): array {
		return iterator_to_array( $gen, false );
	}

	// -----------------------------------------------------------------------
	// evalString — "Evaluate a filter against a JSON string"
	// -----------------------------------------------------------------------

	public function testEvalStringFieldAccess(): void {
		$results = $this->collect(
			JQ::evalString( '{"name":"jq","version":2}', '.name' )
		);
		$this->assertSame( [ 'jq' ], $results );
	}

	// -----------------------------------------------------------------------
	// eval — "Evaluate a filter against a decoded PHP value"
	// -----------------------------------------------------------------------

	public function testEvalWithDecodedInput(): void {
		$input = JQUtils::jsonDecode( '{"items":[1,2,3]}' );
		$results = $this->collect( JQ::eval( $input, '.items[]' ) );
		$this->assertSame( [ 1, 2, 3 ], $results );
	}

	// -----------------------------------------------------------------------
	// compile — "Compile once, evaluate many times"
	// -----------------------------------------------------------------------

	public function testCompileReuse(): void {
		$filter = JQ::compile( '.[] | select(. > 2)' );

		$this->assertSame( [ 3, 4 ], $this->collect( $filter( [ 1, 2, 3, 4 ] ) ) );
		$this->assertSame( [ 5, 6 ], $this->collect( $filter( [ 5, 1, 6 ] ) ) );
	}

	// -----------------------------------------------------------------------
	// Error handling — "Error handling"
	// -----------------------------------------------------------------------

	public function testEvalStringThrowsJQErrorOnTypeError(): void {
		$this->expectException( JQError::class );
		// Iterating the generator triggers evaluation; the exception is thrown
		// when the generator is advanced, not when evalString() is called.
		$this->collect( JQ::evalString( '"hello"', '.foo' ) );
	}

	// -----------------------------------------------------------------------
	// extendEnv — "Custom definitions"
	// -----------------------------------------------------------------------

	public function testExtendEnvCustomDefinition(): void {
		$env = JQEnv::getStdEnv()->extendEnv( 'def double: . * 2;' );
		$results = $this->collect( JQ::eval( 5, 'double', null, $env ) );
		$this->assertSame( [ 10 ], $results );
	}

}
