<?php
// @phan-file-suppress PhanUnusedClosureParameter, PhanEmptyYieldFrom
declare( strict_types = 1 );

namespace Wikimedia\Zest;

use Closure;
use Generator;
use LogicException;

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
 *   JQError  — thrown by error/0, error/1, and type mismatches; caught by
 *              try-catch nodes and the ? suffix operator.
 *   JQBreak  — thrown by break/$label; propagates through try-catch nodes
 *              and is only caught by the matching label/$label node.
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
			yield $input;
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
			foreach ( $leftFn( $input, $env ) as $mid ) {
				yield from $rightFn( $mid, $env );
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
					yield from $bodyFn( $in, $defEnvRef );
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
							static function ( mixed $argIn, JQEnv $ignored ) use ( $argFn, $callEnv ): Generator {
								yield from $argFn( $argIn, $callEnv );
							}
						);
					}
					yield from $bodyFn( $in, $bodyEnv );
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
			foreach ( $condFn( $input, $env ) as $condVal ) {
				if ( $condVal !== null && $condVal !== false ) {
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
			$items = [];
			foreach ( $exprFn( $input, $env ) as $val ) {
				$items[] = $val;
			}
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
				yield (object)$obj;
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
		if ( $pat['type'] === 'var_pattern' ) {
			$key = '$' . $pat['name'];
			return static function ( mixed $val, JQEnv $env ) use ( $key ): Generator {
				yield $env->bind( $key, 0,
					static function ( mixed $input, JQEnv $e ) use ( $val ): Generator {
						yield $val;
					}
				);
			};
		} elseif ( $pat['type'] === 'array_pattern' ) {
			$elemFns = array_map( fn ( $p ) => $this->compilePattern( $p ), $pat['elems'] );
			return static function ( mixed $val, JQEnv $env ) use ( $elemFns ): Generator {
				if ( !is_array( $val ) || !array_is_list( $val ) ) {
					throw new JQError( 'Cannot destructure ' . JQUtils::typeName( $val ) . ' as array' );
				}
				$currentEnv = $env;
				foreach ( $elemFns as $i => $elemFn ) {
					$nextEnv = null;
					foreach ( $elemFn( $val[$i] ?? null, $currentEnv ) as $e ) {
						$nextEnv = $e;
						break;
					}
					if ( $nextEnv === null ) {
						return;
					}
					$currentEnv = $nextEnv;
				}
				yield $currentEnv;
			};
		} elseif ( $pat['type'] === 'obj_pattern' ) {
			$fields = [];
			foreach ( $pat['fields'] as $field ) {
				$fields[] = [
					$this->compileNode( $field['key'] ),
					$this->compilePattern( $field['pattern'] )
				];
			}
			return static function ( mixed $val, JQEnv $env ) use ( $fields ): Generator {
				if ( !is_object( $val ) ) {
					throw new JQError( 'Cannot destructure ' . JQUtils::typeName( $val ) . ' as object' );
				}
				$currentEnv = $env;
				foreach ( $fields as [ $keyFn, $fieldFn ] ) {
					$fieldName = null;
					foreach ( $keyFn( $val, $currentEnv ) as $k ) {
						$fieldName = JQUtils::checkString( 'object index', $k );
						break;
					}
					if ( $fieldName === null ) {
						return;
					}
					$nextEnv = null;
					foreach ( $fieldFn( $val->$fieldName ?? null, $currentEnv ) as $e ) {
						$nextEnv = $e;
						break;
					}
					if ( $nextEnv === null ) {
						return;
					}
					$currentEnv = $nextEnv;
				}
				yield $currentEnv;
			};
		} elseif ( $pat['type'] === 'alt_pattern' ) {
			$altFns = array_map( fn ( $p ) => $this->compilePattern( $p ), $pat['patterns'] );
			return static function ( mixed $val, JQEnv $env ) use ( $altFns ): Generator {
				foreach ( $altFns as $altFn ) {
					try {
						foreach ( $altFn( $val, $env ) as $nextEnv ) {
							yield $nextEnv;
							return;
						}
					} catch ( JQError ) {
						// this alternative failed; try the next one
					}
				}
				// all alternatives failed — yield nothing
			};
		} else {
			throw new LogicException( 'Unknown pattern type: ' . $pat['type'] );
		}
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
			foreach ( $exprFn( $input, $env ) as $base ) {
				try {
					if ( is_object( $base ) ) {
						yield from get_object_vars( $base );
					} elseif ( is_array( $base ) ) {
						yield from $base;
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
			foreach ( $exprFn( $input, $env ) as $base ) {
				try {
					if ( $base === null ) {
						yield null;
					} elseif ( is_object( $base ) ) {
						// Absent key on object always yields null; ? only suppresses type errors.
						yield property_exists( $base, $name ) ? $base->$name : null;
					} elseif ( is_array( $base ) && !array_is_list( $base ) ) {
						// Associative PHP array (defensive; normally objects are stdClass)
						yield array_key_exists( $name, $base ) ? $base[$name] : null;
					} else {
						throw new JQError(
							'Cannot index ' . JQUtils::typeName( $base ) . ' with string "' . $name . '"'
						);
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
			foreach ( $exprFn( $input, $env ) as $base ) {
				// The key expression sees the original $input, not $base.
				// e.g. in .a[.b], .b is evaluated against the outer input,
				// not against the result of .a.
				foreach ( $keyFn( $input, $env ) as $key ) {
					try {
						if ( $base === null ) {
							yield null;
						} elseif ( is_object( $base ) ) {
							if ( !( is_string( $key ) || JQUtils::isNumber( $key ) ) ) {
								throw new JQError(
									'Cannot index object with ' . JQUtils::typeName( $key )
								);
							}
							$key = (string)$key;
							if ( property_exists( $base, $key ) ) {
								yield $base->$key;
							} elseif ( !$opt ) {
								yield null;
							}
						} elseif ( is_array( $base ) && is_int( $key ) ) {
							$idx = $key < 0 ? $key + count( $base ) : $key;
							yield $base[$idx] ?? null;
						} elseif ( is_array( $base ) ) {
							// Associative PHP array (defensive; normally objects are stdClass)
							if ( !is_string( $key ) ) {
								throw new JQError(
									'Cannot index object with ' . JQUtils::typeName( $key )
								);
							}
							if ( array_key_exists( $key, $base ) ) {
								yield $base[$key];
							} elseif ( !$opt ) {
								yield null;
							}
						} else {
							throw new JQError(
								'Cannot index ' . JQUtils::typeName( $base ) .
								' with ' . JQUtils::typeName( $key )
							);
						}
					} catch ( JQError $e ) {
						if ( !$opt ) {
							throw $e;
						}
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
		return static function ( mixed $input, JQEnv $env ) use ( $leftFn, $rightFn, $op ): Generator {
			foreach ( $leftFn( $input, $env ) as $lv ) {
				foreach ( $rightFn( $input, $env ) as $rv ) {
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
				if ( $val !== null && $val !== false ) {
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
					yield from $catchFn( $e->jqValue, $env );
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
					break;
				}
				if ( $extractFn !== null ) {
					yield from $extractFn( $acc, $env );
				} else {
					yield $acc;
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
		$fromFn = $node['from'] !== null ? $this->compileNode( $node['from'] ) : null;
		$toFn   = $node['to'] !== null ? $this->compileNode( $node['to'] ) : null;
		$opt    = $node['opt'];
		return static function ( mixed $input, JQEnv $env ) use ( $exprFn, $fromFn, $toFn, $opt ): Generator {
			foreach ( $exprFn( $input, $env ) as $base ) {
				# This is making the generator not lazy, which is probably
				# not good
				$fromVals = $fromFn !== null ? iterator_to_array( $fromFn( $input, $env ), false ) : [ null ];
				$toVals   = $toFn !== null ? iterator_to_array( $toFn( $input, $env ), false ) : [ null ];
				foreach ( $fromVals as $from ) {
					foreach ( $toVals as $to ) {
						try {
							yield JQUtils::slice( $base, $from, $to );
						} catch ( JQError $e ) {
							if ( !$opt ) {
								throw $e;
							}
						}
					}
				}
			}
		};
	}

	/**
	 * Compile one AST node in path-expression mode.
	 *
	 * The returned Closure yields path arrays such as ["foo", 0, "bar"]
	 * rather than the values at those paths. Reserved for future use by
	 * path/1 and related builtins (getpath, setpath, delpaths, leaf_paths…).
	 *
	 * @param array $node AST node
	 * @return Closure(mixed,JQEnv):Generator a Filter (yields path arrays, not values)
	 * @suppress PhanPluginNeverReturnMethod
	 */
	private function compilePath( array $node ): Closure {
		throw new LogicException( 'compilePath: not yet implemented for node type: ' . $node['type'] );
	}
}
