import type { JQValue, JQValueOrPath } from './internal.js';
import { JQEnv, JQError, assertNever } from './internal.js';

/**
 * Utility functions for dealing with JQ values.
 *
 * JQ's semantics are very similar to, but not identical to, PHP and
 * JavaScript.  There is only one numeric type.  JQ defines its own
 * unique equality, comparison, and sorting operation, which handle
 * objects and arrays gracefully (unlike PHP and JavaScript).  JQ also
 * defines basic "arithmetic" operators, again in order to provide
 * more useful functionality for strings, arrays, and objects.
 */

// -----------------------------------------------------------------------
// Constants
// -----------------------------------------------------------------------

/** Maximum array or string size; prevents accidental huge allocations. */
export const MAX_SIZE = 1024 * 1024;
/** Maximum path size. */
export const MAX_PATH = 10000;

// -----------------------------------------------------------------------
// Type selectors
// -----------------------------------------------------------------------

// Return true if v is a JQ number. JQ has a single numeric type, so every
// numeric check in the compiler goes through this helper to keep them consistent.
export function isNumber( v: JQValue ): v is number {
	return typeof v === 'number';
}

// Coerce a JQ value to a number.
// Numbers pass through unchanged; numeric strings are parsed.
// All other types throw JQError.
export function toNumber( val: JQValueOrPath ): number {
	const v = val as JQValue;
	if ( typeof v === 'number' ) {
		return v;
	}
	if ( typeof v === 'string' && v === v.trim() && v !== '' ) {
		const n = +v;
		if ( !isNaN( n ) ) {
			return n;
		}
	}
	throw new JQError( `${typeNameAndValue( v )} cannot be parsed as a number` );
}

// JQ truthiness: false and null are falsy; everything else is truthy
// (including 0, "", [], and {}).
export function toBoolean( val: JQValueOrPath ): boolean {
	const v = val as JQValue;
	return v !== null && v !== false;
}

// Convert a JQ value to string with tostring semantics:
// strings pass through unchanged; everything else is JSON-encoded.
export function toString( val: JQValueOrPath ): string {
	const v = val as JQValue;
	return typeof v === 'string' ? v : jsonEncode( v );
}

// Assert that val is a string and return it; throw JQError otherwise.
// who names the operation for the error message (e.g. 'explode').
export function checkString( who: string, val: JQValueOrPath ): string {
	const v = val as JQValue;
	if ( typeof v !== 'string' ) {
		throw new JQError( `${who} requires string inputs, got ${typeName( v )}` );
	}
	return v;
}

// Assert that all vals are strings and return them; throw JQError otherwise.
// who names the operation for the error message (e.g. 'explode').
export function checkStrings( who: string, ...vals: JQValueOrPath[] ): string[] {
	return vals.map( ( val ) => checkString( who, val ) );
}

export function isObject( v: JQValue ): v is number {
	return v !== null && typeof v === 'object' && !Array.isArray( v );
}

// Assert that val is an object and return it; throw JQError otherwise.
// who names the operation for the error message (e.g. 'field').
export function checkObject( who: string, val: JQValueOrPath ): Record<string, JQValue> {
	const v = val as JQValue;
	if ( v === null || typeof v !== 'object' || Array.isArray( v ) ) {
		throw new JQError( `${who} requires an object input, got ${typeName( v )}` );
	}
	return v as Record<string, JQValue>;
}

// Assert that val is an array and return it; throw JQError otherwise.
// who names the operation for the error message (e.g. 'implode').
export function checkArray( who: string, val: JQValueOrPath ): JQValue[] {
	const v = val as JQValue;
	if ( !Array.isArray( v ) ) {
		throw new JQError( `${who} requires an array input, got ${typeName( v )}` );
	}
	return v;
}

// Assert that val is a number and return it; throw JQError otherwise.
// who names the operation for the error message (e.g. 'floor').
export function checkNumber( who: string, val: JQValueOrPath, allowNaN = true ): number {
	const v = val as JQValue;
	if ( typeof v !== 'number' ) {
		throw new JQError( `${who} requires a number input, got ${typeName( v )}` );
	}
	if ( !allowNaN && isNaN( v ) ) {
		throw new JQError( `${who} requires a number input, got NaN` );
	}
	return v;
}

