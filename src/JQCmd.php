<?php
declare( strict_types = 1 );

namespace Wikimedia\Zest;

use Closure;
use Wikimedia\WikiPEG\SyntaxError;

/**
 * Command-line entry point for the zestjq tool.
 */
class JQCmd {

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
				fwrite( STDERR, "zestjq: unknown option: $arg\n" );
				return 2;
			} else {
				$args[] = $arg;
			}
		}

		if ( count( $args ) < 1 ) {
			fwrite( STDERR, "Usage: zestjq [-n] [-r] [--ast] <filter> [file...]\n" );
			return 2;
		}

		$filterExpr = $args[0];
		$files      = array_slice( $args, 1 );

		// Compile the filter once
		$g = new JQGrammar;
		try {
			$ast = $g->parse( $filterExpr );
		} catch ( SyntaxError $e ) {
			fwrite( STDERR, "zestjq: syntax error in filter: " . $e->getMessage() . "\n" );
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
						fwrite( STDERR, "zestjq: cannot read file: $file\n" );
						$exitCode = 2;
						continue;
					}
					try {
						$input = JQUtils::jsonDecode( $raw );
					} catch ( JQError ) {
						fwrite( STDERR, "zestjq: invalid JSON in file: $file\n" );
						$exitCode = 2;
						continue;
					}
					$exitCode = max( $exitCode, self::runFilter( $filter, $input, $rawOutput ) );
				}
			} else {
				$raw = stream_get_contents( STDIN );
				if ( $raw === false || trim( $raw ) === '' ) {
					fwrite( STDERR, "zestjq: no input\n" );
					return 2;
				}
				try {
					$input = JQUtils::jsonDecode( $raw );
				} catch ( JQError ) {
					fwrite( STDERR, "zestjq: invalid JSON in stdin\n" );
					return 2;
				}
				$exitCode = self::runFilter( $filter, $input, $rawOutput );
			}
		} catch ( JQHaltException $e ) {
			if ( $e->getMessage() !== '' ) {
				fwrite( STDERR, $e->getMessage() );
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
			fwrite( STDERR, "zestjq: " . $e->getMessage() . "\n" );
			return 5;
		}
		return 0;
	}

}
