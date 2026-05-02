<?php
// @phan-file-suppress PhanUnusedClosureParameter
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
