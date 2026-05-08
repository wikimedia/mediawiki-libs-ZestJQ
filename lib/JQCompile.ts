import type { ASTNode } from './JQGrammar.js';
import type { JQFilter, JQValue } from './JQValue.js';
import type { FilterFn } from './JQUtils.js';
import { assertNotPath } from './JQUtils.js';
import { JQError } from './JQError.js';
import { JQEnv } from './JQEnv.js';

export class JQCompile {
	public static compile( ast: ASTNode, env: JQEnv ): JQFilter {
		const compiler = new JQCompile();
		const fn = compiler.compileNode( ast );
		return ( input: JQValue ) => fn( input, env );
	}

	private compileNode( node: ASTNode ): FilterFn {
		switch ( node.type ) {
			case 'literal': return this.compileLiteral( node );
			case 'pipe': return this.compilePipe( node );
			case 'identity': return this.compileIdentity();
			default:
				throw new JQError( `unimplemented: ${node.type}` );
		}
	}

	private compileLiteral( node: ASTNode ): FilterFn {
		const value = node.value as JQValue;
		return function* ( input: JQValue, env: JQEnv ): Generator<JQValue> {
			assertNotPath( value, env );
			yield value;
		};
	}

	private compilePipe( node: ASTNode ): FilterFn {
		const leftFn = this.compileNode( node.left as ASTNode );
		const rightFn = this.compileNode( node.right as ASTNode );
		return function* ( input: JQValue, env: JQEnv ): Generator<JQValue> {
			for ( const mid of leftFn( input, env ) ) {
				yield* rightFn( mid, env );
			}
		};
	}

	private compileIdentity(): FilterFn {
		return function* ( input: JQValue, _env: JQEnv ): Generator<JQValue> {
			yield input;
		};
	}
}
