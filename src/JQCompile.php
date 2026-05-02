<?php
// @phan-file-suppress PhanUnusedClosureParameter, PhanEmptyYieldFrom
declare( strict_types = 1 );

namespace Wikimedia\Zest;

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
	 * @param array  $ast  AST produced by JQGrammar::parse()
	 * @param JQEnv  $env  Initial lexical environment (builtins, prelude defs)
	 * @return \Closure(mixed $input): \Generator
	 */
	public static function compile( array $ast, JQEnv $env ): \Closure {
		$compiler = new self();
		$fn = $compiler->evalNode( $ast );
		return static function ( mixed $input ) use ( $fn, $env ): \Generator {
			return $fn( $input, $env );
		};
	}

	/**
	 * Compile one AST node into a filter closure.
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
	 * @param array $node  AST node (must have a 'type' key)
	 * @return \Closure(mixed $input, JQEnv $env): \Generator
	 */
	private function evalNode( array $node ): \Closure {
		return match ( $node['type'] ) {
			'identity' => $this->evalIdentity(),
			'literal'  => $this->evalLiteral( $node ),
			'pipe'     => $this->evalPipe( $node ),
			'label'    => $this->evalLabel( $node ),
			'break'    => $this->evalBreak( $node ),
			'field'    => $this->evalField( $node ),
			'index'    => $this->evalIndex( $node ),
			default    => throw new \LogicException( 'evalNode: not yet implemented for node type: ' . $node['type'] ),
		};
	}

	/**
	 * Compile an identity node (.).
	 * Yields the input value unchanged.
	 *
	 * @return \Closure(mixed $input, JQEnv $env): \Generator
	 */
	private function evalIdentity(): \Closure {
		return static function ( mixed $input, JQEnv $env ): \Generator {
			yield $input;
		};
	}

	/**
	 * Compile a pipe node (left | right).
	 * Feeds each output of the left filter as input to the right filter,
	 * yielding all outputs produced across all intermediate values.
	 *
	 * @param array $node  Node with 'left' and 'right' keys
	 * @return \Closure(mixed $input, JQEnv $env): \Generator
	 */
	private function evalPipe( array $node ): \Closure {
		$leftFn  = $this->evalNode( $node['left'] );
		$rightFn = $this->evalNode( $node['right'] );
		return static function ( mixed $input, JQEnv $env ) use ( $leftFn, $rightFn ): \Generator {
			foreach ( $leftFn( $input, $env ) as $mid ) {
				yield from $rightFn( $mid, $env );
			}
		};
	}

	/**
	 * Compile a literal node (null, true, false, number, plain string).
	 * Yields the literal value, ignoring the input.
	 *
	 * @param array $node  Node with 'value' key
	 * @return \Closure(mixed $input, JQEnv $env): \Generator
	 */
	private function evalLiteral( array $node ): \Closure {
		$value = $node['value'];
		return static function ( mixed $input, JQEnv $env ) use ( $value ): \Generator {
			yield $value;
		};
	}

	/**
	 * Compile a label node (label $out | body).
	 * Evaluates the body, catching any JQBreak whose label matches $out
	 * and silently terminating the stream. A break for a different label
	 * is re-thrown so it can be caught by the appropriate outer label.
	 *
	 * @param array $node  Node with 'name' and 'body' keys
	 * @return \Closure(mixed $input, JQEnv $env): \Generator
	 */
	private function evalLabel( array $node ): \Closure {
		$name   = $node['name'];
		$bodyFn = $this->evalNode( $node['body'] );
		return static function ( mixed $input, JQEnv $env ) use ( $name, $bodyFn ): \Generator {
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
	 * @param array $node  Node with 'name' key
	 * @return \Closure(mixed $input, JQEnv $env): \Generator
	 */
	private function evalBreak( array $node ): \Closure {
		$name = $node['name'];
		return static function ( mixed $input, JQEnv $env ) use ( $name ): \Generator {
			yield from [];
			throw new JQBreak( $name );
		};
	}

	/**
	 * Return the JQ type name of a PHP value, used in error messages.
	 */
	private static function typeName( mixed $v ): string {
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
		if ( is_array( $v ) ) {
			return array_is_list( $v ) ? 'array' : 'object';
		}
		return 'unknown';
	}

	/**
	 * Compile a field-access node (expr.name or expr.name?).
	 * null input yields null; object input yields the field value (or null if
	 * absent); any other type throws JQError (suppressed to empty if opt).
	 *
	 * @param array $node  Node with 'expr', 'name', and 'opt' keys
	 * @return \Closure(mixed $input, JQEnv $env): \Generator
	 */
	private function evalField( array $node ): \Closure {
		$exprFn = $this->evalNode( $node['expr'] );
		$name   = $node['name'];
		$opt    = $node['opt'];
		return static function ( mixed $input, JQEnv $env ) use ( $exprFn, $name, $opt ): \Generator {
			foreach ( $exprFn( $input, $env ) as $base ) {
				try {
					if ( $base === null ) {
						yield null;
					} elseif ( is_array( $base ) && !array_is_list( $base ) ) {
						yield $base[$name] ?? null;
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
	 * @param array $node  Node with 'expr', 'key', and 'opt' keys
	 * @return \Closure(mixed $input, JQEnv $env): \Generator
	 */
	private function evalIndex( array $node ): \Closure {
		$exprFn = $this->evalNode( $node['expr'] );
		$keyFn  = $this->evalNode( $node['key'] );
		$opt    = $node['opt'];
		return static function ( mixed $input, JQEnv $env ) use ( $exprFn, $keyFn, $opt ): \Generator {
			foreach ( $exprFn( $input, $env ) as $base ) {
				foreach ( $keyFn( $input, $env ) as $key ) {
					try {
						if ( $base === null ) {
							yield null;
						} elseif ( is_array( $base ) && !array_is_list( $base ) ) {
							if ( !is_string( $key ) ) {
								throw new JQError(
									'Cannot index object with ' . self::typeName( $key )
								);
							}
							yield $base[$key] ?? null;
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
	 * Yields path arrays such as ["foo", 0, "bar"] rather than the values at
	 * those paths. Reserved for future use by path/1 and related builtins
	 * (getpath, setpath, delpaths, leaf_paths, …).
	 *
	 * @param array $node  AST node
	 * @return \Closure(mixed $input, JQEnv $env): \Generator
	 * @suppress PhanPluginNeverReturnMethod
	 */
	private function evalPath( array $node ): \Closure {
		throw new \LogicException( 'evalPath: not yet implemented for node type: ' . $node['type'] );
	}
}
