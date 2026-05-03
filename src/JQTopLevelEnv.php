<?php
// @phan-file-suppress PhanUnusedClosureParameter, PhanEmptyYieldFrom
declare( strict_types = 1 );

namespace Wikimedia\Zest;

use Closure;
use Generator;

/**
 * Root environment pre-populated with native PHP builtin functions.
 *
 * JQTopLevelEnv is the base of every evaluation's environment chain.
 * builtin.jq is compiled on top of it, so that jq-level library
 * functions can call native builtins without special-casing them inside
 * JQCompile.
 *
 * Arity-0 builtins are stored as Closure(mixed,JQEnv):Generator.
 * Arity-N builtins (N≥1) are stored as factories:
 *   Closure(array $argFns): Closure(mixed,JQEnv):Generator
 * matching the convention used by JQCompile::compileCall().
 */
class JQTopLevelEnv extends JQEnv {
	// The character set from jq's jvp_codepoint_is_whitespace():
	// PHP's trim() also strips NUL (U+0000) which jq does not, so we
	// use an explicit regex instead.
	private const WS_CLASS =
		'[\x{0009}-\x{000D}\x{0020}\x{0085}\x{00A0}\x{1680}' .
		'\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}]';
	private const TRIM_BOTH =
		'/^' . self::WS_CLASS . '+|' . self::WS_CLASS . '+$/u';
	private const TRIM_LEFT =
		'/^' . self::WS_CLASS . '+/u';
	private const TRIM_RIGHT =
		'/' . self::WS_CLASS . '+$/u';

	public function __construct( IOContext $io ) {
		parent::__construct( null, $io, self::buildNativeBuiltins() );
	}

