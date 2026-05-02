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
			return $fn( $input, $env );
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
		if ( $key === '$__env__' ) {
			// Built-in: yield the current JQEnv so callers can capture it.
			// Used by bootstrapping code (evalForEnv) to extract the env after
			// a sequence of def statements has been evaluated.
			return static function ( mixed $input, JQEnv $env ): Generator {
				yield $env;
			};
		}
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
			$objects = [ [] ];
			foreach ( $pairFns as [ $keyFn, $valFn ] ) {
				$next = [];
				foreach ( $objects as $obj ) {
					foreach ( $keyFn( $input, $env ) as $key ) {
						foreach ( $valFn( $input, $env ) as $val ) {
							$newObj = $obj;
							$newObj[(string)$key] = $val;
							$next[] = $newObj;
						}
					}
				}
				$objects = $next;
			}
			yield from $objects;
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
		if ( $v === null ) {
			return 'null';
		}
		if ( is_bool( $v ) ) {
			return 'boolean';
		}
		if ( is_int( $v ) || is_float( $v ) ) {
			return 'number';
		}
		if ( is_string( $v ) ) {
			return 'string';
		}
		if ( is_object( $v ) ) {
			return 'object';
		}
		if ( is_array( $v ) ) {
			return 'array';
		}
		return 'unknown';
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
	private static function jqCompare( mixed $a, mixed $b ): int {
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
						throw new JQError( self::typeName( $base ) . ' is not iterable' );
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
						if ( property_exists( $base, $name ) ) {
							yield $base->$name;
						} elseif ( !$opt ) {
							yield null;
						}
						// opt + absent key: yield nothing (suppresses downstream chain)
					} elseif ( is_array( $base ) && !array_is_list( $base ) ) {
						// Associative PHP array (defensive; normally objects are stdClass)
						if ( array_key_exists( $name, $base ) ) {
							yield $base[$name];
						} elseif ( !$opt ) {
							yield null;
						}
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
		$exprFn = $this->compileNode( $node['expr'] );
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
