// eslint-disable-next-line @typescript-eslint/no-unused-vars
import { timeFormat, timeParse, utcFormat, utcParse } from 'd3-time-format';

import type { Binding, JQValue, JQValueOrPath, FilterFn } from './internal.js';
import { JQEnv, IOContext, JQUtils, JQCompile, JQError, JQHaltException } from './internal.js';

// -----------------------------------------------------------------------
// Module-level constants
// -----------------------------------------------------------------------

// The character set from jq's jvp_codepoint_is_whitespace().
// JS's \s also includes \x00 (NUL) and some others; use explicit class instead.
const WS_CLASS =
	'[\\u0009-\\u000D\\u0020\\u0085\\u00A0\\u1680' +
	'\\u2000-\\u200A\\u2028\\u2029\\u202F\\u205F\\u3000]';

const TRIM_BOTH = new RegExp( `^${WS_CLASS}+|${WS_CLASS}+$`, 'gu' );

const TRIM_LEFT = new RegExp( `^${WS_CLASS}+`, 'gu' );

const TRIM_RIGHT = new RegExp( `${WS_CLASS}+$`, 'gu' );

// -----------------------------------------------------------------------
// Class
// -----------------------------------------------------------------------

/**
 * Root environment pre-populated with native JavaScript builtin functions.
 *
 * JQTopLevelEnv is the base of every evaluation's environment chain.
 * builtin.jq is compiled on top of it, so that jq-level library
 * functions can call native builtins without special-casing them inside
 * JQCompile.
 *
 * Arity-0 builtins are stored as FilterFn: (input, env) => Generator<JQValueOrPath>.
 * Arity-N builtins (N≥1) are stored as FilterFactory:
 *   (argFns: FilterFn[]) => FilterFn
 * matching the convention used by JQCompile.compileCall().
 */
export class JQTopLevelEnv extends JQEnv {

	private readonly builtins: Map<string, Binding>;

	public constructor( io: IOContext ) {
		super( null, io );
		this.builtins = JQTopLevelEnv.buildNativeBuiltins();
	}

	public override lookup( name: string, arity: number ): Binding | null {
		return this.builtins.get( `${name}/${arity}` ) ?? null;
	}

