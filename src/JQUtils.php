<?php
declare( strict_types = 1 );

namespace Wikimedia\Zest;

/**
 * Utility functions for dealing with JQ values.
 *
 * JQ's semantics are very similar to, but not identical to,
 * PHP and JavaScript.  There is only one numeric type, unifying
 * PHP's `int` and `float`.  JQ defines its own unique equality,
 * comparison, and sorting operation, which handle objects and
 * arrays gracefully (unlike PHP and JavaScript).  JQ also
 * defines basic "arithmetic" operators, again in order to
 * provide more useful functionality for strings, arrays, and
 * objects.
 */
class JQUtils {

	// -----------------------------------------------------------------------
	// Type selectors
	// -----------------------------------------------------------------------

	/**
	 * Return true if $v is a JQ number (PHP int or float).
	 * JQ has a single numeric type; PHP uses two, so every numeric check
	 * in the compiler goes through this helper to keep them consistent.
	 */
	public static function isNumber( mixed $v ): bool {
		return is_int( $v ) || is_float( $v );
	}

	/**
	 * Return the JQ type name of a PHP value, used in error messages.
	 */
	public static function typeName( mixed $v ): string {
		return match ( true ) {
			( $v === null ) => 'null',
			is_bool( $v ) => 'boolean',
			self::isNumber( $v ) => 'number',
			is_string( $v ) => 'string',
			is_object( $v ) => 'object',
			is_array( $v ) => 'array',
			default => 'unknown',
		};
	}

	// -----------------------------------------------------------------------
	// Comparison and ordering
	// -----------------------------------------------------------------------

