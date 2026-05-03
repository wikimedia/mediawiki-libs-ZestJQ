<?php
// @phan-file-suppress PhanUnusedClosureParameter, PhanEmptyYieldFrom
declare( strict_types = 1 );

namespace Wikimedia\Zest;

use Closure;
use Generator;
use LogicException;
use stdClass;

/**
 * Compiler for JQ filter expressions.
 *
 * Translates a JQ AST (produced by JQGrammar::parse()) into a reusable PHP
 * closure. The closure can be applied to many different inputs against the
 * same initial environment without recompiling the expression.
 *
 * Usage:
 *   $env    = new JQEnv();   // or a pre-built env with builtins
 *   $filter = JQCompile::compile( JQGrammar::parse( $expr ), $env );
 *   foreach ( $filter( $input ) as $output ) { ... }
 *
 * Error handling:
 *   JQError        — thrown by error/0, error/1, and type mismatches; caught by
 *                    try-catch nodes and the ? suffix operator.
 *   JQBreak        — thrown by break/$label; propagates through try-catch nodes
 *                    and is only caught by the matching label/$label node.
 *   JQHaltException — thrown by halt/0 and halt_error/1; propagates through
 *                    everything (not caught by try-catch nodes).
 *
 * Closure types used throughout this file:
 *   Filter  = Closure(mixed $input, JQEnv $env): Generator
 *             where the Generator yields zero or more mixed JQ output values
 *             (null, bool, int, float, string, or array).
 *   Matcher = Closure(mixed $val, JQEnv $env): Generator
 *             where the Generator yields zero or one JQEnv: exactly one on a
 *             successful pattern match (env extended with variable bindings),
 *             or none if the match fails.
 */
class JQCompile {

	/**
	 * Compile a JQ AST into a reusable filter.
	 *
	 * The returned closure accepts one input value and returns a fresh
	 * Generator of output values, using the supplied env as the initial
	 * lexical scope. Calling the closure multiple times (with different
	 * inputs) is safe and efficient: the AST is compiled only once.
	 *
	 * @param array $ast AST produced by JQGrammar::parse()
	 * @param JQEnv $env Initial lexical environment (builtins, prelude defs)
	 * @return Closure(mixed $input): Generator
	 */
	public static function compile( array $ast, JQEnv $env ): Closure {
		$compiler = new self();
		$fn = $compiler->compileNode( $ast );
		return static function ( mixed $input ) use ( $fn, $env ): Generator {
			yield from $fn( $input, $env );
		};
	}

	/**
	 * Compile one AST node into a Filter closure.
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
	 * @param array $node AST node (must have a 'type' key)
	 * @return Closure(mixed,JQEnv):Generator a Filter
	 */
	private function compileNode( array $node ): Closure {
		return match ( $node['type'] ) {
			'identity' => $this->compileIdentity(),
			'literal'  => $this->compileLiteral( $node ),
			'pipe'     => $this->compilePipe( $node ),
			'label'    => $this->compileLabel( $node ),
			'break'    => $this->compileBreak( $node ),
			'variable' => $this->compileVariable( $node ),
			'def'      => $this->compileDef( $node ),
			'call'     => $this->compileCall( $node ),
			'bind'     => $this->compileBind( $node ),
			'compare'  => $this->compileCompare( $node ),
			'and'      => $this->compileAnd( $node ),
			'or'       => $this->compileOr( $node ),
			'iter'     => $this->compileIter( $node ),
			'neg'      => $this->compileNeg( $node ),
			'field'    => $this->compileField( $node ),
			'index'    => $this->compileIndex( $node ),
			'comma'    => $this->compileComma( $node ),
			'array'    => $this->compileArray( $node ),
			'object'   => $this->compileObject( $node ),
			'if'       => $this->compileIf( $node ),
			'string'   => $this->compileString( $node ),
			'format'   => $this->compileFormat( $node ),
			'binop'    => $this->compileBinop( $node ),
			'alternative' => $this->compileAlternative( $node ),
			'try'      => $this->compileTryCatch( $node ),
			'reduce'   => $this->compileReduce( $node ),
			'foreach'  => $this->compileForeach( $node ),
			'slice'    => $this->compileSlice( $node ),
			'assign'   => $this->compileAssign( $node ),
			// Not yet implemented: module-level directives
			'import', 'include', 'module' => static function ( mixed $input, JQEnv $env ) use ( $node ): Generator {
				yield from [];
				throw new LogicException( 'compileNode: module-level directives not implemented: ' . $node['type'] );
			},
			default    => static function ( mixed $input, JQEnv $env ) use ( $node ): Generator {
				yield from [];
				throw new LogicException( 'compileNode: not yet implemented for node type: ' . $node['type'] );
			},
		};
	}

	/**
	 * Compile an identity node (.).
	 * Yields the input value unchanged.
	 *
	 * @return Closure(mixed,JQEnv):Generator a Filter
	 */
	private function compileIdentity(): Closure {
		return static function ( mixed $input, JQEnv $env ): Generator {
			yield $env->maybeWithPath( $input );
		};
	}

	/**
	 * Compile a pipe node (left | right).
	 * Feeds each output of the left filter as input to the right filter,
	 * yielding all outputs produced across all intermediate values.
	 *
	 * @param array $node Node with 'left' and 'right' keys
	 * @return Closure(mixed,JQEnv):Generator a Filter
	 */
	private function compilePipe( array $node ): Closure {
		$leftFn  = $this->compileNode( $node['left'] );
		$rightFn = $this->compileNode( $node['right'] );
		return static function ( mixed $input, JQEnv $env ) use ( $leftFn, $rightFn ): Generator {
			foreach ( $leftFn( $input, $env ) as $item ) {
				[ $nextEnv, $mid ] = $env->maybeUnwrapPath( $item );
				yield from $rightFn( $mid, $nextEnv );
			}
		};
	}

	/**
	 * Compile a literal node (null, true, false, number, plain string).
	 * Yields the literal value, ignoring the input.
	 *
	 * @param array $node Node with 'value' key
	 * @return Closure(mixed,JQEnv):Generator a Filter
	 */
	private function compileLiteral( array $node ): Closure {
		$value = $node['value'];
		return static function ( mixed $input, JQEnv $env ) use ( $value ): Generator {
			yield $value;
		};
	}

	/**
	 * Compile a label node (label $out | body).
	 * Evaluates the body, catching any JQBreak whose label matches $out
	 * and silently terminating the stream. A break for a different label
	 * is re-thrown so it can be caught by the appropriate outer label.
	 *
	 * @param array $node Node with 'name' and 'body' keys
	 * @return Closure(mixed,JQEnv):Generator a Filter
	 */
	private function compileLabel( array $node ): Closure {
		$name   = $node['name'];
		$bodyFn = $this->compileNode( $node['body'] );
		return static function ( mixed $input, JQEnv $env ) use ( $name, $bodyFn ): Generator {
			try {
				yield from $bodyFn( $input, $env );
			} catch ( JQBreak $e ) {
				if ( $e->label !== $name ) {
					throw $e;
				}
				// Matching break: stop the stream, yield nothing further.
			}
		};
	}

