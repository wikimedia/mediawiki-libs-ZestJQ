<?php
declare( strict_types = 1 );

namespace Wikimedia\ZestJQ;

use Closure;

/**
 * A JQEnv whose standard-library parent is resolved lazily on first lookup.
 *
 * Constructing a JQLazyEnv costs nothing beyond object allocation.
 * The standard environment (JQEnv::getStdEnv()) is loaded only when
 * lookup() is first called, so the overhead of deserialising and compiling
 * builtin.jq is not paid unless a built-in function is actually invoked
 * during evaluation.
 *
 * bind() is inherited unchanged: it creates a plain JQEnv whose parent
 * chain eventually reaches this object, so unresolved lookups naturally
 * proxy through here to the standard library.
 */
class JQLazyEnv extends JQEnv {

	private ?JQEnv $resolved = null;

	public function __construct( IOContext $io ) {
		parent::__construct( new JQTopLevelEnv( $io ), $io );
	}

	/** @inheritDoc */
	public function lookup( string $name, int $arity, bool $cache = true ): ?Closure {
		if ( $this->resolved === null ) {
			// Maybe this is a built-in; try to resolve in the
			// top level env
			$binding = parent::lookup( $name, $arity, $cache );
			if ( $binding !== null ) {
				return $binding;
			}
			// Ok, I guess we have to load the stdenv now.
			// @phan-suppress-next-line PhanTypeMismatchArgumentSuperType
			$this->resolved = self::buildStandardEnv( $this->parent );
		}
		return $this->resolved->lookup( $name, $arity, $cache );
	}
}
