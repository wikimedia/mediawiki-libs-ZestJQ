<?php
declare( strict_types = 1 );

namespace Wikimedia\Zest\Tests;

use Wikimedia\Zest\JQGrammar;

/**
 * Parse-only tests driven by the upstream jq test suite from
 * https://github.com/jqlang/jq/blob/master/tests/jq.test
 *
 * Each test group in jq.test is three or more lines:
 *   line 1: jq query
 *   line 2: JSON input
 *   line 3+: expected output(s)
 *
 * Groups starting with %%FAIL (or %%FAIL IGNORE MSG) are expected to
 * be syntactically invalid; the query is on the second line of the group
 * and subsequent lines are the upstream error message.
 *
 * This test class only exercises the parser:
 *   - valid queries must parse without throwing
 *   - %%FAIL queries must throw an exception
 *
 * @covers \Wikimedia\Zest\JQGrammar
 */
class JQGrammarTest extends \PHPUnit\Framework\TestCase {

	private static function testFilePath(): string {
		return __DIR__ . '/jq.test';
	}

	/**
	 * Parse jq.test into an array of tests.
	 *
	 * @return list<array{fail?:true,query:string,label:string,input?:?string,expected?:?string}>
	 */
	public static function loadTests(): array {
		static $tests = null;
		if ( $tests !== null ) {
			return $tests;
		}
		// Parse tests from input file
		$lines = file( self::testFilePath(), FILE_IGNORE_NEW_LINES );
		$tests = [];
		$i = 0;
		$total = count( $lines );

		while ( $i < $total ) {
			// Skip blank lines and comment lines between groups
			if ( trim( $lines[$i] ) === '' || str_starts_with( ltrim( $lines[$i] ), '#' ) ) {
				$i++;
				continue;
			}

			// Collect all non-blank lines of this group, tracking each line's number.
			$groupLines = [];
			while ( $i < $total && trim( $lines[$i] ) !== '' ) {
				$line = $lines[$i];
				if ( !str_starts_with( ltrim( $line ), '#' ) ) {
					$groupLines[] = [ 'line' => $line, 'lineno' => $i + 1 ];
				}
				$i++;
			}

			if ( !$groupLines ) {
				continue;
			}

			$first = $groupLines[0]['line'];

			if ( str_starts_with( $first, '%%FAIL' ) ) {
				// %%FAIL or %%FAIL IGNORE MSG — second line is the bad query
				if ( isset( $groupLines[1] ) ) {
					$query  = $groupLines[1]['line'];
					$lineno = $groupLines[1]['lineno'];
					$tests[] = [
						'fail'  => true,
						'query' => $query,
						'label' => "line $lineno: $query",
						'lineno' => $lineno,
					];
				}
			} else {
				// Normal group: first line is the query
				$query  = $groupLines[0]['line'];
				$lineno = $groupLines[0]['lineno'];
				$expected = array_slice(
					array_map( static fn ( $g )=>$g['line'], $groupLines ),
					2
				);
				$tests[] = [
					'query' => $query,
					'input' => $groupLines[1]['line'] ?? null,
					'expected' => $expected,
					'label' => "line $lineno: $query",
					'lineno' => $lineno,
				];
			}
		}
		return $tests;
	}

	public static function parseGoodProvider(): iterable {
		foreach ( self::loadTests() as $test ) {
			if ( !( $test['fail'] ?? false ) ) {
				yield $test['label'] => [ $test['query'] ];
			}
		}
	}

	public static function parseFailProvider(): iterable {
		foreach ( self::loadTests() as $test ) {
			if ( $test['fail'] ?? false ) {
				yield $test['label'] => [ $test['query'] ];
				return;
			}
		}
	}

	/**
	 * @dataProvider parseGoodProvider
	 */
	public function testParseGood( string $query ): void {
		$g = new JQGrammar;
		try {
			$ast = $g->parse( $query );
			$this->assertIsArray( $ast );
			$this->assertArrayHasKey( 'type', $ast );
		} catch ( \Wikimedia\WikiPEG\SyntaxError $e ) {
			// Include line number information
			throw new \Error( json_encode( $e ) );
		}
	}

	/**
	 * @dataProvider parseFailProvider
	 */
	public function testParseFail( string $query ): void {
		$this->expectException( \Exception::class );
		$g = new JQGrammar;
		$g->parse( $query );
	}
}
