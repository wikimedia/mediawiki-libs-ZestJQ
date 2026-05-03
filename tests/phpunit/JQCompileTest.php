<?php
declare( strict_types = 1 );

namespace Wikimedia\Zest\Tests;

use Wikimedia\Zest\IOContext;
use Wikimedia\Zest\JQCompile;
use Wikimedia\Zest\JQGrammar;
use Wikimedia\Zest\JQLazyEnv;
use Wikimedia\Zest\JQUtils;

/**
 * JQ evaluation tests driven by the upstream jq test suite from
 * https://github.com/jqlang/jq/blob/master/tests/jq.test
 */
class JQCompileTest extends \PHPUnit\Framework\TestCase {

	public static function skipReason( string $label, int $lineno ): ?string {
		if ( $lineno > 1110 ) {
			return 'Skipped temporarily';
		}
		return match ( $lineno ) {
			689 =>
			'JSON can not portably represent NaN or infinite values',

			9999999 =>
			'Exact error message may differ from jq',

			default => null,
		};
	}

	public static function compileProvider(): iterable {
		foreach ( JQGrammarTest::loadTests() as $test ) {
			if ( !( $test['fail'] ?? false ) ) {
				yield $test['label'] => [
					$test['query'],
					$test['input'],
					$test['expected'],
					self::skipReason( $test['label'], $test['lineno'] ),
				];
			}
		}
	}

	/**
	 * @dataProvider compileProvider
	 * @covers \Wikimedia\Zest\JQCompile
	 */
	public function testCompile( string $query, string $input, array $expected, ?string $skip = null ): void {
		if ( $skip !== null ) {
			$this->markTestSkipped( $skip );
		}
		$input = JQUtils::jsonDecode( $input );
		$expected = array_map( JQUtils::jsonDecode( ... ), $expected );
		$g = new JQGrammar;
		$ast = $g->parse( $query );
		$env = new JQLazyEnv( new IOContext );
		$eval = JQCompile::compile( $ast, $env );
		$result = [];
		foreach ( $eval( $input ) as $val ) {
			// Deliberately dropping the keys from the generator here,
			// so we don't get collisions we make a list out of the results.
			$result[] = $val;
		}
		$e = static function ( $val ) {
			$result = json_encode( $val );
			return $result === false ? var_export( $val, true ) : $result;
		};
		$this->assertTrue(
			JQUtils::compare( $expected, $result ) === 0,
			"got: " . $e( $result ) . ", but expected: " . $e( $expected )
		);
	}
}
