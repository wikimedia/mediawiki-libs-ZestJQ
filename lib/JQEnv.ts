import type { JQValue } from './internal.js';
import { JQ, JQUtils, JQError, IOContext, JQCompile, JQBuiltin, assertNever } from './internal.js';
// This is a circular dependency, but we don't need to resolve it until
// runtime.
import { JQTopLevelEnv } from './internal.js';

// Path-mode pair: [pathEnv, value] yielded by structural ops when in path mode.
// eslint-disable-next-line no-use-before-define
export type JQPathValue = [ JQPathEnv, JQValue ];

// Value type visible inside the evaluation engine (includes path-mode pairs).
export type JQValueOrPath = JQValue | JQPathValue;

// eslint-disable-next-line no-use-before-define
export function assertNotPath( value: JQValueOrPath, env: JQEnv ): JQValue {
	if ( env.isPathMode() ) {
		throw new JQError( 'Invalid path expression' );
	}
	return value as JQValue;
}

// Inner filter: input is always a plain JQValue (the pipe unwraps path pairs
// before each call), but outputs may be JQPathValue pairs when in path mode.
// eslint-disable-next-line no-use-before-define
export type FilterFn = ( input: JQValue, env: JQEnv ) => Generator<JQValueOrPath>;

// Filter factory for n-arity functions: takes arg filters, returns a FilterFn
export type FilterFactory = ( args: FilterFn[] ) => FilterFn;

export type Binding = FilterFn | FilterFactory;

/**
 * Immutable lexical environment for JQ evaluation.
 *
 * Maps "name/arity" keys to compiled filter functions or filter
 * factories. bind() returns a new instance rather than mutating, so a
 * base env built from builtin.jq can be shared safely across many
 * independent evaluations. The IOContext object is shared by
 * reference across all envs derived from a common root.
 *
 * Path mode: when isPathMode() returns true, structural ops (field, index,
 * iter, etc.) yield [JQPathEnv, JQValue] pairs instead of bare values.
 * JQPathEnv carries the path accumulated so far as a linked chain of tail
 * segments; getPath() walks up the chain and reconstructs the full array
 * once, at the point where path/1 reads it.
 */
export abstract class JQEnv {
	// eslint-disable-next-line no-use-before-define
	private static stdEnv: JQEnv | null = null;

	/**
	 * @param {JQEnv | null} parent Parent binding chain, or null for a root env
	 * @param {IOContext} io Shared I/O context (same object across all derived envs)
	 */
	public constructor(
		protected readonly parent: JQEnv | null,
		public readonly io: IOContext,
	) {}

	/**
	 * Return a new env with one additional function binding.
	 *
	 * When arity is zero, the Binding should be a FilterFn.  Otherwise
	 * the binding is a FilterFactory and all parameters are represented
	 * as FilterFn: (input: JQValue, env: JQEnv) => Generator<JQValue>.
	 *
	 * @param {string} name Function name (may include a :: namespace)
	 * @param {number} arity Number of filter arguments
	 * @param {Binding} fn Compiled filter or filter factory
	 * @return {JQEnv}
	 */
	public bind( name: string, arity: number, fn: Binding ): JQEnv {
		// eslint-disable-next-line no-use-before-define
		return new JQBindEnv( this, this.io, `${name}/${arity}`, fn );
	}

	/**
	 * Look up a compiled function by name and arity.
	 *
	 * Returns null if no definition is found; the caller is responsible for
	 * falling back to some other environment or raising a JQError.
	 *
	 * @param {string} name
	 * @param {number} arity
	 * @param {boolean} [cache] Whether to cache the result in the nearest JQBindEnv
	 * @return {Binding | null}
	 */
	public lookup( name: string, arity: number, cache: boolean = true ): Binding | null {
		return this.parent?.lookup( name, arity, cache ) ?? null;
	}

