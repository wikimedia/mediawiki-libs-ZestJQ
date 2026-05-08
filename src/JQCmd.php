<?php
declare( strict_types = 1 );

namespace Wikimedia\ZestJQ;

use Closure;
use Wikimedia\WikiPEG\SyntaxError;

/**
 * Command-line entry point for the zestjq tool.
 */
class JQCmd {

	/**
	 * When true, stderr output is accumulated in $err instead of
	 * written to STDERR.
	 */
	public static bool $runningTests = false;

	/**
	 * Accumulated stderr output in test mode; it is reset by each
	 * main() call.
	 */
	public static string $err = '';

	private static function err( string $msg ): void {
		if ( self::$runningTests ) {
			self::$err .= $msg;
		} else {
			fwrite( STDERR, $msg );
		}
	}

	// Script name has been trimmed from the argument array.
	public static function main( int $argc, array $argv ): int {
		self::$err = '';
		// Parse flags and positional args (minimal jq-compatible subset)
		/** @var array{nullInput: bool, rawOutput: bool, compactOutput: bool, astOutput: bool, showHelp: bool} */
		$options = [
			'nullInput'     => false,
			'rawOutput'     => false,
			'compactOutput' => false,
			'astOutput'     => false,
			'showHelp'      => false,
		];

		$args = [];
		for ( $i = 0; $i < $argc; $i++ ) {
			$arg = $argv[$i];
			if ( $arg === '--' ) {
				$args = [ ...$args, ...array_slice( $argv, $i + 1 ) ];
				break;
			} elseif ( $arg === '-n' || $arg === '--null-input' ) {
				$options['nullInput'] = true;
			} elseif ( $arg === '-r' || $arg === '--raw-output' ) {
				$options['rawOutput'] = true;
			} elseif ( $arg === '-c' || $arg === '--compact-output' ) {
				$options['compactOutput'] = true;
			} elseif ( $arg === '-h' || $arg === '--help' ) {
				$options['showHelp'] = true;
			} elseif ( $arg === '--ast' ) {
				$options['astOutput'] = true;
			} elseif ( $arg[0] === '-' ) {
				self::err( "zestjq: unknown option: $arg\n" );
				return 2;
			} else {
				$args[] = $arg;
			}
		}

		if ( count( $args ) < 1 || $options['showHelp'] ) {
			self::err( "Usage: zestjq [-n] [-r] [-c] [--ast] <filter> [file...]\n" );
			return 2;
		}

		$filterExpr = $args[0];
		$files      = array_slice( $args, 1 );

		// Compile the filter once
		$g = new JQGrammar;
		try {
			$ast = $g->parse( $filterExpr, [ 'filename' => '<top-level>' ] );
		} catch ( SyntaxError $e ) {
			self::err( "zestjq: syntax error in filter: " . $e->getMessage() . "\n" );
			return 3;
		}

		$exitCode = 0;
		try {
			// If we support IOContexts in the future, we'd create a new
			// JQIOEnv here with the user's IOContext and the
			// JQEnv::getStdEnv() as its parent, in order to evaluate the
			// code, ensuring that the user IOContext is separate from the
			// 'no op'/'no user input' IO context used to evaluate the
			// standard environment.
			$filter = JQCompile::compile( $ast, JQEnv::getStdEnv() );
			if ( $options['astOutput'] ) {
				echo self::encodeOutput( $ast, $options ) . "\n";
			} elseif ( $options['nullInput'] ) {
				$exitCode = self::runFilter( $filter, null, $options );
			} elseif ( count( $files ) > 0 ) {
				foreach ( $files as $file ) {
					$raw = file_get_contents( $file );
					if ( $raw === false ) {
						self::err( "zestjq: cannot read file: $file\n" );
						$exitCode = max( $exitCode, 2 );
						continue;
					}
					try {
						$input = JQUtils::jsonDecode( $raw );
					} catch ( JQError ) {
						self::err( "zestjq: invalid JSON in file: $file\n" );
						$exitCode = max( $exitCode, 2 );
						continue;
					}
					$exitCode = max( $exitCode,
						self::runFilter( $filter, $input, $options )
					);
				}
			} else {
				$raw = stream_get_contents( STDIN );
				if ( $raw === false || trim( $raw ) === '' ) {
					self::err( "zestjq: no input\n" );
					return 2;
				}
				try {
					$input = JQUtils::jsonDecode( $raw );
				} catch ( JQError ) {
					self::err( "zestjq: invalid JSON in stdin\n" );
					return 2;
				}
				$exitCode = self::runFilter( $filter, $input, $options );
			}
		} catch ( JQHaltException $e ) {
			if ( $e->getMessage() !== '' ) {
				self::err( $e->getMessage() );
			}
			return $e->getCode();
		}

		return $exitCode;
	}

	/**
	 * @param mixed $val
	 * @param array{rawOutput: bool, compactOutput: bool} $options
	 * @return string JSON-encoded output
	 */
	private static function encodeOutput( mixed $val, array $options ): string {
		if ( $options['rawOutput'] && is_string( $val ) ) {
			return $val;
		}
		$flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
		if ( !$options['compactOutput'] ) {
			$flags |= JSON_PRETTY_PRINT;
		}
		// Match the JavaScript behavior of converting NaN/INF to `null`
		$flags |= JSON_PARTIAL_OUTPUT_ON_ERROR;
		$result = json_encode( $val, $flags );
		return ( $result === false ) ? '<json_encode failed>' : $result;
	}

	/**
	 * @param Closure $filter
	 * @param mixed $input
	 * @param array{rawOutput: bool, compactOutput: bool} $options
	 * @return int Exit code
	 */
	private static function runFilter( Closure $filter, mixed $input, array $options ): int {
		try {
			foreach ( $filter( $input ) as $output ) {
				echo self::encodeOutput( $output, $options ) . "\n";
			}
		} catch ( JQError $e ) {
			self::err( "zestjq: " . $e->getMessage() . "\n" );
			return 5;
		}
		return 0;
	}

}
