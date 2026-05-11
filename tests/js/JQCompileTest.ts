import { describe, it, expect } from 'vitest';
import { resolve } from 'path';
import { JQGrammar, JQCompile, JQEnv, JQError, JQUtils } from '../../lib/internal.js';
import type { ASTNode, JQValue } from '../../lib/internal.js';
import { loadTests, type TestCase } from './jqTestLoader.js';

const root = resolve( __dirname, '../..' );

const jqTests = loadTests( resolve( root, 'tests/jq.test' ) );
const localTests = loadTests( resolve( root, 'tests/local.test' ) );

// Line numbers in tests/jq.test with known incompatibilities.
// Each case should include a reason string. Tests that are listed here but
// unexpectedly pass will fail with "Expected to be skipped, but passed".
function upstreamSkipReason( lineno: number ): string | null {
	switch ( lineno ) {
		// JSON cannot represent NaN or infinity; also affects 1E+1000 literals
		// (jq clamps them to MAX_FLOAT; JS represents them as Infinity/NaN).
		// 1306: input contains bare Infinity/-Infinity/NaN/-NaN literals, which
		// our JSON decoder does not accept.
		// 2407: input contains bare nan literal ([nan] element), which our
		// JSON decoder does not accept.
		case 689: case 1306: case 2232: case 2271: case 2275: case 2407:
			return 'JSON can not portably represent NaN or infinite values';

		case 1900: case 1904: case 1908: case 1912: case 1917: case 1921:
		case 1925: case 1929: case 1969: case 1973: case 1977: case 1993:
			return 'Module-level directives not implemented';

			// 2014: large-float number representation in error messages differs
		case 2014:
			return 'Error message format differs from jq';

			// JS uses IEEE 754 doubles, so integer representation at the double-precision
			// boundary should match jq. These tests are about PHP-specific int64 behavior
			// and should pass in JS.
			// 2196, 2200, 2204, 2211, 2215, 2219, 2224, 2241

			// NaN handling: tojson, fromjson for nan/NaN literals
		case 2315: case 2319: case 2324:
			return 'NaN handling differs from jq (tojson, fromjson does not accept nan literals)';

			// debug/0 and input/0 not implemented
		case 2337: case 2341:
			return 'debug/0 and input/0 not implemented';

			// JSON nesting depth limits and path depth limits not implemented
		case 2558: case 2563: case 2568: case 2593: case 2602:
			return 'JSON nesting depth limits and path depth limits not implemented';

			// Below this point we list tests which *do* pass the tests, since
			// we're still in the process of implementation.
		case 8: case 12: case 16: case 20: case 35: case 48: case 54: case 58:
		case 114: case 253: case 257: case 803: case 816: case 2042:
		case 31: case 261:
			return null;

			// All the rest we'll assume are broken because of something we
			// haven't yet ported.
		default: return 'Not implemented yet';
	}
}

// Per-line-number normalization function for acceptable error message
// differences.
// Returns null if no normalization is needed for this line.
function normalizeErrorFn( lineno: number ): ( ( v: JQValue ) => JQValue ) | null {
	switch ( lineno ) {
		// Normalize various "Invalid path expression" error messages.
		case 1127: case 1131: case 1135: case 1290: case 1294:
			return ( v ) => {
				if ( typeof v === 'string' && v.startsWith( 'Invalid path expression' ) ) {
					return 'Invalid path expression';
				}
				return v;
			};

			// trim/ltrim/rtrim: our message vs jq's
		case 1575:
			return ( v ) => {
				if ( typeof v === 'string' && /^(l|r)?trim requires/.test( v ) ) {
					return 'trim input must be a string';
				}
				return v;
			};

			// "Cannot index TYPE with string" vs "field requires an object input, got TYPE"
		case 1448: {
			return ( v ) => {
				if ( typeof v === 'string' ) {
					const m = v.match( /^(?:Cannot index|field requires an object input, got) (\w+)/ );
					if ( m ) {
						return 'Cannot index ' + m[ 1 ];
					}
				}
				return v;
			};
		}

		// invalid JSON parse error: normalize any "Invalid ..." message to prefix
		case 2498:
			return ( v ) => {
				if ( typeof v === 'string' && v.startsWith( 'Invalid ' ) ) {
					return 'Invalid ';
				}
				return v;
			};

			// "TYPE (value) cannot be negated" vs "negation requires a number input, got TYPE"
		case 1481: case 1997: case 2005:
			return ( v ) => {
				if ( typeof v === 'string' ) {
					const m = v.match( /^(null|boolean|string|number|array|object) \(.*\) cannot be negated$/ );
					if ( m ) {
						return 'negation requires a number input, got ' + m[ 1 ];
					}
				}
				return v;
			};

			// "TYPE (value) cannot be searched…" vs "_strindices requires string inputs, got TYPE"
		case 1553: case 1557:
			return ( v ) => {
				if ( typeof v === 'string' ) {
					const m = v.match(
						/^(null|boolean|string|number|array|object) \(.*\) (?:cannot be searched|is not a string)/,
					);
					if ( m ) {
						return '_strindices requires string inputs, got ' + m[ 1 ];
					}
				}
				return v;
			};

			// "startswith()/endswith() requires string inputs" normalization
		case 2516: case 2523:
			return ( v ) => {
				if ( typeof v === 'string' ) {
					const m = v.match(
						// eslint-disable-next-line security/detect-unsafe-regex
						/^((?:start|end)swith)(?:\(\))? requires string inputs(?:, got \S+)?$/,
					);
					if ( m ) {
						return m[ 1 ] + ' requires string inputs';
					}
				}
				return v;
			};

			// invalid tm array: our checkNumber message vs jq's datetime message
		case 1868: case 1872: case 1876:
			return ( v ) => {
				if ( typeof v === 'string' ) {
					const m = v.match(
						/^(strftime\/1|strflocaltime\/1|mktime)\b.*\brequires.*input,\s*got/,
					);
					if ( m ) {
						return m[ 1 ] + ' requires parsed datetime inputs';
					}
				}
				return v;
			};

			// invalid format argument: our checkString message vs jq's
		case 1881: case 1885:
			return ( v ) => {
				if ( typeof v === 'string' ) {
					const m = v.match( /^(strftime\/1|strflocaltime\/1) requires string inputs/ );
					if ( m ) {
						return m[ 1 ] + ' requires a string format';
					}
				}
				return v;
			};

			// setAtPath error message vs jq's "Cannot index TYPE with ..."
		case 1258: case 2494:
			return ( v ) => {
				if ( typeof v === 'string' ) {
					const m = v.match( /^Cannot index (\w+) with (number|string)/ );
					if ( m ) {
						const req = m[ 2 ] === 'number' ? 'array' : 'object';
						return `setAtPath requires an ${req} input, got ${m[ 1 ]}`;
					}
				}
				return v;
			};

			// bsearch error message vs jq's "TYPE (VALUE) cannot be searched from"
		case 1839:
			return ( v ) => {
				if ( typeof v === 'string' ) {
					const m = v.match( /^(\w+) \(.*\) cannot be searched from$/ );
					if ( m ) {
						return 'bsearch requires an array input, got ' + m[ 1 ];
					}
				}
				return v;
			};

			// delpaths error message vs jq's "Paths must be specified as an array"
		case 1173:
			return ( v ) => {
				if ( typeof v === 'string' && v.startsWith( 'delpaths requires an array input' ) ) {
					return 'Paths must be specified as an array';
				}
				return v;
			};

			// setAtPath on string slice vs jq's "Cannot update string slices"
		case 2479:
			return ( v ) => {
				if ( typeof v === 'string' && v.startsWith( 'setAtPath requires an array input' ) ) {
					return 'Cannot update string slices';
				}
				return v;
			};

		default: return null;
	}
}

