import type { ASTNode, JQFilter, JQValue, FilterFn, FilterFactory, JQValueOrPath } from './internal.js';
import { JQUtils, JQEnv, JQError, JQBreak, assertNever, assertNotPath } from './internal.js';

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
			case 'def': return this.compileDef( node );
			case 'call': return this.compileCall( node );
			case 'array': return this.compileArray( node );
			case 'object': return this.compileObject( node );
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

	/**
	 * Compile a def node (def name(params): body; rest).
	 *
	 * Value parameters ($x) are desugared at compile time:
	 *   def f($x): body  =>  def f(x): x as $x | body
	 * so that only filter parameters remain at runtime.
	 *
	 * Lexical scoping is achieved via a forward reference (defEnvRef):
	 * the binding closure captures defEnvRef by reference, which is filled
	 * in just before rest is evaluated. This also enables recursion, because
	 * any recursive call in body or rest will find the function already bound.
	 *
	 * For 0-arity defs, the stored value is a plain FilterFn.
	 * For n-arity defs, the stored value is a FilterFactory:
	 *   (argFns: FilterFn[]) => FilterFn
	 * The factory injects the arg closures as 0-arity filter-param bindings
	 * into the lexical env, then returns the body filter. Filter args are
	 * always evaluated in the call-site env, not the def-body env.
	 *
	 * @param {ASTNode} node Node with 'name', 'params', 'body', and 'rest' keys
	 * @return {FilterFn}
	 */
	private compileDef( node: ASTNode ): FilterFn {
		const name = node.name as string;
		const params = node.params as { kind: string; name: string }[];
		const arity = params.length;

		// Desugar value params: wrap body (in reverse param order so the first
		// param binds outermost): def f($x;$y): body → def f(x;y): x as $x | y as $y | body
		let bodyAst = node.body as ASTNode;
		for ( const param of [ ...params ].reverse() ) {
			if ( param.kind === 'value' ) {
				bodyAst = {
					type: 'bind',
					expr: { type: 'call', name: param.name, args: [] },
					pattern: { type: 'var_pattern', name: param.name },
					body: bodyAst,
				};
			}
		}

		const bodyFn = this.compileNode( bodyAst );
		const restFn = this.compileNode( node.rest as ASTNode );

		if ( arity === 0 ) {
			return function* ( input: JQValue, env: JQEnv ): Generator<JQValueOrPath> {
				let defEnvRef = env; // mutable placeholder; overwritten below before first use
				const binding: FilterFn = function* ( callInput, callEnv ) {
					// Propagate path mode from the call site into the body so that
					// structural operations inside the def yield path-wrapped values
					// when invoked inside path/1.
					const effectiveEnv = defEnvRef.leavePathMode().maybeEnterPathMode( callEnv );
					yield* bodyFn( callInput, effectiveEnv );
				};
				const newEnv = env.bind( name, 0, binding );
				defEnvRef = newEnv;
				yield* restFn( input, newEnv );
			};
		}

		// n-arity: store a FilterFactory in the env
		const filterNames = params.map( ( p ) => p.name );
		return function* ( input: JQValue, env: JQEnv ): Generator<JQValueOrPath> {
			let defEnvRef = env; // mutable placeholder; overwritten below before first use
			const factory: FilterFactory = ( argFns ) => function* ( callInput, callEnv ) {
				const bodyEnv = filterNames.reduce(
					( benv, pName, i ) => {
						const argFn = argFns[ i ];
						return benv.bind( pName, 0, function* ( argIn, argEnv ) {
							// Inject each filter param so it evaluates in the call-site env.
							yield* argFn( argIn, callEnv.leavePathMode()
								// Re-root: call-site bindings (callEnv) with the path accumulated
								// so far in the body (argEnv).
								.maybeEnterPathMode( argEnv ) );
						} );
					},
					// Start from the lexical (normal-mode) env where the def was created.
					defEnvRef.leavePathMode(),
				);
				// Propagate path mode from the call site into the body so that
				// structural operations inside the def (identity, field, iter…)
				// yield path-wrapped values when invoked inside path/1.
				yield* bodyFn( callInput, bodyEnv.maybeEnterPathMode( callEnv ) );
			};
			const newEnv = env.bind( name, arity, factory );
			defEnvRef = newEnv;
			yield* restFn( input, newEnv );
		};
	}

	/**
	 * Compile a call node (name or name(arg; ...)).
	 *
	 * Arg filters are compiled once here at compile time and captured in the
	 * returned closure. At runtime:
	 *  - 0-arity: the stored value is a plain FilterFn; call it directly.
	 *  - n-arity: the stored value is a FilterFactory; pass the compiled arg
	 *    closures to get a FilterFn, then run the FilterFn with the call-site env.
	 *
	 * @param {ASTNode} node Node with 'name' and 'args' keys
	 * @return {FilterFn}
	 */
	private compileCall( node: ASTNode ): FilterFn {
		const name = node.name as string;
		const args = node.args as ASTNode[];
		const arity = args.length;
		const argFns = args.map( ( arg ) => this.compileNode( arg ) );

		if ( arity === 0 ) {
			return function* ( input: JQValue, env: JQEnv ): Generator<JQValueOrPath> {
				const fn = env.lookup( name, 0 ) as FilterFn | null;
				if ( fn === null ) {
					throw new JQError( `${name}/0 is not defined` );
				}
				yield* fn( input, env );
			};
		}

		return function* ( input: JQValue, env: JQEnv ): Generator<JQValueOrPath> {
			const factory = env.lookup( name, arity ) as FilterFactory | null;
			if ( factory === null ) {
				throw new JQError( `${name}/${arity} is not defined` );
			}
			yield* factory( argFns )( input, env );
		};
	}

	/**
	 * Compile an array constructor node ([expr]).
	 * Collects every output of the inner expression into a single JS array.
	 * [empty_expr] produces an empty array.
	 *
	 * @param {ASTNode} node Node with nullable 'expr' key
	 * @return {FilterFn}
	 */
	private compileArray( node: ASTNode ): FilterFn {
		if ( node.expr === null ) {
			return function* ( _input: JQValue, env: JQEnv ): Generator<JQValueOrPath> {
				yield assertNotPath( [], env );
			};
		}
		const exprFn = this.compileNode( node.expr as ASTNode );
		return function* ( input: JQValue, env: JQEnv ): Generator<JQValueOrPath> {
			// Array construction always produces a new value, never a path extension.
			const items: JQValue[] = [];
			for ( const val of exprFn( input, env.leavePathMode() ) ) {
				items.push( val as JQValue );
			}
			yield assertNotPath( items, env );
		};
	}

	/**
	 * Compile an object constructor node ({k1: v1, k2: v2, ...}).
	 *
	 * Each key and value is an arbitrary filter. Multiple outputs from a key
	 * or value expression multiply the number of output objects (Cartesian
	 * product over pairs, evaluated left-to-right). An empty pair list yields
	 * a single empty object.
	 *
	 * @param {ASTNode} node Node with 'pairs' key (array of {key, value} nodes)
	 * @return {FilterFn}
	 */
	private compileObject( node: ASTNode ): FilterFn {
		const pairs = node.pairs as { key: ASTNode; value: ASTNode }[];
		const pairFns: [ FilterFn, FilterFn ][] = pairs.map( ( pair ) => [
			this.compileNode( pair.key ), this.compileNode( pair.value ),
		] );
		return function* ( input: JQValue, env: JQEnv ): Generator<JQValueOrPath> {
			const plainEnv = env.leavePathMode();
			let objects: Record<string, JQValue>[] = [ {} ];
			for ( const [ keyFn, valFn ] of pairFns ) {
				const next: Record<string, JQValue>[] = [];
				for ( const obj of objects ) {
					for ( const key of keyFn( input, plainEnv ) ) {
						for ( const val of valFn( input, plainEnv ) ) {
							if ( typeof key !== 'string' && typeof key !== 'number' ) {
								throw new JQError(
									`Cannot use ${JQUtils.typeName( key as JQValue )} as object key`,
								);
							}
							next.push( { ...obj, [ String( key ) ]: val as JQValue } );
						}
					}
				}
				objects = next;
			}
			for ( const obj of objects ) {
				yield assertNotPath( obj, env );
			}
		};
	}

}
