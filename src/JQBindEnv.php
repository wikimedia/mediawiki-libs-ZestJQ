<?php
declare( strict_types = 1 );

namespace Wikimedia\ZestJQ;

use Closure;
use Generator;

/**
 * A JQEnv node that holds exactly one compiled function binding.
 *
 * Every call to JQEnv::bind() produces a JQBindEnv wrapping the previous
 * env in the parent chain. lookup() checks the local key first, then
 * delegates upward.
 */
class JQBindEnv extends JQEnv {

	/**
	 * @param ?JQEnv $parent Parent env for chained lookups
	 * @param IOContext $io Shared I/O context
	 * @param string $key "name/arity" key (e.g. "map/1", "length/0")
	 * @param ?Closure(mixed,JQEnv):Generator $binding Compiled filter for $key
	 */
	public function __construct(
		?JQEnv $parent,
		IOContext $io,
		private readonly string $key,
		private readonly ?Closure $binding,
	) {
		parent::__construct( $parent, $io );
	}

	private ?array $localCache = null;

	/** @inheritDoc */
	public function lookup( string $name, int $arity, bool $cache = true ): ?Closure {
		$key = "{$name}/{$arity}";
		if ( $this->key === $key ) {
			return $this->binding;
		}
		if ( $this->localCache !== null && array_key_exists( $key, $this->localCache ) ) {
			return $this->localCache[$key];
		}
		// Don't cache in the recursive lookup, it would be redundant.
		$result = parent::lookup( $name, $arity, cache: false );
		if ( $cache ) {
			// Note that we cache even negative lookups ($result = null)
			$this->localCache ??= [];
			$this->localCache[$key] = $result;
		}
		return $result;
	}
}
