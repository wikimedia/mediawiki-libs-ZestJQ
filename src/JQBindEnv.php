<?php
declare( strict_types = 1 );

namespace Wikimedia\Zest;

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

	/** @inheritDoc */
	public function lookup( string $name, int $arity ): ?Closure {
		$key = "{$name}/{$arity}";
		if ( $this->key === $key ) {
			return $this->binding;
		}
		return $this->parent?->lookup( $name, $arity );
	}
}