	private static buildNativeBuiltins(): Map<string, Binding> {
		const defs = new Map<string, Binding>();

		const {
			jqContains,
			dateToJqArray, jqArrayToDate,
			dateToJqArrayLocal, jqArrayToDateLocal,
			checkTmArray,
		} = JQTopLevelEnv;

		// __env__/0 is a private builtin that yields the current JQEnv so
		// callers can capture it.
		// Used by bootstrapping code (JQEnv::buildStandardEnv) to extract
		// the startup env after a sequence of def statements has been
		// evaluated.
		defs.set( '__env__/0', function* ( _input: JQValue, env: JQEnv ): Generator<JQValueOrPath> {
			// XXX should this be a JQValueOrPath? But this is still a JQEnv
			// not a JQPathEnv, so it wouldn't actually fix the type coercion.
			yield env as unknown as JQValue;
		} );

		// length/0 — null→0, array/object→count, string→codepoint length, number→abs
		defs.set( 'length/0', function* ( input: JQValue ): Generator<JQValueOrPath> {
			if ( input === null ) {
				yield 0;
			} else if ( Array.isArray( input ) ) {
				yield input.length;
			} else if ( typeof input === 'object' ) {
				yield Object.keys( input ).length;
			} else if ( typeof input === 'string' ) {
				yield [ ...input ].length;
			} else if ( JQUtils.isNumber( input ) ) {
				yield Math.abs( input );
			} else {
				throw new JQError( JQUtils.typeName( input ) + ' has no length' );
			}
		} );

		// type/0
		defs.set( 'type/0', function* ( input: JQValue ): Generator<JQValueOrPath> {
			yield JQUtils.typeName( input );
		} );

		// not/0 — JQ truthiness: null and false are falsy, everything else truthy
		defs.set( 'not/0', function* ( input: JQValue ): Generator<JQValueOrPath> {
			yield !JQUtils.toBoolean( input );
		} );

		// empty/0 — produces no output
		defs.set( 'empty/0', function* (): Generator<JQValueOrPath> {
			yield* [];
		} );

		// have_decnum/0, have_literal_numbers/0 — capability flags (always false: IEEE 754)
		defs.set( 'have_decnum/0', function* (): Generator<JQValueOrPath> {
			yield false;
		} );
		defs.set( 'have_literal_numbers/0', function* (): Generator<JQValueOrPath> {
			yield false;
		} );

		// error/0 — throw input as a JQError; jqValue carries the original value
		// eslint-disable-next-line require-yield
		defs.set( 'error/0', function* ( input: JQValue ): Generator<JQValueOrPath> {
			throw new JQError( JQUtils.toString( input ), input );
		} );

		// keys_unsorted/0 — object keys (insertion order) or array indices
		defs.set( 'keys_unsorted/0', function* ( input: JQValue ): Generator<JQValueOrPath> {
			if ( Array.isArray( input ) ) {
				yield Array.from( { length: input.length }, ( _, i ) => i );
			} else if ( JQUtils.isObject( input ) ) {
				yield Object.keys( input );
			} else {
				throw new JQError( JQUtils.typeName( input ) + ' has no keys' );
			}
		} );

		// keys/0 — like keys_unsorted but lexicographically sorted
		defs.set( 'keys/0', function* ( input: JQValue ): Generator<JQValueOrPath> {
			if ( Array.isArray( input ) ) {
				yield Array.from( { length: input.length }, ( _, i ) => i );
			} else if ( JQUtils.isObject( input ) ) {
				yield Object.keys( input ).sort();
			} else {
				throw new JQError( JQUtils.typeName( input ) + ' has no keys' );
			}
		} );

		// has/1 — test whether an index/key is present
		defs.set( 'has/1', ( argFns: FilterFn[] ) => {
			const keyFn = argFns[ 0 ];
			return function* ( input: JQValue, env: JQEnv ): Generator<JQValueOrPath> {
				for ( const key of keyFn( input, env ) ) {
					if ( Array.isArray( input ) ) {
						const idx = JQUtils.adjustIndex( 'has', key, input );
						yield idx !== null;
					} else if ( JQUtils.isObject( input ) ) {
						const k = JQUtils.checkString( 'has', key );
						yield Object.prototype.hasOwnProperty.call( input, k );
					} else {
						throw new JQError( JQUtils.typeName( input ) + ' is not indexable' );
					}
				}
			};
		} );

		// range/2 — range($from; $to), yields numbers from $from up to $to-1
		defs.set( 'range/2', ( argFns: FilterFn[] ) => {
			const [ fromFn, toFn ] = argFns;
			return function* ( input: JQValue, env: JQEnv ): Generator<JQValueOrPath> {
				for ( const from of fromFn( input, env ) ) {
					for ( const to of toFn( input, env ) ) {
						const f = JQUtils.checkNumber( 'range', from );
						const t = JQUtils.checkNumber( 'range', to );
						for ( let i = f; i < t; i++ ) {
							yield i;
						}
					}
				}
			};
		} );

		// tostring/0 — strings pass through; everything else is JSON-encoded
		defs.set( 'tostring/0', function* ( input: JQValue ): Generator<JQValueOrPath> {
			yield JQUtils.toString( input );
		} );

		// tojson/0 — always JSON-encode (including strings)
		defs.set( 'tojson/0', function* ( input: JQValue ): Generator<JQValueOrPath> {
			yield JQUtils.jsonEncode( input );
		} );

		// fromjson/0 — parse a JSON string
		defs.set( 'fromjson/0', function* ( input: JQValue ): Generator<JQValueOrPath> {
			yield JQUtils.jsonDecode( JQUtils.checkString( 'fromjson', input ) );
		} );

		// tonumber/0 — numbers pass through; strings are parsed
		defs.set( 'tonumber/0', function* ( input: JQValue ): Generator<JQValueOrPath> {
			yield JQUtils.toNumber( input );
		} );

		// toboolean/0 — booleans pass through; "true"/"false" strings are converted
		defs.set( 'toboolean/0', function* ( input: JQValue ): Generator<JQValueOrPath> {
			if ( typeof input === 'boolean' ) {
				yield input;
			} else if ( input === 'true' ) {
				yield true;
			} else if ( input === 'false' ) {
				yield false;
			} else {
				const repr = JQUtils.typeNameAndValue( input );
				throw new JQError( `${repr} cannot be parsed as a boolean` );
			}
		} );

		// utf8bytelength/0 — byte length of a UTF-8 string
		defs.set( 'utf8bytelength/0', function* ( input: JQValue ): Generator<JQValueOrPath> {
			if ( typeof input !== 'string' ) {
				const repr = JQUtils.typeNameAndValue( input );
				throw new JQError( `${repr} only strings have UTF-8 byte length` );
			}
			yield Buffer.byteLength( input, 'utf8' );
		} );

		// explode/0 — string → array of Unicode codepoints
		defs.set( 'explode/0', function* ( input: JQValue ): Generator<JQValueOrPath> {
			const str = JQUtils.checkString( 'explode', input );
			yield [ ...str ].map( ( c ) => c.codePointAt( 0 ) as number );
		} );

		// implode/0 — array of Unicode codepoints → string
		defs.set( 'implode/0', function* ( input: JQValue ): Generator<JQValueOrPath> {
			const arr = JQUtils.checkArray( 'implode', input );
			const chars = arr.map( ( code ) => {
				let n = Math.trunc( JQUtils.checkNumber( 'implode', code, false ) );
				if ( n < 0 || n > 0x10FFFF || ( n >= 0xD800 && n <= 0xDFFF ) ) {
					n = 0xFFFD; // U+FFFD REPLACEMENT CHARACTER
				}
				return String.fromCodePoint( n );
			} );
			yield chars.join( '' );
		} );

		// startswith/1, endswith/1
		defs.set( 'startswith/1', ( argFns: FilterFn[] ) => {
			const prefixFn = argFns[ 0 ];
			return function* ( input: JQValue, env: JQEnv ): Generator<JQValueOrPath> {
				for ( const prefix of prefixFn( input, env ) ) {
					const [ a, b ] = JQUtils.checkStrings( 'startswith', input, prefix );
					yield a.startsWith( b );
				}
			};
		} );
		defs.set( 'endswith/1', ( argFns: FilterFn[] ) => {
			const suffixFn = argFns[ 0 ];
			return function* ( input: JQValue, env: JQEnv ): Generator<JQValueOrPath> {
				for ( const suffix of suffixFn( input, env ) ) {
					const [ a, b ] = JQUtils.checkStrings( 'endswith', input, suffix );
					yield a.endsWith( b );
				}
			};
		} );

		// split/1 — split string by a literal separator
		defs.set( 'split/1', ( argFns: FilterFn[] ) => {
			const sepFn = argFns[ 0 ];
			return function* ( input: JQValue, env: JQEnv ): Generator<JQValueOrPath> {
				for ( const sep of sepFn( input, env ) ) {
					const [ str, delim ] = JQUtils.checkStrings( 'split', input, sep );
					yield delim === '' ? [ ...str ] : str.split( delim );
				}
			};
		} );

		// contains/1 — recursive containment check
		defs.set( 'contains/1', ( argFns: FilterFn[] ) => {
			const valFn = argFns[ 0 ];
			return function* ( input: JQValue, env: JQEnv ): Generator<JQValueOrPath> {
				for ( const val of valFn( input, env ) ) {
					yield jqContains( input, val as JQValue );
				}
			};
		} );

		// Math builtins — unary
		const mathFn = function (
			name: string, fn?: ( x: number ) => number,
		): FilterFn {
			const f = fn ?? ( Math[ name as keyof Math ] as ( x: number ) => number );
			return function* ( input: JQValue, _env: JQEnv ): Generator<JQValueOrPath> {
				yield f( JQUtils.checkNumber( name, input ) );
			};
		};

		defs.set( 'floor/0', mathFn( 'floor' ) );
		defs.set( 'ceil/0', mathFn( 'ceil' ) );
		defs.set( 'round/0', mathFn( 'round' ) );
		defs.set( 'acos/0', mathFn( 'acos' ) );
		defs.set( 'acosh/0', mathFn( 'acosh' ) );
		defs.set( 'asin/0', mathFn( 'asin' ) );
		defs.set( 'asinh/0', mathFn( 'asinh' ) );
		defs.set( 'atan/0', mathFn( 'atan' ) );
		defs.set( 'atanh/0', mathFn( 'atanh' ) );
		defs.set( 'cos/0', mathFn( 'cos' ) );
		defs.set( 'cosh/0', mathFn( 'cosh' ) );
		defs.set( 'exp/0', mathFn( 'exp' ) );
		defs.set( 'expm1/0', mathFn( 'expm1' ) );
		defs.set( 'fabs/0', mathFn( 'fabs', Math.abs ) );
		defs.set( 'log/0', mathFn( 'log' ) );
		defs.set( 'log10/0', mathFn( 'log10' ) );
		defs.set( 'log1p/0', mathFn( 'log1p' ) );
		defs.set( 'sin/0', mathFn( 'sin' ) );
		defs.set( 'sinh/0', mathFn( 'sinh' ) );
		defs.set( 'sqrt/0', mathFn( 'sqrt' ) );
		defs.set( 'tan/0', mathFn( 'tan' ) );
		defs.set( 'tanh/0', mathFn( 'tanh' ) );
		defs.set( 'cbrt/0', mathFn( 'cbrt' ) );
		defs.set( 'exp2/0', mathFn( 'exp2', ( x ) => 2 ** x ) );
		defs.set( 'exp10/0', mathFn( 'exp10', ( x ) => 10 ** x ) );
		defs.set( 'log2/0', mathFn( 'log2' ) );
		// nearbyint/rint: round half to even (banker's rounding)
		const roundHalfEven = ( x: number ): number => {
			const f = Math.floor( x );
			const diff = x - f;
			if ( diff < 0.5 ) {
				return f;
			}
			if ( diff > 0.5 ) {
				return f + 1;
			}
			return f % 2 === 0 ? f : f + 1;
		};
		defs.set( 'nearbyint/0', mathFn( 'nearbyint', roundHalfEven ) );
		defs.set( 'rint/0', mathFn( 'rint', roundHalfEven ) );
		defs.set( 'trunc/0', mathFn( 'trunc', Math.trunc ) );
		// Omitted — no JS equivalent: erf, erfc, tgamma/gamma, lgamma,
		// j0, j1 (Bessel functions of the first kind), y0, y1 (second kind),
		// logb (IEEE exponent extraction), significand (IEEE significand).

		// Binary math functions (ignore input; take two args; right-outer, left-inner)
		const mathFn2 = (
			name: string, fn?: ( x: number, y: number ) => number,
		) => ( argFns: FilterFn[] ) => {
			const [ leftFn, rightFn ] = argFns;
			const f = fn ?? ( Math[ name as keyof Math ] as ( x: number, y: number ) => number );
			return function* ( input: JQValue, env: JQEnv ): Generator<JQValueOrPath> {
				// for binops jq generally evaluates right first (outer loop)
				// then left (inner loop).
				for ( const rv of rightFn( input, env ) ) {
					const r = JQUtils.checkNumber( name, rv );
					for ( const lv of leftFn( input, env ) ) {
						yield f( JQUtils.checkNumber( name, lv ), r );
					}
				}
			};
		};

		defs.set( 'atan2/2', mathFn2( 'atan2' ) );
		defs.set( 'fmod/2', mathFn2( 'fmod', ( x, y ) => x % y ) );
		defs.set( 'hypot/2', mathFn2( 'hypot' ) );
		defs.set( 'pow/2', mathFn2( 'pow' ) );
		defs.set( 'copysign/2', mathFn2(
			'copysign',
			( x, y ) => ( y < 0 || Object.is( y, -0 ) ) ? -Math.abs( x ) : Math.abs( x ),
		) );
		defs.set( 'fdim/2', mathFn2( 'fdim', ( x, y ) => Math.max( x - y, 0 ) ) );
		defs.set( 'fmax/2', mathFn2( 'fmax', Math.max ) );
		defs.set( 'fmin/2', mathFn2( 'fmin', Math.min ) );

		// Special float values and predicates
		defs.set( 'nan/0', function* (): Generator<JQValueOrPath> {
			yield NaN;
		} );
		defs.set( 'infinite/0', function* (): Generator<JQValueOrPath> {
			yield Infinity;
		} );
		defs.set( 'isinfinite/0', function* ( input: JQValue ): Generator<JQValueOrPath> {
			yield typeof input === 'number' && !isFinite( input ) && !isNaN( input );
		} );
		defs.set( 'isnan/0', function* ( input: JQValue ): Generator<JQValueOrPath> {
			yield typeof input === 'number' && isNaN( input );
		} );
		defs.set( 'isnormal/0', function* ( input: JQValue ): Generator<JQValueOrPath> {
			// "Normal": finite and nonzero (subnormals are not checked, matching PHP)
			yield typeof input === 'number' ?
				( isFinite( input ) && input !== 0 ) :
				Number.isInteger( input );
		} );

		// last/1 — yield the last output of expr; yield nothing if expr is empty
		defs.set( 'last/1', ( argFns: FilterFn[] ) => {
			const exprFn = argFns[ 0 ];
			return function* ( input: JQValue, env: JQEnv ): Generator<JQValueOrPath> {
				let last: JQValue = null;
				let found = false;
				for ( const val of exprFn( input, env ) ) {
					last = val as JQValue;
					found = true;
				}
				if ( found ) {
					yield last;
				}
			};
		} );

		// halt/0, halt_error/1
		// eslint-disable-next-line require-yield
		defs.set( 'halt/0', function* (): Generator<JQValueOrPath> {
			throw new JQHaltException( 0 );
		} );
		defs.set( 'halt_error/1', ( argFns: FilterFn[] ) => {
			const codeFn = argFns[ 0 ];
			// eslint-disable-next-line require-yield
			return function* ( input: JQValue, env: JQEnv ): Generator<JQValueOrPath> {
				// eslint-disable-next-line no-unreachable-loop
				for ( const code of codeFn( input, env ) ) {
					const c = Math.trunc( JQUtils.checkNumber( 'halt_error', code ) );
					throw new JQHaltException( c, input !== null ? JQUtils.toString( input ) : '' );
				}
				throw new JQHaltException( 0, input !== null ? JQUtils.toString( input ) : '' );
			};
		} );

		// path/1 — yield the path(s) that expr traverses
		defs.set( 'path/1', ( argFns: FilterFn[] ) => {
			const exprFn = argFns[ 0 ];
			return function* ( input: JQValue, env: JQEnv ): Generator<JQValueOrPath> {
				const pathEnv = env.enterPathMode();
				for ( const item of exprFn( input, pathEnv ) ) {
					const [ itemEnv ] = pathEnv.maybeUnwrapPath( item );
					yield itemEnv.getPath() as JQValue;
				}
			};
		} );

		// getpath/1 — navigate input by the path array produced by $pathFn
		defs.set( 'getpath/1', ( argFns: FilterFn[] ) => {
			const pathFn = argFns[ 0 ];
			return function* ( input: JQValue, env: JQEnv ): Generator<JQValueOrPath> {
				for ( const pathVal of pathFn( input, env.leavePathMode() ) ) {
					const path = JQUtils.checkArray( 'getpath', pathVal );
					if ( path.length > JQUtils.MAX_PATH ) {
						throw new JQError( 'Path too deep' );
					}
					const result = JQCompile.getAtPath( input, path, 0 );
					if ( env.isPathMode() ) {
						// Don't bother to do this iteration to transfer the
						// array into a pathEnv chain unless we're actually
						// in a nested path
						let pathEnv = env;
						for ( const key of path ) {
							pathEnv = pathEnv.appendPath( key );
						}
						yield pathEnv.maybeWithPath( result );
					} else {
						yield result;
					}
				}
			};
		} );

		// setpath/2 — return input with newVal written at the path array
		defs.set( 'setpath/2', ( argFns: FilterFn[] ) => {
			const [ pathFn, valFn ] = argFns;
			return function* ( input: JQValue, env: JQEnv ): Generator<JQValueOrPath> {
				const plain = env.leavePathMode();
				for ( const pathVal of pathFn( input, plain ) ) {
					const path = JQUtils.checkArray( 'setpath', pathVal );
					if ( path.length > JQUtils.MAX_PATH ) {
						throw new JQError( 'Path too deep' );
					}
					for ( const newVal of valFn( input, plain ) ) {
						yield JQCompile.setAtPath( input, path, 0, newVal as JQValue );
					}
				}
			};
		} );

		// delpaths/1 — delete all listed paths from the input
		defs.set( 'delpaths/1', ( argFns: FilterFn[] ) => {
			const pathsFn = argFns[ 0 ];
			return function* ( input: JQValue, env: JQEnv ): Generator<JQValueOrPath> {
				for ( const paths of pathsFn( input, env ) ) {
					yield JQCompile.deleteAtPaths(
						input, JQUtils.checkArray( 'delpaths', paths ),
					);
				}
			};
		} );

		// trim/0, ltrim/0, rtrim/0 — strip Unicode whitespace
		defs.set( 'trim/0', function* ( input: JQValue ): Generator<JQValueOrPath> {
			yield JQUtils.checkString( 'trim', input ).replace( TRIM_BOTH, '' );
		} );
		defs.set( 'ltrim/0', function* ( input: JQValue ): Generator<JQValueOrPath> {
			yield JQUtils.checkString( 'ltrim', input ).replace( TRIM_LEFT, '' );
		} );
		defs.set( 'rtrim/0', function* ( input: JQValue ): Generator<JQValueOrPath> {
			yield JQUtils.checkString( 'rtrim', input ).replace( TRIM_RIGHT, '' );
		} );

		// sort/0 — sort array by jq type ordering
		defs.set( 'sort/0', function* ( input: JQValue ): Generator<JQValueOrPath> {
			const arr = [ ...JQUtils.checkArray( 'sort', input ) ];
			arr.sort( JQUtils.compare );
			yield arr;
		} );

		// unique/0 — sort then remove consecutive duplicates
		defs.set( 'unique/0', function* ( input: JQValue ): Generator<JQValueOrPath> {
			const arr = [ ...JQUtils.checkArray( 'unique', input ) ];
			arr.sort( JQUtils.compare );
			const result: JQValue[] = [];
			let last: JQValue = false;
			for ( const v of arr ) {
				if ( result.length === 0 || JQUtils.compare( last, v ) !== 0 ) {
					result.push( v );
					last = v;
				}
			}
			yield result;
		} );

		// min/0, max/0 — null for empty array, otherwise the extreme value
		type CmpFunc = ( elem: JQValue, best: JQValue ) => boolean;
		const minmax = ( name: string, cmp: CmpFunc ) =>
			function* ( input: JQValue ): Generator<JQValueOrPath> {
				const arr = JQUtils.checkArray( name, input );
				let best: JQValue = arr[ 0 ] ?? null;
				for ( let i = 1; i < arr.length; i++ ) {
					if ( cmp( arr[ i ], best ) ) {
						best = arr[ i ];
					}
				}
				yield best;
			};
		const mincmp: CmpFunc = ( el, best ) => JQUtils.compare( el, best ) < 0;
		// On ties, max/max_by keeps last element
		const maxcmp: CmpFunc = ( el, best ) => JQUtils.compare( el, best ) >= 0;
		defs.set( 'min/0', minmax( 'min', mincmp ) );
		defs.set( 'max/0', minmax( 'max', maxcmp ) );

		// _min_by_impl/1 and _max_by_impl/1 — cores of min_by/1 and max_by/1.
		// Empty array yields null; otherwise a single linear scan finds the extremum.
		const minmaxBy = ( name: string, cmp: CmpFunc ) => ( argFns: FilterFn[] ) =>
			function* ( input: JQValue, env: JQEnv ): Generator<JQValueOrPath> {
				const arr = JQUtils.checkArray( name, input );
				const keyFn = argFns[ 0 ];
				for ( const keysUnchecked of keyFn( input, env ) ) {
					const keys = JQUtils.checkArray( name, keysUnchecked );
					let bestVal: JQValue = arr[ 0 ] ?? null;
					let bestKey: JQValue = keys[ 0 ] ?? null;
					for ( let i = 1; i < arr.length; i++ ) {
						if ( cmp( keys[ i ], bestKey ) ) {
							bestVal = arr[ i ];
							bestKey = keys[ i ];
						}
					}
					yield bestVal;
				}
			};

		defs.set( '_min_by_impl/1', minmaxBy( '_min_by_impl', mincmp ) );
		defs.set( '_max_by_impl/1', minmaxBy( '_max_by_impl', maxcmp ) );

		// _sort_by_impl/1 — core of sort_by/1.
		// Called as _sort_by_impl(map([f])): receives input array and a pre-mapped
		// array of key-arrays (one per element); returns input sorted by those keys.
		defs.set( '_sort_by_impl/1', ( argFns: FilterFn[] ) =>
			function* ( input: JQValue, env: JQEnv ): Generator<JQValueOrPath> {
				const arr = JQUtils.checkArray( '_sort_by_impl', input );
				const keysFn = argFns[ 0 ];
				for ( const keysUnchecked of keysFn( input, env ) ) {
					const keys = JQUtils.checkArray( '_sort_by_impl', keysUnchecked );
					// pair each value with its key, sort by key, extract values
					const pairs = arr.map( ( v, i ) => [ v, keys[ i ] ] ) as [JQValue, JQValue][];
					pairs.sort( ( a, b ) => JQUtils.compare( a[ 1 ], b[ 1 ] ) );
					yield pairs.map( ( p ) => p[ 0 ] ) as JQValue;
				}
			},
		);

		// _unique_by_impl/1 — core of unique_by/1.
		// Sort by keys then keep only the first element of each run of equal keys.
		defs.set( '_unique_by_impl/1', ( argFns: FilterFn[] ) =>
			function* ( input: JQValue, env: JQEnv ): Generator<JQValueOrPath> {
				const arr = JQUtils.checkArray( '_unique_by_impl', input );
				const keysFn = argFns[ 0 ];
				for ( const keysUnchecked of keysFn( input, env ) ) {
					const keys = JQUtils.checkArray( '_unique_by_impl', keysUnchecked );
					const pairs = arr.map( ( v, i ) => [ v, keys[ i ] ] ) as [JQValue, JQValue][];
					pairs.sort( ( a, b ) => JQUtils.compare( a[ 1 ], b[ 1 ] ) );
					const result: JQValue[] = [];
					let prevKey: JQValue = null;
					for ( const [ val, key ] of pairs ) {
						if ( result.length === 0 ||
							JQUtils.compare( key, prevKey ) !== 0 ) {
							result.push( val );
							prevKey = key;
						}
					}
					yield result;
				}
			},
		);

		// _group_by_impl/1 — core of group_by/1.
		// Same calling convention as _sort_by_impl; returns array of groups.
		defs.set( '_group_by_impl/1', ( argFns: FilterFn[] ) =>
			function* ( input: JQValue, env: JQEnv ): Generator<JQValueOrPath> {
				const arr = JQUtils.checkArray( '_group_by_impl', input );
				const keysFn = argFns[ 0 ];
				for ( const keysUnchecked of keysFn( input, env ) ) {
					const keys = JQUtils.checkArray( '_group_by_impl', keysUnchecked );
					const pairs = arr.map( ( v, i ) => [ v, keys[ i ] ] ) as [JQValue, JQValue][];
					pairs.sort( ( a, b ) => JQUtils.compare( a[ 1 ], b[ 1 ] ) );
					const groups: JQValue[] = [];
					let curGroup: JQValue[] = [ pairs[ 0 ][ 0 ] ];
					let curKey: JQValue = pairs[ 0 ][ 1 ];
					for ( let i = 1; i < pairs.length; i++ ) {
						if ( JQUtils.compare( pairs[ i ][ 1 ], curKey ) === 0 ) {
							curGroup.push( pairs[ i ][ 0 ] );
						} else {
							groups.push( curGroup );
							curGroup = [ pairs[ i ][ 0 ] ];
							curKey = pairs[ i ][ 1 ];
						}
					}
					groups.push( curGroup );
					yield groups;
				}
			},
		);

		// _strindices/1 — Unicode codepoint positions where needle occurs in input
		defs.set( '_strindices/1', ( argFns: FilterFn[] ) => {
			const needleFn = argFns[ 0 ];
			return function* ( input: JQValue, env: JQEnv ): Generator<JQValueOrPath> {
				const str = JQUtils.checkString( '_strindices', input );
				for ( const needleUnchecked of needleFn( input, env ) ) {
					const needle = JQUtils.checkString( '_strindices', needleUnchecked );
					const indices: JQValue[] = [];
					if ( needle !== '' ) {
						// O(N*M) codepoint-aware search for overlapping matches
						const strCps = [ ...str ];
						const ndlCps = [ ...needle ];
						const nLen = ndlCps.length;
						for ( let i = 0; i <= strCps.length - nLen; i++ ) {
							let ok = true;
							for ( let j = 0; j < nLen; j++ ) {
								if ( strCps[ i + j ] !== ndlCps[ j ] ) {
									ok = false;
									break;
								}
							}
							if ( ok ) {
								indices.push( i );
							}
						}
					}
					yield indices;
				}
			};
		} );

		// bsearch/1 — binary search on a sorted array.
		// Returns the index if found; -(insertion_point)-1 if not found.
		defs.set( 'bsearch/1', ( argFns: FilterFn[] ) => {
			const needleFn = argFns[ 0 ];
			return function* ( input: JQValue, env: JQEnv ): Generator<JQValueOrPath> {
				const arr = JQUtils.checkArray( 'bsearch', input );
				for ( const needle of needleFn( input, env ) ) {
					let lo = 0;
					let hi = arr.length - 1;
					let found = -1;
					while ( lo <= hi ) {
						// eslint-disable-next-line no-bitwise
						const mid = ( lo + hi ) >>> 1;
						const cmp = JQUtils.compare( arr[ mid ], needle as JQValue );
						if ( cmp === 0 ) {
							found = mid;
							break;
						} else if ( cmp < 0 ) {
							lo = mid + 1;
						} else {
							hi = mid - 1;
						}
					}
					yield found >= 0 ? found : -lo - 1;
				}
			};
		} );

		// -----------------------------------------------------------------------
		// Date/time builtins
		// -----------------------------------------------------------------------

		defs.set( 'now/0', function* (): Generator<JQValueOrPath> {
			// jq uses "seconds"; javascript uses "milliseconds"
			yield Date.now() / 1000;
		} );

		defs.set( 'gmtime/0', function* ( input: JQValue ): Generator<JQValueOrPath> {
			const ts = JQUtils.checkNumber( 'gmtime', input );
			yield dateToJqArray( new Date( ts * 1000 ) );
		} );

		defs.set( 'localtime/0', function* ( input: JQValue ): Generator<JQValueOrPath> {
			const ts = JQUtils.checkNumber( 'localtime', input );
			yield dateToJqArrayLocal( new Date( ts * 1000 ) );
		} );

		defs.set( 'mktime/0', function* ( input: JQValue ): Generator<JQValueOrPath> {
			yield jqArrayToDate( checkTmArray( 'mktime', input ) ).getTime() / 1000;
		} );

		defs.set( 'strftime/1', ( argFns: FilterFn[] ) => {
			const fmtFn = argFns[ 0 ];
			return function* ( input: JQValue, env: JQEnv ): Generator<JQValueOrPath> {
				for ( const fmtVal of fmtFn( input, env ) ) {
					const fmt = JQUtils.checkString( 'strftime/1', fmtVal );
					let d: Date;
					if ( JQUtils.isNumber( input ) ) {
						d = new Date( ( input as number ) * 1000 );
					} else {
						d = jqArrayToDate( checkTmArray( 'strftime/1', input ) );
					}
					const formatter = utcFormat( fmt );
					yield formatter( d );
				}
			};
		} );

		defs.set( 'strflocaltime/1', ( argFns: FilterFn[] ) => {
			const fmtFn = argFns[ 0 ];
			return function* ( input: JQValue, env: JQEnv ): Generator<JQValueOrPath> {
				for ( const fmtVal of fmtFn( input, env ) ) {
					const fmt = JQUtils.checkString( 'strflocaltime/1', fmtVal );
					let d: Date;
					if ( JQUtils.isNumber( input ) ) {
						d = new Date( ( input as number ) * 1000 );
					} else {
						d = jqArrayToDateLocal( checkTmArray( 'strflocaltime/1', input ) );
					}
					const formatter = timeFormat( fmt );
					yield formatter( d );
				}
			};
		} );

		defs.set( 'strptime/1', ( argFns: FilterFn[] ) => {
			const fmtFn = argFns[ 0 ];
			return function* ( input: JQValue, env: JQEnv ): Generator<JQValueOrPath> {
				for ( const fmtVal of fmtFn( input, env ) ) {
					const [ str, fmt ] = JQUtils.checkStrings( 'strptime/1', input, fmtVal );
					const parser = timeParse( fmt );
					const d: Date|null = parser( str );
					if ( d === null ) {
						throw new JQError( 'Bad date' );
					}
					yield dateToJqArray( d );
				}
			};
		} );

		// builtins/0 — list public builtin names (no _ prefix, excludes builtins/0 itself)
		const names = [ ...defs.keys() ].filter( ( k ) => !k.startsWith( '_' ) ).sort();
		defs.set( 'builtins/0', function* (): Generator<JQValueOrPath> {
			yield names;
		} );

		return defs;
	}

