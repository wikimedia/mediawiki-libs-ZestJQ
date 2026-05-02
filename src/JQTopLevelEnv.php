<?php
// @phan-file-suppress PhanUnusedClosureParameter, PhanEmptyYieldFrom
declare( strict_types = 1 );

namespace Wikimedia\Zest;

use Closure;
use Generator;
use JsonException;

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
				is_array( $input ) => count( $input ),
				is_object( $input ) => count( get_object_vars( $input ) ),
				is_string( $input ) => mb_strlen( $input ),
				is_int( $input ) || is_float( $input ) => abs( $input ),
				default => throw new JQError( JQCompile::typeName( $input ) . ' has no length' ),
			};
		};

		// type/0 — "null", "boolean", "number", "string", "array", "object"
		$defs['type/0'] = static function ( mixed $input, JQEnv $env ): Generator {
			yield JQCompile::typeName( $input );
		};

		// not/0 — JQ truthiness: null and false are falsy, everything else truthy
		$defs['not/0'] = static function ( mixed $input, JQEnv $env ): Generator {
			yield $input === null || $input === false;
		};

		// empty/0 — produces no output
		$defs['empty/0'] = static function ( mixed $input, JQEnv $env ): Generator {
			yield from [];
		};

		// error/0 — throw the input value as a JQError
		$defs['error/0'] = static function ( mixed $input, JQEnv $env ): Generator {
			yield from [];
			throw new JQError( is_string( $input ) ? $input : ( json_encode( $input ) ?: 'null' ) );
		};

		// keys_unsorted/0 — object keys (insertion order) or array indices
		$defs['keys_unsorted/0'] = static function ( mixed $input, JQEnv $env ): Generator {
			if ( is_array( $input ) ) {
				yield array_is_list( $input )
					? ( count( $input ) > 0 ? range( 0, count( $input ) - 1 ) : [] )
					: array_keys( $input );
			} elseif ( is_object( $input ) ) {
				yield array_keys( get_object_vars( $input ) );
			} else {
				throw new JQError( JQCompile::typeName( $input ) . ' has no keys' );
			}
		};

		// keys/0 — like keys_unsorted but lexicographically sorted
		$defs['keys/0'] = static function ( mixed $input, JQEnv $env ): Generator {
			if ( is_array( $input ) ) {
				if ( array_is_list( $input ) ) {
					yield count( $input ) > 0 ? range( 0, count( $input ) - 1 ) : [];
				} else {
					$keys = array_keys( $input );
					sort( $keys );
					yield $keys;
				}
			} elseif ( is_object( $input ) ) {
				$keys = array_keys( get_object_vars( $input ) );
				sort( $keys );
				yield $keys;
			} else {
				throw new JQError( JQCompile::typeName( $input ) . ' has no keys' );
			}
		};

		// has/1 — test whether an index/key is present
		$defs['has/1'] = static function ( array $argFns ): Closure {
			$keyFn = $argFns[0];
			return static function ( mixed $input, JQEnv $env ) use ( $keyFn ): Generator {
				foreach ( $keyFn( $input, $env ) as $key ) {
					if ( is_array( $input ) && array_is_list( $input ) ) {
						yield is_int( $key ) && $key >= 0 && $key < count( $input );
					} elseif ( is_array( $input ) ) {
						yield array_key_exists( $key, $input );
					} elseif ( is_object( $input ) ) {
						yield property_exists( $input, (string)$key );
					} else {
						throw new JQError( JQCompile::typeName( $input ) . ' is not indexable' );
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
			yield is_string( $input )
				? $input
				: ( json_encode( $input, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ?: 'null' );
		};

		// tojson/0 — always JSON-encode (including strings)
		$defs['tojson/0'] = static function ( mixed $input, JQEnv $env ): Generator {
			yield json_encode( $input, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ?: 'null';
		};

		// fromjson/0 — parse a JSON string
		$defs['fromjson/0'] = static function ( mixed $input, JQEnv $env ): Generator {
			if ( !is_string( $input ) ) {
				throw new JQError( 'fromjson requires a string, got ' . JQCompile::typeName( $input ) );
			}
			try {
				$decoded = ZestJQ::jsonDecode( $input );
			} catch ( JsonException ) {
				throw new JQError( 'Invalid JSON: ' . $input );
			}
			yield $decoded;
		};

		// tonumber/0 — numbers pass through; strings are parsed
		$defs['tonumber/0'] = static function ( mixed $input, JQEnv $env ): Generator {
			if ( is_int( $input ) || is_float( $input ) ) {
				yield $input;
			} elseif ( is_string( $input ) && is_numeric( $input ) ) {
				// @phan-suppress-next-line PhanTypeMismatchReturn
				yield $input + 0;
			} else {
				throw new JQError( JQCompile::typeName( $input ) . ' is not a number' );
			}
		};

		// explode/0 — string → array of Unicode codepoints
		$defs['explode/0'] = static function ( mixed $input, JQEnv $env ): Generator {
			if ( !is_string( $input ) ) {
				throw new JQError( 'explode requires a string, got ' . JQCompile::typeName( $input ) );
			}
			$codes = [];
			for ( $i = 0, $n = mb_strlen( $input ); $i < $n; $i++ ) {
				$codes[] = mb_ord( mb_substr( $input, $i, 1 ) );
			}
			yield $codes;
		};

		// implode/0 — array of Unicode codepoints → string
		$defs['implode/0'] = static function ( mixed $input, JQEnv $env ): Generator {
			if ( !is_array( $input ) ) {
				throw new JQError( 'implode requires an array, got ' . JQCompile::typeName( $input ) );
			}
			$str = '';
			foreach ( $input as $code ) {
				$str .= mb_chr( (int)$code );
			}
			yield $str;
		};

		// startswith/1, endswith/1
		$defs['startswith/1'] = static function ( array $argFns ): Closure {
			$prefixFn = $argFns[0];
			return static function ( mixed $input, JQEnv $env ) use ( $prefixFn ): Generator {
				foreach ( $prefixFn( $input, $env ) as $prefix ) {
					yield str_starts_with( (string)$input, (string)$prefix );
				}
			};
		};
		$defs['endswith/1'] = static function ( array $argFns ): Closure {
			$suffixFn = $argFns[0];
			return static function ( mixed $input, JQEnv $env ) use ( $suffixFn ): Generator {
				foreach ( $suffixFn( $input, $env ) as $suffix ) {
					yield str_ends_with( (string)$input, (string)$suffix );
				}
			};
		};

		// split/1 — split string by a literal separator
		$defs['split/1'] = static function ( array $argFns ): Closure {
			$sepFn = $argFns[0];
			return static function ( mixed $input, JQEnv $env ) use ( $sepFn ): Generator {
				foreach ( $sepFn( $input, $env ) as $sep ) {
					if ( !is_string( $input ) ) {
						throw new JQError( 'split requires a string input, got ' . JQCompile::typeName( $input ) );
					}
					yield $sep === '' ? mb_str_split( $input ) : explode( (string)$sep, $input );
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
			yield (int)floor( (float)$input );
		};
		$defs['ceil/0'] = static function ( mixed $input, JQEnv $env ): Generator {
			yield (int)ceil( (float)$input );
		};
		$defs['round/0'] = static function ( mixed $input, JQEnv $env ): Generator {
			yield (int)round( (float)$input );
		};
		$defs['sqrt/0'] = static function ( mixed $input, JQEnv $env ): Generator {
			yield sqrt( (float)$input );
		};
		$defs['fabs/0'] = static function ( mixed $input, JQEnv $env ): Generator {
			yield abs( (float)$input );
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
			// PHP has no is_normal(); approximate: finite and nonzero
			yield is_float( $input ) && is_finite( $input ) && $input != 0.0;
		};

		// halt/0, halt_error/1
		$defs['halt/0'] = static function ( mixed $input, JQEnv $env ): Generator {
			yield from [];
			exit( 0 );
		};
		$defs['halt_error/1'] = static function ( array $argFns ): Closure {
			$codeFn = $argFns[0];
			return static function ( mixed $input, JQEnv $env ) use ( $codeFn ): Generator {
				yield from [];
				foreach ( $codeFn( $input, $env ) as $code ) {
					if ( is_string( $input ) ) {
						fwrite( STDERR, $input );
					}
					exit( is_int( $code ) ? $code : 5 );
				}
				exit( 0 );
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
		if ( is_array( $a ) && array_is_list( $a ) && is_array( $b ) && array_is_list( $b ) ) {
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
		if ( is_int( $a ) || is_float( $a ) ) {
			return ( is_int( $b ) || is_float( $b ) ) && $a == $b;
		}
		return $a === $b;
	}

}
