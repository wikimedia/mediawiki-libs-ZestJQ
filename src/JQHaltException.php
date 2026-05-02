<?php
declare( strict_types = 1 );

namespace Wikimedia\Zest;

use RuntimeException;

/**
 * Thrown by halt/0 and halt_error/1 to terminate JQ execution.
 *
 * Intentionally does NOT extend JQError, so it propagates through jq
 * try/catch expressions without being caught by them.
 *
 * $code  — process exit code (default 0)
 * $message — optional string to write to stderr before exiting;
 *            empty string means no output
 */
class JQHaltException extends RuntimeException {

	public function __construct( int $code = 0, string $message = '' ) {
		parent::__construct( $message, $code );
	}
}
