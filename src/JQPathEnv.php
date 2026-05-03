<?php
declare( strict_types = 1 );

namespace Wikimedia\Zest;

use Closure;
use LogicException;

/**
 * Subclass of JQEnv used in path mode to trace the
 * path corresponding to values collected, in addition
 * to the usual functions of an Env.
 */
class JQPathEnv extends JQEnv {

	public function __construct(
		?JQEnv $parent,
		IOContext $io,
		array $defs,
		/** The single path segment stored at this level. (First segment is
		 * a sentinel `false`.)
		 */
		private mixed $pathKey,
		/** Whether this path key is valid (some JQEnv are created for
		 * bindings, not to extend a path).
		 */
		private bool $pathValid,
	) {
		parent::__construct( $parent, $io, $defs );
	}

	/** @inheritDoc */
	public function bind( string $name, int $arity, Closure $fn ): self {
		$key = "{$name}/{$arity}";
		return new JQPathEnv( $this, $this->io, [
			$key => $fn,
		], false, false );
	}

	/** @inheritDoc */
	public function isPathMode(): bool {
		return true;
	}

	/**
	 * @inheritDoc
	 * @return never
	 */
	public function enterPathMode(): JQPathEnv {
		throw new LogicException( 'already in path mode' );
	}

	/** @inheritDoc */
	public function appendPath( mixed $key ): self {
		return new self( $this, $this->io, [], $key, true );
	}

	/** @inheritDoc */
	public function leavePathMode(): JQEnv {
		// *not* a JQPathEnv
		return new JQEnv( $this, $this->io, [] );
	}

	/**
	 * Reconstruct the full path array for this env.
	 * Segments are gathered bottom-up (O(N) push) and then reversed once.
	 * @inheritDoc
	 */
	public function getPath(): array {
		$r = [];
		// Note that we don't add the path key corresponding to the
		// JQPathEnvs where the pathValid flag isn't set.
		for ( $p = $this; $p?->isPathMode(); $p = $p->parent ) {
			if ( $p->pathValid ) {
				$r[] = $p->pathKey;
			}
		}
		return array_reverse( $r );
	}

	/** @inheritDoc */
	public function maybeWithPath( mixed $value ): mixed {
		return [ $this, $value ];
	}

	/** @inheritDoc */
	public function maybeUnwrapPath( mixed $item ): array {
		if ( !is_array( $item ) || !isset( $item[0] ) || !( $item[0] instanceof JQPathEnv ) ) {
			throw new JQError( 'Invalid path expression with result ' . JQUtils::jsonEncode( $item ) );
		}
		return $item;
	}
}