export function adjustIndex(
	who: string, index: JQValueOrPath, container: JQValue[],
): number | null {
	const i0 = checkNumber( who, index );
	if ( isNaN( i0 ) ) {
		return null;
	}
	let i = Math.trunc( i0 );
	const length = container.length;
	if ( i < 0 ) {
		i += length;
	}
	return ( i >= 0 && i < length ) ? i : null;
}

// Return the JQ type name of a value, used in error messages.
export function typeName( v: JQValue ): string {
	if ( v === null ) {
		return 'null';
	}
	if ( typeof v === 'boolean' ) {
		return 'boolean';
	}
	if ( typeof v === 'number' ) {
		return 'number';
	}
	if ( typeof v === 'string' ) {
		return 'string';
	}
	if ( Array.isArray( v ) ) {
		return 'array';
	}
	return 'object';
}

// Format a value as "TYPE (value)" for inclusion in an error message,
// matching jq's style. Strings longer than 24 code points are truncated.
export function typeNameAndValue( v: JQValueOrPath ): string {
	const plain = v as JQValue;
	let encoded: string;
	if ( typeof plain === 'string' ) {
		let prefix = '';
		let count = 0;
		let long = false;
		for ( const ch of plain ) {
			if ( count === 24 ) {
				long = true;
				break;
			}
			prefix += ch;
			count++;
		}
		encoded = long ? jsonEncode( prefix ).slice( 0, -1 ) + '..."' : jsonEncode( plain );
	} else {
		encoded = jsonEncode( plain );
	}
	return `${typeName( plain )} (${encoded})`;
}

// -----------------------------------------------------------------------
// Comparison and ordering
// -----------------------------------------------------------------------

// Structural JSON equality.
// Numbers use value equality (42 === 42.0). Arrays and objects are compared
// recursively by key-value pairs.
export function equal( a: JQValue, b: JQValue ): boolean {
	if ( typeof a === 'number' ) {
		return typeof b === 'number' && a === b;
	}
	if ( a !== null && typeof a === 'object' && !Array.isArray( a ) ) {
		if ( b === null || typeof b !== 'object' || Array.isArray( b ) ) {
			return false;
		}
		const ao = a as Record<string, JQValue>;
		const bo = b as Record<string, JQValue>;
		const ak = Object.keys( ao );
		if ( ak.length !== Object.keys( bo ).length ) {
			return false;
		}
		for ( const k of ak ) {
			if ( !Object.hasOwn( bo, k ) || !equal( ao[ k ], bo[ k ] ) ) {
				return false;
			}
		}
		return true;
	}
	if ( !Array.isArray( a ) ) {
		return a === b; // null, bool, string
	}
	if ( !Array.isArray( b ) || a.length !== b.length ) {
		return false;
	}
	for ( let i = 0; i < a.length; i++ ) {
		if ( !equal( a[ i ], b[ i ] ) ) {
			return false;
		}
	}
	return true;
}

// JQ cross-type ordering: null(0) < false(1) < true(2) < number(3) <
// string(4) < array(5) < object(6).
// Returns negative, zero, or positive like the spaceship operator.
export function compare( a: JQValue, b: JQValue ): number {
	const order = ( v: JQValue ): number => {
		if ( v === null ) {
			return 0;
		}
		if ( v === false ) {
			return 1;
		}
		if ( v === true ) {
			return 2;
		}
		if ( typeof v === 'number' ) {
			return 3;
		}
		if ( typeof v === 'string' ) {
			return 4;
		}
		if ( Array.isArray( v ) ) {
			return 5;
		}
		return 6; // object
	};
	const ta = order( a );
	const tb = order( b );
	if ( ta !== tb ) {
		return ta - tb;
	}
	if ( ta <= 2 ) {
		return 0; // null or a specific boolean; only one value of this rank
	}
	if ( ta <= 3 ) {
		// weird behavior with NaN
		if ( isNaN( a as number ) ) {
			return -1;
		}
		if ( isNaN( b as number ) ) {
			return 1;
		}
		// fall through
	}
	if ( ta <= 4 ) {
		// number or string: use explicit comparisons to avoid Infinity - Infinity = NaN
		return ( a as number | string ) < ( b as number | string ) ? -1 :
			( a as number | string ) > ( b as number | string ) ? 1 :
				0;
	}
	if ( ta === 5 ) {
		// array: zip-compare then by length
		const aa = a as JQValue[];
		const ba = b as JQValue[];
		const len = Math.min( aa.length, ba.length );
		for ( let i = 0; i < len; i++ ) {
			const c = compare( aa[ i ], ba[ i ] );
			if ( c !== 0 ) {
				return c;
			}
		}
		return aa.length - ba.length;
	}
	// object: compare sorted keys, then values in key order
	const ao = a as Record<string, JQValue>;
	const bo = b as Record<string, JQValue>;
	const ka = Object.keys( ao ).sort();
	const kb = Object.keys( bo ).sort();
	const kc = compare( ka as unknown as JQValue, kb as unknown as JQValue );
	if ( kc !== 0 ) {
		return kc;
	}
	for ( const k of ka ) {
		const c = compare( ao[ k ], bo[ k ] );
		if ( c !== 0 ) {
			return c;
		}
	}
	return 0;
}

