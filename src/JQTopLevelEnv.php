<?php
// @phan-file-suppress PhanUnusedClosureParameter, PhanEmptyYieldFrom
declare( strict_types = 1 );

namespace Wikimedia\ZestJQ;

use Closure;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
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

	/**
	 * Map between C strftime/strptime specifier letters and PHP date() characters.
	 */
	private const TIME_SPEC_FROM_C_MAP = [
		'Y' => 'Y', 'y' => 'y', 'm' => 'm', 'd' => 'd',
		'H' => 'H', 'I' => 'h', 'M' => 'i', 'S' => 's',
		'A' => 'l', 'a' => 'D', 'B' => 'F', 'b' => 'M', 'h' => 'M',
		'p' => 'A', 'P' => 'a', 'u' => 'N', 'w' => 'w',
		'Z' => 'T', 'z' => 'O', 's' => 'U',
	];

	/** @var array<string,Closure> */
	private array $builtins;

	public function __construct( IOContext $io ) {
		parent::__construct( null, $io );
		$this->builtins = self::buildNativeBuiltins();
	}

	/** @inheritDoc */
	// @phan-suppress-next-line PhanUnusedPublicMethodParameter
	public function lookup( string $name, int $arity, bool $cache = true ): ?Closure {
		return $this->builtins["{$name}/{$arity}"] ?? null;
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

		// have_decnum/0, have_literal_numbers/0 — capability flags; this
		// implementation uses IEEE 754 doubles, not arbitrary-precision decimals
		$defs['have_decnum/0'] = static function ( mixed $input, JQEnv $env ): Generator {
			yield false;
		};
		$defs['have_literal_numbers/0'] = static function ( mixed $input, JQEnv $env ): Generator {
			yield false;
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
					$f = JQUtils::checkNumber( 'range', $from );
					foreach ( $toFn( $input, $env ) as $to ) {
						$t = JQUtils::checkNumber( 'range', $to );
						for ( $i = $f; $i < $t; $i++ ) {
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
				$repr = JQUtils::typeNameAndValue( $input );
				throw new JQError( "{$repr} cannot be parsed as a boolean" );
			}
		};

		// utf8bytelength/0 — byte length of a UTF-8 string
		$defs['utf8bytelength/0'] = static function ( mixed $input, JQEnv $env ): Generator {
			if ( !is_string( $input ) ) {
				$repr = JQUtils::typeNameAndValue( $input );
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
		$validate = static fn ( $n ) =>
			// improper codepoints get mapped to U+FFFD REPLACEMENT CHARACTER
			// (out of range, or in the utf16 surrogate area)
			( $n < 0 || $n > 0x10FFFF || ( $n >= 0xD800 && $n <= 0xDFFF ) )
				  ? 0xFFFD : $n;
		$defs['implode/0'] = static function ( mixed $input, JQEnv $env ) use ( $validate ): Generator {
			$input = JQUtils::checkArray( 'implode', $input );
			$chars = array_map(
				static fn ( $code ) => mb_chr(
					$validate( (int)JQUtils::checkNumber( 'implode', $code, allowNaN: false ) )
				),
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
				throw new JQHaltException(
					0,
					$input !== null ? JQUtils::toString( $input ) : ''
				);
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

		// getpath/1 — navigate input by the path array produced by $pathFn
		$defs['getpath/1'] = static function ( array $argFns ): Closure {
			$pathFn = $argFns[0];
			return static function ( mixed $input, JQEnv $env ) use ( $pathFn ): Generator {
				foreach ( $pathFn( $input, $env->leavePathMode() ) as $pathVal ) {
					$path = JQUtils::checkArray( 'getpath', $pathVal );
					if ( count( $path ) > JQUtils::MAX_PATH ) {
						throw new JQError( 'Path too deep' );
					}
					$result = JQCompile::getAtPath( $input, $path, 0 );
					if ( $env->isPathMode() ) {
						// Don't bother to do this iteration to transfer the
						// array into a pathEnv chain unless we're actually
						// in a nested path
						$pathEnv = $env;
						foreach ( $path as $key ) {
							$pathEnv = $pathEnv->appendPath( $key );
						}
						yield $pathEnv->maybeWithPath( $result );
					} else {
						yield $result;
					}
				}
			};
		};

		// setpath/2 — return input with newVal written at the path array
		$defs['setpath/2'] = static function ( array $argFns ): Closure {
			[ $pathFn, $valFn ] = $argFns;
			return static function ( mixed $input, JQEnv $env ) use ( $pathFn, $valFn ): Generator {
				$plain = $env->leavePathMode();
				foreach ( $pathFn( $input, $plain ) as $pathVal ) {
					$path = JQUtils::checkArray( 'setpath', $pathVal );
					if ( count( $path ) > JQUtils::MAX_PATH ) {
						throw new JQError( 'Path too deep' );
					}
					foreach ( $valFn( $input, $plain ) as $newVal ) {
						yield JQCompile::setAtPath( $input, $path, 0, $newVal );
					}
				}
			};
		};

		// delpaths/1 — delete all listed paths from the input
		$defs['delpaths/1'] = static function ( array $argFns ): Closure {
			$pathsFn = $argFns[0];
			return static function ( mixed $input, JQEnv $env ) use ( $pathsFn ): Generator {
				foreach ( $pathsFn( $input, $env ) as $paths ) {
					$paths = JQUtils::checkArray( 'delpaths', $paths );
					yield JQCompile::deleteAtPaths( $input, $paths );
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

		// sort/0 — sort array by jq type ordering
		$defs['sort/0'] = static function ( mixed $input, JQEnv $env ): Generator {
			$arr = JQUtils::checkArray( 'sort', $input );
			usort( $arr, JQUtils::compare( ... ) );
			yield $arr;
		};

		// unique/0 — sort then remove consecutive duplicates
		$defs['unique/0'] = static function ( mixed $input, JQEnv $env ): Generator {
			$arr = JQUtils::checkArray( 'unique', $input );
			usort( $arr, JQUtils::compare( ... ) );
			$result = [];
			$last = false;
			foreach ( $arr as $v ) {
				if ( $result === [] || JQUtils::compare( $last, $v ) !== 0 ) {
					$result[] = $v;
					$last = $v;
				}
			}
			yield $result;
		};

		// min/0, max/0 — null for empty array, otherwise the extreme value
		$minmax = static fn ( $name, $cmp ) => ( static function ( mixed $input, JQEnv $env ) use ( $name, $cmp ): Generator {
			$arr = JQUtils::checkArray( $name, $input );
			$best = $arr[0] ?? null;
			$len = count( $arr );
			for ( $i = 1; $i < $len; $i++ ) {
				if ( $cmp( $arr[$i], $best ) ) {
					$best = $arr[$i];
				}
			}
			yield $best;
		} );
		$mincmp = static fn ( $el, $best ) => JQUtils::compare( $el, $best ) < 0;
		// On ties, max/max_by keeps last element
		$maxcmp = static fn ( $el, $best ) => JQUtils::compare( $el, $best ) >= 0;
		$defs['min/0'] = $minmax( 'min', $mincmp );
		$defs['max/0'] = $minmax( 'max', $maxcmp );

		// _min_by_impl/1 and _max_by_impl/1 — cores of min_by/1 and max_by/1.
		// Empty array yields null; otherwise a single linear scan finds the extremum.
		$minmax_by = static fn ( $name, $cmp ) => ( static function ( array $argFns ) use ( $name, $cmp ): Closure {
			$keysFn = $argFns[0];
			return static function ( mixed $input, JQEnv $env ) use ( $name, $cmp, $keysFn ): Generator {
				$arr = JQUtils::checkArray( $name, $input );
				foreach ( $keysFn( $input, $env ) as $keys ) {
					$keys = JQUtils::checkArray( $name, $keys );
					$bestVal = $arr[0] ?? null;
					$bestKey = $keys[0] ?? null;
					for ( $i = 1, $n = count( $arr ); $i < $n; $i++ ) {
						if ( $cmp( $keys[$i], $bestKey ) ) {
							$bestVal = $arr[$i];
							$bestKey = $keys[$i];
						}
					}
					yield $bestVal;
				}
			};
		} );
		$defs['_min_by_impl/1'] = $minmax_by( '_min_by_impl', $mincmp );
		$defs['_max_by_impl/1'] = $minmax_by( '_max_by_impl', $maxcmp );

		// _sort_by_impl/1 — core of sort_by/1.
		// Called as _sort_by_impl(map([f])): receives input array and a pre-mapped
		// array of key-arrays (one per element); returns input sorted by those keys.
		$defs['_sort_by_impl/1'] = static function ( array $argFns ): Closure {
			$keysFn = $argFns[0];
			return static function ( mixed $input, JQEnv $env ) use ( $keysFn ): Generator {
				$arr = JQUtils::checkArray( '_sort_by_impl', $input );
				foreach ( $keysFn( $input, $env ) as $keys ) {
					$keys = JQUtils::checkArray( '_sort_by_impl', $keys );
					// pair each value with its key, sort by key, extract values
					$pairs = array_map( null, $arr, $keys );
					usort( $pairs, static fn ( $a, $b ) => JQUtils::compare( $a[1], $b[1] ) );
					yield array_column( $pairs, 0 );
				}
			};
		};

		// _unique_by_impl/1 — core of unique_by/1.
		// Sort by keys then keep only the first element of each run of equal keys.
		$defs['_unique_by_impl/1'] = static function ( array $argFns ): Closure {
			$keysFn = $argFns[0];
			return static function ( mixed $input, JQEnv $env ) use ( $keysFn ): Generator {
				$arr = JQUtils::checkArray( '_unique_by_impl', $input );
				foreach ( $keysFn( $input, $env ) as $keys ) {
					$keys = JQUtils::checkArray( '_unique_by_impl', $keys );
					$pairs = array_map( null, $arr, $keys );
					usort( $pairs, static fn ( $a, $b ) => JQUtils::compare( $a[1], $b[1] ) );
					$result  = [];
					$prevKey = null;
					foreach ( $pairs as [ $val, $key ] ) {
						if ( $result === [] || JQUtils::compare( $key, $prevKey ) !== 0 ) {
							$result[] = $val;
							$prevKey  = $key;
						}
					}
					yield $result;
				}
			};
		};

		// _group_by_impl/1 — core of group_by/1.
		// Same calling convention as _sort_by_impl; returns array of groups.
		$defs['_group_by_impl/1'] = static function ( array $argFns ): Closure {
			$keysFn = $argFns[0];
			return static function ( mixed $input, JQEnv $env ) use ( $keysFn ): Generator {
				$arr = JQUtils::checkArray( '_group_by_impl', $input );
				foreach ( $keysFn( $input, $env ) as $keys ) {
					$keys = JQUtils::checkArray( '_group_by_impl', $keys );
					$pairs = array_map( null, $arr, $keys );
					usort( $pairs, static fn ( $a, $b ) => JQUtils::compare( $a[1], $b[1] ) );
					$groups     = [];
					$curGroup   = [ $pairs[0][0] ];
					$curKey     = $pairs[0][1];
					for ( $i = 1, $n = count( $pairs ); $i < $n; $i++ ) {
						if ( JQUtils::compare( $pairs[$i][1], $curKey ) === 0 ) {
							$curGroup[] = $pairs[$i][0];
						} else {
							$groups[] = $curGroup;
							$curGroup = [ $pairs[$i][0] ];
							$curKey   = $pairs[$i][1];
						}
					}
					$groups[] = $curGroup;
					yield $groups;
				}
			};
		};

		// _strindices/1 — array of codepoint positions where needle occurs in input string
		$defs['_strindices/1'] = static function ( array $argFns ): Closure {
			$needleFn = $argFns[0];
			return static function ( mixed $input, JQEnv $env ) use ( $needleFn ): Generator {
				$str = JQUtils::checkString( '_strindices', $input );
				foreach ( $needleFn( $input, $env ) as $needle ) {
					$needle  = JQUtils::checkString( '_strindices', $needle );
					$indices = [];
					if ( $needle !== '' ) {
						// Lookahead (?=...) finds overlapping
						// matches; preg_split on it gives the pieces
						// between split points.  mb_strlen of each
						// piece gives the cumulative codepoint offset
						// to the next match.
						$pieces  = preg_split(
							'/(?=' . preg_quote( $needle, '/' ) . ')/su',
							$str
						);
						$charPos = 0;
						foreach ( $pieces as $p ) {
							$charPos  += mb_strlen( $p );
							$indices[] = $charPos;
						}
						// last element is just the strlen of $input
						array_pop( $indices );
					}
					yield $indices;
				}
			};
		};

		// bsearch/1 — binary search on a sorted array.
		// Returns the index if found; -(insertion_point)-1 if not found.
		$defs['bsearch/1'] = static function ( array $argFns ): Closure {
			$needleFn = $argFns[0];
			return static function ( mixed $input, JQEnv $env ) use ( $needleFn ): Generator {
				$input = JQUtils::checkArray( 'bsearch', $input );
				foreach ( $needleFn( $input, $env ) as $needle ) {
					$lo = 0;
					$hi = count( $input ) - 1;
					$result = null;
					while ( $lo <= $hi ) {
						$mid = intdiv( $lo + $hi, 2 );
						$cmp = JQUtils::compare( $input[$mid], $needle );
						if ( $cmp === 0 ) {
							$result = $mid;
							break;
						} elseif ( $cmp < 0 ) {
							$lo = $mid + 1;
						} else {
							$hi = $mid - 1;
						}
					}
					yield $result ?? -$lo - 1;
				}
			};
		};

		// -----------------------------------------------------------------------
		// Date/time builtins
		// -----------------------------------------------------------------------

		$utc = new DateTimeZone( 'UTC' );
		$localtz = new DateTimeZone( date_default_timezone_get() );

		$defs['now/0'] = static function ( mixed $input, JQEnv $env ): Generator {
			yield microtime( true );
		};

		$defs['gmtime/0'] = static function ( mixed $input, JQEnv $env ) use ( $utc ): Generator {
			$ts = JQUtils::checkNumber( 'gmtime', $input );
			$dt = ( new DateTimeImmutable( '@' . (int)$ts ) )
				->setTimezone( $utc );
			yield self::dateTimeToJqArray( $dt, (float)$ts );
		};

		$defs['mktime/0'] = static function ( mixed $input, JQEnv $env ) use ( $utc ): Generator {
			$input = self::checkTmArray( 'mktime', $input );
			yield self::jqArrayToDateTime( $input, $utc )->getTimestamp();
		};

		$defs['strftime/1'] = static function ( array $argFns ) use ( $utc ): Closure {
			$fmtFn = $argFns[0];
			return static function ( mixed $input, JQEnv $env ) use ( $utc, $fmtFn ): Generator {
				foreach ( $fmtFn( $input, $env ) as $fmt ) {
					$fmt = JQUtils::checkString( 'strftime/1', $fmt );
					if ( JQUtils::isNumber( $input ) ) {
						$dt = ( new DateTimeImmutable( '@' . (int)$input ) )
							->setTimezone( $utc );
					} else {
						$input = self::checkTmArray( 'strftime/1', $input );
						$dt = self::jqArrayToDateTime( $input, $utc );
					}
					yield self::strftimeFmt( $fmt, $dt );
				}
			};
		};

		$defs['strflocaltime/1'] = static function ( array $argFns ) use ( $localtz ): Closure {
			$fmtFn = $argFns[0];
			return static function ( mixed $input, JQEnv $env ) use ( $localtz, $fmtFn ): Generator {
				foreach ( $fmtFn( $input, $env ) as $fmt ) {
					$fmt = JQUtils::checkString( 'strflocaltime/1', $fmt );
					if ( JQUtils::isNumber( $input ) ) {
						$dt = ( new DateTimeImmutable( '@' . (int)$input ) )
							->setTimezone( $localtz );
					} else {
						$input = self::checkTmArray( 'strflocaltime/1', $input );
						$dt = self::jqArrayToDateTime( $input, $localtz );
					}
					yield self::strftimeFmt( $fmt, $dt );
				}
			};
		};

		$defs['strptime/1'] = static function ( array $argFns ) use ( $utc ): Closure {
			$fmtFn = $argFns[0];
			return static function ( mixed $input, JQEnv $env ) use ( $utc, $fmtFn ): Generator {
				foreach ( $fmtFn( $input, $env ) as $fmt ) {
					[ $input, $fmt ] = JQUtils::checkStrings( 'strptime/1', $input, $fmt );
					$phpFmt = self::cFmtToPhpParseFmt( $fmt );
					$dt = DateTimeImmutable::createFromFormat(
						'!' . $phpFmt, $input, $utc
					);
					if ( $dt === false ) {
						throw new JQError(
							'date ' . JQUtils::jsonEncode( $input ) .
							' does not match format ' . JQUtils::jsonEncode( $fmt )
						);
					}
					yield self::dateTimeToJqArray( $dt );
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

	// -----------------------------------------------------------------------
	// Date/time helpers
	// -----------------------------------------------------------------------

	/**
	 * Check that a jq broken-down time array has all-numeric (non-NaN) elements.
	 * Short arrays are allowed; the missing tail elements default to
	 * 1970-01-01T00:00:00.00.
	 *
	 * @return list<int|float>
	 */
	private static function checkTmArray( string $who, mixed $v ): array {
		$defaults = [ 1970, 0, 1, 0, 0, 0, 0, 0 ];
		$v = JQUtils::checkArray( $who, $v );
		for ( $i = 0; $i < 8; $i++ ) {
			$v[$i] = JQUtils::checkNumber(
				"{$who} element {$i}", $v[$i] ?? $defaults[$i], allowNaN: false
			);
		}
		return $v;
	}

	/**
	 * Convert a jq broken-down time array to a DateTimeImmutable in the
	 * given time zone.
	 * Array layout: [year, month(0-based), day(1-based), hour, min, sec+frac, wday, yday].
	 * Short arrays are padded with zeros (day defaults to 1).
	 */
	private static function jqArrayToDateTime( array $arr, DateTimeZone $tz ): DateTimeImmutable {
		// @phan-suppress-next-line PhanUnusedVariable serves as documentation
		[ $year, $month, $day, $hour, $min, $sec, $weekday, $yearday ] = $arr;
		// @phan-suppress-next-line PhanTypeMismatchReturnNullable
		return DateTimeImmutable::createFromFormat(
			'Y-m-d H:i:s',
			// Month is 0-based, convert to 1-based
			sprintf( '%04d-%02d-%02d %02d:%02d:%02d', $year, $month + 1, $day, $hour, $min, $sec ),
			$tz
		);
	}

	/**
	 * Convert a DateTimeInterface to a jq 8-element broken-down time array.
	 * $fracSecs is the original float Unix timestamp; its fractional part is
	 * added to the seconds field (matching C's tm2jv behaviour).
	 */
	private static function dateTimeToJqArray( DateTimeInterface $dt, float $fracSecs = 0.0 ): array {
		$frac = fmod( $fracSecs, 1.0 );
		return [
			(int)$dt->format( 'Y' ),
			(int)$dt->format( 'n' ) - 1, // 1-based → 0-based
			(int)$dt->format( 'j' ),
			(int)$dt->format( 'G' ),
			(int)$dt->format( 'i' ),
			(int)$dt->format( 's' ) + $frac,
			(int)$dt->format( 'w' ), // 0=Sunday
			(int)$dt->format( 'z' ), // 0-based yearday
		];
	}

	/**
	 * Format a DateTime using a C strftime-style format string.
	 */
	private static function strftimeFmt( string $fmt, DateTimeInterface $dt ): string {
		$out = '';
		for ( $i = 0, $len = strlen( $fmt ); $i < $len; $i++ ) {
			if ( $fmt[$i] !== '%' || $i + 1 >= $len ) {
				$out .= $fmt[$i];
			} else {
				$spec = $fmt[++$i];
				$cspec = self::TIME_SPEC_FROM_C_MAP[$spec] ?? null;
				$out .= match ( $spec ) {
					'%' => '%',
					'n' => "\n",
					't' => "\t",
					'j' => sprintf( '%03d', (int)$dt->format( 'z' ) + 1 ),
					'e' => sprintf( '%2d', (int)$dt->format( 'j' ) ),
					'T' => $dt->format( 'H:i:s' ),
					'D' => $dt->format( 'm/d/y' ),
					'R' => $dt->format( 'H:i' ),
					'F' => $dt->format( 'Y-m-d' ),
					default => $cspec ? $dt->format( $cspec ) : '',
				};
			}
		}
		return $out;
	}

	/**
	 * Convert a C strptime format string to a PHP createFromFormat format string.
	 * Alphabetic literal characters are escaped with \ so PHP doesn't treat them
	 * as format specifiers.
	 */
	private static function cFmtToPhpParseFmt( string $fmt ): string {
		$out = '';
		for ( $i = 0, $len = strlen( $fmt ); $i < $len; $i++ ) {
			$ch = $fmt[$i];
			if ( $ch !== '%' || $i + 1 >= $len ) {
				$out .= ctype_alpha( $ch ) ? ( '\\' . $ch ) : $ch;
			} else {
				$spec = $fmt[++$i];
				$cspec = self::TIME_SPEC_FROM_C_MAP[ $spec ] ?? null;
				$out .= match ( $spec ) {
					'%' => '\\%',
					'n' => "\n",
					't' => "\t",
					default => $cspec ?? '',
				};
			}
		}
		return $out;
	}

	// -----------------------------------------------------------------------
	// Other helpers
	// -----------------------------------------------------------------------

	/**
	 * Recursive JQ containment check used by contains/1 and inside/1.
	 *
	 * - strings: substring containment
	 * - lists: every element of $b has a "contained" element in $a
	 * - objects: every key of $b exists in $a with a contained value
	 * - scalars: structural equality
	 */
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
