<?php
declare( strict_types = 1 );

namespace Wikimedia\ZestJQ;

/**
 * Runtime error thrown by JQ expressions (e.g. error/0, type errors).
 *
 * $jqValue carries the original JQ value for try/catch handlers. For errors
 * thrown with a string (e.g. `"msg" | error`), $jqValue is that string. For
 * errors thrown with a non-string value (e.g. `[1,2] | error`), $jqValue is
 * the original array/object/number so that catch handlers receive it intact.
 * For internal type-error strings thrown without an explicit value,
 * $jqValue falls back to the message string.
 *
 * A variadic second parameter is used instead of a nullable default so that
 * an explicit null (e.g. `null | error`) is preserved as null rather than
 * being coerced to the message string by the ?? operator.
 */
class JQError extends \RuntimeException {

	public readonly mixed $jqValue;

	public function __construct( string $message, mixed ...$jqValue ) {
		parent::__construct( $message );
		// count() > 0 distinguishes "no value given" from "value is null"
		$this->jqValue = count( $jqValue ) > 0 ? $jqValue[0] : $message;
	}
}