	/**
	 * Check that a jq broken-down time array has all-numeric (non-NaN) elements.
	 * Short arrays are allowed; the missing tail elements default to
	 * 1970-01-01T00:00:00.00.
	 *
	 * @param {string} who
	 * @param {JQValue} v
	 * @return {number[]}
	 */
	private static checkTmArray( who: string, v: JQValue ): number[] {
		const defaults = [ 1970, 0, 1, 0, 0, 0, 0, 0 ];
		const arr = JQUtils.checkArray( who, v );
		const result: number[] = [];
		for ( let i = 0; i < 8; i++ ) {
			result.push( JQUtils.checkNumber(
				`${who} element ${i}`,
				arr[ i ] ?? defaults[ i ],
				false,
			) );
		}
		return result;
	}

	/**
	 * Convert a jq broken-down UTC time array to a Date.
	 * Array: [year, month(0-based), mday(1-based), hour, min, sec+frac, wday, yday].
	 *
	 * @param {number[]} arr
	 * @return {Date}
	 */
	private static jqArrayToDate( arr: number[] ): Date {
		const [ year, month, day, hour, min, sec ] = arr;
		const ms = Math.round( sec * 1000 ) % 1000;
		const d = new Date( 0 );
		d.setUTCFullYear( year, month, day );
		d.setUTCHours( hour, min, Math.floor( sec ), ms );
		return d;
	}