	/**
	 * Return the shared standard-library environment, building it on first call.
	 *
	 * The env is built by evaluating src/builtin.jq with __env__ appended so
	 * that all def statements register themselves and the resulting JQEnv is
	 * returned. The env is then cached for the lifetime of the process.
	 *
	 * @return {JQEnv}
	 */
	public static getStdEnv(): JQEnv {
		if ( JQEnv.stdEnv === null ) {
			// The standard environment is built with a null IOContext
			// eslint-disable-next-line no-use-before-define
			JQEnv.stdEnv = new JQLazyEnv( new IOContext() );
		}
		return JQEnv.stdEnv;
	}

	/**
	 * Extend this environment with the jq definitions in defs.
	 *
	 * Compiles `${defs}\n__env__` with this env as the base, iterates the
	 * results, and returns the first JQEnv value yielded (which carries all
	 * newly defined functions as bindings).
	 *
	 * @param {string} defs jq source containing one or more def statements
	 * @param {string} [filename] Optional source name for error messages
	 * @throws {JQError} on syntax error
	 * @return {JQEnv}
	 */
	public extendEnv( defs: string, filename?: string ): JQEnv {
		const effectiveFilename = filename ?? '<definitions>';
		const f = JQ.compile( `${defs}\n__env__`, effectiveFilename, this );
		for ( const val of f( null ) ) {
			if ( val instanceof JQEnv ) {
				return val;
			}
		}
		throw new JQError( 'JQEnv.extendEnv: __env__ was not yielded' );
	}

	/**
	 * Build the standard jq library environment by compiling builtin.jq on
	 * top of baseEnv. JQLazyEnv passes its JQTopLevelEnv parent so that
	 * native builtins are visible inside builtin.jq during compilation.
	 *
	 * @param {JQEnv} topLevelEnv The native-builtin env to compile on top of.
	 * @return {JQEnv}
	 */
	protected static buildStandardEnv( topLevelEnv: JQEnv ): JQEnv {
		const ast = JQBuiltin.getAst();
		const f = JQCompile.compile( ast, topLevelEnv );
		for ( const val of f( null ) ) {
			if ( val instanceof JQEnv ) {
				return val;
			}
		}
		return assertNever( 'JQEnv.buildStandardEnv: __env__ was not yielded' );
	}

	// -----------------------------------------------------------------------
	// Path mode
	// -----------------------------------------------------------------------

	/**
	 * Returns true when structural ops should yield [pathEnv, value] pairs.
	 *
	 * @return {boolean}
	 */
	public isPathMode(): boolean {
		return false;
	}

	/**
	 * Return a new env that is the root of a fresh path-collection context.
	 * The returned env is in path mode with an empty path.
	 *
	 * @return {JQEnv}
	 */
	public enterPathMode(): JQEnv {
		// eslint-disable-next-line no-use-before-define
		return new JQPathEnv( this, this.io, null, null, false );
	}

	/**
	 * If pathParent is in path mode, enter path mode with pathParent
	 * as the path parent so that getPath() chains through it. This is
	 * used for re-rooting when threading path context across def/pipe
	 * boundaries.
	 *
	 * @param {JQEnv} pathParent Existing path chain to re-root onto, if it is
	 *   a JQPathEnv
	 * @return {JQEnv}
	 */
	public maybeEnterPathMode( pathParent: JQEnv ): JQEnv {
		if ( pathParent.isPathMode() ) {
			// eslint-disable-next-line no-use-before-define
			return new JQPathEnv( this, this.io, pathParent as JQPathEnv, null, false );
		}
		return this;
	}

	/**
	 * Extend the current path by one key.
	 * In normal mode returns this unchanged (fast path, no allocation).
	 * In path mode returns a new env whose pathKey is `key` and whose parent
	 * is `this`; getPath() will prepend `this`'s path to `key`.
	 *
	 * @param {JQValue} _key
	 * @return {JQEnv}
	 */
	public appendPath( _key: JQValue ): JQEnv {
		// Not in path mode, throw away the key
		return this;
	}

	/**
	 * Return an env with path mode disabled, for evaluating conditions and
	 * key expressions that must not themselves produce path-mode outputs.
	 * In normal mode returns this unchanged (fast path, no allocation).
	 *
	 * @return {JQEnv}
	 */
	public leavePathMode(): JQEnv {
		return this;
	}