// -----------------------------------------------------------------------
// Binary operations
// -----------------------------------------------------------------------

// JQ addition: null acts as identity; numbers add; strings concatenate;
// arrays concatenate; objects merge (right keys overwrite left).
export function add( a: JQValue, b: JQValue ): JQValue {
	if ( a === null ) {
		return b;
	}
	if ( b === null ) {
		return a;
	}
	if ( typeof a === 'number' && typeof b === 'number' ) {
		return a + b;
	}
	if ( typeof a === 'string' && typeof b === 'string' ) {
		return a + b;
	}
	if ( Array.isArray( a ) && Array.isArray( b ) ) {
		return [ ...a, ...b ];
	}
	if ( a !== null && typeof a === 'object' && !Array.isArray( a ) &&
		b !== null && typeof b === 'object' && !Array.isArray( b ) ) {
		return { ...( a as Record<string, JQValue> ), ...( b as Record<string, JQValue> ) };
	}
	throw new JQError( `${typeNameAndValue( a )} and ${typeNameAndValue( b )} cannot be added` );
}

// JQ subtraction: numbers subtract; arrays remove matching elements.
export function subtract( a: JQValue, b: JQValue ): JQValue {
	if ( typeof a === 'number' && typeof b === 'number' ) {
		return a - b;
	}
	if ( Array.isArray( a ) && Array.isArray( b ) ) {
		return a.filter( ( item ) => !b.some( ( bItem ) => equal( item, bItem ) ) );
	}
	throw new JQError(
		`${typeNameAndValue( a )} and ${typeNameAndValue( b )} cannot be subtracted`,
	);
}

// JQ multiplication: numbers multiply; string * number repeats string;
// null * anything = null; objects are recursively merged.
export function multiply( a: JQValue, b: JQValue ): JQValue {
	if ( a === null || b === null ) {
		return null;
	}
	if ( typeof a === 'number' && typeof b === 'number' ) {
		return a * b;
	}
	if ( typeof a === 'string' && typeof b === 'number' ) {
		if ( isNaN( b ) ) {
			return null;
		}
		const n = Math.floor( b );
		if ( n < 0 ) {
			return null;
		}
		if ( a === '' || n === 0 ) {
			return '';
		}
		if ( n > MAX_SIZE || a.length * n > MAX_SIZE ) {
			throw new JQError( 'Repeat string result too long' );
		}
		return a.repeat( n );
	}
	if ( typeof a === 'number' && typeof b === 'string' ) {
		return multiply( b, a );
	}
	if ( a !== null && typeof a === 'object' && !Array.isArray( a ) &&
		b !== null && typeof b === 'object' && !Array.isArray( b ) ) {
		return mergeObjects( a as Record<string, JQValue>, b as Record<string, JQValue> );
	}
	throw new JQError( `${typeName( a )} and ${typeName( b )} cannot be multiplied` );
}

// Recursive object merge: values in b overwrite a, nested objects are merged.
function mergeObjects( a: Record<string, JQValue>, b: Record<string, JQValue> ): JQValue {
	const result: Record<string, JQValue> = { ...a };
	for ( const [ k, bVal ] of Object.entries( b ) ) {
		const aVal = result[ k ];
		if ( aVal !== undefined && aVal !== null && typeof aVal === 'object' &&
			!Array.isArray( aVal ) && bVal !== null && typeof bVal === 'object' &&
			!Array.isArray( bVal ) ) {
			result[ k ] = mergeObjects(
				aVal as Record<string, JQValue>, bVal as Record<string, JQValue>,
			);
		} else {
			result[ k ] = bVal;
		}
	}
	return result;
}