	/**
	 * Compile a break node (break $label).
	 * Throws JQBreak when the generator is iterated, terminating the
	 * nearest enclosing label node with a matching name.
	 *
	 * yield from [] before the throw makes this a generator function
	 * (so calling it returns a Generator rather than throwing immediately)
	 * without introducing unreachable code.
	 *
	 * @param array $node Node with 'name' key
	 * @return Closure(mixed,JQEnv):Generator Filter
	 */
	private function compileBreak( array $node ): Closure {
		$name = $node['name'];
		return static function ( mixed $input, JQEnv $env ) use ( $name ): Generator {
			yield from [];
			throw new JQBreak( $name );
		};
	}

	/**
	 * Compile a variable node ($name).
	 * Looks the name up as a 0-arity filter in the runtime env and delegates
	 * to it. Variables are bound into the env by compilePattern's var_pattern
	 * case; the stored filter ignores its input and yields the captured value.
	 *
	 * @param array $node Node with 'name' key
	 * @return Closure(mixed,JQEnv):Generator a Filter
	 */
	private function compileVariable( array $node ): Closure {
		$key = '$' . $node['name'];
		return static function ( mixed $input, JQEnv $env ) use ( $key ): Generator {
			$fn = $env->lookup( $key, 0 );
			if ( $fn === null ) {
				throw new JQError( $key . ' is not defined' );
			}
			yield from $fn( $input, $env );
		};
	}

	/**
	 * Compile a def node (def name(params): body; rest).
	 *
	 * Value parameters ($x) are desugared at compile time:
	 *   def f($x): body  =>  def f(x): x as $x | body
	 * so that only filter parameters remain at runtime.
	 *
	 * Lexical scoping is achieved via a forward reference ($defEnvRef):
	 * the binding closure captures $defEnvRef by reference, which is filled
	 * in just before rest is evaluated. This also enables recursion, because
	 * any recursive call in body or rest will find the function already bound.
	 *
	 * For 0-arity defs, the stored value is a plain Filter.
	 * For n-arity defs, the stored value is a FunctionFactory:
	 *   Closure(Closure[] $argFns): Filter
	 * The factory injects the arg closures as 0-arity filter-param bindings
	 * into the lexical env, then returns the body filter. Filter args are
	 * always evaluated in the call-site env, not the def-body env.
	 *
	 * @param array $node Node with 'name', 'params', 'body', and 'rest' keys
	 * @return Closure(mixed,JQEnv):Generator Filter
	 */
	private function compileDef( array $node ): Closure {
		$name   = $node['name'];
		$params = $node['params'];
		$arity  = count( $params );

		// Desugar value params: wrap body (in reverse param order so the first
		// param binds outermost): def f($x;$y): body → def f(x;y): x as $x | y as $y | body
		$bodyAst = $node['body'];
		foreach ( array_reverse( $params ) as $param ) {
			if ( $param['kind'] === 'value' ) {
				$bodyAst = [
					'type'    => 'bind',
					'expr'    => [ 'type' => 'call', 'name' => $param['name'], 'args' => [] ],
					'pattern' => [ 'type' => 'var_pattern', 'name' => $param['name'] ],
					'body'    => $bodyAst,
				];
			}
		}

		$bodyFn = $this->compileNode( $bodyAst );
		$restFn = $this->compileNode( $node['rest'] );

		if ( $arity === 0 ) {
			return static function ( mixed $input, JQEnv $env ) use ( $name, $bodyFn, $restFn ): Generator {
				$defEnvRef = $env;  // placeholder; overwritten below before first use
				$binding = static function ( mixed $in, JQEnv $e ) use ( $bodyFn, &$defEnvRef ): Generator {
					// Propagate path mode from the call site into the body (same as
					// n-arity defs) so that structural operations inside the def
					// yield path-wrapped values when invoked inside path/1.
					$effectiveEnv = $defEnvRef->leavePathMode();
					if ( $e->isPathMode() ) {
						$effectiveEnv = $effectiveEnv->enterPathMode();
						foreach ( $e->getPath() as $key ) {
							$effectiveEnv = $effectiveEnv->appendPath( $key );
						}
					}
					yield from $bodyFn( $in, $effectiveEnv );
				};
				$newEnv = $env->bind( $name, 0, $binding );
				$defEnvRef = $newEnv;
				yield from $restFn( $input, $newEnv );
			};
		}

		// n-arity: store a FunctionFactory in the env
		$filterNames = array_column( $params, 'name' );
		return static function ( mixed $input, JQEnv $env ) use ( $name, $arity, $filterNames, $bodyFn, $restFn ): Generator {
			$defEnvRef = $env;  // placeholder; overwritten below before first use
			$factory = static function ( array $argFns ) use ( $filterNames, $bodyFn, &$defEnvRef ): Closure {
				return static function ( mixed $in, JQEnv $callEnv ) use ( $filterNames, $argFns, $bodyFn, &$defEnvRef ): Generator {
					// Start from the lexical env where the def was created.
					$bodyEnv = $defEnvRef;
					// Inject each filter param so it evaluates in the call-site env.
					foreach ( $filterNames as $i => $pName ) {
						$argFn = $argFns[$i];
						$bodyEnv = $bodyEnv->bind( $pName, 0,
							static function ( mixed $argIn, JQEnv $env ) use ( $argFn, $callEnv ): Generator {
								$effectiveEnv = $callEnv->leavePathMode();
								if ( $env->isPathMode() ) {
									// Re-root the accumulated path onto $callEnv so that
									// call-site variable bindings and path mode are both
									// preserved.  Strip any path mode from $callEnv first
									// so enterPathMode() doesn't throw when called from
									// nested path-mode contexts.
									$effectiveEnv = $effectiveEnv->enterPathMode();
									foreach ( $env->getPath() as $key ) {
										$effectiveEnv = $effectiveEnv->appendPath( $key );
									}
								}
								yield from $argFn( $argIn, $effectiveEnv );
							}
						);
					}
					// Propagate path mode from the call site into the body so that
					// structural operations inside the def (identity, field, iter…)
					// yield path-wrapped values when invoked inside path/1.
					if ( $callEnv->isPathMode() ) {
						$bodyPathEnv = $bodyEnv->enterPathMode();
						foreach ( $callEnv->getPath() as $key ) {
							$bodyPathEnv = $bodyPathEnv->appendPath( $key );
						}
						yield from $bodyFn( $in, $bodyPathEnv );
					} else {
						yield from $bodyFn( $in, $bodyEnv );
					}
				};
			};
			// @phan-suppress-next-line PhanTypeMismatchArgumentSuperType
			$newEnv = $env->bind( $name, $arity, $factory );
			$defEnvRef = $newEnv;
			yield from $restFn( $input, $newEnv );
		};
	}