// Same for tests/local.test.
function localSkipReason( lineno: number ): string | null {
	switch ( lineno ) {
		default: return 'not implemented yet';
	}
}

function normalizeErrors( vals: JQValue[], lineno: number ): JQValue[] {
	const norm = normalizeErrorFn( lineno );
	return norm === null ? vals : vals.map( v => mapDeep( norm, v ) );
}

// Recursively apply fn to every leaf value in a nested array/object tree.
function mapDeep( fn: ( v: JQValue ) => JQValue, val: JQValue ): JQValue {
	val = fn( val );
	if ( Array.isArray( val ) ) {
		return val.map( item => mapDeep( fn, item ) );
	} else if ( val !== null && typeof val === 'object' ) {
		const result: Record<string, JQValue> = {};
		for ( const [ k, item ] of Object.entries( val ) ) {
			result[ k ] = mapDeep( fn, item );
		}
		return result;
	}
	return val;
}

// Compile and run a jq query, comparing results using JQUtils.compare.
// Throws JQError if compilation or evaluation fails (with no partial results).
// normalizeFn is applied to both expected and actual before comparison.
function runTest(
	query: string,
	input: string,
	expected: string[],
	normalizeFn: ( v: JQValue[] ) => JQValue[],
): void {
	const inputVal = JQUtils.jsonDecode( input );
	const expectedVal = expected.map( s => JQUtils.jsonDecode( s ) );
	const ast = JQGrammar.parse( query ) as ASTNode;
	const fn = JQCompile.compile( ast, JQEnv.getStdEnv() );
	const result: JQValue[] = [];
	try {
		for ( const v of fn( inputVal ) ) {
			result.push( v );
		}
	} catch ( e ) {
		// As with the upstream test runner (apparently): if we throw
		// only after at least one result, then don't count this as a
		// failure. (See test on line 2359)
		if ( !( e instanceof JQError && result.length > 0 ) ) {
			throw e;
		}
	}
	const enc = ( v: JQValue[] ): string => JQUtils.jsonEncode( v as JQValue );
	expect(
		JQUtils.compare( normalizeFn( expectedVal ) as JQValue, normalizeFn( result ) as JQValue ),
		`got: ${enc( result )}, but expected: ${enc( expectedVal )}`,
	).toBe( 0 );
}

function setupTests(
	tests: TestCase[],
	skipFn: ( n: number ) => string | null,
	normFn: ( vals: JQValue[], lineno: number ) => JQValue[],
	suiteName: string,
): void {
	describe( suiteName, () => {
		for ( const t of tests.filter( tc => !tc.fail ) ) {
			const skipReason = skipFn( t.lineno );

			it( t.label, ( ctx ) => {
				try {
					runTest( t.query, t.input ?? 'null', t.expected ?? [], v => normFn( v, t.lineno ) );
				} catch ( e ) {
					if ( skipReason ) {
						ctx.skip( skipReason );
						return;
					}
					throw e;
				}

				if ( skipReason ) {
					throw new Error( `Expected to be skipped (${skipReason}), but test passed` );
				}
			} );
		}
	} );
}

setupTests( jqTests, upstreamSkipReason, normalizeErrors, 'JQCompile jq.test' );
setupTests( localTests, localSkipReason, ( v ) => v, 'JQCompile local.test' );
