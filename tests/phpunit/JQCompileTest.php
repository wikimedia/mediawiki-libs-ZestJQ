<?php
declare( strict_types = 1 );

namespace Wikimedia\Zest\Tests;

use Closure;
use Throwable;
use Wikimedia\Zest\JQCompile;
use Wikimedia\Zest\JQEnv;
use Wikimedia\Zest\JQError;
use Wikimedia\Zest\JQGrammar;
use Wikimedia\Zest\JQUtils;

/**
 * JQ evaluation tests driven by the upstream jq test suite from
 * https://github.com/jqlang/jq/blob/master/tests/jq.test
 */
class JQCompileTest extends \PHPUnit\Framework\TestCase {

	public static function skipReason( string $label, int $lineno ): ?string {
		return match ( $lineno ) {
			// JSON cannot represent NaN or infinity; also affects 1E+1000 literals
			// (jq clamps them to MAX_FLOAT; PHP represents them as INF which tojson encodes as null).
			// 1306: input contains bare Infinity/-Infinity/NaN/-NaN literals, which
			// our JSON decoder does not accept.
			// 2407: input contains bare nan literal ([nan] element), which our
			// JSON decoder does not accept.
			689, 1306, 2232, 2271, 2275, 2407 =>
			'JSON can not portably represent NaN or infinite values',

			1900, 1904, 1908, 1912, 1917, 1921, 1925, 1929, 1969, 1973, 1977,
			1993 =>
			'Module-level directives not implemented',

			// del() bugs:
			// 1184: mixed integer+slice deletion — e.g. del(.[1],.[2],[-3:9]):
			//   slice keys (stdClass, rank 6) sort before integer keys (rank 3)
			//   in reversed JQUtils::compare, so slices are always deleted first;
			//   but when an integer index >= slice.start, removing the slice shifts
			//   the element, so the integer deletion then misses or hits the wrong
			//   position.  Correct handling requires path adjustment after each
			//   deletion (tracking how each splice shifts later indices), which is
			//   not yet implemented.
			1184 =>
			'del() has a bug with mixed integer+slice overlapping deletion',

			// various error message format differences
			// 2014: large-float number representation in error messages differs
			// (jq: "12345678901234568000000000...", PHP: "1.2345678901234568E+29")
			2014 =>
			'Error message format differs from jq',

			// PHP uses exact int64 for integers, so values like 13911860366432393 are
			// preserved exactly; jq without decnum rounds them to the nearest double
			// (13911860366432392). Tests expect the jq double-rounded value.
			2196, 2200, 2204, 2211, 2215, 2219, 2224, 2241 =>
			'Number representation at IEEE 754 double-precision boundary differs from jq: PHP preserves exact int64 values',

			// NaN handling: tojson, fromjson for nan/NaN literals
			2315, 2319, 2324 =>
			'NaN handling differs from jq (tojson, fromjson does not accept nan literals)',

			// debug/0 and input/0 not yet implemented
			2337, 2341 =>
			'debug/0 and input/0 not yet implemented',

			// setpath with a 10000-element path: setAtPath is recursive, so a
			// path of depth 10000 exhausts PHP's call stack before returning.
			// setpath(depth>10000) and getpath(depth>10000) correctly throw
			// "Path too deep" at the JQTopLevelEnv level before recursing.
			2573 =>
			'setAtPath recursive implementation exhausts PHP call stack at depth 10000',

			// JSON nesting and path depth limits not implemented
			2558, 2563, 2568, 2593, 2602 =>
			'JSON nesting depth limits and path depth limits not yet implemented',

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

			// jq says "Cannot index TYPE with string ("field")"; we say
			// "field requires an object input, got TYPE" — normalize both to "Cannot index TYPE"
			1448 =>
			static function ( mixed $v ): mixed {
				if ( is_string( $v ) && preg_match(
					'/^(?:Cannot index|field requires an object input, got) (\w+)/',
					$v, $m
				) ) {
					return 'Cannot index ' . $m[1];
				}
				return $v;
			},

			// jq gives a detailed parse error; we emit "Invalid JSON: <input>" —
			// normalize any "Invalid ..." message to the common prefix
			2498 =>
			static function ( mixed $v ): mixed {
				if ( is_string( $v ) && str_starts_with( $v, 'Invalid ' ) ) {
					return 'Invalid ';
				}
				return $v;
			},

			// jq says "TYPE (value) cannot be negated"; we say
			// "negation requires a number input, got TYPE"
			1481, 1997, 2005 =>
			static function ( mixed $v ): mixed {
				if ( is_string( $v ) && preg_match(
					'/^(null|boolean|string|number|array|object) \(.*\) cannot be negated$/',
					$v, $m
				) ) {
					return 'negation requires a number input, got ' . $m[1];
				}
				return $v;
			},

			// jq says "TYPE (value) cannot be searched…" / "TYPE (value) is not a string";
			// we say "_strindices requires string inputs, got TYPE"
			1553, 1557 =>
			static function ( mixed $v ): mixed {
				if ( is_string( $v ) && preg_match(
					'/^(null|boolean|string|number|array|object) \(.*\) (?:cannot be searched|is not a string)/',
					$v, $m
				) ) {
					return '_strindices requires string inputs, got ' . $m[1];
				}
				return $v;
			},

			// jq says "startswith() requires string inputs" (with parens, no type suffix);
			// we say "startswith requires string inputs, got TYPE"
			2516, 2523 =>
			static function ( mixed $v ): mixed {
				if ( is_string( $v ) && preg_match(
					'/^((?:start|end)swith)(?:\(\))? requires string inputs(?:, got \S+)?$/',
					$v, $m
				) ) {
					return $m[1] . ' requires string inputs';
				}
				return $v;
			},

			// invalid tm array: our checkNumber error says "FUNC element N requires
			// a number input, got TYPE"; jq says "FUNC requires parsed datetime inputs"
			1868, 1872, 1876 =>
			static function ( mixed $v ): mixed {
				if ( is_string( $v ) && preg_match(
					'/^(strftime\/1|strflocaltime\/1|mktime)\b.*\brequires.*input,\s*got/',
					$v, $m
				) ) {
					return $m[1] . ' requires parsed datetime inputs';
				}
				return $v;
			},

			// invalid format argument: our checkString says "FUNC requires string
			// inputs, got TYPE"; jq says "FUNC requires a string format"
			1881, 1885 =>
			static function ( mixed $v ): mixed {
				if ( is_string( $v ) && preg_match(
					'/^(strftime\/1|strflocaltime\/1) requires string inputs/',
					$v, $m
				) ) {
					return $m[1] . ' requires a string format';
				}
				return $v;
			},

			// setAtPath uses checkArray/checkObject which say
			// "setAtPath requires an {array|object} input, got TYPE";
			// jq says "Cannot index TYPE_CONTAINER with {number|string} (VALUE)".
			// Normalise jq's message to our format.
			1258, 2494 =>
			static function ( mixed $v ): mixed {
				if ( is_string( $v ) && preg_match(
					'/^Cannot index (\w+) with (number|string)/', $v, $m
				) ) {
					$req = $m[2] === 'number' ? 'array' : 'object';
					return "setAtPath requires an {$req} input, got {$m[1]}";
				}
				return $v;
			},

			// bsearch uses checkArray which says "bsearch requires an array input, got TYPE";
			// jq says "TYPE (VALUE) cannot be searched from"
			1839 =>
			static function ( mixed $v ): mixed {
				if ( is_string( $v ) && preg_match( '/^(\w+) \(.*\) cannot be searched from$/', $v, $m ) ) {
					return 'bsearch requires an array input, got ' . $m[1];
				}
				return $v;
			},

			// delpaths uses checkArray which says "delpaths requires an array input, got TYPE";
			// jq says "Paths must be specified as an array"
			1173 =>
			static function ( mixed $v ): mixed {
				if ( is_string( $v ) && str_starts_with( $v, 'delpaths requires an array input' ) ) {
					return 'Paths must be specified as an array';
				}
				return $v;
			},

			// setAtPath throws "setAtPath requires an array input, got string"
			// when asked to update a string slice; jq says "Cannot update string slices"
			2479 =>
			static function ( mixed $v ): mixed {
				if ( is_string( $v ) && str_starts_with( $v, 'setAtPath requires an array input' ) ) {
					return 'Cannot update string slices';
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
		$eval = JQCompile::compile( $ast, JQEnv::getStdEnv() );
		$result = [];
		try {
			foreach ( $eval( $input ) as $val ) {
				// Deliberately dropping the keys from the generator here,
				// so we don't get collisions we make a list out of the results.
				$result[] = $val;
			}
		} catch ( JQError $e ) {
			// As with the upstream test runner (apparently): if we throw
			// only after at least one result, then don't count this as a
			// failure. (See test on line 2359)
			if ( !$result ) {
				throw $e;
			}
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
