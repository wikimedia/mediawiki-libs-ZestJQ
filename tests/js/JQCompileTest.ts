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

			// Below this point we list tests not yet implemented.
		case 39:
		case 187:
		case 200:
		case 213:
		case 217:
		case 221:
		case 225:
		case 229:
		case 295:
		case 299:
		case 303:
		case 307:
		case 311:
		case 329:
		case 337:
		case 361:
		case 365:
		case 369:
		case 373:
		case 377:
		case 381:
		case 385:
		case 389:
		case 393:
		case 397:
		case 401:
		case 405:
		case 410:
		case 420:
		case 425:
		case 430:
		case 435:
		case 440:
		case 445:
		case 450:
		case 455:
		case 474:
		case 478:
		case 490:
		case 705:
		case 750:
		case 758:
		case 762:
		case 766:
		case 771:
		case 775:
		case 838:
		case 1045:
		case 1049:
		case 1053:
		case 1057:
		case 1062:
		case 1066:
		case 1070:
		case 1074:
		case 1078:
		case 1082:
		case 1086:
		case 1090:
		case 1094:
		case 1098:
		case 1102:
		case 1115:
		case 1123:
		case 1127:
		case 1131:
		case 1135:
		case 1143:
		case 1153:
		case 1159:
		case 1177:
		case 1184:
		case 1188:
		case 1192:
		case 1201:
		case 1205:
		case 1209:
		case 1214:
		case 1221:
		case 1225:
		case 1229:
		case 1233:
		case 1245:
		case 1249:
		case 1253:
		case 1258:
		case 1270:
		case 1274:
		case 1278:
		case 1282:
		case 1286:
		case 1290:
		case 1294:
		case 1298:
		case 1302:
		case 1374:
		case 1448:
		case 1464:
		case 1468:
		case 1499:
		case 1520:
		case 1524:
		case 1528:
		case 1532:
		case 1536:
		case 1540:
		case 1544:
		case 1548:
		case 1563:
		case 1581:
		case 1585:
		case 1589:
		case 1593:
		case 1597:
		case 1601:
		case 1605:
		case 1609:
		case 1653:
		case 1657:
		case 1677:
		case 1693:
		case 1697:
		case 1705:
		case 1709:
		case 1713:
		case 1717:
		case 1721:
		case 1725:
		case 1729:
		case 1745:
		case 1767:
		case 1771:
		case 1775:
		case 1779:
		case 1783:
		case 1787:
		case 1791:
		case 1795:
		case 1799:
		case 1803:
		case 1807:
		case 1811:
		case 1815:
		case 1819:
		case 1823:
		case 1895:
		case 2005:
		case 2018:
		case 2022:
		case 2029:
		case 2034:
		case 2038:
		case 2067:
		case 2071:
		case 2086:
		case 2089:
		case 2093:
		case 2097:
		case 2105:
		case 2116:
		case 2121:
		case 2125:
		case 2130:
		case 2135:
		case 2139:
		case 2143:
		case 2147:
		case 2152:
		case 2161:
		case 2165:
		case 2169:
		case 2190:
		case 2236:
		case 2250:
		case 2254:
		case 2258:
		case 2262:
		case 2267:
		case 2285:
		case 2372:
		case 2377:
		case 2382:
		case 2386:
		case 2390:
		case 2395:
		case 2416:
		case 2420:
		case 2425:
		case 2430:
		case 2435:
		case 2439:
		case 2443:
		case 2447:
		case 2451:
		case 2455:
		case 2459:
		case 2463:
		case 2467:
		case 2471:
		case 2475:
		case 2479:
		case 2483:
		case 2504:
		case 2509:
		case 2516:
		case 2523:
		case 2573:
		case 2577:
		case 2581:
		case 2585:
		case 2589:
		case 2598:
			return 'Not implemented yet';

		default: return null;
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
		case 27:
		case 32:
		case 37:
		case 42:
		case 47:
		case 52:
		case 57:
		case 62:
		case 71:
		case 76:
		case 81:
		case 86:
		case 91:
		case 96:
		case 101:
		case 106:
		case 111:
		case 125:
		case 130:
		case 135:
		case 140:
		case 145:
		case 150:
		case 155:
		case 160:
		case 165:
		case 170:
		case 179:
		case 184:
		case 189:
		case 194:
		case 205:
		case 210:
		case 215:
		case 220:
		case 225:
		case 230:
		case 235:
		case 240:
		case 249:
		case 254:
		case 260:
		case 271:
		case 276:
		case 282:
		case 316:
		case 322:
		case 327:
			return 'not implemented yet';

		default: return null;
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
