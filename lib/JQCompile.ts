import type { ASTNode, JQFilter, JQValue, FilterFn, JQValueOrPath } from './internal.js';
import { JQEnv, assertNever, assertNotPath } from './internal.js';

export class JQCompile {
	public static compile( ast: ASTNode, env: JQEnv ): JQFilter {
		const compiler = new JQCompile();
		const fn = compiler.compileNode( ast );
		return function* ( input: JQValue ): Generator<JQValue> {
			for ( const v of fn( input, env ) ) {
				yield v as JQValue;
			}
		};
	}

	private compileNode( node: ASTNode ): FilterFn {
		switch ( node.type ) {
			case 'literal': return this.compileLiteral( node );
			case 'pipe': return this.compilePipe( node );
			case 'identity': return this.compileIdentity();
			default:
				assertNever( `unimplemented: ${node.type}` );
		}
	}

	private compileLiteral( node: ASTNode ): FilterFn {
		const value = node.value as JQValue;
		return function* ( input: JQValue, env: JQEnv ): Generator<JQValueOrPath> {
			assertNotPath( value, env );
			yield value;
		};
	}

	private compilePipe( node: ASTNode ): FilterFn {
		const leftFn = this.compileNode( node.left as ASTNode );
		const rightFn = this.compileNode( node.right as ASTNode );
		return function* ( input: JQValue, env: JQEnv ): Generator<JQValueOrPath> {
			for ( const item of leftFn( input, env ) ) {
				const [ nextEnv, mid ] = env.maybeUnwrapPath( item );
				yield* rightFn( mid, env.leavePathMode().maybeEnterPathMode( nextEnv ) );
			}
		};
	}

	private compileIdentity(): FilterFn {
		return function* ( input: JQValue, env: JQEnv ): Generator<JQValueOrPath> {
			yield env.maybeWithPath( input );
		};
	}
}
