<?php
declare( strict_types = 1 );

namespace Wikimedia\Zest;

use Closure;
use Generator;

/**
 * Immutable lexical environment for JQ evaluation.
 *
 * Maps "name/arity" keys to compiled filter closures. bind() returns a new
 * instance rather than mutating, so a base env built from builtin.jq can be
 * shared safely across many independent evaluations. The IOContext object is
 * shared by reference across all envs derived from a common root.
 */
class JQEnv {

	private static ?JQEnv $stdEnv = null;

	/**
	 * @param ?JQEnv $parent Parent binding
	 * @param IOContext $io Shared I/O context (same object across all derived envs)
	 * @param array<string,?Closure(mixed,JQEnv):Generator> $defs Compiled functions keyed "name/arity"
	 *   e.g. "map/1", "length/0", "foo::bar/2"
	 */
	public function __construct(
		private readonly ?JQEnv $parent,
		public readonly IOContext $io,
		private array $defs = [],
	) {
	}

	/**
	 * Return a new env with one additional function binding.
	 *
	 * All filter parameters (including desugared value params) are represented
	 * as Closure(mixed $input, JQEnv $env): Generator.
	 *
	 * @param string $name Function name (may include a :: namespace)
	 * @param int $arity Number of filter arguments
	 * @param Closure(mixed,JQEnv):Generator $fn Compiled Filter
	 */
	public function bind( string $name, int $arity, Closure $fn ): self {
		$key = "{$name}/{$arity}";
		return new self( $this, $this->io, [
			$key => $fn,
		] );
	}

	/**
	 * Look up a compiled function by name and arity.
	 *
	 * Returns null if no definition is found; the caller is responsible for
	 * falling back to the builtin registry or raising a JQError.
	 *
	 * @return ?Closure(mixed,JQEnv):Generator a Filter, or
	 *   null if the binding doesn't exists
	 */
	public function lookup( string $name, int $arity ): ?Closure {
		$key = "{$name}/{$arity}";
		if ( !array_key_exists( $key, $this->defs ) ) {
			// look up in parent, and cache it here.
			$this->defs[$key] = $this->parent?->lookup( $name, $arity );
		}
		return $this->defs[$key];
	}

	/**
	 * Return the shared standard-library environment, building it on first call.
	 *
	 * The env is built by evaluating src/builtin.jq with $__env__ appended so
	 * that all def statements register themselves and the resulting JQEnv is
	 * returned. The env is then cached for the lifetime of the process.
	 */
	public static function getStdEnv(): JQEnv {
		self::$stdEnv ??= self::buildStandardEnv();
		return self::$stdEnv;
	}

	private static function buildStandardEnv(): JQEnv {
		$baseEnv = new JQTopLevelEnv( new IOContext );
		$ast = JQBuiltin::getAst();
		$f = JQCompile::compile( $ast, $baseEnv );
		foreach ( $f( null ) as $val ) {
			if ( $val instanceof JQEnv ) {
				return $val;
			}
		}
		throw new \RuntimeException( __METHOD__ . ': $__env__ was not yielded' );
	}

}