// JQ division: numbers divide (zero divisor throws); strings split by separator.
export function divide( a: JQValue, b: JQValue ): JQValue {
	if ( typeof a === 'number' && typeof b === 'number' ) {
		if ( b === 0 ) {
			throw new JQError(
				`${typeNameAndValue( a )} and ${typeNameAndValue( b )}` +
				' cannot be divided because the divisor is zero',
			);
		}
		return a / b;
	}
	if ( typeof a === 'string' && typeof b === 'string' ) {
		return b === '' ? [ ...a ] : a.split( b );
	}
	throw new JQError(
		`${typeNameAndValue( a )} and ${typeNameAndValue( b )} cannot be divided`,
	);
}

// JQ modulo: floating-point remainder (zero divisor throws).
export function modulo( a: JQValue, b: JQValue ): JQValue {
	let extra = '';
	if ( typeof a === 'number' && typeof b === 'number' ) {
		if ( b !== 0 ) {
			return a % b;
		}
		extra = ' because the divisor is zero';
	}
	throw new JQError(
		`${typeNameAndValue( a )} and ${typeNameAndValue( b )} cannot be divided (remainder)${extra}`,
	);
}

// Return a slice of an array or string.
// Null input yields null; other types throw JQError (unless opt is true).
export function* slice(
	base: JQValue, from: JQValue, to: JQValue, opt: boolean,
): Generator<JQValue> {
	if ( base === null ) {
		yield null;
	} else if ( opt && !( typeof from === 'number' && typeof to === 'number' ) ) {
		// optional slice with non-numeric bounds: yield nothing
	} else if ( typeof base === 'string' ) {
		const chars = [ ...base ];
		const len = chars.length;
		const f = normalizeSliceIdx( from, len, 0, true, false );
		const t = normalizeSliceIdx( to, len, len, false, true );
		yield chars.slice( f, Math.max( f, t ) ).join( '' );
	} else if ( Array.isArray( base ) ) {
		const len = base.length;
		const f = normalizeSliceIdx( from, len, 0, true, false );
		const t = normalizeSliceIdx( to, len, len, false, true );
		yield base.slice( f, Math.max( f, t ) );
	} else if ( !opt ) {
		throw new JQError( `${typeName( base )} cannot be sliced` );
	}
}

export function normalizeSliceIdx(
	idx: JQValue, len: number, defaultVal: number, floor = false, ceil = false,
): number {
	const raw: JQValue = idx === null ? defaultVal : idx;
	let i = checkNumber( 'slice', raw );
	if ( isNaN( i ) ) {
		i = defaultVal;
	} else {
		i = floor ? Math.floor( i ) : ( ceil ? Math.ceil( i ) : Math.trunc( i ) );
	}
	if ( i < 0 ) {
		i += len;
	}
	return Math.min( Math.max( 0, i ), len );
}

// Throw a JQError when called inside a path-expression context.
// Add this to compile* methods that cannot produce valid path outputs
// (literals, arithmetic, object/array constructors, etc.).
export function assertNotPath( value: JQValueOrPath, env: JQEnv ): JQValue {
	if ( env.isPathMode() ) {
		throw new JQError( 'Invalid path expression with result ' + jsonEncode( value as JQValue ) );
	}
	return value as JQValue;
}

// -----------------------------------------------------------------------
// JSON encode/decode
// -----------------------------------------------------------------------

// Decode a JSON string, stripping any leading Unicode BOM first (jq compatibility).
export function jsonDecode( s: string ): JQValue {
	const stripped = s.replace( /^\uFEFF/, '' ); // strip BOM
	try {
		return JSON.parse( stripped ) as JQValue;
	} catch {
		throw new JQError( `Invalid JSON: ${s}` );
	}
}

// Encoding failures result in "null" to match jq behavior.
export function jsonEncode( v: JQValue ): string {
	try {
		return JSON.stringify( v ) ?? 'null';
	} catch {
		return 'null';
	}
}

// -----------------------------------------------------------------------
// Formatting operators
// -----------------------------------------------------------------------