	/**
	 * Convert a jq broken-down local time array to a Date (local timezone).
	 *
	 * @param {number[]} arr
	 * @return {Date}
	 */
	private static jqArrayToDateLocal( arr: number[] ): Date {
		const [ year, month, day, hour, min, sec ] = arr;
		const ms = Math.round( sec * 1000 ) % 1000;
		const d = new Date( 0 );
		d.setFullYear( year, month, day );
		d.setHours( hour, min, Math.floor( sec ), ms );
		return d;
	}

	/**
	 * Convert a UTC Date + original float timestamp to a jq broken-down time array.
	 *
	 * @param {Date} d
	 * @return {JQValue[]}
	 */
	private static dateToJqArray( d: Date ): JQValue[] {
		return [
			d.getUTCFullYear(),
			d.getUTCMonth(), // 0-based
			d.getUTCDate(), // 1-based
			d.getUTCHours(),
			d.getUTCMinutes(),
			d.getUTCSeconds() + ( d.getUTCMilliseconds() / 1000 ),
			d.getUTCDay(), // 0=Sunday
			parseInt( utcFormat( '%j' )( d ), 10 ) - 1, // 0-based
		];
	}

	/**
	 * Convert a local Date + original float timestamp to a jq broken-down time array.
	 *
	 * @param {Date} d
	 * @return {JQValue[]}
	 */
	private static dateToJqArrayLocal( d: Date ): JQValue[] {
		return [
			d.getFullYear(),
			d.getMonth(), // 0-based
			d.getDate(), // 1-based
			d.getHours(),
			d.getMinutes(),
			d.getSeconds() + ( d.getMilliseconds() / 1000 ),
			d.getDay(), // 0=Sunday
			parseInt( timeFormat( '%j' )( d ), 10 ) - 1, // 0-based
		];
	}
	// -----------------------------------------------------------------------
	// Other helpers
	// -----------------------------------------------------------------------

