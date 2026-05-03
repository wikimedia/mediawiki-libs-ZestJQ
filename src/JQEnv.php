<?php
declare( strict_types = 1 );

namespace Wikimedia\Zest;

use Closure;
use Generator;
use LogicException;

/**
 * Immutable lexical environment for JQ evaluation.
 *
 * Maps "name/arity" keys to compiled filter closures. bind() returns a new
 * instance rather than mutating, so a base env built from builtin.jq can be
 * shared safely across many independent evaluations. The IOContext object is
 * shared by reference across all envs derived from a common root.
 *
 * Path mode: when $pathMode is true, structural ops (field, index, iter, etc.)
 * yield [JQEnv $pathEnv, mixed $value] pairs instead of bare values. $pathEnv
 * carries the path accumulated so far as a linked chain of tail segments;
 * getPath() walks up the chain and reconstructs the full array once, at the
 * point where path/1 reads it.
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
		protected readonly ?JQEnv $parent,
		public readonly IOContext $io,
		protected array $defs = [],
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
	 * The env is built by evaluating src/builtin.jq with __env__ appended so
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
		throw new \RuntimeException( __METHOD__ . ': __env__ was not yielded' );
	}

	// -----------------------------------------------------------------------
	// Path mode
	// -----------------------------------------------------------------------

	/** Returns true when structural ops should yield [pathEnv, value] pairs. */
	public function isPathMode(): bool {
		return false;
	}

	/**
	 * Return a new env that is the root of a fresh path-collection
	 * context.  The returned env returns true from ::isPathMode() and
	 * has an empty path; $this becomes the parent for variable
	 * lookups but is NOT itself in path mode, so getPath() stops
	 * here.
	 */
	public function enterPathMode(): JQPathEnv {
		return new JQPathEnv( $this, $this->io, [], false, false );
	}

	/**
	 * Extend the current path by one key.
	 * In normal mode returns $this unchanged (fast path, no allocation).
	 * In path mode returns a new env whose $pathKey is $key and whose parent
	 * is $this; getPath() will prepend $this's path to $key.
	 */
	// @phan-suppress-next-line PhanUnusedPublicMethodParameter
	public function appendPath( mixed $key ): self {
		// Not in path mode, throw away the key
		return $this;
	}

	/**
	 * Return an env with path mode disabled (for evaluating conditions and
	 * key expressions that must not themselves produce path-mode outputs).
	 * In normal mode returns $this unchanged (fast path, no allocation).
	 */
	public function leavePathMode(): self {
		return $this;
	}

	/**
	 * Reconstruct the full path array for this env.
	 * Segments are gathered bottom-up (O(N) push) and then reversed once.
	 */
	public function getPath(): array {
		throw new LogicException( 'not in path mode' );
	}

	/**
	 * Wrap $value with path context when in path mode.
	 * Normal mode: returns $value unchanged.
	 * Path mode:   returns [$this, $value].
	 *
	 * Used at the yield site in every structural compile* method.
	 */
	public function maybeWithPath( mixed $value ): mixed {
		return $value;
	}

	/**
	 * Unwrap a potentially path-wrapped generator output.
	 * Always returns [JQEnv $nextEnv, mixed $value].
	 * Normal mode: [$this, $item]  (identity; no allocation avoided here for
	 *              uniformity — hot-path callers may add an isPathMode() guard).
	 * Path mode:   [$item[0], $item[1]]  (unwraps the [pathEnv, value] pair).
	 *
	 * Used in compilePipe to thread the path env into the right-hand side.
	 */
	public function maybeUnwrapPath( mixed $item ): array {
		return [ $this, $item ];
	}

	/**
	 * Extract the full path array from a path-mode generator output.
	 * $item must be a [$pathEnv, $value] pair produced by maybeWithPath().
	 * Only meaningful when in path mode; used exclusively by path/1.
	 */
	public function extractPath( mixed $item ): array {
		return $item[0]->getPath();
	}

}
