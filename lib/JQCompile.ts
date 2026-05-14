import type { ASTNode, JQFilter, JQValue, FilterFn, FilterFactory, JQValueOrPath } from './internal.js';
import { JQUtils, JQEnv, JQError, JQBreak, assertNever } from './internal.js';

const {
	assertNotPath,
	checkArray, checkNumber, checkObject, checkString, isNumber, adjustIndex,
	normalizeSliceIdx, slice, MAX_SIZE, toBoolean, typeName, typeNameAndValue,
	equal, compare, add, subtract, multiply, divide, modulo,
	formatterFor,
} = JQUtils;

// Matcher: given a value and an env, yields extended envs on successful match.
// Type mismatches should throw JQError so that alt_pattern can catch and retry.
type Matcher = ( val: JQValue, env: JQEnv ) => Generator<JQEnv>;

// UpdateFn: used in compileAssign and compileAssignUpdate
// updateFn receives (outerInput, currentPathValue, env) and yields the
// replacement value
type UpdateFn = ( input: JQValue, current: JQValue, env: JQEnv ) => Generator<JQValueOrPath>;

// Tombstone sentinel for deleteAtPaths — a unique object that cannot be
// produced by JSON.parse, so it cannot alias any user-supplied JQ value.
const TOMB: JQValue = Object.freeze( Object.create( null ) as Record<string, JQValue> );