	/**
	 * Reconstruct the full path array for this env.
	 * Only callable on JQPathEnv; the base implementation always throws.
	 *
	 * @throws {Error} always in normal mode; subclass overrides return a value
	 * @return {JQValue[]}
	 */
	public getPath(): JQValue[] {
		return assertNever( 'not in path mode' );
	}

	/**
	 * Wrap value with path context when in path mode.
	 * Normal mode: returns value unchanged.
	 * Path mode:   returns [this, value].
	 *
	 * Used at the yield site in every structural compile* method.
	 *
	 * @param {JQValue} value
	 * @return {JQValueOrPath}
	 */
	public maybeWithPath( value: JQValue ): JQValueOrPath {
		return value;
	}

	/**
	 * Unwrap a potentially path-wrapped generator output.
	 * Always returns [nextEnv, value].
	 * Normal mode: [this, item]  (identity).
	 * Path mode:   [item[0], item[1]]  (unwraps the [pathEnv, value] pair).
	 *
	 * Used in compilePipe to thread the path env into the right-hand side.
	 *
	 * @param {JQValueOrPath} item
	 * @return {Array}
	 */
	public maybeUnwrapPath( item: JQValueOrPath ): [ JQEnv, JQValue ] {
		return [ this, item as JQValue ];
	}

	/**
	 * Extract the full path array from a path-mode generator output.
	 * item must be a [pathEnv, value] pair produced by maybeWithPath().
	 * Only meaningful when in path mode; used exclusively by path/1.
	 *
	 * @param {JQValueOrPath} item
	 * @return {JQValue[]}
	 */
	public extractPath( item: JQValueOrPath ): JQValue[] {
		return ( item as JQPathValue )[ 0 ].getPath();
	}

}

/**
 * A JQEnv node that holds exactly one compiled function binding.
 *
 * Every call to JQEnv.bind() produces a JQBindEnv wrapping the previous
 * env in the parent chain. lookup() checks the local key first, then
 * delegates upward and caches the result so repeated lookups through a
 * deep chain are O(1) after the first miss.
 */
class JQBindEnv extends JQEnv {
	private localCache: Map<string, Binding | null> | null = null;

	/**
	 * @param {JQEnv} parent Parent env for chained lookups
	 * @param {IOContext} io Shared I/O context
	 * @param {string} key "name/arity" key (e.g. "map/1", "length/0")
	 * @param {Binding} binding Compiled binding for key
	 */
	public constructor(
		parent: JQEnv,
		io: IOContext,
		private readonly key: string,
		private readonly binding: Binding,
	) {
		super( parent, io );
	}

	public override lookup( name: string, arity: number, cache: boolean = true ): Binding | null {
		const key = `${name}/${arity}`;
		if ( key === this.key ) {
			return this.binding;
		}
		const cached = this.localCache?.get( key );
		if ( cached !== undefined ) {
			return cached;
		}
		// Pass cache=false so only the outermost JQBindEnv caches each result.
		const result = super.lookup( name, arity, false );
		if ( cache ) {
			if ( this.localCache === null ) {
				this.localCache = new Map();
			}
			// cache negative results (failed lookups) as well as positive
			this.localCache.set( key, result );
		}
		return result;
	}
}

/**
 * Subclass of JQEnv used in path mode to trace the path corresponding to
 * values collected, in addition to the usual functions of an env.
 *
 * Two-parent design: parent (inherited from JQEnv) is always a plain JQEnv
 * used exclusively for binding lookups. pathParent is the previous JQPathEnv
 * in the path chain, used exclusively by getPath(). The two chains are
 * completely independent, so binding depth and path depth do not affect
 * each other's performance.
 */
export class JQPathEnv extends JQEnv {
	public constructor(
		parent: JQEnv | null,
		io: IOContext,
		/** Previous JQPathEnv in the path chain; null at the root. */
		private readonly pathParent: JQPathEnv | null,
		/** The single path segment stored at this level; null when pathValid is false. */
		private readonly pathKey: JQValue | null,
		/** Whether this node contributes a key to the path (false for binding nodes). */
		private readonly pathValid: boolean,
	) {
		super( parent, io );
	}