	/**
	 * Structural JSON equality.
	 * int and float are treated as the same numeric type (42 == 42.0).
	 * Arrays and objects are compared recursively by key-value pairs.
	 */
	public static function equal( mixed $a, mixed $b ): bool {
		// Numeric: int and float are the same JQ type
		if ( self::isNumber( $a ) ) {
			return ( self::isNumber( $b ) ) && $a == $b;
		}
		// stdClass objects (JSON objects)
		if ( is_object( $a ) ) {
			if ( !is_object( $b ) ) {
				return false;
			}
			$av = get_object_vars( $a );
			$bv = get_object_vars( $b );
			if ( count( $av ) !== count( $bv ) ) {
				return false;
			}
			foreach ( $av as $k => $v ) {
				if ( !array_key_exists( $k, $bv ) || !self::equal( $v, $bv[$k] ) ) {
					return false;
				}
			}
			return true;
		}
		// null, bool, string: identity
		if ( !is_array( $a ) ) {
			return $a === $b;
		}
		// array
		if ( !is_array( $b ) || count( $a ) !== count( $b ) ) {
			return false;
		}
		foreach ( $a as $k => $v ) {
			if ( !array_key_exists( $k, $b ) || !self::equal( $v, $b[$k] ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * JQ cross-type ordering: null(0) < false(1) < true(2) < number(3) <
	 * string(4) < array(5) < object(6).
	 * Returns negative, zero, or positive like the spaceship operator.
	 */
	public static function compare( mixed $a, mixed $b ): int {
		static $order = null;
		$order ??= static function ( mixed $v ): int {
			return match ( true ) {
				( $v === null ) => 0,
				( $v === false ) => 1,
				( $v === true ) => 2,
				( self::isNumber( $v ) ) => 3,
				is_string( $v ) => 4,
				is_array( $v ) => 5,
				default => 6, // stdClass object
			};
		};
		$ta = $order( $a );
		$tb = $order( $b );
		if ( $ta !== $tb ) {
			return $ta <=> $tb;
		}
		if ( $ta <= 2 ) {
			return 0;  // null or a specific boolean; same rank means same value
		}
		if ( $ta <= 4 ) {
			return $a <=> $b;  // number or string: natural PHP ordering
		}
		// array: lexicographic element comparison then by length
		if ( $ta === 5 ) {
			foreach ( array_map( null, $a, $b ) as [ $av, $bv ] ) {
				$c = self::compare( $av, $bv );
				if ( $c !== 0 ) {
					return $c;
				}
			}
			return count( $a ) <=> count( $b );
		}
		// objects (stdClass): sort by keys, then compare key-value pairs in order
		$av = get_object_vars( $a );
		$bv = get_object_vars( $b );
		$ka = array_keys( $av );
		$kb = array_keys( $bv );
		sort( $ka );
		sort( $kb );
		$c = self::compare( $ka, $kb );
		if ( $c !== 0 ) {
			return $c;
		}
		foreach ( $ka as $k ) {
			$c = self::compare( $av[$k], $bv[$k] );
			if ( $c !== 0 ) {
				return $c;
			}
		}
		return 0;
	}

	// -----------------------------------------------------------------------
	// Binary operations
	// -----------------------------------------------------------------------

	/**
	 * JQ addition: null acts as identity; numbers add; strings concatenate;
	 * arrays concatenate; objects merge (right keys overwrite left).
	 */
	public static function add( mixed $a, mixed $b ): mixed {
		if ( $a === null ) {
			return $b;
		}
		if ( $b === null ) {
			return $a;
		}
		if ( self::isNumber( $a ) && self::isNumber( $b ) ) {
			return $a + $b;
		}
		if ( is_string( $a ) && is_string( $b ) ) {
			return $a . $b;
		}
		if ( is_array( $a ) && is_array( $b ) ) {
			return array_merge( $a, $b );
		}
		if ( is_object( $a ) && is_object( $b ) ) {
			return (object)array_merge( get_object_vars( $a ), get_object_vars( $b ) );
		}
		throw new JQError( self::typeName( $a ) . ' and ' . self::typeName( $b ) . ' cannot be added' );
	}

	/**
	 * JQ subtraction: numbers subtract; arrays remove matching elements.
	 */
	public static function subtract( mixed $a, mixed $b ): mixed {
		if ( self::isNumber( $a ) && self::isNumber( $b ) ) {
			return $a - $b;
		}
		if ( is_array( $a ) && is_array( $b ) ) {
			return array_values( array_filter( $a,
				static function ( $item ) use ( $b ): bool {
					foreach ( $b as $bItem ) {
						if ( self::equal( $item, $bItem ) ) {
							return false;
						}
					}
					return true;
				}
			) );
		}
		throw new JQError( self::typeName( $a ) . ' and ' . self::typeName( $b ) . ' cannot be subtracted' );
	}

	/**
	 * JQ multiplication: numbers multiply; string * number repeats string;
	 * null * anything = null; objects are recursively merged.
	 */
	public static function multiply( mixed $a, mixed $b ): mixed {
		if ( $a === null || $b === null ) {
			return null;
		}
		if ( self::isNumber( $a ) && self::isNumber( $b ) ) {
			return $a * $b;
		}
		if ( is_string( $a ) && self::isNumber( $b ) ) {
			return $b <= 0 ? null : str_repeat( $a, (int)$b );
		}
		if ( self::isNumber( $a ) && is_string( $b ) ) {
			return $a <= 0 ? null : str_repeat( $b, (int)$a );
		}
		if ( is_object( $a ) && is_object( $b ) ) {
			return self::mergeObjects( $a, $b );
		}
		throw new JQError( self::typeName( $a ) . ' and ' . self::typeName( $b ) . ' cannot be multiplied' );
	}

	/** Recursive object merge: values in $b overwrite $a, nested objects are merged. */
	private static function mergeObjects( object $a, object $b ): object {
		$result = get_object_vars( $a );
		foreach ( get_object_vars( $b ) as $k => $bVal ) {
			if ( isset( $result[$k] ) && is_object( $result[$k] ) && is_object( $bVal ) ) {
				$result[$k] = self::mergeObjects( $result[$k], $bVal );
			} else {
				$result[$k] = $bVal;
			}
		}
		return (object)$result;
	}

	/**
	 * JQ division: numbers divide (zero divisor throws); strings split.
	 */
	public static function divide( mixed $a, mixed $b ): mixed {
		if ( self::isNumber( $a ) && self::isNumber( $b ) ) {
			if ( $b == 0 ) {
				throw new JQError( 'number (' . $a . ') and number (' . $b . ') cannot be divided because the divisor is zero' );
			}
			return $a / $b;
		}
		if ( is_string( $a ) && is_string( $b ) ) {
			return $b === '' ? mb_str_split( $a ) : explode( $b, $a );
		}
		throw new JQError( self::typeName( $a ) . ' and ' . self::typeName( $b ) . ' cannot be divided' );
	}

	/**
	 * JQ modulo: integer remainder (zero divisor throws).
	 */
	public static function modulo( mixed $a, mixed $b ): mixed {
		if ( self::isNumber( $a ) && self::isNumber( $b ) ) {
			if ( $b == 0 ) {
				throw new JQError( 'number (' . $a . ') modulo zero' );
			}
			return fmod( (float)$a, (float)$b );
		}
		throw new JQError( self::typeName( $a ) . ' and ' . self::typeName( $b ) . ' cannot have remainder computed' );
	}

	/**
	 * Return a slice of an array or string.
	 * Null input yields null; other types throw JQError.
	 */
	public static function slice( mixed $base, mixed $from, mixed $to ): mixed {
		if ( $base === null ) {
			return null;
		}
		if ( is_string( $base ) ) {
			$len = mb_strlen( $base );
			$f = self::normalizeSliceIdx( $from, $len, 0 );
			$t = self::normalizeSliceIdx( $to, $len, $len );
			return mb_substr( $base, $f, max( 0, $t - $f ) );
		}
		if ( is_array( $base ) ) {
			$len = count( $base );
			$f = self::normalizeSliceIdx( $from, $len, 0 );
			$t = self::normalizeSliceIdx( $to, $len, $len );
			return array_values( array_slice( $base, $f, max( 0, $t - $f ) ) );
		}
		throw new JQError( self::typeName( $base ) . ' cannot be sliced' );
	}

	private static function normalizeSliceIdx( mixed $idx, int $len, int $default ): int {
		if ( $idx === null ) {
			return $default;
		}
		$i = (int)$idx;
		if ( $i < 0 ) {
			$i = $len + $i;
		}
		return min( max( 0, $i ), $len );
	}

	// -----------------------------------------------------------------------
	// JSON decode
	// -----------------------------------------------------------------------

	/**
	 * Decode a JSON string into a PHP value, using stdClass for objects.
	 * Strips any leading Unicode BOM before parsing (jq compatibility).
	 */
	public static function jsonDecode( string $json ): mixed {
		// Strip any Unicode BOM marker (JQ compatibility)
		$json = preg_replace( '/^(\xEF\xBB\xBF|\xFF\xFE|\xFE\xFF)/', '', $json );
		return json_decode( $json, false, 512, JSON_THROW_ON_ERROR );
	}

	// -----------------------------------------------------------------------
	// Formatting operators
	// -----------------------------------------------------------------------

	/**
	 * Convert a JQ value to string with tostring semantics:
	 * strings pass through unchanged; everything else is JSON-encoded.
	 */
	public static function toString( mixed $val ): string {
		return is_string( $val )
			? $val
			: ( json_encode( $val, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ?: 'null' );
	}

	/**
	 * Apply a named format (@html, @base64, etc.) to a value.
	 * Non-string values are first converted with toString(), except
	 * the json format which always JSON-encodes its input (including strings).
	 */
	public static function applyFormat( string $fmt, mixed $val ): string {
		$str = self::toString( $val );
		return match ( $fmt ) {
			'text'    => $str,
			'json'    => json_encode( $val, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ?: 'null',
			'html'    => htmlspecialchars( $str, ENT_QUOTES | ENT_XML1, 'UTF-8' ),
			'uri'     => rawurlencode( $str ),
			'urid'    => rawurldecode( $str ),
			'base64'  => base64_encode( $str ),
			'base64d' => (string)base64_decode( trim( $str ) ),
			'sh'      => "'" . str_replace( "'", "'\\''", $str ) . "'",
			'csv'     => self::formatCsv( $val ),
			'tsv'     => self::formatTsv( $val ),
			default   => throw new JQError( 'Unknown format: @' . $fmt ),
		};
	}

	/**
	 * Format an array as CSV: numbers are bare, strings are double-quoted
	 * with internal double-quotes doubled; values are comma-separated.
	 */
	private static function formatCsv( mixed $val ): string {
		if ( !is_array( $val ) || !array_is_list( $val ) ) {
			throw new JQError( '@csv input must be an array, got ' . self::typeName( $val ) );
		}
		$cols = [];
		foreach ( $val as $item ) {
			if ( self::isNumber( $item ) ) {
				$cols[] = json_encode( $item ) ?: '0';
			} elseif ( is_string( $item ) ) {
				$cols[] = '"' . str_replace( '"', '""', $item ) . '"';
			} elseif ( $item === true ) {
				$cols[] = 'true';
			} elseif ( $item === false ) {
				$cols[] = 'false';
			} elseif ( $item === null ) {
				$cols[] = '';
			} else {
				throw new JQError( '@csv: invalid element type ' . self::typeName( $item ) );
			}
		}
		return implode( ',', $cols );
	}

	/**
	 * Format an array as TSV: values are tab-separated; tab, newline,
	 * carriage-return, and backslash in strings are backslash-escaped.
	 */
	private static function formatTsv( mixed $val ): string {
		if ( !is_array( $val ) || !array_is_list( $val ) ) {
			throw new JQError( '@tsv input must be an array, got ' . self::typeName( $val ) );
		}
		$cols = [];
		foreach ( $val as $item ) {
			if ( self::isNumber( $item ) ) {
				$cols[] = json_encode( $item ) ?: '0';
			} elseif ( is_string( $item ) ) {
				$cols[] = str_replace(
					[ '\\', "\t", "\n", "\r" ],
					[ '\\\\', '\\t', '\\n', '\\r' ],
					$item
				);
			} elseif ( $item === true ) {
				$cols[] = 'true';
			} elseif ( $item === false ) {
				$cols[] = 'false';
			} elseif ( $item === null ) {
				$cols[] = '';
			} else {
				throw new JQError( '@tsv: invalid element type ' . self::typeName( $item ) );
			}
		}
		return implode( "\t", $cols );
	}

}
