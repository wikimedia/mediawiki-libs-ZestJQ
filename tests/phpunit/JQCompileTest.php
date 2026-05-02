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
	public static function compileProvider(): iterable {
		foreach ( JQGrammarTest::loadTests() as $test ) {
			if ( $test['lineno'] > 430 ) {
				return;
			}
			if ( !( $test['fail'] ?? false ) ) {
				yield $test['label'] => [
					$test['query'],
					$test['input'],
					$test['expected'],
				];
			}
		}
	}

	/**
	 * @dataProvider compileProvider
	 * @covers \Wikimedia\Zest\JQCompile
	 */
	public function testCompile( string $query, string $input, array $expected ): void {
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
		$this->assertTrue(
			JQUtils::compare( $expected, $result ) === 0,
			json_encode( $result )
		);
	}
}
