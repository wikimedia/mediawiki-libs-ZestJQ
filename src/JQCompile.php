<?php
// @phan-file-suppress PhanUnusedClosureParameter, PhanEmptyYieldFrom
declare( strict_types = 1 );

namespace Wikimedia\Zest;

use Closure;
use Generator;

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
				throw new \LogicException( 'compileNode: not yet implemented for node type: ' . $node['type'] );
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
	 *   def f($x): body  →  def f(x): x as $x | body
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
		$argFns = array_map( fn ( $arg ) => $this->compileNode( $arg ), $node['args'] );

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
								throw new JQError( "Cannot use " . self::typeName( $key ) . " as object key" );
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
					throw new JQError( 'Cannot destructure ' . self::typeName( $val ) . ' as array' );
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
				$keyNode = $field['key'];
				if ( $keyNode['type'] !== 'literal' || !is_string( $keyNode['value'] ) ) {
					throw new \LogicException( 'Computed keys in object patterns are not yet supported' );
				}
				$fields[] = [ $keyNode['value'], $this->compilePattern( $field['pattern'] ) ];
			}
			return static function ( mixed $val, JQEnv $env ) use ( $fields ): Generator {
				if ( !is_array( $val ) || array_is_list( $val ) ) {
					throw new JQError( 'Cannot destructure ' . self::typeName( $val ) . ' as object' );
				}
				$currentEnv = $env;
				foreach ( $fields as [ $fieldName, $fieldFn ] ) {
					$nextEnv = null;
					foreach ( $fieldFn( $val[$fieldName] ?? null, $currentEnv ) as $e ) {
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
			throw new \LogicException( 'Unknown pattern type: ' . $pat['type'] );
		}
	}

	/**
	 * Return the JQ type name of a PHP value, used in error messages.
	 */
	public static function typeName( mixed $v ): string {
		return match ( true ) {
			( $v === null ) => 'null',
			is_bool( $v ) => 'boolean',
			is_int( $v ) || is_float( $v ) => 'number',
			is_string( $v ) => 'string',
			is_object( $v ) => 'object',
			is_array( $v ) => 'array',
			default => 'unknown',
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
		$op      = $node['op'];
		return static function ( mixed $input, JQEnv $env ) use ( $leftFn, $rightFn, $op ): Generator {
			foreach ( $leftFn( $input, $env ) as $lv ) {
				foreach ( $rightFn( $input, $env ) as $rv ) {
					yield match ( $op ) {
						'==' => self::jqEqual( $lv, $rv ),
						'!=' => !self::jqEqual( $lv, $rv ),
						'<'  => self::jqCompare( $lv, $rv ) < 0,
						'<=' => self::jqCompare( $lv, $rv ) <= 0,
						'>'  => self::jqCompare( $lv, $rv ) > 0,
						'>=' => self::jqCompare( $lv, $rv ) >= 0,
						default => throw new JQError( 'Unknown comparison operator: ' . $op ),
					};
				}
			}
		};
	}

	/**
	 * Structural JSON equality.
	 * int and float are treated as the same numeric type (42 == 42.0).
	 * Arrays and objects are compared recursively by key-value pairs.
	 */
	private static function jqEqual( mixed $a, mixed $b ): bool {
		// Numeric: int and float are the same JQ type
		if ( is_int( $a ) || is_float( $a ) ) {
			return ( is_int( $b ) || is_float( $b ) ) && $a == $b;
		}
		// stdClass objects (JSON objects)
		if ( is_object( $a ) ) {
			if ( !is_object( $b ) ) {
				return false;
			}
			$av = get_object_vars( $a );
			$bv = get_object_vars( $b );
			if ( count( $av ) !== count( $bv ) ) {
				return false;
			}
			foreach ( $av as $k => $v ) {
				if ( !array_key_exists( $k, $bv ) || !self::jqEqual( $v, $bv[$k] ) ) {
					return false;
				}
			}
			return true;
		}
		// null, bool, string: identity
		if ( !is_array( $a ) ) {
			return $a === $b;
		}
		// array
		if ( !is_array( $b ) || count( $a ) !== count( $b ) ) {
			return false;
		}
		foreach ( $a as $k => $v ) {
			if ( !array_key_exists( $k, $b ) || !self::jqEqual( $v, $b[$k] ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * JQ cross-type ordering: null(0) < false(1) < true(2) < number(3) <
	 * string(4) < array(5) < object(6).
	 * Returns negative, zero, or positive like the spaceship operator.
	 */
	public static function jqCompare( mixed $a, mixed $b ): int {
		static $order = null;
		$order ??= static function ( mixed $v ): int {
			return match ( true ) {
				( $v === null ) => 0,
				( $v === false ) => 1,
				( $v === true ) => 2,
				( is_int( $v ) || is_float( $v ) ) => 3,
				is_string( $v ) => 4,
				is_array( $v ) => 5,
				default => 6, // stdClass object
			};
		};
		$ta = $order( $a );
		$tb = $order( $b );
		if ( $ta !== $tb ) {
			return $ta <=> $tb;
		}
		if ( $ta <= 2 ) {
			return 0;  // null or a specific boolean; same rank means same value
		}
		if ( $ta <= 4 ) {
			return $a <=> $b;  // number or string: natural PHP ordering
		}
		// array: lexicographic element comparison then by length
		if ( $ta === 5 ) {
			foreach ( array_map( null, $a, $b ) as [ $av, $bv ] ) {
				$c = self::jqCompare( $av, $bv );
				if ( $c !== 0 ) {
					return $c;
				}
			}
			return count( $a ) <=> count( $b );
		}
		// objects (stdClass): sort by keys, then compare key-value pairs in order
		$av = get_object_vars( $a );
		$bv = get_object_vars( $b );
		$ka = array_keys( $av );
		$kb = array_keys( $bv );
		sort( $ka );
		sort( $kb );
		if ( ( $c = self::jqCompare( $ka, $kb ) ) !== 0 ) {
			return $c;
		}
		foreach ( $ka as $k ) {
			if ( ( $c = self::jqCompare( $av[$k], $bv[$k] ) ) !== 0 ) {
				return $c;
			}
		}
		return 0;
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
						throw new JQError( 'Cannot iterate over ' . self::typeName( $base ) . ' (' . self::jqValueToString( $base ) . ')' );
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
				if ( !is_int( $v ) && !is_float( $v ) ) {
					throw new JQError( 'Cannot negate ' . self::typeName( $v ) );
				}
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
							'Cannot index ' . self::typeName( $base ) . ' with string "' . $name . '"'
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
		$exprFn = isset( $node['expr'] ) ? $this->compileNode( $node['expr'] ) : $this->compileIdentity();
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
							if ( !is_string( $key ) ) {
								throw new JQError(
									'Cannot index object with ' . self::typeName( $key )
								);
							}
							if ( property_exists( $base, $key ) ) {
								yield $base->$key;
							} elseif ( !$opt ) {
								yield null;
							}
						} elseif ( is_array( $base ) && !array_is_list( $base ) ) {
							// Associative PHP array (defensive; normally objects are stdClass)
							if ( !is_string( $key ) ) {
								throw new JQError(
									'Cannot index object with ' . self::typeName( $key )
								);
							}
							if ( array_key_exists( $key, $base ) ) {
								yield $base[$key];
							} elseif ( !$opt ) {
								yield null;
							}
						} elseif ( is_array( $base ) ) {
							if ( !is_int( $key ) ) {
								throw new JQError(
									'Cannot index array with ' . self::typeName( $key )
								);
							}
							$idx = $key < 0 ? $key + count( $base ) : $key;
							yield $base[$idx] ?? null;
						} else {
							throw new JQError(
								'Cannot index ' . self::typeName( $base ) .
								' with ' . self::typeName( $key )
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
		$fmt = $node['fmt'];
		$compiledParts = [];
		foreach ( $node['parts'] as $part ) {
			if ( $part['type'] === 'str_interp' ) {
				$compiledParts[] = [ 'interp', $this->compileNode( $part['expr'] ) ];
			} else {
				$compiledParts[] = [ 'text', $part['text'] ];
			}
		}
		return static function ( mixed $input, JQEnv $env ) use ( $fmt, $compiledParts ): Generator {
			$strings = [ '' ];
			foreach ( $compiledParts as [ $kind, $data ] ) {
				if ( $kind === 'text' ) {
					$strings = array_map( static fn ( $s ) => $s . $data, $strings );
				} else {
					$next = [];
					foreach ( $strings as $prefix ) {
						foreach ( $data( $input, $env ) as $val ) {
							$seg = $fmt !== null
								? self::applyFormat( $fmt, $val )
								: self::jqValueToString( $val );
							$next[] = $prefix . $seg;
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
		$fmt = $node['fmt'];
		return static function ( mixed $input, JQEnv $env ) use ( $fmt ): Generator {
			yield self::applyFormat( $fmt, $input );
		};
	}

	/**
	 * Apply a named format (@html, @base64, etc.) to a value.
	 * Non-string values are first converted with jqValueToString(), except
	 * the json format which always JSON-encodes its input (including strings).
	 */
	private static function applyFormat( string $fmt, mixed $val ): string {
		$str = self::jqValueToString( $val );
		return match ( $fmt ) {
			'text'    => $str,
			'json'    => json_encode( $val, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ?: 'null',
			'html'    => htmlspecialchars( $str, ENT_QUOTES | ENT_XML1, 'UTF-8' ),
			'uri'     => rawurlencode( $str ),
			'urid'    => rawurldecode( $str ),
			'base64'  => base64_encode( $str ),
			'base64d' => (string)base64_decode( trim( $str ) ),
			'sh'      => "'" . str_replace( "'", "'\\''", $str ) . "'",
			'csv'     => self::formatCsv( $val ),
			'tsv'     => self::formatTsv( $val ),
			default   => throw new JQError( 'Unknown format: @' . $fmt ),
		};
	}

	/**
	 * Convert a JQ value to string with tostring semantics:
	 * strings pass through unchanged; everything else is JSON-encoded.
	 */
	private static function jqValueToString( mixed $val ): string {
		return is_string( $val )
			? $val
			: ( json_encode( $val, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ?: 'null' );
	}

	/**
	 * Format an array as CSV: numbers are bare, strings are double-quoted
	 * with internal double-quotes doubled; values are comma-separated.
	 */
	private static function formatCsv( mixed $val ): string {
		if ( !is_array( $val ) || !array_is_list( $val ) ) {
			throw new JQError( '@csv input must be an array, got ' . self::typeName( $val ) );
		}
		$cols = [];
		foreach ( $val as $item ) {
			if ( is_int( $item ) || is_float( $item ) ) {
				$cols[] = json_encode( $item ) ?: '0';
			} elseif ( is_string( $item ) ) {
				$cols[] = '"' . str_replace( '"', '""', $item ) . '"';
			} elseif ( $item === true ) {
				$cols[] = 'true';
			} elseif ( $item === false ) {
				$cols[] = 'false';
			} elseif ( $item === null ) {
				$cols[] = '';
			} else {
				throw new JQError( '@csv: invalid element type ' . self::typeName( $item ) );
			}
		}
		return implode( ',', $cols );
	}

	/**
	 * Format an array as TSV: values are tab-separated; tab, newline,
	 * carriage-return, and backslash in strings are backslash-escaped.
	 */
	private static function formatTsv( mixed $val ): string {
		if ( !is_array( $val ) || !array_is_list( $val ) ) {
			throw new JQError( '@tsv input must be an array, got ' . self::typeName( $val ) );
		}
		$cols = [];
		foreach ( $val as $item ) {
			if ( is_int( $item ) || is_float( $item ) ) {
				$cols[] = json_encode( $item ) ?: '0';
			} elseif ( is_string( $item ) ) {
				$cols[] = str_replace(
					[ '\\', "\t", "\n", "\r" ],
					[ '\\\\', '\\t', '\\n', '\\r' ],
					$item
				);
			} elseif ( $item === true ) {
				$cols[] = 'true';
			} elseif ( $item === false ) {
				$cols[] = 'false';
			} elseif ( $item === null ) {
				$cols[] = '';
			} else {
				throw new JQError( '@tsv: invalid element type ' . self::typeName( $item ) );
			}
		}
		return implode( "\t", $cols );
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
		$op      = $node['op'];
		return static function ( mixed $input, JQEnv $env ) use ( $leftFn, $rightFn, $op ): Generator {
			foreach ( $leftFn( $input, $env ) as $lv ) {
				foreach ( $rightFn( $input, $env ) as $rv ) {
					yield self::jqBinop( $op, $lv, $rv );
				}
			}
		};
	}

	private static function jqBinop( string $op, mixed $a, mixed $b ): mixed {
		return match ( $op ) {
			'+' => self::jqAdd( $a, $b ),
			'-' => self::jqSubtract( $a, $b ),
			'*' => self::jqMultiply( $a, $b ),
			'/' => self::jqDivide( $a, $b ),
			'%' => self::jqModulo( $a, $b ),
			default => throw new JQError( 'Unknown operator: ' . $op ),
		};
	}

	/**
	 * JQ addition: null acts as identity; numbers add; strings concatenate;
	 * arrays concatenate; objects merge (right keys overwrite left).
	 */
	private static function jqAdd( mixed $a, mixed $b ): mixed {
		if ( $a === null ) {
			return $b;
		}
		if ( $b === null ) {
			return $a;
		}
		if ( ( is_int( $a ) || is_float( $a ) ) && ( is_int( $b ) || is_float( $b ) ) ) {
			return $a + $b;
		}
		if ( is_string( $a ) && is_string( $b ) ) {
			return $a . $b;
		}
		if ( is_array( $a ) && is_array( $b ) ) {
			return array_merge( $a, $b );
		}
		if ( is_object( $a ) && is_object( $b ) ) {
			return (object)array_merge( get_object_vars( $a ), get_object_vars( $b ) );
		}
		throw new JQError( self::typeName( $a ) . ' and ' . self::typeName( $b ) . ' cannot be added' );
	}

	/**
	 * JQ subtraction: numbers subtract; arrays remove matching elements.
	 */
	private static function jqSubtract( mixed $a, mixed $b ): mixed {
		if ( ( is_int( $a ) || is_float( $a ) ) && ( is_int( $b ) || is_float( $b ) ) ) {
			return $a - $b;
		}
		if ( is_array( $a ) && is_array( $b ) ) {
			return array_values( array_filter( $a,
				static function ( $item ) use ( $b ): bool {
					foreach ( $b as $bItem ) {
						if ( self::jqEqual( $item, $bItem ) ) {
							return false;
						}
					}
					return true;
				}
			) );
		}
		throw new JQError( self::typeName( $a ) . ' and ' . self::typeName( $b ) . ' cannot be subtracted' );
	}

	/**
	 * JQ multiplication: numbers multiply; string * number repeats string;
	 * null * anything = null; objects are recursively merged.
	 */
	private static function jqMultiply( mixed $a, mixed $b ): mixed {
		if ( $a === null || $b === null ) {
			return null;
		}
		if ( ( is_int( $a ) || is_float( $a ) ) && ( is_int( $b ) || is_float( $b ) ) ) {
			return $a * $b;
		}
		if ( is_string( $a ) && ( is_int( $b ) || is_float( $b ) ) ) {
			return $b <= 0 ? null : str_repeat( $a, (int)$b );
		}
		if ( ( is_int( $a ) || is_float( $a ) ) && is_string( $b ) ) {
			return $a <= 0 ? null : str_repeat( $b, (int)$a );
		}
		if ( is_object( $a ) && is_object( $b ) ) {
			return self::jqMergeObjects( $a, $b );
		}
		throw new JQError( self::typeName( $a ) . ' and ' . self::typeName( $b ) . ' cannot be multiplied' );
	}

	/** Recursive object merge: values in $b overwrite $a, nested objects are merged. */
	private static function jqMergeObjects( object $a, object $b ): object {
		$result = get_object_vars( $a );
		foreach ( get_object_vars( $b ) as $k => $bVal ) {
			if ( isset( $result[$k] ) && is_object( $result[$k] ) && is_object( $bVal ) ) {
				$result[$k] = self::jqMergeObjects( $result[$k], $bVal );
			} else {
				$result[$k] = $bVal;
			}
		}
		return (object)$result;
	}

	/**
	 * JQ division: numbers divide (zero divisor throws); strings split.
	 */
	private static function jqDivide( mixed $a, mixed $b ): mixed {
		if ( ( is_int( $a ) || is_float( $a ) ) && ( is_int( $b ) || is_float( $b ) ) ) {
			if ( $b == 0 ) {
				throw new JQError( 'number (' . $a . ') and number (' . $b . ') cannot be divided because the divisor is zero' );
			}
			return $a / $b;
		}
		if ( is_string( $a ) && is_string( $b ) ) {
			return $b === '' ? mb_str_split( $a ) : explode( $b, $a );
		}
		throw new JQError( self::typeName( $a ) . ' and ' . self::typeName( $b ) . ' cannot be divided' );
	}

	/**
	 * JQ modulo: integer remainder (zero divisor throws).
	 */
	private static function jqModulo( mixed $a, mixed $b ): mixed {
		if ( ( is_int( $a ) || is_float( $a ) ) && ( is_int( $b ) || is_float( $b ) ) ) {
			if ( $b == 0 ) {
				throw new JQError( 'number (' . $a . ') modulo zero' );
			}
			return fmod( (float)$a, (float)$b );
		}
		throw new JQError( self::typeName( $a ) . ' and ' . self::typeName( $b ) . ' cannot have remainder computed' );
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
				$fromVals = $fromFn !== null ? iterator_to_array( $fromFn( $input, $env ), false ) : [ null ];
				$toVals   = $toFn !== null ? iterator_to_array( $toFn( $input, $env ), false ) : [ null ];
				foreach ( $fromVals as $from ) {
					foreach ( $toVals as $to ) {
						try {
							yield self::jqSlice( $base, $from, $to );
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

	private static function jqSlice( mixed $base, mixed $from, mixed $to ): mixed {
		if ( $base === null ) {
			return null;
		}
		if ( is_string( $base ) ) {
			$len = mb_strlen( $base );
			$f = self::normalizeSliceIdx( $from, $len, 0 );
			$t = self::normalizeSliceIdx( $to, $len, $len );
			return mb_substr( $base, $f, max( 0, $t - $f ) );
		}
		if ( is_array( $base ) ) {
			$len = count( $base );
			$f = self::normalizeSliceIdx( $from, $len, 0 );
			$t = self::normalizeSliceIdx( $to, $len, $len );
			return array_values( array_slice( $base, $f, max( 0, $t - $f ) ) );
		}
		throw new JQError( self::typeName( $base ) . ' cannot be sliced' );
	}

	private static function normalizeSliceIdx( mixed $idx, int $len, int $default ): int {
		if ( $idx === null ) {
			return $default;
		}
		$i = (int)$idx;
		if ( $i < 0 ) {
			$i = $len + $i;
		}
		return min( max( 0, $i ), $len );
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
		throw new \LogicException( 'compilePath: not yet implemented for node type: ' . $node['type'] );
	}
}