	/** @return array<string,Closure> */
	private static function buildNativeBuiltins(): array {
		$defs = [];

		// __env__/0 is a private builtin that yields the current JQEnv so
		// callers can capture it.
		// Used by bootstrapping code (JQEnv::buildStandardEnv) to extract
		// the startup env after a sequence of def statements has been
		// evaluated.
		$defs['__env__/0'] = static function ( mixed $input, JQEnv $env ): Generator {
			yield $env;
		};

		// length/0 — null→0, array/object→count, string→mb_strlen, number→abs
		$defs['length/0'] = static function ( mixed $input, JQEnv $env ): Generator {
			yield match ( true ) {
				$input === null => 0,
				is_array( $input ) => count( JQUtils::assertIsList( 'length', $input ) ),
				is_object( $input ) => count( get_object_vars( $input ) ),
				is_string( $input ) => mb_strlen( $input ),
				JQUtils::isNumber( $input ) => abs( $input ),
				default => throw new JQError( JQUtils::typeName( $input ) . ' has no length' ),
			};
		};

		// type/0 — "null", "boolean", "number", "string", "array", "object"
		$defs['type/0'] = static function ( mixed $input, JQEnv $env ): Generator {
			yield JQUtils::typeName( $input );
		};

		// not/0 — JQ truthiness: null and false are falsy, everything else truthy
		$defs['not/0'] = static function ( mixed $input, JQEnv $env ): Generator {
			yield !JQUtils::toBoolean( $input );
		};

		// empty/0 — produces no output
		$defs['empty/0'] = static function ( mixed $input, JQEnv $env ): Generator {
			yield from [];
		};

		// error/0 — throw the input value as a JQError; jqValue carries original
		$defs['error/0'] = static function ( mixed $input, JQEnv $env ): Generator {
			yield from [];
			throw new JQError( JQUtils::toString( $input ), $input );
		};

		// keys_unsorted/0 — object keys (insertion order) or array indices
		$defs['keys_unsorted/0'] = static function ( mixed $input, JQEnv $env ): Generator {
			if ( is_array( $input ) ) {
				$arr = JQUtils::assertIsList( 'keys_unsorted', $input );
				yield count( $arr ) > 0 ? range( 0, count( $arr ) - 1 ) : [];
			} elseif ( is_object( $input ) ) {
				yield array_keys( get_object_vars( $input ) );
			} else {
				throw new JQError( JQUtils::typeName( $input ) . ' has no keys' );
			}
		};

		// keys/0 — like keys_unsorted but lexicographically sorted
		$defs['keys/0'] = static function ( mixed $input, JQEnv $env ): Generator {
			if ( is_array( $input ) ) {
				$arr = JQUtils::assertIsList( 'keys', $input );
				yield count( $arr ) > 0 ? range( 0, count( $arr ) - 1 ) : [];
			} elseif ( is_object( $input ) ) {
				$keys = array_keys( get_object_vars( $input ) );
				sort( $keys );
				yield $keys;
			} else {
				throw new JQError( JQUtils::typeName( $input ) . ' has no keys' );
			}
		};

		// has/1 — test whether an index/key is present
		$defs['has/1'] = static function ( array $argFns ): Closure {
			$keyFn = $argFns[0];
			return static function ( mixed $input, JQEnv $env ) use ( $keyFn ): Generator {
				foreach ( $keyFn( $input, $env ) as $key ) {
					if ( is_array( $input ) ) {
						$index = JQUtils::adjustIndex( 'has', $key, $input );
						yield ( $index !== null );
					} elseif ( is_object( $input ) ) {
						$key = JQUtils::checkString( 'has', $key );
						yield property_exists( $input, $key );
					} else {
						throw new JQError( JQUtils::typeName( $input ) . ' is not indexable' );
					}
				}
			};
		};

		// range/2 — range($from; $to), yields integers $from .. $to-1
		$defs['range/2'] = static function ( array $argFns ): Closure {
			$fromFn = $argFns[0];
			$toFn   = $argFns[1];
			return static function ( mixed $input, JQEnv $env ) use ( $fromFn, $toFn ): Generator {
				foreach ( $fromFn( $input, $env ) as $from ) {
					foreach ( $toFn( $input, $env ) as $to ) {
						for ( $i = $from; $i < $to; $i++ ) {
							yield $i;
						}
					}
				}
			};
		};

		// tostring/0 — strings pass through; everything else is JSON-encoded
		$defs['tostring/0'] = static function ( mixed $input, JQEnv $env ): Generator {
			yield JQUtils::toString( $input );
		};

		// tojson/0 — always JSON-encode (including strings)
		$defs['tojson/0'] = static function ( mixed $input, JQEnv $env ): Generator {
			yield JQUtils::jsonEncode( $input );
		};

		// fromjson/0 — parse a JSON string
		$defs['fromjson/0'] = static function ( mixed $input, JQEnv $env ): Generator {
			$json = JQUtils::checkString( 'fromjson', $input );
			yield JQUtils::jsonDecode( $json );
		};

		// tonumber/0 — numbers pass through; strings are parsed
		$defs['tonumber/0'] = static function ( mixed $input, JQEnv $env ): Generator {
			yield JQUtils::toNumber( $input );
		};

		// toboolean/0 — booleans pass through; exactly "true"/"false" strings are converted
		$defs['toboolean/0'] = static function ( mixed $input, JQEnv $env ): Generator {
			if ( is_bool( $input ) ) {
				yield $input;
			} elseif ( $input === 'true' ) {
				yield true;
			} elseif ( $input === 'false' ) {
				yield false;
			} else {
				$repr = JQUtils::typeName( $input ) . ' (' . JQUtils::jsonEncode( $input ) . ')';
				throw new JQError( "{$repr} cannot be parsed as a boolean" );
			}
		};

		// utf8bytelength/0 — byte length of a UTF-8 string
		$defs['utf8bytelength/0'] = static function ( mixed $input, JQEnv $env ): Generator {
			if ( !is_string( $input ) ) {
				$repr = JQUtils::typeName( $input ) . ' (' . JQUtils::jsonEncode( $input ) . ')';
				throw new JQError( "{$repr} only strings have UTF-8 byte length" );
			}
			yield strlen( $input );
		};

		// explode/0 — string → array of Unicode codepoints
		$defs['explode/0'] = static function ( mixed $input, JQEnv $env ): Generator {
			$str = JQUtils::checkString( 'explode', $input );
			$chars = mb_str_split( $str );
			yield array_map( static fn ( $c )=>mb_ord( $c ), $chars );
		};

		// implode/0 — array of Unicode codepoints → string
		$defs['implode/0'] = static function ( mixed $input, JQEnv $env ): Generator {
			$input = JQUtils::checkArray( 'implode', $input );
			$chars = array_map(
				static fn ( $code ) => mb_chr( (int)JQUtils::checkNumber( 'implode', $code ) ),
				$input
			);
			yield implode( '', $chars );
		};

		// startswith/1, endswith/1
		$defs['startswith/1'] = static function ( array $argFns ): Closure {
			$prefixFn = $argFns[0];
			return static function ( mixed $input, JQEnv $env ) use ( $prefixFn ): Generator {
				foreach ( $prefixFn( $input, $env ) as $prefix ) {
					[ $input, $prefix ] = JQUtils::checkStrings( 'startswith', $input, $prefix );
					yield str_starts_with( $input, $prefix );
				}
			};
		};
		$defs['endswith/1'] = static function ( array $argFns ): Closure {
			$suffixFn = $argFns[0];
			return static function ( mixed $input, JQEnv $env ) use ( $suffixFn ): Generator {
				foreach ( $suffixFn( $input, $env ) as $suffix ) {
					[ $input, $suffix ] = JQUtils::checkStrings( 'endswith', $input, $suffix );
					yield str_ends_with( $input, $suffix );
				}
			};
		};

		// split/1 — split string by a literal separator
		$defs['split/1'] = static function ( array $argFns ): Closure {
			$sepFn = $argFns[0];
			return static function ( mixed $input, JQEnv $env ) use ( $sepFn ): Generator {
				foreach ( $sepFn( $input, $env ) as $sep ) {
					[ $input, $sep ] = JQUtils::checkStrings( 'split', $input, $sep );
					yield $sep === '' ? mb_str_split( $input ) : explode( $sep, $input );
				}
			};
		};

		// contains/1 — recursive containment check
		$defs['contains/1'] = static function ( array $argFns ): Closure {
			$valFn = $argFns[0];
			return static function ( mixed $input, JQEnv $env ) use ( $valFn ): Generator {
				foreach ( $valFn( $input, $env ) as $val ) {
					yield self::jqContains( $input, $val );
				}
			};
		};

		// Math builtins — integer-rounding functions yield int to match jq semantics
		$defs['floor/0'] = static function ( mixed $input, JQEnv $env ): Generator {
			yield (int)floor( JQUtils::checkNumber( 'floor', $input ) );
		};
		$defs['ceil/0'] = static function ( mixed $input, JQEnv $env ): Generator {
			yield (int)ceil( JQUtils::checkNumber( 'ceil', $input ) );
		};
		$defs['round/0'] = static function ( mixed $input, JQEnv $env ): Generator {
			yield (int)round( JQUtils::checkNumber( 'round', $input ) );
		};
		// Direct PHP function equivalents
		$defs['acos/0']  = self::mathFn( 'acos' );
		$defs['acosh/0'] = self::mathFn( 'acosh' );
		$defs['asin/0']  = self::mathFn( 'asin' );
		$defs['asinh/0'] = self::mathFn( 'asinh' );
		$defs['atan/0']  = self::mathFn( 'atan' );
		$defs['atanh/0'] = self::mathFn( 'atanh' );
		$defs['cos/0']   = self::mathFn( 'cos' );
		$defs['cosh/0']  = self::mathFn( 'cosh' );
		$defs['exp/0']   = self::mathFn( 'exp' );
		$defs['expm1/0'] = self::mathFn( 'expm1' );
		$defs['fabs/0']  = self::mathFn( 'fabs', abs( ... ) );
		$defs['log/0']   = self::mathFn( 'log' );
		$defs['log10/0'] = self::mathFn( 'log10' );
		$defs['log1p/0'] = self::mathFn( 'log1p' );
		$defs['sin/0']   = self::mathFn( 'sin' );
		$defs['sinh/0']  = self::mathFn( 'sinh' );
		$defs['sqrt/0']  = self::mathFn( 'sqrt' );
		$defs['tan/0']   = self::mathFn( 'tan' );
		$defs['tanh/0']  = self::mathFn( 'tanh' );
		// Custom callables for functions with no direct PHP equivalent
		$defs['cbrt/0']      = self::mathFn( 'cbrt', static fn ( $x ) => $x >= 0 ? $x ** ( 1 / 3 ) : -( ( -$x ) ** ( 1 / 3 ) ) );
		$defs['exp2/0']      = self::mathFn( 'exp2', static fn ( $x ) => 2 ** $x );
		$defs['exp10/0']     = self::mathFn( 'exp10', static fn ( $x ) => 10 ** $x );
		$defs['log2/0']      = self::mathFn( 'log2', static fn ( $x ) => log( $x, 2 ) );
		$defs['nearbyint/0'] = self::mathFn( 'nearbyint', static fn ( $x ) => round( $x, 0, PHP_ROUND_HALF_EVEN ) );
		$defs['rint/0']      = self::mathFn( 'rint', static fn ( $x ) => round( $x, 0, PHP_ROUND_HALF_EVEN ) );
		$defs['trunc/0']     = self::mathFn( 'trunc', static fn ( $x ) => $x < 0 ? ceil( $x ) : floor( $x ) );
		// Omitted — no PHP equivalent: erf, erfc, tgamma/gamma, lgamma,
		// j0, j1 (Bessel functions of the first kind), y0, y1 (second kind),
		// logb (IEEE exponent extraction), significand (IEEE significand).

		// Two-input math functions
		$defs['atan2/2'] = self::mathFn2( 'atan2' );
		$defs['fmod/2'] = self::mathFn2( 'fmod' );
		$defs['hypot/2'] = self::mathFn2( 'hypot' );
		$defs['pow/2'] = self::mathFn2( 'pow' );
		$defs['copysign/2'] = self::mathFn2( 'copysign', static fn ( $x, $y )=>abs( $x ) * ( $y < 0 ? -1 : 1 ) );
		$defs['fdim/2'] = self::mathFn2( 'fdim', static fn ( $x, $y )=>max( $x - $y, 0 ) );
		$defs['fmax/2'] = self::mathFn2( 'fmax', static fn ( $x, $y )=>max( $x, $y ) );
		$defs['fmin/2'] = self::mathFn2( 'fmin', static fn ( $x, $y )=>min( $x, $y ) );

		// Special float values and predicates
		$defs['nan/0'] = static function ( mixed $input, JQEnv $env ): Generator {
			yield NAN;
		};
		$defs['infinite/0'] = static function ( mixed $input, JQEnv $env ): Generator {
			yield INF;
		};
		$defs['isinfinite/0'] = static function ( mixed $input, JQEnv $env ): Generator {
			yield is_float( $input ) && is_infinite( $input );
		};
		$defs['isnan/0'] = static function ( mixed $input, JQEnv $env ): Generator {
			yield is_float( $input ) && is_nan( $input );
		};
		$defs['isnormal/0'] = static function ( mixed $input, JQEnv $env ): Generator {
			// PHP has no is_normal(); approximate: finite and nonzero;
			// this misses only those "subnormal" small numbers very close
			// to zero.
			yield is_int( $input ) ||
				( is_float( $input ) && is_finite( $input ) && $input != 0.0 );
		};

		// last/1 — yield the last output of expr; yield nothing if expr is empty
		$defs['last/1'] = static function ( array $argFns ): Closure {
			$exprFn = $argFns[0];
			return static function ( mixed $input, JQEnv $env ) use ( $exprFn ): Generator {
				$last  = null;
				$found = false;
				foreach ( $exprFn( $input, $env ) as $val ) {
					$last  = $val;
					$found = true;
				}
				if ( $found ) {
					yield $last;
				}
			};
		};

		// halt/0, halt_error/1
		$defs['halt/0'] = static function ( mixed $input, JQEnv $env ): Generator {
			yield from [];
			throw new JQHaltException( 0 );
		};
		$defs['halt_error/1'] = static function ( array $argFns ): Closure {
			$codeFn = $argFns[0];
			return static function ( mixed $input, JQEnv $env ) use ( $codeFn ): Generator {
				yield from [];
				foreach ( $codeFn( $input, $env ) as $code ) {
					throw new JQHaltException(
						(int)JQUtils::checkNumber( 'halt_error', $code ),
						$input !== null ? JQUtils::toString( $input ) : ''
					);
				}
				throw new JQHaltException( 0 );
			};
		};

		// path/1 — yield the path(s) that expr traverses
		$defs['path/1'] = static function ( array $argFns ): Closure {
			$exprFn = $argFns[0];
			return static function ( mixed $input, JQEnv $env ) use ( $exprFn ): Generator {
				$pathEnv = $env->enterPathMode();
				foreach ( $exprFn( $input, $pathEnv ) as $item ) {
					[ $itemEnv ] = $pathEnv->maybeUnwrapPath( $item );
					yield $itemEnv->getPath();
				}
			};
		};

		// delpaths/1 — delete all listed paths from the input
		$defs['delpaths/1'] = static function ( array $argFns ): Closure {
			$pathsFn = $argFns[0];
			return static function ( mixed $input, JQEnv $env ) use ( $pathsFn ): Generator {
				foreach ( $pathsFn( $input, $env ) as $paths ) {
					$paths = JQUtils::checkArray( 'delpaths', $paths );
					// Process paths in reverse order so that deleting an array element
					// by index doesn't shift the positions of later indices.
					usort( $paths, static fn ( $a, $b ) => -JQUtils::compare( $a, $b ) );
					$result = $input;
					foreach ( $paths as $path ) {
						$result = JQCompile::deleteAtPath( $result, (array)$path, 0 );
					}
					yield $result;
				}
			};
		};

		// trim/0, ltrim/0, rtrim/0 — strip Unicode whitespace
		$defs['trim/0'] = static function ( mixed $input, JQEnv $env ): Generator {
			$str = JQUtils::checkString( 'trim', $input );
			yield preg_replace( self::TRIM_BOTH, '', $str );
		};
		$defs['ltrim/0'] = static function ( mixed $input, JQEnv $env ): Generator {
			$str = JQUtils::checkString( 'ltrim', $input );
			yield preg_replace( self::TRIM_LEFT, '', $str );
		};
		$defs['rtrim/0'] = static function ( mixed $input, JQEnv $env ): Generator {
			$str = JQUtils::checkString( 'rtrim', $input );
			yield preg_replace( self::TRIM_RIGHT, '', $str );
		};

		// _strindices/1 — array of codepoint positions where needle occurs in input string
		$defs['_strindices/1'] = static function ( array $argFns ): Closure {
			$needleFn = $argFns[0];
			return static function ( mixed $input, JQEnv $env ) use ( $needleFn ): Generator {
				$str = JQUtils::checkString( '_strindices', $input );
				foreach ( $needleFn( $input, $env ) as $needle ) {
					$needle  = JQUtils::checkString( '_strindices', $needle );
					$indices = [];
					// explode() splits on the byte sequence of $needle in O(N).
					// mb_strlen() of each piece gives the codepoint distance to
					// the next match, avoiding O(N^2) repeated mb_strpos scans.
					$pieces        = $needle ? explode( $needle, $str ) : [];
					$needleCharLen = mb_strlen( $needle );
					$charPos       = 0;
					$last          = count( $pieces ) - 1;
					for ( $i = 0; $i < $last; $i++ ) {
						$charPos  += mb_strlen( $pieces[$i] );
						$indices[] = $charPos;
						$charPos  += $needleCharLen;
					}
					yield $indices;
				}
			};
		};

		// builtins/0 — list public native builtin names (no _ prefix; populated
		// after the rest are defined so builtins/0 itself is excluded)
		$names = array_values( array_filter(
			array_keys( $defs ), static fn ( $name ) => !str_starts_with( $name, '_' )
		) );
		sort( $names );
		$defs['builtins/0'] = static function ( mixed $input, JQEnv $env ) use ( $names ): Generator {
			yield $names;
		};

		return $defs;
	}

