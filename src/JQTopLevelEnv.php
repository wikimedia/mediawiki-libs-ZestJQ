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

	public function __construct( IOContext $io ) {
		parent::__construct( null, $io, self::buildNativeBuiltins() );
	}

	/** @return array<string,Closure> */
	private static function buildNativeBuiltins(): array {
		$defs = [];

		// $__env__/0 is a hack which yield the current JQEnv so callers
		// can capture it.
		// Used by bootstrapping code (JQEnv::buildStandardEnv) to extract
		// the startup env after a sequence of def statements has been
		// evaluated.
		$defs['$__env__/0'] = static function ( mixed $input, JQEnv $env ): Generator {
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

		// Math builtins
		$defs['floor/0'] = static function ( mixed $input, JQEnv $env ): Generator {
			yield (int)floor( JQUtils::checkNumber( 'floor', $input ) );
		};
		$defs['ceil/0'] = static function ( mixed $input, JQEnv $env ): Generator {
			yield (int)ceil( JQUtils::checkNumber( 'ceil', $input ) );
		};
		$defs['round/0'] = static function ( mixed $input, JQEnv $env ): Generator {
			yield (int)round( JQUtils::checkNumber( 'round', $input ) );
		};
		$defs['sqrt/0'] = static function ( mixed $input, JQEnv $env ): Generator {
			yield sqrt( JQUtils::checkNumber( 'sqrt', $input ) );
		};
		$defs['fabs/0'] = static function ( mixed $input, JQEnv $env ): Generator {
			yield abs( JQUtils::checkNumber( 'fabs', $input ) );
		};

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

		// builtins/0 — list native builtin names (populated after the rest are defined)
		$names = array_keys( $defs );
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
