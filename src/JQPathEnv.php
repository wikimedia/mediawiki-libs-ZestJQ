<?php
declare( strict_types = 1 );

namespace Wikimedia\Zest;

use Closure;
use LogicException;

/**
 * Subclass of JQEnv used in path mode to trace the path corresponding to
 * values collected, in addition to the usual functions of an Env.
 *
 * Two-parent design: $parent (inherited from JQEnv) is always a plain JQEnv
 * used exclusively for variable lookups — lookup() reaches it in one step.
 * $pathParent is the previous JQPathEnv in the path chain, used exclusively
 * by getPath(). The two chains are completely independent, so binding depth
 * and path depth do not affect each other's performance.
 */
class JQPathEnv extends JQEnv {

	public function __construct(
		?JQEnv $parent,
		IOContext $io,
		/** Previous JQPathEnv in the path chain; null at the root. */
		private readonly ?JQPathEnv $pathParent,
		/** The single path segment stored at this level. */
		private readonly mixed $pathKey,
		/** Whether this node contributes a key to the path (false for binding nodes). */
		private readonly bool $pathValid,
	) {
		parent::__construct( $parent, $io );
	}

	/** @inheritDoc */
	public function bind( string $name, int $arity, Closure $fn ): self {
		// Insert the binding into the plain-JQEnv binding-parent chain so
		// that lookup() stays O(1), then return a new JQPathEnv at the same
		// path position (same pathParent/pathKey/pathValid).
		$newEnv = $this->parent->bind( $name, $arity, $fn );
		return new self( $newEnv, $this->io, $this->pathParent, $this->pathKey, $this->pathValid );
	}

	/** @inheritDoc */
	public function isPathMode(): bool {
		return true;
	}

	/**
	 * @inheritDoc
	 * @return never
	 */
	// @phan-suppress-next-line PhanUnusedPublicMethodParameter
	public function enterPathMode(): JQPathEnv {
		throw new LogicException( 'already in path mode' );
	}

	/**
	 * @inheritDoc
	 * @return never
	 */
	// @phan-suppress-next-line PhanUnusedPublicMethodParameter
	public function maybeEnterPathMode( JQEnv $parent ): JQEnv {
		throw new LogicException( 'already in path mode' );
	}

	/** @inheritDoc */
	public function appendPath( mixed $key ): self {
		// Binding parent is unchanged; extend the path chain by one step.
		return new self( $this->parent, $this->io, $this, $key, true );
	}

	/** @inheritDoc */
	public function leavePathMode(): JQEnv {
		// O(1): return the plain-JQEnv binding parent directly, no allocation.
		return $this->parent ??
			throw new LogicException( 'JQPathEnv has no binding parent' );
	}

	/**
	 * Reconstruct the full path array for this env.
	 * Traverses the $pathParent chain (one step per path segment) and reverses once.
	 * @inheritDoc
	 */
	public function getPath(): array {
		$r = [];
		for ( $p = $this; $p !== null; $p = $p->pathParent ) {
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