export class JQCompile {
	public static compile( ast: ASTNode, env: JQEnv ): JQFilter {
		const compiler = new JQCompile();
		const fn = compiler.compileNode( ast );
		return function* ( input: JQValue ): Generator<JQValue> {
			for ( const v of fn( input, env ) ) {
				yield assertNotPath( v, env );
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
			case 'if': return this.compileIf( node );
			case 'comma': return this.compileComma( node );
			case 'array': return this.compileArray( node );
			case 'object': return this.compileObject( node );
			case 'bind': return this.compileBind( node );
			case 'compare': return this.compileCompare( node );
			case 'and': return this.compileAnd( node );
			case 'or': return this.compileOr( node );
			case 'neg': return this.compileNeg( node );
			case 'iter': return this.compileIter( node );
			case 'alternative': return this.compileAlternative( node );
			case 'try': return this.compileTryCatch( node );
			case 'reduce': return this.compileReduce( node );
			case 'foreach': return this.compileForeach( node );
			case 'slice': return this.compileSlice( node );
			case 'assign': return this.compileAssign( node );
			case 'field': return this.compileField( node );
			case 'index': return this.compileIndex( node );
			case 'string': return this.compileString( node );
			case 'format': return this.compileFormat( node );
			case 'binop': return this.compileBinop( node );
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
			yield assertNotPath( value, env );
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
				yield assertNotPath( val, env );
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
	 * Compile an if node (if cond then body else alt end).
	 *
	 * The condition is evaluated against the input; for each of its outputs,
	 * the then-branch is evaluated if the output is JQ-truthy (anything except
	 * null and false), otherwise the else-branch is evaluated. Both branches
	 * receive the original input, not the condition's output.
	 *
	 * elif chains are represented in the AST as a nested if in the else slot.
	 * An if without an explicit else has {type:'literal',value:null} as its
	 * else node (the grammar's canonical representation).
	 *
	 * @param {ASTNode} node Node with 'cond', 'then', and 'else' keys
	 * @return {FilterFn}
	 */
	private compileIf( node: ASTNode ): FilterFn {
		const condFn = this.compileNode( node.cond as ASTNode );
		const thenFn = this.compileNode( node.then as ASTNode );
		const elseFn = this.compileNode( node.else as ASTNode );
		return function* ( input: JQValue, env: JQEnv ): Generator<JQValueOrPath> {
			for ( const condVal of condFn( input, env.leavePathMode() ) ) {
				if ( JQUtils.toBoolean( condVal as JQValue ) ) {
					yield* thenFn( input, env );
				} else {
					yield* elseFn( input, env );
				}
			}
		};
	}

	/**
	 * Compile a comma node (left, right).
	 * Yields all outputs of the left filter followed by all outputs of the
	 * right filter, preserving path-mode wrapping in both halves.
	 *
	 * @param {ASTNode} node Node with 'left' and 'right' keys
	 * @return {FilterFn}
	 */
	private compileComma( node: ASTNode ): FilterFn {
		const leftFn = this.compileNode( node.left as ASTNode );
		const rightFn = this.compileNode( node.right as ASTNode );
		return function* ( input: JQValue, env: JQEnv ): Generator<JQValueOrPath> {
			yield* leftFn( input, env );
			yield* rightFn( input, env );
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
									`Cannot use ${typeName( key as JQValue )} as object key`,
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

	/**
	 * Compile a bind node: `expr as $pat | body`.
	 *
	 * For each output of expr, match it against the pattern and evaluate
	 * body in the extended environment. Two important points:
	 *
	 * - Body receives the original $input, not the bound value. This is
	 *   what distinguishes "as" from a pipe: . stays the same in body.
	 * - $innerEnv flows only into body, never outward. Bindings introduced
	 *   here are invisible outside the body, giving correct lexical scoping.
	 *
	 * @param {ASTNode} node Node with 'expr', 'pattern', and 'body' keys
	 * @return {FilterFn}
	 */
	private compileBind( node: ASTNode ): FilterFn {
		const srcFn = this.compileNode( node.expr as ASTNode );
		const bodyFn = this.compileNode( node.body as ASTNode );
		const patFn = this.compilePattern( node.pattern as ASTNode );
		return function* ( input: JQValue, env: JQEnv ): Generator<JQValueOrPath> {
			for ( const val of srcFn( input, env.leavePathMode() ) ) {
				for ( const innerEnv of patFn( val as JQValue, env ) ) {
					yield* bodyFn( input, innerEnv );
				}
			}
		};
	}

	// Recursive helper for obj_pattern matching.
	//
	// For each value yielded by the field's key function, validates it is a
	// string, looks up that field in $val, runs the field's pattern matcher,
	// then recurses into the remaining fields.  Produces one output environment
	// per combination of key values, matching jq's multi-output semantics.
	private static *matchObjFields(
		val: Record<string, JQValue>,
		env: JQEnv,
		fields: [ FilterFn, Matcher ][],
		idx: number,
	): Generator<JQEnv> {
		if ( idx >= fields.length ) {
			yield env;
			return;
		}
		const [ keyFn, fieldFn ] = fields[ idx ];
		for ( const k of keyFn( val as JQValue, env.leavePathMode() ) ) {
			const fieldName = checkString( 'object index', k as JQValue );
			const fieldVal: JQValue = val[ fieldName ] ?? null;
			for ( const nextEnv of fieldFn( fieldVal, env ) ) {
				yield* JQCompile.matchObjFields( val, nextEnv, fields, idx + 1 );
			}
		}
	}

	// Recursively collect all variable names (with leading $) bound by a pattern.
	private static collectPatternVars( pat: ASTNode ): string[] {
		switch ( pat.type as string ) {
			case 'var_pattern':
				return [ '$' + ( pat.name as string ) ];
			case 'array_pattern':
				return ( pat.elems as ASTNode[] )
					.flatMap( ( p ) => JQCompile.collectPatternVars( p ) );
			case 'obj_pattern':
				return ( pat.fields as { pattern: ASTNode }[] )
					.map( ( f ) => f.pattern )
					.flatMap( ( p ) => JQCompile.collectPatternVars( p ) );
			case 'and_pattern':
				return ( pat.patterns as ASTNode[] )
					.flatMap( ( p ) => JQCompile.collectPatternVars( p ) );
			case 'alt_pattern':
				return [ ...new Set(
					( pat.patterns as ASTNode[] )
						.flatMap( ( p ) => JQCompile.collectPatternVars( p ) ),
				) ];
			default:
				return [];
		}
	}

	/**
	 * Compile a pattern AST node into a Matcher.
	 *
	 * A Matcher takes (val, env) and yields zero or more extended envs representing
	 * successful bindings. Type mismatches should throw JQError so alt_pattern can
	 * catch and try the next alternative.
	 *
	 * @param {ASTNode} pat Pattern node
	 * @return {Matcher}
	 */
	private compilePattern( pat: ASTNode ): Matcher {
		switch ( pat.type as string ) {
			case 'var_pattern': return this.compilePatternVar( pat );
			case 'array_pattern': return this.compilePatternArray( pat );
			case 'obj_pattern': return this.compilePatternObj( pat );
			case 'and_pattern': return this.compilePatternAnd( pat );
			case 'alt_pattern': return this.compilePatternAlt( pat );
			default:
				return assertNever( `Unknown pattern type: ${pat.type as string}` );
		}
	}

	// Compile a var_pattern ($x): always succeeds, binding the value to $x.
	private compilePatternVar( pat: ASTNode ): Matcher {
		const key = '$' + ( pat.name as string );
		return function* ( val: JQValue, env: JQEnv ): Generator<JQEnv> {
			yield env.bind( key, 0,
				function* ( _input: JQValue, _env: JQEnv ): Generator<JQValueOrPath> {
					yield val;
				},
			);
		};
	}

	// Compile an array_pattern ([$a, $b, ...]): checks type, then matches each element.
	private compilePatternArray( pat: ASTNode ): Matcher {
		const elemFns = ( pat.elems as ASTNode[] ).map( ( p ) => this.compilePattern( p ) );
		return function* ( val: JQValue, env: JQEnv ): Generator<JQEnv> {
			const arr = checkArray( 'array_pattern', val );
			let envs: JQEnv[] = [ env ];
			for ( let i = 0; i < elemFns.length; i++ ) {
				const elemFn = elemFns[ i ];
				const nextEnvs: JQEnv[] = [];
				for ( const currentEnv of envs ) {
					for ( const e of elemFn( arr[ i ] ?? null, currentEnv ) ) {
						nextEnvs.push( e );
					}
				}
				if ( nextEnvs.length === 0 ) {
					return;
				}
				envs = nextEnvs;
			}
			yield* envs;
		};
	}

	// Compile an obj_pattern ({key: pat, ...}): checks type, then matches each field.
	private compilePatternObj( pat: ASTNode ): Matcher {
		const fields: [ FilterFn, Matcher ][] =
			( pat.fields as { key: ASTNode; pattern: ASTNode }[] ).map( ( field ) => [
				this.compileNode( field.key ),
				this.compilePattern( field.pattern ),
			] );
		return function* ( val: JQValue, env: JQEnv ): Generator<JQEnv> {
			const obj = checkObject( 'obj_pattern', val );
			yield* JQCompile.matchObjFields( obj, env, fields, 0 );
		};
	}

	/**
	 * Compile an and_pattern: applies each sub-pattern to the same value,
	 * threading the env and accumulating all binding combinations.
	 * Generated for {$b: subpat}, which must bind $b AND match subpat.
	 *
	 * @param {ASTNode} pat Pattern node with 'patterns' key
	 * @return {Matcher}
	 */
	private compilePatternAnd( pat: ASTNode ): Matcher {
		const patFns = ( pat.patterns as ASTNode[] ).map( ( p ) => this.compilePattern( p ) );
		return function* ( val: JQValue, env: JQEnv ): Generator<JQEnv> {
			let envs: JQEnv[] = [ env ];
			for ( const patFn of patFns ) {
				const nextEnvs: JQEnv[] = [];
				for ( const currentEnv of envs ) {
					for ( const e of patFn( val, currentEnv ) ) {
						nextEnvs.push( e );
					}
				}
				if ( nextEnvs.length === 0 ) {
					return;
				}
				envs = nextEnvs;
			}
			yield* envs;
		};
	}

	/**
	 * Compile an alt_pattern (p1 ?// p2 ?// ...): tries each alternative in
	 * order, yielding all matches from the first that succeeds. Variables bound only in
	 * non-matching alternatives are null-filled in the resulting env.
	 *
	 * @param {ASTNode} pat Pattern node with 'patterns' key
	 * @return {Matcher}
	 */
	private compilePatternAlt( pat: ASTNode ): Matcher {
		const altFns = ( pat.patterns as ASTNode[] ).map(
			( p ) => this.compilePattern( p ),
		);
		const perAltVars = ( pat.patterns as ASTNode[] ).map(
			( p ) => JQCompile.collectPatternVars( p ),
		);
		const allVars = [ ...new Set( perAltVars.flat() ) ];
		const missingPerAlt = perAltVars.map(
			( altVars ) => allVars.filter( ( v ) => !altVars.includes( v ) ),
		);
		const nullFn = this.compileLiteral( { type: 'literal', value: null } as ASTNode );
		return function* ( val: JQValue, env: JQEnv ): Generator<JQEnv> {
			for ( let i = 0; i < altFns.length; i++ ) {
				try {
					// Take only the first successful match from each alternative.
					for ( const nextEnv of altFns[ i ]( val, env ) ) {
						let finalEnv = nextEnv;
						// Bind all the missing variables to `null`
						for ( const varName of missingPerAlt[ i ] ) {
							finalEnv = finalEnv.bind( varName, 0, nullFn );
						}
						// Yield the successful matches
						yield finalEnv;
					}
					// Don't advance to next alternative if we've matched
					return;
				} catch ( e ) {
					if ( e instanceof JQError ) {
						// this alternative failed; try the next one
					} else {
						throw e;
					}
				}
			}
			// all alternatives failed — yield nothing
		};
	}

	/**
	 * Compile a compare node (left op right).
	 * Both operands are evaluated against the original $input (not piped).
	 * Yields one boolean per combination of left and right outputs.
	 *
	 * @param {ASTNode} node Node with 'op', 'left', and 'right' keys
	 * @return {FilterFn}
	 */
	private compileCompare( node: ASTNode ): FilterFn {
		const leftFn = this.compileNode( node.left as ASTNode );
		const rightFn = this.compileNode( node.right as ASTNode );
		const opStr = node.op as string;
		let op: ( lv: JQValue, rv: JQValue ) => boolean;
		switch ( opStr ) {
			case '==':
				op = equal;
				break;
			case '!=':
				op = ( lv, rv ) => !equal( lv, rv );
				break;
			case '<':
				op = ( lv, rv ) => compare( lv, rv ) < 0;
				break;
			case '<=':
				op = ( lv, rv ) => compare( lv, rv ) <= 0;
				break;
			case '>':
				op = ( lv, rv ) => compare( lv, rv ) > 0;
				break;
			case '>=':
				op = ( lv, rv ) => compare( lv, rv ) >= 0;
				break;
			default:
				assertNever( `Unknown comparison operator: ${opStr}` );
		}
		return function* ( input: JQValue, env: JQEnv ): Generator<JQValueOrPath> {
			const plainEnv = env.leavePathMode();
			for ( const lv of leftFn( input, plainEnv ) ) {
				for ( const rv of rightFn( input, plainEnv ) ) {
					yield assertNotPath( op( lv as JQValue, rv as JQValue ), env );
				}
			}
		};
	}

	/**
	 * Compile an 'and' node: `left and right`.
	 *
	 * Short-circuits: falsy left yields false without evaluating right.
	 * Truthy left yields bool(rv) for each output of right.
	 *
	 * @param {ASTNode} node Node with 'left' and 'right' keys
	 * @return {FilterFn}
	 */
	private compileAnd( node: ASTNode ): FilterFn {
		const leftFn = this.compileNode( node.left as ASTNode );
		const rightFn = this.compileNode( node.right as ASTNode );
		return function* ( input: JQValue, env: JQEnv ): Generator<JQValueOrPath> {
			const plainEnv = env.leavePathMode();
			for ( const lv of leftFn( input, plainEnv ) ) {
				if ( !toBoolean( lv as JQValue ) ) {
					yield assertNotPath( false, env );
				} else {
					for ( const rv of rightFn( input, plainEnv ) ) {
						yield assertNotPath( toBoolean( rv as JQValue ), env );
					}
				}
			}
		};
	}

	/**
	 * Compile an 'or' node: `left or right`.
	 *
	 * Short-circuits: truthy left yields true without evaluating right.
	 * Falsy left yields bool(rv) for each output of right.
	 *
	 * @param {ASTNode} node Node with 'left' and 'right' keys
	 * @return {FilterFn}
	 */
	private compileOr( node: ASTNode ): FilterFn {
		const leftFn = this.compileNode( node.left as ASTNode );
		const rightFn = this.compileNode( node.right as ASTNode );
		return function* ( input: JQValue, env: JQEnv ): Generator<JQValueOrPath> {
			const plainEnv = env.leavePathMode();
			for ( const lv of leftFn( input, plainEnv ) ) {
				if ( toBoolean( lv as JQValue ) ) {
					yield assertNotPath( true, env );
				} else {
					for ( const rv of rightFn( input, plainEnv ) ) {
						yield assertNotPath( toBoolean( rv as JQValue ), env );
					}
				}
			}
		};
	}

	/**
	 * Compile an iterator node (.[] or expr[]?).
	 * Iterates over arrays (yielding each element) and objects (yielding each
	 * value in insertion order). null and other non-iterable types throw
	 * JQError, suppressed to empty output when opt is true.
	 *
	 * @param {ASTNode} node Node with 'expr' and 'opt' keys
	 * @return {FilterFn}
	 */
	private compileIter( node: ASTNode ): FilterFn {
		const exprFn = this.compileNode( node.expr as ASTNode );
		const opt = node.opt as boolean;
		return function* ( input: JQValue, env: JQEnv ): Generator<JQValueOrPath> {
			for ( const item of exprFn( input, env ) ) {
				const [ baseEnv, base ] = env.maybeUnwrapPath( item );
				try {
					if ( JQUtils.isObject( base ) ) {
						for (
							const [ k, v ] of
							Object.entries( base as unknown as Record<string, JQValue> )
						) {
							yield baseEnv.appendPath( k ).maybeWithPath( v );
						}
					} else if ( Array.isArray( base ) ) {
						for ( let k = 0; k < base.length; k++ ) {
							yield baseEnv.appendPath( k ).maybeWithPath( base[ k ] );
						}
					} else {
						throw new JQError( `Cannot iterate over ${typeNameAndValue( base )}` );
					}
				} catch ( e ) {
					if ( !( opt && ( e instanceof JQError ) ) ) {
						throw e;
					}
				}
			}
		};
	}

	/**
	 * Compile a unary negation node (-expr).
	 * Yields -v for each numeric value yielded by the inner expression;
	 * throws JQError for non-numeric values.
	 *
	 * @param {ASTNode} node Node with 'expr' key
	 * @return {FilterFn}
	 */
	private compileNeg( node: ASTNode ): FilterFn {
		const exprFn = this.compileNode( node.expr as ASTNode );
		return function* ( input: JQValue, env: JQEnv ): Generator<JQValueOrPath> {
			const plainEnv = env.leavePathMode();
			for ( const v of exprFn( input, plainEnv ) ) {
				const result = -checkNumber( 'negation', v );
				yield assertNotPath( result, env );
			}
		};
	}

	/**
	 * Compile a field-access node: `expr.name` or `expr.name?`.
	 * null input yields null; object input yields the field value (or null if
	 * absent); any other type throws JQError (suppressed to empty if opt).
	 *
	 * @param {ASTNode} node Node with 'expr', 'name', and 'opt' keys
	 * @return {FilterFn}
	 */
	private compileField( node: ASTNode ): FilterFn {
		const exprFn = this.compileNode( node.expr as ASTNode );
		const name = node.name as string;
		const opt = node.opt as boolean;
		return function* ( input: JQValue, env: JQEnv ): Generator<JQValueOrPath> {
			for ( const item of exprFn( input, env ) ) {
				const [ baseEnv, base ] = env.maybeUnwrapPath( item );
				if ( base === null ) {
					yield baseEnv.appendPath( name ).maybeWithPath( null );
					continue;
				}
				if ( opt && ( typeof base !== 'object' || Array.isArray( base ) ) ) {
					continue;
				}
				const obj = checkObject( 'field', base );
				yield baseEnv.appendPath( name ).maybeWithPath(
					obj[ name ] ?? null,
				);
			}
		};
	}

	/**
	 * Compile an index node: `expr[key]` or `expr[key]?`.
	 *
	 * The key expression is evaluated against the original input (not the base).
	 * Supports object indexing by string and array indexing by integer
	 * (with negative indices counting from the end). null input yields null.
	 *
	 * @param {ASTNode} node Node with optional 'expr', required 'key', and 'opt' keys
	 * @return {FilterFn}
	 */
	private compileIndex( node: ASTNode ): FilterFn {
		const exprFn = node.expr !== undefined ?
			this.compileNode( node.expr as ASTNode ) : this.compileIdentity();
		const keyFn = this.compileNode( node.key as ASTNode );
		const opt = node.opt as boolean;
		return function* ( input: JQValue, env: JQEnv ): Generator<JQValueOrPath> {
			for ( const item of exprFn( input, env ) ) {
				const [ baseEnv, base ] = env.maybeUnwrapPath( item );
				// Key is evaluated against the original input, not the base.
				// e.g. in .a[.b], .b is evaluated against the outer input,
				// not against the result of .a.  The key expression is also
				// always evaluated in normal (non-path) mode: the key determines
				// which slot to access; it is not itself a path to collect.
				for ( const keyItem of keyFn( input, env.leavePathMode() ) ) {
					const key = keyItem as JQValue;
					if ( base === null ) {
						yield baseEnv.appendPath( key ).maybeWithPath( null );
					} else if ( typeof base === 'object' && !Array.isArray( base ) ) {
						if ( opt && typeof key !== 'string' ) {
							continue;
						}
						const k = checkString( 'index', key );
						const obj = base as Record<string, JQValue>;
						yield baseEnv.appendPath( k ).maybeWithPath(
							obj[ k ] ?? null,
						);
					} else if ( Array.isArray( base ) ) {
						if ( Array.isArray( key ) ) {
							// Sub-array search: find all positions where key appears
							// as a contiguous sub-sequence of base.
							// Used by builtin.jq's indices/1 definition.
							const positions = JQCompile.arraySubarraySearch( base, key );
							yield baseEnv.appendPath( key ).maybeWithPath( positions );
						} else if ( opt && !isNumber( key ) ) {
							continue;
						} else {
							const index = adjustIndex( 'index', key, base );
							yield baseEnv.appendPath( key ).maybeWithPath(
								index === null ? null : base[ index ],
							);
						}
					} else if ( !opt ) {
						throw new JQError(
							`Cannot index ${typeName( base )} with ${typeNameAndValue( key )}`,
						);
					}
				}
			}
		};
	}

	/**
	 * Compile a string node ("text\(expr)text...").
	 *
	 * Parts alternate between str_text (literal text) and str_interp
	 * (expression to evaluate and interpolate). All combinations of
	 * interpolated outputs are produced via Cartesian product.
	 *
	 * If fmt is set (@html, @base64, …), each interpolated segment is
	 * formatted before being inserted; literal text parts are left as-is.
	 * If fmt is null, interpolated values are converted with tostring
	 * semantics (strings pass through; everything else is JSON-encoded).
	 *
	 * @param {ASTNode} node Node with 'fmt' (null or format name) and 'parts' keys
	 * @return {FilterFn}
	 */
	private compileString( node: ASTNode ): FilterFn {
		const formatter = formatterFor( ( node.fmt as string | null ) ?? 'text' );
		type CompiledPart =
			| { kind: 'text'; text: string }
			| { kind: 'interp'; fn: FilterFn };
		const compiledParts: CompiledPart[] = ( node.parts as ASTNode[] ).map( ( part ) => {
			if ( ( part as { type: string } ).type === 'str_interp' ) {
				return { kind: 'interp' as const, fn: this.compileNode( part.expr as ASTNode ) };
			}
			return { kind: 'text' as const, text: part.text as string };
		} );
		return function* ( input: JQValue, env: JQEnv ): Generator<JQValueOrPath> {
			const plainEnv = env.leavePathMode();
			let strings: string[] = [ '' ];
			for ( const part of compiledParts ) {
				if ( part.kind === 'text' ) {
					strings = strings.map( ( s ) => s + part.text );
				} else {
					const next: string[] = [];
					for ( const prefix of strings ) {
						for ( const val of part.fn( input, plainEnv ) ) {
							next.push( prefix + formatter( val as JQValue ) );
						}
					}
					strings = next;
				}
			}
			for ( const s of strings ) {
				yield assertNotPath( s, env );
			}
		};
	}

	/**
	 * Compile a standalone format node (@base64, @html, etc.).
	 * Applies the named format to the input value directly.
	 *
	 * @param {ASTNode} node Node with 'fmt' key
	 * @return {FilterFn}
	 */
	private compileFormat( node: ASTNode ): FilterFn {
		const formatter = formatterFor( node.fmt as string );
		return function* ( input: JQValue, env: JQEnv ): Generator<JQValueOrPath> {
			yield assertNotPath( formatter( input ), env );
		};
	}

	/**
	 * Compile a binary operation node: `left op right`.
	 *
	 * Evaluates both sides against the original input, then applies the
	 * operator to each combination of outputs. Right is the outer loop,
	 * left is the inner loop (jq semantics).
	 *
	 * @param {ASTNode} node Node with 'op', 'left', and 'right' keys
	 * @return {FilterFn}
	 */
	private compileBinop( node: ASTNode ): FilterFn {
		const leftFn = this.compileNode( node.left as ASTNode );
		const rightFn = this.compileNode( node.right as ASTNode );
		const opStr = node.op as string;
		let op: ( lv: JQValue, rv: JQValue ) => JQValue;
		switch ( opStr ) {
			case '+':
				op = add;
				break;
			case '-':
				op = subtract;
				break;
			case '*':
				op = multiply;
				break;
			case '/':
				op = divide;
				break;
			case '%':
				op = modulo;
				break;
			default:
				assertNever( `Unknown operator: ${opStr}` );
		}
		// jq evaluates right first (outer loop) then left (inner loop)
		return function* ( input: JQValue, env: JQEnv ): Generator<JQValueOrPath> {
			const plainEnv = env.leavePathMode();
			for ( const rv of rightFn( input, plainEnv ) ) {
				for ( const lv of leftFn( input, plainEnv ) ) {
					yield assertNotPath( op( lv as JQValue, rv as JQValue ), env );
				}
			}
		};
	}

	/**
	 * Compile an alternative node (left // right).
	 * Evaluates left; yields all non-false/non-null outputs. If none were
	 * yielded, evaluates right and yields all its outputs instead.
	 *
	 * @param {ASTNode} node Node with 'left' and 'right' keys
	 * @return {FilterFn}
	 */
	private compileAlternative( node: ASTNode ): FilterFn {
		const leftFn = this.compileNode( node.left as ASTNode );
		const rightFn = this.compileNode( node.right as ASTNode );
		return function* ( input: JQValue, env: JQEnv ): Generator<JQValueOrPath> {
			let found = false;
			for ( const item of leftFn( input, env ) ) {
				const [ itemEnv, val ] = env.maybeUnwrapPath( item );
				if ( toBoolean( val ) ) {
					yield itemEnv.maybeWithPath( val );
					found = true;
				}
			}
			if ( !found ) {
				yield* rightFn( input, env );
			}
		};
	}

	/**
	 * Compile a try-catch node (try body catch handler).
	 * Evaluates body; if a JQError is thrown, catches it and either
	 * evaluates handler with the error message as input, or produces no
	 * output if there is no catch clause.
	 *
	 * @param {ASTNode} node Node with 'body' and nullable 'catch' keys
	 * @return {FilterFn}
	 */
	private compileTryCatch( node: ASTNode ): FilterFn {
		const bodyFn = this.compileNode( node.body as ASTNode );
		const catchFn = node.catch ? this.compileNode( node.catch as ASTNode ) : null;
		return function* ( input: JQValue, env: JQEnv ): Generator<JQValueOrPath> {
			try {
				yield* bodyFn( input, env );
			} catch ( e ) {
				if ( !( e instanceof JQError ) ) {
					throw e;
				}
				if ( catchFn !== null ) {
					// The catch handler receives the error value, not a path;
					// always run it in normal mode.
					yield* catchFn( e.jqValue as JQValue, env.leavePathMode() );
				}
			}
		};
	}

	/**
	 * Compile a reduce node (reduce src as $pat (init; update)).
	 * Iterates over all outputs of src; for each output, matches the pattern
	 * and evaluates update with the current accumulator as input in the
	 * extended env. Yields the final accumulator value.
	 *
	 * @param {ASTNode} node Node with 'src', 'pattern', 'init', and 'update' keys
	 * @return {FilterFn}
	 */
	private compileReduce( node: ASTNode ): FilterFn {
		const srcFn = this.compileNode( node.src as ASTNode );
		const initFn = this.compileNode( node.init as ASTNode );
		const updateFn = this.compileNode( node.update as ASTNode );
		const patFn = this.compilePattern( node.pattern as ASTNode );
		return function* ( input: JQValue, env: JQEnv ): Generator<JQValueOrPath> {
			const plainEnv = env.leavePathMode();
			for ( let acc of initFn( input, plainEnv ) ) {
				for ( const val of srcFn( input, plainEnv ) ) {
					for ( const boundEnv of patFn( val as JQValue, plainEnv ) ) {
						for ( const newAcc of updateFn( acc as JQValue, boundEnv ) ) {
							acc = newAcc;
							// no break: use the last update value as the new acc
						}
						// no break: chain all pattern bindings through the acc
					}
				}
				yield assertNotPath( acc, env );
			}
		};
	}

	/**
	 * Compile a foreach node (foreach src as $pat (init; update[; extract])).
	 * Like reduce but yields the accumulator (or extract output) after each step.
	 *
	 * @param {ASTNode} node Node with 'src', 'pattern', 'init', 'update',
	 *   and nullable 'extract' keys
	 * @return {FilterFn}
	 */
	private compileForeach( node: ASTNode ): FilterFn {
		const srcFn = this.compileNode( node.src as ASTNode );
		const initFn = this.compileNode( node.init as ASTNode );
		const updateFn = this.compileNode( node.update as ASTNode );
		const patFn = this.compilePattern( node.pattern as ASTNode );
		const extractFn = node.extract ? this.compileNode( node.extract as ASTNode ) : null;
		return function* ( input: JQValue, env: JQEnv ): Generator<JQValueOrPath> {
			const plainEnv = env.leavePathMode();
			for ( let acc of initFn( input, plainEnv ) ) {
				for ( const val of srcFn( input, plainEnv ) ) {
					for ( const boundEnv of patFn( val as JQValue, plainEnv ) ) {
						// Yield each update value (or its extract); use the last as the
						// new acc.  If update is empty, acc is unchanged and nothing is
						// yielded for this step.  Multiple pattern bindings per source
						// value chain through the accumulator in order.
						for ( const newAcc of updateFn( acc as JQValue, boundEnv ) ) {
							acc = newAcc;
							if ( extractFn !== null ) {
								for ( const extracted of extractFn( acc as JQValue, boundEnv ) ) {
									yield assertNotPath( extracted, env );
								}
							} else {
								yield assertNotPath( acc, env );
							}
						}
					}
				}
			}
		};
	}

	/**
	 * Compile a slice node (expr[from:to] or expr[from:to]?).
	 * Applies to arrays (returns subarray) and strings (returns substring).
	 * Null input yields null; other types throw JQError (suppressed when opt).
	 * In path mode, yields a slice-path key alongside the sliced value.
	 *
	 * @param {ASTNode} node Node with 'expr', 'from', 'to', and 'opt' keys
	 * @return {FilterFn}
	 */
	private compileSlice( node: ASTNode ): FilterFn {
		const exprFn = this.compileNode( node.expr as ASTNode );
		const nullFn = this.compileLiteral( { type: 'literal', value: null } as ASTNode );
		const fromFn = node.from ?
			this.compileNode( node.from as ASTNode ) : nullFn;
		const toFn = node.to ?
			this.compileNode( node.to as ASTNode ) : nullFn;
		const opt = node.opt as boolean;
		return function* ( input: JQValue, env: JQEnv ): Generator<JQValueOrPath> {
			for ( const item of exprFn( input, env ) ) {
				const [ baseEnv, base ] = env.maybeUnwrapPath( item );
				// from/to bounds are not part of the path; evaluate in normal mode.
				const normalEnv = env.leavePathMode();
				for ( const from of fromFn( input, normalEnv ) ) {
					for ( const to of toFn( input, normalEnv ) ) {
						// In path mode yield the slice-path key alongside the
						// sliced value so that downstream ops (and delpaths) work.
						const sliceKey = {
							start: from as JQValue,
							end: to as JQValue,
						};
						for (
							const sliceVal of
							slice( base, sliceKey.start, sliceKey.end, opt )
						) {
							yield baseEnv.appendPath( sliceKey as JQValue )
								.maybeWithPath( sliceVal );
						}
					}
				}
			}
		};
	}

	/**
	 * Compile an assign node (lhs = rhs, lhs |= f, or lhs op= rhs).
	 *
	 * For plain =:  evaluate rhs against input, then set lhs to each result.
	 * For |=:       get the current value at lhs, apply rhs as an update fn, set back.
	 * For op= (+=, -=, *=, /=, %=, //=): the RHS is evaluated on the OUTER input
	 *   (the input at the time of evaluation), not on the current path value.
	 *   "A op= B" means: for each path p in A, set p to (value-at-p) op ($outer | B).
	 *   This differs from "A |= . op B" when B references the outer object/array
	 *   (e.g. ".foo += .foo" must read .foo from the original input, not from the
	 *   scalar value 2 that is the current .foo).
	 *
	 * @param {ASTNode} node Node with 'op', 'left', and 'right' keys
	 * @return {FilterFn}
	 */
	private compileAssign( node: ASTNode ): FilterFn {
		const op = node.op as string;
		const lhsNode = node.left as ASTNode;
		const rhsFn = this.compileNode( node.right as ASTNode );

		if ( op === '=' ) {
			return this.compileAssignSet( lhsNode, rhsFn );
		}

		// Compound op= : the RHS is evaluated on the outer input, not the
		// path value, so use a special form for $updateFn which can take
		// the outer input, *and* the current value at the path, and yield
		// a result (which will be written back).

		let binaryOp: null|( ( a: JQValue, b: JQValue ) => JQValue );
		switch ( op ) {
			case '+=':
				binaryOp = add;
				break;
			case '-=':
				binaryOp = subtract;
				break;
			case '*=':
				binaryOp = multiply;
				break;
			case '/=':
				binaryOp = divide;
				break;
			case '%=':
				binaryOp = modulo;
				break;
			default:
				binaryOp = null;
				break;
		}

		let updateFn: UpdateFn;

		switch ( op ) {
			case '|=':
				updateFn = function* ( _input, current, env ) {
					yield* rhsFn( current, env );
				};
				break;
			case '//=':
				updateFn = function* ( input, current, env ) {
					if ( toBoolean( current ) ) {
						yield current;
					} else {
						yield* rhsFn( input, env );
					}
				};
				break;
			default:
				if ( binaryOp === null ) {
					assertNever( `Unknown compound assignment operator: ${op}` );
				}
				updateFn = function* ( input, current, env ) {
					for ( const r of rhsFn( input, env ) ) {
						yield binaryOp( current, assertNotPath( r, env ) );
					}
				};
				break;
		}
		return this.compileAssignUpdate( lhsNode, updateFn );
	}

	/**
	 * Compile "pathNode = rhsFn": for each value produced by rhsFn, set every
	 * path produced by pathNode to that value and yield the updated input.
	 *
	 * @param {ASTNode} pathNode AST path node (the LHS)
	 * @param {FilterFn} rhsFn compiled RHS expression
	 * @return {FilterFn}
	 */
	private compileAssignSet( pathNode: ASTNode, rhsFn: FilterFn ): FilterFn {
		const pathFn = this.compileNode( pathNode );
		return function* ( input: JQValue, env: JQEnv ): Generator<JQValueOrPath> {
			assertNotPath( input, env );
			const pathEnv = env.enterPathMode();
			for ( const newVal of rhsFn( input, env ) ) {
				let result = input;
				for ( const item of pathFn( input, pathEnv ) ) {
					result = JQCompile.setAtPath(
						result,
						pathEnv.extractPath( item ),
						0,
						assertNotPath( newVal, env ),
					);
				}
				yield result;
			}
		};
	}

	/**
	 * Compile "pathNode |= updateFn": apply updateFn to every slot produced by
	 * pathNode and write the result back. Slots where updateFn yields nothing
	 * are deleted (jq `|= empty` semantics). Array deletions
	 * preserve correct positions.
	 *
	 * @param {ASTNode} pathNode AST path node (the LHS)
	 * @param {Function} updateFn takes (outerInput, currentValue, env), yields replacement
	 * @return {FilterFn}
	 */
	private compileAssignUpdate(
		pathNode: ASTNode,
		updateFn: UpdateFn,
	): FilterFn {
		const pathFn = this.compileNode( pathNode );
		return function* ( input: JQValue, env: JQEnv ): Generator<JQValueOrPath> {
			assertNotPath( input, env );
			const pathEnv = env.enterPathMode();
			const toDelete: JQValue[] = [];
			for ( const item of pathFn( input, pathEnv ) ) {
				const path = pathEnv.extractPath( item );
				const current = JQCompile.getAtPath( input, path, 0 );
				let hasOutput = false;
				// eslint-disable-next-line no-unreachable-loop
				for ( const newVal of updateFn( input, current, env ) ) {
					input = JQCompile.setAtPath( input, path, 0, assertNotPath( newVal, env ) );
					hasOutput = true;
					break;
				}
				if ( !hasOutput ) {
					toDelete.push( path as JQValue );
				}
			}
			yield JQCompile.deleteAtPaths( input, toDelete );
		};
	}

	// Find all starting positions in haystack where needle appears as a
	// contiguous sub-sequence. Used by indices/1 via compileIndex.
	private static arraySubarraySearch( haystack: JQValue[], needle: JQValue[] ): JQValue[] {
		const needleLen = needle.length;
		const limit = haystack.length - needleLen;
		const positions: JQValue[] = [];
		for ( let j = 0; j <= limit; j++ ) {
			let k = 0;
			for ( ; k < needleLen; k++ ) {
				if ( compare( haystack[ j + k ], needle[ k ] ) !== 0 ) {
					break;
				}
			}
			if ( k === needleLen ) {
				positions.push( j );
			}
		}
		return positions;
	}

	/**
	 * Navigate to the value at `path` within `val`.
	 * null propagates (null[x] → null); out-of-bounds returns null.
	 *
	 * @param {JQValue} val
	 * @param {JQValue[]} path
	 * @param {number} offset The current offset into path
	 * @return {JQValue} The value at this patch within val
	 */
	public static getAtPath( val: JQValue, path: JQValue[], offset: number ): JQValue {
		if ( offset >= path.length ) {
			return val;
		}
		const key = path[ offset++ ];
		if ( typeof key === 'string' ) {
			if ( val !== null && typeof val === 'object' && !Array.isArray( val ) ) {
				const obj = val as Record<string, JQValue>;
				if ( obj[ key ] !== undefined ) {
					return JQCompile.getAtPath( obj[ key ], path, offset );
				}
			}
			return null;
		}
		if ( isNumber( key ) ) {
			if ( !Array.isArray( val ) ) {
				return null;
			}
			const idx = adjustIndex( 'getAtPath', key, val );
			if ( idx === null ) {
				return null;
			}
			return JQCompile.getAtPath( val[ idx ], path, offset );
		}
		if ( Array.isArray( key ) ) {
			if ( !Array.isArray( val ) ) {
				return null;
			}
			return JQCompile.getAtPath(
				JQCompile.arraySubarraySearch( val, key as JQValue[] ) as JQValue,
				path, offset,
			);
		}
		return null;
	}

	/**
	 * Return `container` with `newVal` written at `path`.
	 * Promotes null → [] or stdClass based on key type; fills array gaps with null.
	 * Throws JQError for out-of-bounds negative indices and oversized indices.
	 *
	 * @param {JQValue} container
	 * @param {JQValue[]} path
	 * @param {number} offset The current offset into $path
	 * @param {JQValue} newVal The value we expect to set at the end of the path
	 * @return {JQValue}
	 */
	public static setAtPath(
		container: JQValue, path: JQValue[], offset: number, newVal: JQValue,
	): JQValue {
		if ( offset >= path.length ) {
			return newVal;
		}
		const key = path[ offset++ ];
		if ( typeof key === 'string' ) {
			if ( container === null ) {
				// null is promoted to object
				container = {};
			}
			const obj = checkObject( 'setAtPath', container );
			return {
				...obj,
				[ key ]: JQCompile.setAtPath( obj[ key ] ?? null, path, offset, newVal ),
			};
		}
		if ( isNumber( key ) ) {
			if ( container === null ) {
				// null is promoted to array
				container = [];
			}
			const arr = checkArray( 'setAtPath', container );
			if ( isNaN( key ) ) {
				throw new JQError( 'Cannot set array element at NaN index' );
			}
			let index = Math.trunc( key );
			if ( index < 0 ) {
				index += arr.length;
			}
			if ( index < 0 ) {
				throw new JQError( 'Out of bounds negative array index' );
			}
			if ( index >= MAX_SIZE ) {
				throw new JQError( 'Array index too large' );
			}
			const newArr = [ ...arr ];
			while ( newArr.length < index ) {
				newArr.push( null );
			}
			newArr[ index ] = JQCompile.setAtPath(
				arr[ index ] ?? null, path, offset, newVal,
			);
			return newArr;
		}
		if ( Array.isArray( key ) ) {
			throw new JQError( 'Cannot update field at array index of array' );
		}
		// Slice-path key: { start: ..., end: ... } produced by compileSlice in path mode.
		if ( key !== null && typeof key === 'object' ) {
			const arr = checkArray( 'setAtPath', container );
			const len = arr.length;
			const keyObj = key as Record<string, JQValue>;
			const f = normalizeSliceIdx( keyObj.start ?? null, len, 0, true, false );
			const t = normalizeSliceIdx( keyObj.end ?? null, len, len, false, true );
			const repl = Array.isArray( newVal ) ? newVal : [];
			const newArr = [ ...arr ];
			newArr.splice( f, Math.max( 0, t - f ), ...repl );
			return newArr;
		}
		return container;
	}

	/**
	 * Return `container` with the slot at each `path` in `paths` removed.
	 * Array elements are spliced out (later elements shift left); object keys are unset.
	 * Non-existent paths are silently ignored.
	 *
	 * @param {JQValue} container
	 * @param {JQValue[]} paths
	 * @return {JQValue}
	 */
	public static deleteAtPaths( container: JQValue, paths: JQValue[] ): JQValue {
		let result = container;
		const tombstonePaths: JQValue[][] = [];
		for ( const path of paths ) {
			result = JQCompile.deleteAtPath(
				result, checkArray( 'delpaths', path ), 0, tombstonePaths,
			);
		}
		// Process deepest (longest) paths first so children are compacted before parents.
		tombstonePaths.sort( ( a, b ) =>
			( b.length - a.length ) || compare( a as JQValue, b as JQValue ),
		);
		// Deduplicate and compact each unique tombstone parent path.
		const seen = new Set<string>();
		for ( const path of tombstonePaths ) {
			const key = JSON.stringify( path );
			if ( !seen.has( key ) ) {
				seen.add( key );
				result = JQCompile.compactAtPath( result, path, 0 );
			}
		}
		return result;
	}

	private static deleteAtPath(
		container: JQValue, path: JQValue[], offset: number, tombstonePaths: JQValue[][],
	): JQValue {
		if ( offset >= path.length ) {
			return null;
		}
		const key = path[ offset++ ];
		if ( offset < path.length ) {
			// Not at leaf — recurse into the container
			if ( typeof key === 'string' && container !== null &&
					typeof container === 'object' && !Array.isArray( container ) ) {
				const obj = container as Record<string, JQValue>;
				if ( obj[ key ] === undefined ) {
					return container;
				}
				return {
					...obj,
					[ key ]: JQCompile.deleteAtPath( obj[ key ], path, offset, tombstonePaths ),
				};
			}
			if ( isNumber( key ) && Array.isArray( container ) ) {
				const idx = adjustIndex( 'deleteAtPath', key, container );
				if ( idx !== null && container[ idx ] !== TOMB ) {
					const newArr = [ ...container ];
					newArr[ idx ] = JQCompile.deleteAtPath(
						container[ idx ], path, offset, tombstonePaths,
					);
					return newArr;
				}
			}
			return container;
		}
		// At leaf — delete the key (or replace it with a tombstone)
		if ( typeof key === 'string' && container !== null &&
				typeof container === 'object' && !Array.isArray( container ) ) {
			const obj = container as Record<string, JQValue>;
			const newObj = { ...obj };
			delete newObj[ key ];
			return newObj;
		}
		if ( isNumber( key ) && Array.isArray( container ) ) {
			const index = adjustIndex( 'deleteAtPath', key, container );
			if ( index !== null && container[ index ] !== TOMB ) {
				const newArr = [ ...container ];
				newArr[ index ] = TOMB;
				tombstonePaths.push( path.slice( 0, -1 ) );
				return newArr;
			}
		}
		if ( Array.isArray( key ) ) {
			throw new JQError( 'Cannot delete array element of array' );
		}
		// Slice-path key: { start: ..., end: ... }
		if ( key !== null && typeof key === 'object' && !Array.isArray( key ) &&
				Array.isArray( container ) ) {
			const len = container.length;
			const keyObj = key as Record<string, JQValue>;
			const f = normalizeSliceIdx( keyObj.start ?? null, len, 0, true, false );
			const t = normalizeSliceIdx( keyObj.end ?? null, len, len, false, true );
			const newArr = [ ...container ];
			let sawTombstone = false;
			for ( let i = f; i < t; i++ ) {
				sawTombstone = sawTombstone || newArr[ i ] === TOMB;
				newArr[ i ] = TOMB;
			}
			if ( !sawTombstone ) {
				// Optimization: If we saw a tombstone, this array is already
				// on the tombstonePaths list.
				tombstonePaths.push( path.slice( 0, -1 ) );
			}
			return newArr;
		}
		return container;
	}

	// Remove tombstones from arrays to complete deleteAtPath.
	private static compactAtPath(
		container: JQValue, path: JQValue[], offset: number,
	): JQValue {
		if ( offset >= path.length ) {
			const arr = checkArray( 'compactAtPath', container );
			return arr.filter( ( v ) => v !== TOMB );
		}
		const key = path[ offset++ ];
		if ( typeof key === 'string' && container !== null &&
				typeof container === 'object' && !Array.isArray( container ) ) {
			const obj = container as Record<string, JQValue>;
			if ( obj[ key ] === undefined ) {
				return container;
			}
			return {
				...obj,
				[ key ]: JQCompile.compactAtPath( obj[ key ], path, offset ),
			};
		}
		if ( isNumber( key ) && Array.isArray( container ) ) {
			const idx = adjustIndex( 'compactAtPath', key, container );
			if ( idx !== null && container[ idx ] !== TOMB ) {
				const newArr = [ ...container ];
				newArr[ idx ] = JQCompile.compactAtPath( container[ idx ], path, offset );
				return newArr;
			}
		}
		return container;
	}
}