	/**
	 * Insert binding into the plain-env parent chain; return new JQPathEnv at same path position.
	 *
	 * @param {string} name
	 * @param {number} arity
	 * @param {Binding} fn
	 * @return {JQPathEnv}
	 */
	public override bind( name: string, arity: number, fn: Binding ): JQPathEnv {
		const newEnv = this.parent!.bind( name, arity, fn );
		return new JQPathEnv( newEnv, this.io, this.pathParent, this.pathKey, this.pathValid );
	}

	/** @return {boolean} */
	public override isPathMode(): boolean {
		return true;
	}

	public override enterPathMode(): never {
		assertNever( 'already in path mode' );
	}

	public override maybeEnterPathMode( _pathParent: JQEnv ): never {
		assertNever( 'already in path mode' );
	}

	/**
	 * Extend the path chain by one step; binding parent is unchanged.
	 *
	 * @param {JQValue} key
	 * @return {JQPathEnv}
	 */
	public override appendPath( key: JQValue ): JQPathEnv {
		return new JQPathEnv( this.parent, this.io, this, key, true );
	}

	/**
	 * Return the plain-env binding parent, leaving path mode.
	 *
	 * @return {JQEnv}
	 */
	public override leavePathMode(): JQEnv {
		if ( this.parent === null ) {
			assertNever( 'JQPathEnv has no binding parent' );
		}
		return this.parent;
	}

	/**
	 * Traverse pathParent chain collecting keys; reverse once at the end.
	 *
	 * @return {JQValue[]}
	 */
	public override getPath(): JQValue[] {
		const r: JQValue[] = [];
		// eslint-disable-next-line @typescript-eslint/no-this-alias
		for ( let p: JQPathEnv | null = this; p !== null; p = p.pathParent ) {
			if ( p.pathValid ) {
				r.push( p.pathKey as JQValue );
			}
		}
		return r.reverse();
	}

	/**
	 * Wrap value as a [this, value] pair for downstream path tracking.
	 *
	 * @param {JQValue} value
	 * @return {JQValueOrPath}
	 */
	public override maybeWithPath( value: JQValue ): JQValueOrPath {
		return [ this, value ];
	}

	/**
	 * Unwrap a [JQPathEnv, value] pair; throws if item is not a valid path output.
	 *
	 * @param {JQValueOrPath} item
	 * @return {Array}
	 */
	public override maybeUnwrapPath( item: JQValueOrPath ): [ JQEnv, JQValue ] {
		if ( !Array.isArray( item ) || !( item[ 0 ] instanceof JQPathEnv ) ) {
			throw new JQError( `Invalid path expression with result ${JQUtils.jsonEncode( item as JQValue )}` );
		}
		return item as JQPathValue;
	}
}

/**
 * A JQEnv whose standard-library parent is resolved lazily on first lookup.
 *
 * The standard environment (JQEnv::getStdEnv()) is loaded only when
 * lookup() is first called, so the overhead of deserialising and compiling
 * builtin.jq is not paid unless a built-in function is actually invoked
 * during evaluation.
 *
 * bind() is inherited unchanged: it creates a JQBindEnv whose parent
 * chain eventually reaches this object, so unresolved lookups naturally
 * proxy through here to the standard library.
 */
export class JQLazyEnv extends JQEnv {

	private resolved: JQEnv | null = null;

	public constructor( io: IOContext ) {
		super( new JQTopLevelEnv( io ), io );
	}

	public override lookup( name: string, arity: number, cache: boolean = true ): Binding | null {
		if ( this.resolved === null ) {
			// Check native builtins (parent = JQTopLevelEnv) before
			// paying the cost of compiling builtin.jq; avoids a full
			// stdenv load when only native builtins are needed.
			const binding = super.lookup( name, arity, cache );
			if ( binding !== null ) {
				return binding;
			}
			// Ok, I guess we have to load the stdenv now.
			this.resolved = JQLazyEnv.buildStandardEnv( this.parent! );
		}
		return this.resolved.lookup( name, arity, cache );
	}
}