	/**
	 * Recursive JQ containment check used by contains/1 and inside/1.
	 *
	 * - strings: substring containment
	 * - lists: every element of $b has a "contained" element in $a
	 * - objects: every key of $b exists in $a with a contained value
	 * - scalars: structural equality
	 */

	/**
	 * Build a arity-0 Filter that applies a unary PHP math function to a
	 * numeric input, using $name for both the JQ error message and (when $fn
	 * is omitted) the PHP function to call.
	 *
	 * @param string $name JQ builtin name (used in type-error messages)
	 * @param ?callable(int|float):(int|float) $fn PHP callable; defaults to the
	 *   global function named $name
	 * @return Closure(mixed,JQEnv):Generator
	 */
	private static function mathFn( string $name, ?callable $fn = null ): Closure {
		$fn ??= $name( ... );
		return static function ( mixed $input, JQEnv $env ) use ( $name, $fn ): Generator {
			yield $fn( JQUtils::checkNumber( $name, $input ) );
		};
	}

	/**
	 * Build a arity-2 Filter that applies a binary PHP math function to a
	 * two arguments, using $name for both the JQ error message and (when $fn
	 * is omitted) the PHP function to call.  The Filter ignores its
	 * input.
	 *
	 * @param string $name JQ builtin name (used in type-error messages)
	 * @param ?callable(int|float,int|float):(int|float) $fn PHP callable; defaults to the
	 *   global function named $name
	 * @return Closure(array):Closure
	 */
	private static function mathFn2( string $name, ?callable $fn = null ): Closure {
		$fn ??= $name( ... );
		return static function ( array $argFns ) use ( $name, $fn ): Closure {
			[ $leftFn, $rightFn ] = $argFns;
			return static function ( mixed $input, JQEnv $env ) use ( $name, $fn, $leftFn, $rightFn ): Generator {
				// for binops jq generally evaluates right first (outer loop)
				// then left (inner loop).
				foreach ( $rightFn( $input, $env ) as $rv ) {
					$rv = JQUtils::checkNumber( $name, $rv );
					foreach ( $leftFn( $input, $env ) as $lv ) {
						$lv = JQUtils::checkNumber( $name, $lv );
						yield $fn( $lv, $rv );
					}
				}
			};
		};
	}

	private static function jqContains( mixed $a, mixed $b ): bool {
		if ( is_string( $a ) && is_string( $b ) ) {
			return str_contains( $a, $b );
		}
		if ( is_array( $a ) && is_array( $b ) ) {
			JQUtils::assertIsList( 'contains', $a );
			JQUtils::assertIsList( 'contains', $b );
			foreach ( $b as $bItem ) {
				$found = false;
				foreach ( $a as $aItem ) {
					if ( self::jqContains( $aItem, $bItem ) ) {
						$found = true;
						break;
					}
				}
				if ( !$found ) {
					return false;
				}
			}
			return true;
		}
		if ( is_object( $a ) && is_object( $b ) ) {
			$av = get_object_vars( $a );
			$bv = get_object_vars( $b );
			foreach ( $bv as $k => $bVal ) {
				if ( !array_key_exists( $k, $av ) || !self::jqContains( $av[$k], $bVal ) ) {
					return false;
				}
			}
			return true;
		}
		if ( JQUtils::isNumber( $a ) ) {
			return ( JQUtils::isNumber( $b ) ) && $a == $b;
		}
		return $a === $b;
	}

}
