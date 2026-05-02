<?php
declare( strict_types = 1 );

namespace Wikimedia\Zest;

use Closure;
use Wikimedia\WikiPEG\SyntaxError;

/**
 * Command-line entry point for the zestjq tool.
 */
class JQCmd {

	/** When true, stderr output is accumulated in $err instead of written to STDERR. */
	public static bool $runningTests = false;

	/** Accumulated stderr output in test mode; reset before each main() call. */
	public static string $err = '';

	private static function err( string $msg ): void {
		if ( self::$runningTests ) {
			self::$err .= $msg;
		} else {
			fwrite( STDERR, $msg );
		}
	}

	public static function main( int $argc, array $argv ): int {
		// Parse flags and positional args (minimal jq-compatible subset)
		$nullInput = false;
		$rawOutput = false;
		$astOutput = false;
		$args = [];
		for ( $i = 1; $i < $argc; $i++ ) {
			$arg = $argv[$i];
			if ( $arg === '--' ) {
				$args = array_merge( $args, array_slice( $argv, $i + 1 ) );
				break;
			} elseif ( $arg === '-n' || $arg === '--null-input' ) {
				$nullInput = true;
			} elseif ( $arg === '-r' || $arg === '--raw-output' ) {
				$rawOutput = true;
			} elseif ( $arg === '--ast' ) {
				$astOutput = true;
			} elseif ( $arg[0] === '-' ) {
				self::err( "zestjq: unknown option: $arg\n" );
				return 2;
			} else {
				$args[] = $arg;
			}
		}

		if ( count( $args ) < 1 ) {
			self::err( "Usage: zestjq [-n] [-r] [--ast] <filter> [file...]\n" );
			return 2;
		}

		$filterExpr = $args[0];
		$files      = array_slice( $args, 1 );

		// Compile the filter once
		$g = new JQGrammar;
		try {
			$ast = $g->parse( $filterExpr );
		} catch ( SyntaxError $e ) {
			self::err( "zestjq: syntax error in filter: " . $e->getMessage() . "\n" );
			return 3;
		}

		$io     = new IOContext;
		$env    = new JQLazyEnv( $io );
		$filter = JQCompile::compile( $ast, $env );

		$exitCode = 0;
		try {
			if ( $astOutput ) {
				echo self::encodeOutput( $ast, $rawOutput ) . "\n";
			} elseif ( $nullInput ) {
				$exitCode = self::runFilter( $filter, null, $rawOutput );
			} elseif ( count( $files ) > 0 ) {
				foreach ( $files as $file ) {
					$raw = file_get_contents( $file );
					if ( $raw === false ) {
						self::err( "zestjq: cannot read file: $file\n" );
						$exitCode = 2;
						continue;
					}
					try {
						$input = JQUtils::jsonDecode( $raw );
					} catch ( JQError ) {
						self::err( "zestjq: invalid JSON in file: $file\n" );
						$exitCode = 2;
						continue;
					}
					$exitCode = max( $exitCode, self::runFilter( $filter, $input, $rawOutput ) );
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
				$exitCode = self::runFilter( $filter, $input, $rawOutput );
			}
		} catch ( JQHaltException $e ) {
			if ( $e->getMessage() !== '' ) {
				self::err( $e->getMessage() );
			}
			return $e->getCode();
		}

		return $exitCode;
	}

	private static function encodeOutput( mixed $val, bool $rawOutput ): string {
		if ( $rawOutput && is_string( $val ) ) {
			return $val;
		}
		return json_encode( $val, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	private static function runFilter( Closure $filter, mixed $input, bool $rawOutput ): int {
		try {
			foreach ( $filter( $input ) as $output ) {
				echo self::encodeOutput( $output, $rawOutput ) . "\n";
			}
		} catch ( JQError $e ) {
			self::err( "zestjq: " . $e->getMessage() . "\n" );
			return 5;
		}
		return 0;
	}

}
