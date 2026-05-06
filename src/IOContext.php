<?php
declare( strict_types = 1 );

namespace Wikimedia\ZestJQ;

/**
 * I/O context for JQ evaluation.
 *
 * Holds the input stream and output callbacks (debug, stderr) used by
 * I/O builtins such as input/0, inputs/0, debug/0, and stderr/0.
 * A single instance is shared across all JQEnv values derived from a
 * common root, so builtins see consistent state regardless of how far
 * the environment has been extended with new bindings.
 */
class IOContext {
	// TODO: input stream, debug/stderr callbacks
}
