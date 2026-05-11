import type { ASTNode, JQFilter, JQValue, FilterFn, JQValueOrPath } from './internal.js';
import { JQEnv, JQError, JQBreak, assertNever, assertNotPath } from './internal.js';

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

	/**
	 * Compile one AST node into a FilterFn.
	 *
	 * The JQEnv is threaded at call time rather than captured at compile time.
	 * This means runtime bindings — as-patterns, def scopes — can extend the
	 * env without requiring the body subtree to be recompiled on each iteration.
	 *
	 * Lexical scoping for def: when binding a new function, the stored closure
	 * calls the body with the definition-time env (captured via a forward
	 * reference so that recursive calls work), ignoring the call-time env for
	 * the body itself. See the 'def' case for details.
	 *
	 * @param {ASTNode} node AST node (must have a 'type' key)
	 * @return {FilterFn}
	 */
	private compileNode( node: ASTNode ): FilterFn {
		switch ( node.type ) {
			case 'identity': return this.compileIdentity();
			case 'literal': return this.compileLiteral( node );
			case 'pipe': return this.compilePipe( node );
			case 'label': return this.compileLabel( node );
			case 'break': return this.compileBreak( node );
			case 'variable': return this.compileVariable( node );
			default:
				assertNever( `unimplemented: ${node.type}` );
		}
	}

	/**
	 * Compile an identity node (.).
	 * Yields the input value unchanged.
	 *
	 * @return {FilterFn}
	 */
	private compileIdentity(): FilterFn {
		return function* ( input: JQValue, env: JQEnv ): Generator<JQValueOrPath> {
			yield env.maybeWithPath( input );
		};
	}

	/**
	 * Compile a literal node (null, true, false, number, plain string).
	 * Yields the literal value, ignoring the input.
	 *
	 * @param {ASTNode} node Node with 'value' key
	 * @return {FilterFn}
	 */
	private compileLiteral( node: ASTNode ): FilterFn {
		const value = node.value as JQValue;
		return function* ( _input: JQValue, env: JQEnv ): Generator<JQValueOrPath> {
			assertNotPath( value, env );
			yield value;
		};
	}

	/**
	 * Compile a pipe node (left | right).
	 * Feeds each output of the left filter as input to the right filter,
	 * yielding all outputs produced across all intermediate values.
	 *
	 * @param {ASTNode} node Node with 'left' and 'right' keys
	 * @return {FilterFn}
	 */
	private compilePipe( node: ASTNode ): FilterFn {
		const leftFn = this.compileNode( node.left as ASTNode );
		const rightFn = this.compileNode( node.right as ASTNode );
		return function* ( input: JQValue, env: JQEnv ): Generator<JQValueOrPath> {
			for ( const item of leftFn( input, env ) ) {
				const [ nextEnv, mid ] = env.maybeUnwrapPath( item );
				// Re-root: keep env's binding chain but carry nextEnv's accumulated
				// path so the right side extends from the correct position. O(1).
				yield* rightFn( mid, env.leavePathMode().maybeEnterPathMode( nextEnv ) );
			}
		};
	}

	/**
	 * Compile a label node (label $out | body).
	 * Evaluates the body, catching any JQBreak whose label matches $out
	 * and silently terminating the stream. A break for a different label
	 * is re-thrown so it can be caught by the appropriate outer label.
	 *
	 * @param {ASTNode} node Node with 'name' and 'body' keys
	 * @return {FilterFn}
	 */
	private compileLabel( node: ASTNode ): FilterFn {
		const name = node.name as string;
		const bodyFn = this.compileNode( node.body as ASTNode );
		return function* ( input: JQValue, env: JQEnv ): Generator<JQValueOrPath> {
			try {
				yield* bodyFn( input, env );
			} catch ( e ) {
				if ( e instanceof JQBreak && e.label === name ) {
					// Matching break: stop the stream, yield nothing further.
				} else {
					throw e;
				}
			}
		};
	}

	/**
	 * Compile a break node (break $label).
	 * Throws JQBreak when the generator is iterated, terminating the
	 * nearest enclosing label node with a matching name.
	 *
	 * @param {ASTNode} node Node with 'name' key
	 * @return {FilterFn}
	 */
	private compileBreak( node: ASTNode ): FilterFn {
		const name = node.name as string;
		// eslint-disable-next-line require-yield
		return function* ( _input: JQValue, _env: JQEnv ): Generator<JQValueOrPath> {
			throw new JQBreak( name );
		};
	}

	/**
	 * Compile a variable node ($name).
	 * Looks the name up as a 0-arity filter in the runtime env and delegates
	 * to it. Variables are bound into the env by compilePattern's var_pattern
	 * case; the stored filter ignores its input and yields the captured value.
	 *
	 * @param {ASTNode} node Node with 'name' key
	 * @return {FilterFn}
	 */
	private compileVariable( node: ASTNode ): FilterFn {
		const key = '$' + ( node.name as string );
		return function* ( input: JQValue, env: JQEnv ): Generator<JQValueOrPath> {
			const fn = env.lookup( key, 0 ) as FilterFn | null;
			if ( fn === null ) {
				throw new JQError( `${key} is not defined` );
			}
			for ( const val of fn( input, env ) ) {
				assertNotPath( val as JQValue, env );
				yield val;
			}
		};
	}

}