	/**
	 * Compile a call node (name or name(arg; ...)).
	 *
	 * Arg filters are compiled once here at compile time and captured in the
	 * returned closure. At runtime:
	 *  - 0-arity: the stored value is a plain Filter; call it directly.
	 *  - n-arity: the stored value is a FunctionFactory; pass the compiled arg
	 *    closures to get a Filter, then run the Filter with the call-site env.
	 *
	 * @param array $node Node with 'name' and 'args' keys
	 * @return Closure(mixed,JQEnv):Generator a Filter
	 */
	private function compileCall( array $node ): Closure {
		$name   = $node['name'];
		$arity  = count( $node['args'] );
		$argFns = array_map(
			fn ( $arg ) => $this->compileNode( $arg ),
			$node['args']
		);

		if ( $arity === 0 ) {
			return static function ( mixed $input, JQEnv $env ) use ( $name ): Generator {
				$fn = $env->lookup( $name, 0 );
				if ( $fn === null ) {
					throw new JQError( $name . '/0 is not defined' );
				}
				yield from $fn( $input, $env );
			};
		}

		return static function ( mixed $input, JQEnv $env ) use ( $name, $arity, $argFns ): Generator {
			$factory = $env->lookup( $name, $arity );
			if ( $factory === null ) {
				throw new JQError( $name . '/' . $arity . ' is not defined' );
			}
			// @phan-suppress-next-line PhanParamTooFew
			$fn = ( $factory )( $argFns );
			// @phan-suppress-next-line PhanTypeInvalidCallable,PhanUndeclaredInvokeInCallable
			yield from $fn( $input, $env );
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
	 * @param array $node Node with 'cond', 'then', and 'else' keys
	 * @return Closure(mixed,JQEnv):Generator a Filter
	 */
	private function compileIf( array $node ): Closure {
		$condFn = $this->compileNode( $node['cond'] );
		$thenFn = $this->compileNode( $node['then'] );
		$elseFn = $this->compileNode( $node['else'] );
		return static function ( mixed $input, JQEnv $env ) use ( $condFn, $thenFn, $elseFn ): Generator {
			// Condition must be evaluated in normal mode (it produces a boolean,
			// not a path), even when the enclosing expression is in path mode.
			foreach ( $condFn( $input, $env->leavePathMode() ) as $condVal ) {
				if ( JQUtils::toBoolean( $condVal ) ) {
					yield from $thenFn( $input, $env );
				} else {
					yield from $elseFn( $input, $env );
				}
			}
		};
	}

	/**
	 * Compile a comma node (left, right).
	 * Yields all outputs of left, then all outputs of right.
	 *
	 * @param array $node Node with 'left' and 'right' keys
	 * @return Closure(mixed,JQEnv):Generator a Filter
	 */
	private function compileComma( array $node ): Closure {
		$leftFn  = $this->compileNode( $node['left'] );
		$rightFn = $this->compileNode( $node['right'] );
		return static function ( mixed $input, JQEnv $env ) use ( $leftFn, $rightFn ): Generator {
			yield from $leftFn( $input, $env );
			yield from $rightFn( $input, $env );
		};
	}

	/**
	 * Compile an array constructor node ([expr]).
	 * Collects every output of the inner expression into a single PHP list.
	 * [empty_expr] produces an empty array.
	 *
	 * @param array $node Node with nullable 'expr' key
	 * @return Closure(mixed,JQEnv):Generator a Filter
	 */
	private function compileArray( array $node ): Closure {
		if ( $node['expr'] === null ) {
			return static function ( mixed $input, JQEnv $env ): Generator {
				yield [];
			};
		}
		$exprFn = $this->compileNode( $node['expr'] );
		return static function ( mixed $input, JQEnv $env ) use ( $exprFn ): Generator {
			// Array construction always produces a new value, never a path extension.
			$items = [];
			foreach ( $exprFn( $input, $env->leavePathMode() ) as $val ) {
				$items[] = $val;
			}
			JQUtils::assertNotPath( $items, $env );
			yield $items;
		};
	}

	/**
	 * Compile an object constructor node ({k1: v1, k2: v2, ...}).
	 *
	 * Each key and value is an arbitrary filter. Multiple outputs from a key
	 * or value expression multiply the number of output objects (Cartesian
	 * product over pairs, evaluated left-to-right). An empty pair list yields
	 * a single empty associative array.
	 *
	 * @param array $node Node with 'pairs' key (array of {key, value} nodes)
	 * @return Closure(mixed,JQEnv):Generator a Filter
	 */
	private function compileObject( array $node ): Closure {
		$pairFns = array_map(
			fn ( $pair ) => [ $this->compileNode( $pair['key'] ), $this->compileNode( $pair['value'] ) ],
			$node['pairs']
		);
		return static function ( mixed $input, JQEnv $env ) use ( $pairFns ): Generator {
			// Create the objects as associative arrays in order to
			// benefit from copy-on-write value semantics
			$objects = [ [] ];
			foreach ( $pairFns as [ $keyFn, $valFn ] ) {
				$next = [];
				foreach ( $objects as $obj ) {
					foreach ( $keyFn( $input, $env ) as $key ) {
						foreach ( $valFn( $input, $env ) as $val ) {
							$newObj = $obj;
							if ( !( is_string( $key ) || is_numeric( $key ) ) ) {
								throw new JQError( "Cannot use " . JQUtils::typeName( $key ) . " as object key" );
							}
							$newObj[(string)$key] = $val;
							$next[] = $newObj;
						}
					}
				}
				$objects = $next;
			}
			foreach ( $objects as $obj ) {
				// Convert constructed arrays into objects
				$result = (object)$obj;
				JQUtils::assertNotPath( $result, $env );
				yield $result;
			}
		};
	}

	/**
	 * Compile a bind node (expr as $pat | body).
	 *
	 * For each output of expr, match it against the pattern and evaluate
	 * body in the extended environment. Two important points:
	 *
	 * - Body receives the original $input, not the bound value. This is
	 *   what distinguishes "as" from a pipe: . stays the same in body.
	 * - $innerEnv flows only into body, never outward. Bindings introduced
	 *   here are invisible outside the body, giving correct lexical scoping.
	 *
	 * @param array $node Node with 'expr', 'pattern', and 'body' keys
	 * @return Closure(mixed,JQEnv):Generator a Filter
	 */
	private function compileBind( array $node ): Closure {
		$srcFn  = $this->compileNode( $node['expr'] );
		$bodyFn = $this->compileNode( $node['body'] );
		$patFn  = $this->compilePattern( $node['pattern'] );
		return static function ( mixed $input, JQEnv $env ) use ( $srcFn, $bodyFn, $patFn ): Generator {
			foreach ( $srcFn( $input, $env ) as $val ) {
				foreach ( $patFn( $val, $env ) as $innerEnv ) {
					yield from $bodyFn( $input, $innerEnv );
				}
			}
		};
	}

	/**
	 * Recursive helper for obj_pattern matching.
	 *
	 * For each value yielded by the field's key function, validates it is a
	 * string, looks up that field in $val, runs the field's pattern matcher,
	 * then recurses into the remaining fields.  Produces one output environment
	 * per combination of key values, matching jq's multi-output semantics.
	 *
	 * @param stdClass $val
	 * @param JQEnv $env
	 * @param list<array{Closure,Closure}> $fields
	 * @param int $idx
	 */
	private static function matchObjFields(
		stdClass $val, JQEnv $env, array $fields, int $idx
	): Generator {
		if ( $idx >= count( $fields ) ) {
			yield $env;
			return;
		}
		[ $keyFn, $fieldFn ] = $fields[$idx];
		foreach ( $keyFn( $val, $env ) as $k ) {
			$fieldName = JQUtils::checkString( 'object index', $k );
			foreach ( $fieldFn( $val->$fieldName ?? null, $env ) as $nextEnv ) {
				yield from self::matchObjFields( $val, $nextEnv, $fields, $idx + 1 );
			}
		}
	}

	/** Collect all variable names (with leading $) bound by a pattern, recursively. */
	private static function collectPatternVars( array $pat ): array {
		return match ( $pat['type'] ) {
			'var_pattern' => [ '$' . $pat['name'] ],
			'array_pattern' => array_merge(
				...array_map( self::collectPatternVars( ... ), $pat['elems'] )
			),
			'obj_pattern' => array_merge(
				...array_map(
					self::collectPatternVars( ... ),
					array_map( static fn ( $f )=>$f['pattern'], $pat['fields'] )
				)
			),
			'and_pattern' => array_merge(
				...array_map( self::collectPatternVars( ... ), $pat['patterns'] )
			),
			'alt_pattern' => array_unique( array_merge(
				...array_map( self::collectPatternVars( ... ), $pat['patterns'] )
			) ),
			default => [],
		};
	}

	/**
	 * Compile a pattern into a Matcher closure.
	 *
	 * The pattern AST is traversed once here at compile time; sub-patterns
	 * are recursively compiled and captured in the returned closure.
	 * Type mismatches throw JQError at runtime so that alt_pattern (?//)
	 * can catch and try the next alternative. A var_pattern always succeeds.
	 *
	 * @return Closure(mixed $val, JQEnv $env): Generator  Matcher
	 */
	private function compilePattern( array $pat ): Closure {
		return match ( $pat['type'] ) {
			'var_pattern'   => $this->compilePatternVar( $pat ),
			'array_pattern' => $this->compilePatternArray( $pat ),
			'obj_pattern'   => $this->compilePatternObj( $pat ),
			'and_pattern'   => $this->compilePatternAnd( $pat ),
			'alt_pattern'   => $this->compilePatternAlt( $pat ),
			default => throw new LogicException( 'Unknown pattern type: ' . $pat['type'] ),
		};
	}

	/** Compile a var_pattern ($x): always succeeds, binding the value to $x. */
	private function compilePatternVar( array $pat ): Closure {
		$key = '$' . $pat['name'];
		return static function ( mixed $val, JQEnv $env ) use ( $key ): Generator {
			yield $env->bind( $key, 0,
				static function ( mixed $input, JQEnv $e ) use ( $val ): Generator {
					yield $val;
				}
			);
		};
	}

	/** Compile an array_pattern ([$a, $b, ...]): checks type, then matches each element. */
	private function compilePatternArray( array $pat ): Closure {
		$elemFns = array_map( fn ( $p ) => $this->compilePattern( $p ), $pat['elems'] );
		return static function ( mixed $val, JQEnv $env ) use ( $elemFns ): Generator {
			$val = JQUtils::checkArray( 'array_pattern', $val );
			$envs = [ $env ];
			foreach ( $elemFns as $i => $elemFn ) {
				$nextEnvs = [];
				foreach ( $envs as $currentEnv ) {
					foreach ( $elemFn( $val[$i] ?? null, $currentEnv ) as $e ) {
						$nextEnvs[] = $e;
					}
				}
				if ( $nextEnvs === [] ) {
					return;
				}
				$envs = $nextEnvs;
			}
			yield from $envs;
		};
	}

	/** Compile an obj_pattern ({key: pat, ...}): checks type, then matches each field. */
	private function compilePatternObj( array $pat ): Closure {
		$fields = [];
		foreach ( $pat['fields'] as $field ) {
			$fields[] = [
				$this->compileNode( $field['key'] ),
				$this->compilePattern( $field['pattern'] ),
			];
		}
		return static function ( mixed $val, JQEnv $env ) use ( $fields ): Generator {
			$val = JQUtils::checkObject( 'obj_pattern', $val );
			yield from self::matchObjFields( $val, $env, $fields, 0 );
		};
	}

	/**
	 * Compile an and_pattern: applies each sub-pattern to the same value,
	 * threading the env and accumulating all combinations.
	 * Generated for {$b: subpat}, which must bind $b AND match subpat.
	 */
	private function compilePatternAnd( array $pat ): Closure {
		$patFns = array_map( $this->compilePattern( ... ), $pat['patterns'] );
		return static function ( mixed $val, JQEnv $env ) use ( $patFns ): Generator {
			$envs = [ $env ];
			foreach ( $patFns as $patFn ) {
				$nextEnvs = [];
				foreach ( $envs as $currentEnv ) {
					foreach ( $patFn( $val, $currentEnv ) as $e ) {
						$nextEnvs[] = $e;
					}
				}
				if ( $nextEnvs === [] ) {
					return;
				}
				$envs = $nextEnvs;
			}
			yield from $envs;
		};
	}

	/**
	 * Compile an alt_pattern (p1 ?// p2 ?// ...): tries each alternative in
	 * order, yielding from the first that succeeds. Variables bound only in
	 * non-matching alternatives are null-filled in the resulting env.
	 */
	private function compilePatternAlt( array $pat ): Closure {
		$altFns = array_map( $this->compilePattern( ... ), $pat['patterns'] );
		$perAltVars = array_map( self::collectPatternVars( ... ), $pat['patterns'] );
		$allVars = array_unique( array_merge( ...$perAltVars ) );
		$missingPerAlt = array_map(
			static fn ( $altVars ) => array_values( array_diff( $allVars, $altVars ) ),
			$perAltVars
		);
		$nullFn = $this->compileLiteral( [ 'value' => null ] );
		return static function ( mixed $val, JQEnv $env ) use ( $altFns, $missingPerAlt, $nullFn ): Generator {
			foreach ( $altFns as $i => $altFn ) {
				try {
					foreach ( $altFn( $val, $env ) as $nextEnv ) {
						foreach ( $missingPerAlt[$i] as $var ) {
							$nextEnv = $nextEnv->bind( $var, 0, $nullFn );
						}
						yield $nextEnv;
						return;
					}
				} catch ( JQError ) {
					// this alternative failed; try the next one
				}
			}
			// all alternatives failed — yield nothing
		};
	}

	/**
	 * Compile a comparison node (left op right).
	 * Both operands are evaluated against the original $input (not piped).
	 * Yields one boolean per combination of left and right outputs.
	 *
	 * == and != use structural JSON equality (int/float are the same numeric
	 * type; arrays and objects are compared recursively).
	 * <, <=, >, >= use jq's cross-type ordering: null < false < true <
	 * number < string < array < object; within each type, the natural order.
	 *
	 * @param array $node Node with 'op', 'left', and 'right' keys
	 * @return Closure(mixed,JQEnv):Generator a Filter
	 */
	private function compileCompare( array $node ): Closure {
		$leftFn  = $this->compileNode( $node['left'] );
		$rightFn = $this->compileNode( $node['right'] );
		$op      = match ( $node['op'] ) {
			'==' => JQUtils::equal( ... ),
			'!=' => static fn ( $lv, $rv ) => !JQUtils::equal( $lv, $rv ),
			'<'  => static fn ( $lv, $rv ) => JQUtils::compare( $lv, $rv ) < 0,
			'<=' => static fn ( $lv, $rv ) => JQUtils::compare( $lv, $rv ) <= 0,
			'>'  => static fn ( $lv, $rv ) => JQUtils::compare( $lv, $rv ) > 0,
			'>=' => static fn ( $lv, $rv ) => JQUtils::compare( $lv, $rv ) >= 0,
			default => throw new LogicException(
				'Unknown comparison operator: ' . $node['op']
			),
		};
		return static function ( mixed $input, JQEnv $env ) use ( $leftFn, $rightFn, $op ): Generator {
			foreach ( $leftFn( $input, $env ) as $lv ) {
				foreach ( $rightFn( $input, $env ) as $rv ) {
					yield $op( $lv, $rv );
				}
			}
		};
	}

	/**
	 * Compile an 'and' node. Left-to-right, short-circuits on falsy left:
	 * yields false without evaluating right. If left is truthy, yields
	 * bool(right) for each output of right. Uses jq truthiness (null and
	 * false are falsy; everything else, including 0 and "", is truthy).
	 */
	private function compileAnd( array $node ): Closure {
		$leftFn  = $this->compileNode( $node['left'] );
		$rightFn = $this->compileNode( $node['right'] );
		return static function ( mixed $input, JQEnv $env ) use ( $leftFn, $rightFn ): Generator {
			foreach ( $leftFn( $input, $env ) as $lv ) {
				if ( !JQUtils::toBoolean( $lv ) ) {
					yield false;
				} else {
					foreach ( $rightFn( $input, $env ) as $rv ) {
						yield JQUtils::toBoolean( $rv );
					}
				}
			}
		};
	}

	/**
	 * Compile an 'or' node. Left-to-right, short-circuits on truthy left:
	 * yields true without evaluating right. If left is falsy, yields
	 * bool(right) for each output of right.
	 */
	private function compileOr( array $node ): Closure {
		$leftFn  = $this->compileNode( $node['left'] );
		$rightFn = $this->compileNode( $node['right'] );
		return static function ( mixed $input, JQEnv $env ) use ( $leftFn, $rightFn ): Generator {
			foreach ( $leftFn( $input, $env ) as $lv ) {
				if ( JQUtils::toBoolean( $lv ) ) {
					yield true;
				} else {
					foreach ( $rightFn( $input, $env ) as $rv ) {
						yield JQUtils::toBoolean( $rv );
					}
				}
			}
		};
	}

	/**
	 * Compile an iter node (expr[] or expr[]?).
	 * Iterates over arrays (yielding each element) and objects (yielding each
	 * value in insertion order). null and other non-iterable types throw
	 * JQError, suppressed to empty output when opt is true.
	 *
	 * @param array $node Node with 'expr' and 'opt' keys
	 * @return Closure(mixed,JQEnv):Generator a Filter
	 */
	private function compileIter( array $node ): Closure {
		$exprFn = $this->compileNode( $node['expr'] );
		$opt    = $node['opt'];
		return static function ( mixed $input, JQEnv $env ) use ( $exprFn, $opt ): Generator {
			foreach ( $exprFn( $input, $env ) as $item ) {
				[ $baseEnv, $base ] = $env->maybeUnwrapPath( $item );
				try {
					if ( is_object( $base ) ) {
						foreach ( get_object_vars( $base ) as $k => $v ) {
							yield $baseEnv->appendPath( $k )->maybeWithPath( $v );
						}
					} elseif ( is_array( $base ) ) {
						foreach ( $base as $k => $v ) {
							yield $baseEnv->appendPath( $k )->maybeWithPath( $v );
						}
					} else {
						throw new JQError( 'Cannot iterate over ' . JQUtils::typeName( $base ) . ' (' . JQUtils::toString( $base ) . ')' );
					}
				} catch ( JQError $e ) {
					if ( !$opt ) {
						throw $e;
					}
				}
			}
		};
	}

	/**
	 * Compile a unary negation node (-expr).
	 * Yields -$v for each numeric value; throws JQError for non-numbers.
	 *
	 * @param array $node Node with 'expr' key
	 * @return Closure(mixed,JQEnv):Generator a Filter
	 */
	private function compileNeg( array $node ): Closure {
		$exprFn = $this->compileNode( $node['expr'] );
		return static function ( mixed $input, JQEnv $env ) use ( $exprFn ): Generator {
			foreach ( $exprFn( $input, $env ) as $v ) {
				$v = JQUtils::checkNumber( 'negation', $v );
				yield -$v;
			}
		};
	}

	/**
	 * Compile a field-access node (expr.name or expr.name?).
	 * null input yields null; object input yields the field value (or null if
	 * absent); any other type throws JQError (suppressed to empty if opt).
	 *
	 * @param array $node Node with 'expr', 'name', and 'opt' keys
	 * @return Closure(mixed,JQEnv):Generator a Filter
	 */
	private function compileField( array $node ): Closure {
		$exprFn = $this->compileNode( $node['expr'] );
		$name   = $node['name'];
		$opt    = $node['opt'];
		return static function ( mixed $input, JQEnv $env ) use ( $exprFn, $name, $opt ): Generator {
			foreach ( $exprFn( $input, $env ) as $item ) {
				[ $baseEnv, $base ] = $env->maybeUnwrapPath( $item );
				if ( $base === null ) {
					yield $baseEnv->appendPath( $name )->maybeWithPath( null );
					continue;
				} elseif ( $opt && !is_object( $base ) ) {
					continue;
				}
				$obj = JQUtils::checkObject( 'field', $base );
				yield $baseEnv->appendPath( $name )->maybeWithPath( $obj->$name ?? null );
			}
		};
	}

	/**
	 * Compile an index node (expr[key] or expr[key]?).
	 * The key expression is evaluated against the original input (not the base).
	 * Supports object indexing by string and array indexing by integer
	 * (with negative indices counting from the end). null input yields null.
	 *
	 * @param array $node Node with 'expr', 'key', and 'opt' keys
	 * @return Closure(mixed,JQEnv):Generator a Filter
	 */
	private function compileIndex( array $node ): Closure {
		$exprFn = isset( $node['expr'] ) ?
			$this->compileNode( $node['expr'] ) : $this->compileIdentity();
		$keyFn  = $this->compileNode( $node['key'] );
		$opt    = $node['opt'];
		return static function ( mixed $input, JQEnv $env ) use ( $exprFn, $keyFn, $opt ): Generator {
			foreach ( $exprFn( $input, $env ) as $item ) {
				[ $baseEnv, $base ] = $env->maybeUnwrapPath( $item );
				// The key expression sees the original $input, not $base.
				// e.g. in .a[.b], .b is evaluated against the outer input,
				// not against the result of .a.  The key expression is also
				// always evaluated in normal (non-path) mode: the key determines
				// which slot to access; it is not itself a path to collect.
				foreach ( $keyFn( $input, $env->leavePathMode() ) as $key ) {
					if ( $base === null ) {
						yield $baseEnv->appendPath( $key )->maybeWithPath( null );
					} elseif ( is_object( $base ) ) {
						if ( $opt && !is_string( $key ) ) {
							continue;
						}
						$key = JQUtils::checkString( 'index', $key );
						yield $baseEnv->appendPath( $key )->maybeWithPath( $base->$key ?? null );
					} elseif ( is_array( $base ) ) {
						JQUtils::assertIsList( 'index', $base );
						if ( $opt && !JQUtils::isNumber( $key ) ) {
							continue;
						}
						$index = JQUtils::adjustIndex( 'index', $key, $base );
						yield $baseEnv->appendPath( (int)$key )->maybeWithPath(
							( $index === null ) ? null : $base[$index]
						);
					} elseif ( !$opt ) {
						throw new JQError(
							'Cannot index ' . JQUtils::typeName( $base ) .
								' with ' . JQUtils::typeName( $key )
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
	 * @param array $node Node with 'fmt' (null or format name) and 'parts' keys
	 * @return Closure(mixed,JQEnv):Generator a Filter
	 */
	private function compileString( array $node ): Closure {
		$formatter = JQUtils::formatterFor( $node['fmt'] ?? 'text' );
		// XXX seems like we should be able to precompile this a bit
		// more aggressively.
		$compiledParts = [];
		foreach ( $node['parts'] as $part ) {
			if ( $part['type'] === 'str_interp' ) {
				$compiledParts[] = [ 'interp', $this->compileNode( $part['expr'] ) ];
			} else {
				$compiledParts[] = [ 'text', $part['text'] ];
			}
		}
		return static function ( mixed $input, JQEnv $env ) use ( $formatter, $compiledParts ): Generator {
			$strings = [ '' ];
			foreach ( $compiledParts as [ $kind, $data ] ) {
				if ( $kind === 'text' ) {
					$strings = array_map( static fn ( $s ) => $s . $data, $strings );
				} else {
					$next = [];
					foreach ( $strings as $prefix ) {
						foreach ( $data( $input, $env ) as $val ) {
							$next[] = $prefix . $formatter( $val );
						}
					}
					$strings = $next;
				}
			}
			yield from $strings;
		};
	}

	/**
	 * Compile a standalone format node (@base64, @html, etc.).
	 * Applies the named format to the input value directly.
	 *
	 * @param array $node Node with 'fmt' key
	 * @return Closure(mixed,JQEnv):Generator a Filter
	 */
	private function compileFormat( array $node ): Closure {
		$formatter = JQUtils::formatterFor( $node['fmt'] );
		return static function ( mixed $input, JQEnv $env ) use ( $formatter ): Generator {
			yield $formatter( $input );
		};
	}

	/**
	 * Compile a binary operation node (left op right).
	 * Evaluates both sides against the original input, then applies the
	 * operator to each combination of outputs.
	 *
	 * @param array $node Node with 'op', 'left', and 'right' keys
	 * @return Closure(mixed,JQEnv):Generator a Filter
	 */
	private function compileBinop( array $node ): Closure {
		$leftFn  = $this->compileNode( $node['left'] );
		$rightFn = $this->compileNode( $node['right'] );
		$op      = match ( $node['op'] ) {
			'+' => JQUtils::add( ... ),
			'-' => JQUtils::subtract( ... ),
			'*' => JQUtils::multiply( ... ),
			'/' => JQUtils::divide( ... ),
			'%' => JQUtils::modulo( ... ),
			default => throw new LogicException( 'Unknown operator: ' . $node['op'] ),
		};
		// jq evaluates right first (outer loop) then left (inner loop).
		return static function ( mixed $input, JQEnv $env ) use ( $leftFn, $rightFn, $op ): Generator {
			foreach ( $rightFn( $input, $env ) as $rv ) {
				foreach ( $leftFn( $input, $env ) as $lv ) {
					yield $op( $lv, $rv );
				}
			}
		};
	}

	/**
	 * Compile an alternative node (left // right).
	 * Evaluates left; yields all non-false/non-null outputs. If none were
	 * yielded, evaluates right and yields all its outputs instead.
	 *
	 * @param array $node Node with 'left' and 'right' keys
	 * @return Closure(mixed,JQEnv):Generator a Filter
	 */
	private function compileAlternative( array $node ): Closure {
		$leftFn  = $this->compileNode( $node['left'] );
		$rightFn = $this->compileNode( $node['right'] );
		return static function ( mixed $input, JQEnv $env ) use ( $leftFn, $rightFn ): Generator {
			$found = false;
			foreach ( $leftFn( $input, $env ) as $val ) {
				if ( JQUtils::toBoolean( $val ) ) {
					yield $val;
					$found = true;
				}
			}
			if ( !$found ) {
				yield from $rightFn( $input, $env );
			}
		};
	}

	/**
	 * Compile a try node (try body catch handler).
	 * Evaluates body; if a JQError is thrown, catches it and either
	 * evaluates handler with the error message as input, or produces no
	 * output if there is no catch clause.
	 *
	 * @param array $node Node with 'body' and nullable 'catch' keys
	 * @return Closure(mixed,JQEnv):Generator a Filter
	 */
	private function compileTryCatch( array $node ): Closure {
		$bodyFn  = $this->compileNode( $node['body'] );
		$catchFn = $node['catch'] !== null ? $this->compileNode( $node['catch'] ) : null;
		return static function ( mixed $input, JQEnv $env ) use ( $bodyFn, $catchFn ): Generator {
			try {
				yield from $bodyFn( $input, $env );
			} catch ( JQError $e ) {
				if ( $catchFn !== null ) {
					// The catch handler receives the error value, not a path;
					// always run it in normal mode.
					yield from $catchFn( $e->jqValue, $env->leavePathMode() );
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
	 * @param array $node Node with 'src', 'pattern', 'init', and 'update' keys
	 * @return Closure(mixed,JQEnv):Generator a Filter
	 */
	private function compileReduce( array $node ): Closure {
		$srcFn    = $this->compileNode( $node['src'] );
		$initFn   = $this->compileNode( $node['init'] );
		$updateFn = $this->compileNode( $node['update'] );
		$patFn    = $this->compilePattern( $node['pattern'] );
		return static function ( mixed $input, JQEnv $env ) use ( $srcFn, $initFn, $updateFn, $patFn ): Generator {
			$acc = null;
			foreach ( $initFn( $input, $env ) as $initVal ) {
				$acc = $initVal;
				break;
			}
			foreach ( $srcFn( $input, $env ) as $val ) {
				foreach ( $patFn( $val, $env ) as $boundEnv ) {
					foreach ( $updateFn( $acc, $boundEnv ) as $newAcc ) {
						$acc = $newAcc;
						break;
					}
					break;
				}
			}
			yield $acc;
		};
	}

	/**
	 * Compile a foreach node (foreach src as $pat (init; update) or
	 * foreach src as $pat (init; update; extract)).
	 * Like reduce but yields the accumulator (or extract output) after each step.
	 *
	 * @param array $node Node with 'src', 'pattern', 'init', 'update', and nullable 'extract' keys
	 * @return Closure(mixed,JQEnv):Generator a Filter
	 */
	private function compileForeach( array $node ): Closure {
		$srcFn     = $this->compileNode( $node['src'] );
		$initFn    = $this->compileNode( $node['init'] );
		$updateFn  = $this->compileNode( $node['update'] );
		$patFn     = $this->compilePattern( $node['pattern'] );
		$extractFn = $node['extract'] !== null ? $this->compileNode( $node['extract'] ) : null;
		return static function ( mixed $input, JQEnv $env ) use ( $srcFn, $initFn, $updateFn, $patFn, $extractFn ): Generator {
			$acc = null;
			foreach ( $initFn( $input, $env ) as $initVal ) {
				$acc = $initVal;
				break;
			}
			foreach ( $srcFn( $input, $env ) as $val ) {
				foreach ( $patFn( $val, $env ) as $boundEnv ) {
					foreach ( $updateFn( $acc, $boundEnv ) as $newAcc ) {
						$acc = $newAcc;
						break;
					}
					if ( $extractFn !== null ) {
						yield from $extractFn( $acc, $boundEnv );
					} else {
						yield $acc;
					}
					break;
				}
			}
		};
	}

	/**
	 * Compile a slice node (expr[from:to] or expr[from:to]?).
	 * Applies to arrays (returns subarray) and strings (returns substring).
	 * Null input yields null; other types throw JQError (suppressed when opt).
	 *
	 * @param array $node Node with 'expr', 'from', 'to', and 'opt' keys
	 * @return Closure(mixed,JQEnv):Generator a Filter
	 */
	private function compileSlice( array $node ): Closure {
		$exprFn = $this->compileNode( $node['expr'] );
		$yieldNull = $this->compileLiteral( [ 'value' => null ] );
		$fromFn = $node['from'] !== null ? $this->compileNode( $node['from'] ) : $yieldNull;
		$toFn   = $node['to'] !== null ? $this->compileNode( $node['to'] ) : $yieldNull;
		$opt    = $node['opt'];
		return static function ( mixed $input, JQEnv $env ) use ( $exprFn, $fromFn, $toFn, $opt ): Generator {
			foreach ( $exprFn( $input, $env ) as $item ) {
				[ $baseEnv, $base ] = $env->maybeUnwrapPath( $item );
				// from/to bounds are not part of the path; evaluate in normal mode.
				$normalEnv = $env->leavePathMode();
				foreach ( $fromFn( $input, $normalEnv ) as $from ) {
					foreach ( $toFn( $input, $normalEnv ) as $to ) {
						// In path mode yield the slice-path key alongside the
						// sliced value so that downstream ops (and delpaths) work.
						$sliceKey = (object)[ 'start' => $from, 'end' => $to ];
						foreach ( JQUtils::slice( $base, $from, $to, $opt ) as $sliceVal ) {
							yield $baseEnv->appendPath( $sliceKey )->maybeWithPath( $sliceVal );
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
	 * For op= (+=, -=, *=, /=, %=, //=): desugars to lhs |= (. op rhs).
	 *
	 * @param array $node Node with 'op', 'left', and 'right' keys
	 * @return Closure(mixed,JQEnv):Generator a Filter
	 */
	private function compileAssign( array $node ): Closure {
		$op      = $node['op'];
		$lhsNode = $node['left'];

		// Desugar compound ops to |= : "lhs op= rhs" → "lhs |= (. op rhs)"
		$updateFn = match ( $op ) {
			'=',
			'|='  => $this->compileNode( $node['right'] ),
			'//=' => $this->compileAlternative( [ 'left' => [ 'type' => 'identity' ], 'right' => $node['right'] ] ),
			default => $this->compileBinop( [
				'op'    => rtrim( $op, '=' ),
				'left'  => [ 'type' => 'identity' ],
				'right' => $node['right'],
			] ),
		};

		if ( $op === '=' ) {
			return $this->compileAssignSet( $lhsNode, $updateFn );
		}
		return $this->compileAssignUpdate( $lhsNode, $updateFn );
	}

	/**
	 * Compile "pathNode = rhsFn" — for each value produced by rhsFn, set every
	 * path produced by pathNode to that value and yield the updated input.
	 *
	 * @param array $pathNode AST path node
	 * @param Closure(mixed,JQEnv):Generator $rhsFn compiled RHS expression
	 * @return Closure(mixed,JQEnv):Generator a Filter
	 */
	private function compileAssignSet( array $pathNode, Closure $rhsFn ): Closure {
		$pathFn = $this->compileNode( $pathNode );
		return static function ( mixed $input, JQEnv $env ) use ( $pathFn, $rhsFn ): Generator {
			$pathEnv = $env->enterPathMode();
			foreach ( $rhsFn( $input, $env ) as $newVal ) {
				$result = $input;
				foreach ( $pathFn( $input, $pathEnv ) as $item ) {
					$result = self::setAtPath( $result, $pathEnv->extractPath( $item ), 0, $newVal );
				}
				yield $result;
			}
		};
	}

	/**
	 * Compile "pathNode |= updateFn" — apply updateFn to every slot produced by
	 * pathNode and write the result back.  Slots for which updateFn yields nothing
	 * are deleted (jq |= empty semantics).  Array deletions are applied in reverse
	 * index order to preserve correct positions.
	 *
	 * @param array $pathNode AST path node
	 * @param Closure(mixed,JQEnv):Generator $updateFn
	 * @return Closure(mixed,JQEnv):Generator a Filter
	 */
	private function compileAssignUpdate( array $pathNode, Closure $updateFn ): Closure {
		$pathFn = $this->compileNode( $pathNode );
		return static function ( mixed $input, JQEnv $env ) use ( $pathFn, $updateFn ): Generator {
			$pathEnv  = $env->enterPathMode();
			$toDelete = [];
			foreach ( $pathFn( $input, $pathEnv ) as $item ) {
				$path      = $pathEnv->extractPath( $item );
				$current   = self::getAtPath( $input, $path, 0 );
				$hasOutput = false;
				foreach ( $updateFn( $current, $env ) as $newVal ) {
					$input     = self::setAtPath( $input, $path, 0, $newVal );
					$hasOutput = true;
					break;
				}
				if ( !$hasOutput ) {
					$toDelete[] = $path;
				}
			}
			foreach ( array_reverse( $toDelete ) as $path ) {
				$input = self::deleteAtPath( $input, $path, 0 );
			}
			yield $input;
		};
	}

	/**
	 * Navigate to the value at $path within $val.
	 * null propagates (null[x] → null); out-of-bounds returns null.
	 *
	 * @param mixed $val
	 * @param list<int|float|string> $path
	 * @param int $offset The current offset into $path
	 */
	private static function getAtPath( mixed $val, array $path, int $offset ): mixed {
		if ( $offset >= count( $path ) ) {
			return $val;
		}
		$key = $path[$offset++];
		if ( is_string( $key ) ) {
			if ( is_object( $val ) && property_exists( $val, $key ) ) {
				return self::getAtPath( $val->$key, $path, $offset );
			}
			return null;
		} elseif ( JQUtils::isNumber( $key ) ) {
			if ( !is_array( $val ) ) {
				return null;
			}
			$idx = JQUtils::adjustIndex( 'getAtPath', $key, $val );
			if ( $idx === null ) {
				return null;
			}
			return self::getAtPath( $val[$idx], $path, $offset );
		}
		return null;
	}

	/**
	 * Return $container with $newVal written at $path.
	 * Promotes null → [] or stdClass based on key type; fills array gaps with null.
	 * Throws JQError for out-of-bounds negative indices and oversized indices.
	 *
	 * @param mixed $container
	 * @param list<int|float|string> $path
	 * @param int $offset The current offset into $path
	 * @param mixed $newVal The value we expect to set at the end of the path
	 */
	private static function setAtPath( mixed $container, array $path, int $offset, mixed $newVal ): mixed {
		if ( $offset >= count( $path ) ) {
			return $newVal;
		}
		$key = $path[$offset++];
		if ( is_string( $key ) ) {
			// null is promoted to object.
			$container ??= (object)[];
			$container = JQUtils::checkObject( 'setAtPath', $container );
			$newObj = clone $container;
			$newObj->$key = self::setAtPath(
				$container->$key ?? null, $path, $offset, $newVal
			);
			return $newObj;
		}
		if ( JQUtils::isNumber( $key ) ) {
			// null is promoted to array
			$container ??= [];
			$container = JQUtils::checkArray( 'setAtPath', $container );
			$index = (int)$key;
			if ( $index < 0 ) {
				$index += count( $container );
			}
			if ( $index < 0 ) {
				throw new JQError( 'Out of bounds negative array index' );
			}
			if ( $index >= JQUtils::MAX_SIZE ) {
				throw new JQError( 'Array index too large' );
			}
			$newArr = $container;
			// Maintain array as list by padding with nulls
			while ( count( $newArr ) < $index ) {
				$newArr[] = null;
			}
			$newArr[$index] = self::setAtPath(
				$container[$index] ?? null, $path, $offset, $newVal
			);
			return $newArr;
		}
		// Slice-path key: (object)['start' => ..., 'end' => ...] produced by
		// compileSlice in path mode.  Splices the replacement array into position.
		if ( is_object( $key ) && is_array( $container ) ) {
			JQUtils::assertIsList( 'setAtPath', $container );
			$len  = count( $container );
			$f    = JQUtils::normalizeSliceIdx( $key->start ?? null, $len, 0 );
			$t    = JQUtils::normalizeSliceIdx( $key->end ?? null, $len, $len );
			$repl = is_array( $newVal ) ? $newVal : [];
			$newArr = $container;
			array_splice( $newArr, $f, max( 0, $t - $f ), $repl );
			return array_values( $newArr );
		}
		return $container;
	}

	/**
	 * Return $container with the slot at $path removed.
	 * Array elements are spliced out (later elements shift left); object keys are unset.
	 * Non-existent paths are silently ignored.
	 *
	 * @param mixed $container
	 * @param list<int|float|string> $path
	 * @param int $offset The current offset into $path
	 */
	public static function deleteAtPath( mixed $container, array $path, int $offset ): mixed {
		if ( $offset >= count( $path ) ) {
			return null;
		}
		$key = $path[$offset++];
		if ( $offset < count( $path ) ) {
			if ( is_string( $key ) && is_object( $container ) ) {
				$new       = clone $container;
				$new->$key = self::deleteAtPath( $container->$key ?? null, $path, $offset );
				return $new;
			}
			if ( JQUtils::isNumber( $key ) && is_array( $container ) ) {
				$idx = JQUtils::adjustIndex( 'deleteAtPath', $key, $container );
				if ( $idx !== null ) {
					$new       = $container;
					$new[$idx] = self::deleteAtPath( $container[$idx], $path, $offset );
					return $new;
				}
			}
			return $container;
		}
		if ( is_string( $key ) && is_object( $container ) ) {
			$newObj = clone $container;
			unset( $newObj->$key );
			return $newObj;
		}
		if ( JQUtils::isNumber( $key ) && is_array( $container ) ) {
			JQUtils::assertIsList( 'deleteAtPath', $container );
			$index = (int)$key;
			if ( $index < 0 ) {
				$index += count( $container );
			}
			if ( $index >= 0 && $index < count( $container ) ) {
				$newArr = $container;
				array_splice( $newArr, $index, 1 );
				return $newArr;
			}
		}
		// Slice-path key: (object)['start' => ..., 'end' => ...] produced by
		// compileSlice in path mode.  Splices the range out of the array.
		if ( is_object( $key ) && is_array( $container ) ) {
			JQUtils::assertIsList( 'deleteAtPath', $container );
			$len = count( $container );
			$f   = JQUtils::normalizeSliceIdx( $key->start ?? null, $len, 0 );
			$t   = JQUtils::normalizeSliceIdx( $key->end ?? null, $len, $len );
			$newArr = $container;
			array_splice( $newArr, $f, max( 0, $t - $f ) );
			return array_values( $newArr );
		}
		return $container;
	}
}
