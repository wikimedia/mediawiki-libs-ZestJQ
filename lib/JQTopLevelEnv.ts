import type { Binding, JQValue, JQValueOrPath } from './internal.js';
import { JQEnv, IOContext, JQUtils } from './internal.js';

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

		// __env__/0 is a private builtin that yields the current JQEnv so
		// callers can capture it.
		// Used by bootstrapping code (JQEnv.buildStandardEnv) to extract
		// the startup env after a sequence of def statements has been
		// evaluated.
		defs.set( '__env__/0', function* ( _input: JQValue, env: JQEnv ): Generator<JQValueOrPath> {
			yield env as unknown as JQValue;
		} );

		// not/0 — JQ truthiness: null and false are falsy, everything else truthy
		defs.set( 'not/0', function* ( input: JQValue ): Generator<JQValueOrPath> {
			yield !JQUtils.toBoolean( input );
		} );

		// builtins/0 — list public native builtin names (no _ prefix; populated
		// after the rest are defined so builtins/0 itself is excluded)
		const names = [ ...defs.keys() ].filter( k => !k.startsWith( '_' ) ).sort();
		defs.set( 'builtins/0', function* (): Generator<JQValueOrPath> {
			yield names;
		} );

		return defs;
	}
}