// Return a formatter function for the named JQ format string.
// The returned function accepts any JQ value and returns a formatted string.
// Non-string values are first converted with toString(), except:
// @json always JSON-encodes (including strings, which get double-quoted);
// @csv and @tsv require an array and throw JQError otherwise.
// Valid format names: text, json, html, uri, urid, base64, base64d, sh, csv, tsv.
// Throws for unknown format names.
export function formatterFor( fmt: string ): ( val: JQValue ) => string {
	switch ( fmt ) {
		case 'text': return toString;
		case 'json': return jsonEncode;
		case 'html': return formatHtml;
		case 'uri': return formatUri;
		case 'urid': return formatUrid;
		case 'base64': return formatBase64;
		case 'base64d': return formatBase64d;
		case 'sh': return formatSh;
		case 'csv': return formatCsv;
		case 'tsv': return formatTsv;
		default: assertNever( `Unknown format: @${fmt}` );
	}
}

// HTML-escape: & < > " ' → &amp; &lt; &gt; &quot; &apos;
function formatHtml( val: JQValue ): string {
	return toString( val )
		.replace( /&/g, '&amp;' )
		.replace( /</g, '&lt;' )
		.replace( />/g, '&gt;' )
		.replace( /"/g, '&quot;' )
		.replace( /'/g, '&apos;' );
}

// Percent-encode every byte that is not unreserved (RFC 3986).
// encodeURIComponent leaves !'()*; additionally encode them for RFC 3986.
function formatUri( val: JQValue ): string {
	return encodeURIComponent( toString( val ) )
		.replace( /[!'()*]/g, ( c ) => '%' + c.charCodeAt( 0 ).toString( 16 ).toUpperCase() );
}

// Percent-decode a URI-encoded string.
// '+' is left as-is (unlike application/x-www-form-urlencoded decoding).
function formatUrid( val: JQValue ): string {
	return decodeURIComponent( toString( val ) );
}

// Base64-encode the UTF-8 bytes of the string value.
function formatBase64( val: JQValue ): string {
	return Buffer.from( toString( val ) ).toString( 'base64' );
}

// Base64-decode, stripping leading/trailing whitespace first
// (common in multiline PEM blocks).
function formatBase64d( val: JQValue ): string {
	return Buffer.from( toString( val ).trim(), 'base64' ).toString();
}

// Single-quote shell-escape: wraps in ' and replaces embedded ' with '\''.
function formatSh( val: JQValue ): string {
	return "'" + toString( val ).replace( /'/g, "'\\''" ) + "'";
}

// Format an array as CSV: numbers are bare, strings are double-quoted with
// internal double-quotes doubled; values are comma-separated.
function formatCsv( val: JQValue ): string {
	const arr = checkArray( '@csv', val );
	const cols = arr.map( ( item ) => {
		if ( typeof item === 'number' ) {
			if ( isNaN( item as number ) ) {
				return '';
			}
			return jsonEncode( item );
		}
		if ( typeof item === 'string' ) {
			return '"' + item.replace( /"/g, '""' ) + '"';
		}
		if ( item === true ) {
			return 'true';
		}
		if ( item === false ) {
			return 'false';
		}
		if ( item === null ) {
			return '';
		}
		throw new JQError( '@csv: invalid element type ' + typeName( item ) );
	} );
	return cols.join( ',' );
}

// Format an array as TSV: values are tab-separated; tab, newline,
// carriage-return, and backslash in strings are backslash-escaped.
function formatTsv( val: JQValue ): string {
	const arr = checkArray( '@tsv', val );
	const cols = arr.map( ( item ) => {
		if ( typeof item === 'number' ) {
			if ( isNaN( item as number ) ) {
				return '';
			}
			return jsonEncode( item );
		}
		if ( typeof item === 'string' ) {
			return item
				.replace( /\\/g, '\\\\' )
				.replace( /\t/g, '\\t' )
				.replace( /\n/g, '\\n' )
				.replace( /\r/g, '\\r' );
		}
		if ( item === true ) {
			return 'true';
		}
		if ( item === false ) {
			return 'false';
		}
		if ( item === null ) {
			return '';
		}
		throw new JQError( '@tsv: invalid element type ' + typeName( item ) );
	} );
	return cols.join( '\t' );
}
