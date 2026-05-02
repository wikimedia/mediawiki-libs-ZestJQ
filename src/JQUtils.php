<?php
declare( strict_types = 1 );

namespace Wikimedia\Zest;

use Closure;
use LogicException;

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
	 * Coerce a JQ value to a number.
	 * Numbers pass through unchanged; numeric strings are parsed.
	 * All other types throw JQError.
	 */
	public static function toNumber( mixed $val ): int|float {
		if ( self::isNumber( $val ) ) {
			return $val;
		}
		if ( is_string( $val ) && is_numeric( $val ) ) {
			// @phan-suppress-next-line PhanTypeInvalidLeftOperandOfAdd
			return $val + 0;
		}
		throw new JQError( self::typeName( $val ) . ' is not a number' );
	}

	/**
	 * Convert a JQ value to string with tostring semantics:
	 * strings pass through unchanged; everything else is JSON-encoded.
	 */
	public static function toString( mixed $val ): string {
		return is_string( $val ) ? $val : self::jsonEncode( $val );
	}

	/**
	 * Assert that $val is a string and return it; throw JQError otherwise.
	 * $who names the operation for the error message (e.g. 'explode').
	 */
	public static function checkString( string $who, mixed $val ): string {
		if ( !is_string( $val ) ) {
			throw new JQError(
				"{$who} requires string inputs, got " . self::typeName( $val )
			);
		}
		return $val;
	}

	/**
	 * Assert that $vals are all strings and returns them; throw JQError otherwise.
	 * $who names the operation for the error message (e.g. 'explode').
	 * @return list<string>
	 */
	public static function checkStrings( string $who, mixed ...$vals ): array {
		foreach ( $vals as $val ) {
			if ( !is_string( $val ) ) {
				throw new JQError(
					"{$who} requires string inputs, got " . self::typeName( $val )
				);
			}
		}
		return $vals;
	}

	/**
	 * Assert that $val is an array and return it; throw JQError otherwise.
	 * $who names the operation for the error message (e.g. 'implode').
	 * @return array<mixed>
	 */
	public static function checkArray( string $who, mixed $val ): array {
		if ( !is_array( $val ) ) {
			throw new JQError(
				"{$who} requires an array input, got " . self::typeName( $val )
			);
		}
		return $val;
	}

	/**
	 * Assert that $val is a list array (sequential integer keys) and return it;
	 * throw JQError otherwise.
	 * $who names the operation for the error message (e.g. '@csv').
	 * @return list<mixed>
	 */
	public static function checkList( string $who, mixed $val ): array {
		if ( !is_array( $val ) || !array_is_list( $val ) ) {
			throw new JQError(
				"{$who} requires an array input, got " . self::typeName( $val )
			);
		}
		return $val;
	}

	/**
	 * Assert that $val is a number (int or float) and return it; throw JQError otherwise.
	 * $who names the operation for the error message (e.g. 'floor').
	 */
	public static function checkNumber( string $who, mixed $val ): int|float {
		if ( !self::isNumber( $val ) ) {
			throw new JQError(
				"{$who} requires a number input, got " . self::typeName( $val )
			);
		}
		return $val;
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
	// JSON encode/decode
	// -----------------------------------------------------------------------

	/**
	 * Decode a JSON string into a PHP value, using stdClass for objects.
	 * Strips any leading Unicode BOM before parsing (jq compatibility).
	 */
	public static function jsonDecode( string $json ): mixed {
		// Strip any Unicode BOM marker (JQ compatibility)
		$stripped = preg_replace( '/^(\xEF\xBB\xBF|\xFF\xFE|\xFE\xFF)/', '', $json );
		try {
			return json_decode( $stripped, false, 512, JSON_THROW_ON_ERROR );
		} catch ( \JsonException ) {
			throw new JQError( 'Invalid JSON: ' . $json );
		}
	}

	/**
	 * In JQ, encoding failures result in `"null"`.
	 * (This is typically due to Unicode encoding issues, like
	 * `json_encode("\xFF")`.)
	 */
	public static function jsonEncode( mixed $val ): string {
		return json_encode( $val, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ?: 'null';
	}

	// -----------------------------------------------------------------------
	// Formatting operators
	// -----------------------------------------------------------------------

	/**
	 * Return a formatter Closure for the named JQ format string.
	 *
	 * The returned Closure accepts any JQ value and returns a formatted string.
	 * Non-string values are first converted with toString(), except:
	 * - @json always JSON-encodes (including strings, which get double-quoted)
	 * - @csv and @tsv require a list array and throw JQError otherwise
	 *
	 * Valid format names: text, json, html, uri, urid, base64, base64d, sh, csv, tsv.
	 *
	 * @throws LogicException for unknown format names
	 * @return Closure(mixed):string
	 */
	public static function formatterFor( string $fmt ): Closure {
		return match ( $fmt ) {
			'text'    => self::toString( ... ),
			'json'    => self::jsonEncode( ... ),
			'html'    => self::formatHtml( ... ),
			'uri'     => self::formatUri( ... ),
			'urid'    => self::formatUrid( ... ),
			'base64'  => self::formatBase64( ... ),
			'base64d' => self::formatBase64d( ... ),
			'sh'      => self::formatSh( ... ),
			'csv'     => self::formatCsv( ... ),
			'tsv'     => self::formatTsv( ... ),
			default   => throw new LogicException( 'Unknown format: @' . $fmt ),
		};
	}

	/** HTML-escape using htmlspecialchars with ENT_QUOTES|ENT_XML1 (so ' → &apos;). */
	private static function formatHtml( mixed $val ): string {
		return htmlspecialchars(
			self::toString( $val ), ENT_QUOTES | ENT_XML1, 'UTF-8'
		);
	}

	/** Percent-encode every byte that is not unreserved (RFC 3986) via rawurlencode. */
	private static function formatUri( mixed $val ): string {
		return rawurlencode( self::toString( $val ) );
	}

	/** Percent-decode a string value via rawurldecode ('+' is left as-is, unlike urldecode). */
	private static function formatUrid( mixed $val ): string {
		return rawurldecode( self::toString( $val ) );
	}

	/** Base64-encode after converting to string. */
	private static function formatBase64( mixed $val ): string {
		return base64_encode( self::toString( $val ) );
	}

	/** Base64-decode, stripping leading/trailing whitespace first (common in multiline PEM blocks). */
	private static function formatBase64d( mixed $val ): string {
		return (string)base64_decode( trim( self::toString( $val ) ) );
	}

	/** Single-quote shell-escape: wraps in ' and replaces embedded ' with '\''. */
	private static function formatSh( mixed $val ): string {
		return "'" . str_replace( "'", "'\\''", self::toString( $val ) ) . "'";
	}

	/**
	 * Format an array as CSV: numbers are bare, strings are double-quoted
	 * with internal double-quotes doubled; values are comma-separated.
	 */
	private static function formatCsv( mixed $val ): string {
		$val = self::checkList( '@csv', $val );
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
		$val = self::checkList( '@tsv', $val );
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