	/**
	 * Recursive jq containment check used by contains/1.
	 *
	 * @param {JQValue} a
	 * @param {JQValue} b
	 * @return {boolean}
	 */
	private static jqContains( a: JQValue, b: JQValue ): boolean {
		if ( typeof a === 'string' && typeof b === 'string' ) {
			return a.includes( b );
		}
		if ( Array.isArray( a ) && Array.isArray( b ) ) {
			for ( const bItem of b ) {
				if ( !a.some( ( aItem ) => JQTopLevelEnv.jqContains( aItem, bItem ) ) ) {
					return false;
				}
			}
			return true;
		}
		if ( a !== null && typeof a === 'object' && !Array.isArray( a ) &&
			b !== null && typeof b === 'object' && !Array.isArray( b ) ) {
			const ao = a as Record<string, JQValue>;
			const bo = b as Record<string, JQValue>;
			for ( const [ k, bVal ] of Object.entries( bo ) ) {
				if (
					!Object.prototype.hasOwnProperty.call( ao, k ) ||
					!JQTopLevelEnv.jqContains( ao[ k ], bVal )
				) {
					return false;
				}
			}
			return true;
		}
		if ( JQUtils.isNumber( a ) ) {
			// eslint-disable-next-line eqeqeq
			return JQUtils.isNumber( b ) && a == b;
		}
		return a === b;
	}

}
