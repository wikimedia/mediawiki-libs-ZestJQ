<?php
declare( strict_types = 1 );

namespace Wikimedia\ZestJQ;

/** Non-local exit thrown by break/$label, caught only by the matching label/$label node. */
class JQBreak extends \RuntimeException {
	public function __construct( public readonly string $label ) {
		parent::__construct( "break \$$label" );
	}
}
