<?php
declare( strict_types = 1 );

namespace Wikimedia\Zest\Tests;

use Closure;
use Throwable;
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
		return match ( $lineno ) {
			689 =>
			'JSON can not portably represent NaN or infinite values',

			1900, 1904, 1908, 1912, 1917, 1921, 1925, 1929, 1969, 1973, 1977,
			1993 =>
			'Module-level directives not implemented',

			// getpath/1, setpath/2, and the pick/1 builtin that depends on them
			// are not yet implemented; also affects deep-path limit tests
			1147, 1153, 1163, 1197, 1201, 1205, 1209, 1214, 1258, 2494, 2533,
			2573, 2577, 2581, 2585 =>
			'getpath/1, setpath/2, and pick/1 not yet implemented',

			// del() bugs: wrong error message for non-array arg, null nodes
			// created for paths through missing keys, wrong results for
			// overlapping/duplicate indices, and NaN index treated as index 0
			1173, 1177, 1184, 1188, 1192 =>
			'del() has bugs with error messages, missing-key paths, overlapping indices, and NaN indices',

			// assignment update operator bugs
			1245, 1278, 1306, 1374 =>
			'Assignment update operators (|=, +=, //=) have bugs with multi-index empty, NaN/Infinity inputs, and alternative update',

// various error message format differences
			1448, 1481, 1553, 1557, 1997, 2001, 2005, 2034, 2038, 2058, 2062,
			2411, 2487, 2498, 2516, 2523 =>
			'Error message format differs from jq',

			// indices/1 doesn't support overlapping string matches or array needles
			1548, 1581, 1585, 1589 =>
			'indices/1 does not support overlapping string matches or array-needle searches on arrays',

			// string * fractional/NaN number behavior
			1625, 1629 =>
			'String repetition with fractional or NaN multiplier differs from jq behavior',

			// sort/0, unique/0, min/0, max/0 and related builtins not yet implemented
			1673, 1677, 1685, 1689, 1693, 1697, 2262, 2271, 2275 =>
			'sort/0, unique/0, min/0, max/0, sort_by/1, group_by/1, min_by/1, max_by/1 not yet implemented',

			// transpose/0 requires max/0 which is not yet implemented
			1815, 1819 =>
			'transpose/0 not yet implemented (depends on max/0)',

			// bsearch/1 not yet implemented
			1827, 1835, 1839 =>
			'bsearch/1 not yet implemented',

			// date/time builtins not yet implemented
			1843, 1847, 1851, 1855, 1859, 1863, 1868, 1872, 1876, 1881, 1885,
			1889, 1895, 2548 =>
			'Date/time builtins not yet implemented (gmtime, mktime, strftime, strptime, strflocaltime)',

			// have_decnum/0 not implemented; tests require arbitrary-precision decimal support
			2014, 2196, 2200, 2204, 2224, 2228, 2232 =>
			'have_decnum/0 not implemented; tests require arbitrary-precision decimal number support',

			// arithmetic on integers outside IEEE 754 safe range
			2211, 2215, 2219, 2241 =>
			'Arithmetic on integers beyond IEEE 754 safe range differs from jq (no bignum support)',

			// NaN handling: has(nan) on arrays, fromjson for nan/NaN literals
			1733, 2315, 2319, 2324 =>
			'NaN handling differs from jq (has(nan) on array, fromjson does not accept nan literals)',

			// debug/0 and input/0 not yet implemented
			2337, 2341 =>
			'debug/0 and input/0 not yet implemented',

// implode does not replace out-of-range codepoints with U+FFFD
			2403, 2407 =>
			'implode does not replace out-of-range codepoints with U+FFFD replacement character',

			// array slicing with float or NaN bounds uses truncation rather than floor
			2435, 2439, 2443, 2467, 2471, 2475, 2479, 2483 =>
			'Array slicing and indexing with float or NaN bounds differs from jq behavior',

// foreach with multiple initial values
			2538 =>
			'foreach with a multi-valued init expression not fully supported',

			// JSON nesting and path depth limits not implemented
			2558, 2563, 2568, 2593, 2602 =>
			'JSON nesting depth limits and path depth limits not yet implemented',

			// ? operator does not suppress errors from assignment with invalid key type
			2086 =>
			'Error suppression with ? on assignment with invalid key type does not produce empty output',

			// error re-thrown inside try-catch propagates past outer generator yields
			2359 =>
			'Error propagation through generators differs from jq when error follows yielded output',

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
					fn ( $v ) => self::normalizeErrors( $v, $test['label'], $test['lineno'] ),
					self::skipReason( $test['label'], $test['lineno'] ),
				];
			}
		}
	}

	/**
	 * Recursively apply $fn to every leaf value in a nested array/object tree.
	 * Arrays and stdClass objects are traversed; $fn gets a chance to look
	 * at (and normalize) every value, and the resulting objects are traversed.
	 */
	private static function mapDeep( callable $fn, mixed $v ): mixed {
		$v = $fn( $v );
		if ( is_array( $v ) ) {
			return array_map(
				static fn ( $item ) => self::mapDeep( $fn, $item ), $v
			);
		} elseif ( is_object( $v ) ) {
			return (object)array_map(
				static fn ( $item ) => self::mapDeep( $fn, $item ), (array)$v
			);
		}
		return $v;
	}

	/**
	 * Normalize error message strings so that minor wording differences between
	 * our implementation and jq don't cause test failures.  Currently strips
	 * the trailing context from "Invalid path expression …" messages.
	 */
	private static function normalizeErrors( array $vals, string $label, int $lineno ): array {
		// Our implementation does not try to maintain exact error message
		// compatibility with upstream, so here we try to normalize some
		// acceptable differences in error messages, especially when the
		// upstream test in test.jq captures the error message into the
		// output value.
		$norm = match ( $lineno ) {
			// Normalization function for various types of "invalid path
			// expression" error messages.
			1127, 1131, 1135, 1290, 1294 =>
			static function ( mixed $v ): mixed {
				if ( is_string( $v ) && str_starts_with( $v, 'Invalid path expression' ) ) {
					return 'Invalid path expression';
				}
				return $v;
			},

			// trim/ltrim/rtrim use checkString() which produces
			// "X requires string inputs, got Y"; jq says "trim input must be a string"
			1575 =>
			static function ( mixed $v ): mixed {
				if ( is_string( $v ) && preg_match( '/^(l|r)?trim requires/', $v ) ) {
					return 'trim input must be a string';
				}
				return $v;
			},

			default => null,
		};

		return ( $norm === null ) ? $vals : self::mapDeep( $norm, $vals );
	}

	/**
	 * @dataProvider compileProvider
	 * @covers \Wikimedia\Zest\JQCompile
	 */
	public function testCompile( string $query, string $input, array $expected, Closure $normalizeFn, ?string $skip = null ): void {
		if ( $skip === null ) {
			$this->runTest( $query, $input, $expected, $normalizeFn );
			return;
		}
		// In skip mode, we want to verify that the test *fails*, and flag
		// any "unexpected skip".
		try {
			$this->runTest( $query, $input, $expected, $normalizeFn );
		} catch ( Throwable $e ) {
			// This is okay, this was expected to fail. Explain why:
			$this->markTestSkipped( $skip );
		}
		// Hm, this shouldn't happen! We should clean up our skip list.
		$this->fail( 'Test marked to skip, but it unexpectedly passed!' );
	}

	private function runTest( string $query, string $input, array $expected, Closure $normalizeFn ): void {
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
			JQUtils::compare( $normalizeFn( $expected ), $normalizeFn( $result ) ) === 0,
			"got: " . $e( $result ) . ", but expected: " . $e( $expected )
		);
	}
}
