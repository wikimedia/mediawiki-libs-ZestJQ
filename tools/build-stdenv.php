#!/usr/bin/env php
<?php
declare( strict_types = 1 );

require_once __DIR__ . '/../vendor/autoload.php';

use Wikimedia\WikiPEG\SyntaxError;
use Wikimedia\Zest\JQGrammar;

$builtinPath = __DIR__ . '/../src/builtin.jq';
$outPath     = __DIR__ . '/../src/JQBuiltin.php';

$src = file_get_contents( $builtinPath );
if ( $src === false ) {
	fwrite( STDERR, "Error: could not read $builtinPath\n" );
	exit( 1 );
}

$g = new JQGrammar;
try {
	$ast = $g->parse( $src . "\n\$__env__" );
} catch ( SyntaxError $e ) {
	fwrite( STDERR, "Syntax error in builtin.jq: " . $e->getMessage() . "\n" );
	exit( 1 );
}

$serialized = var_export( serialize( $ast ), true );
$content = <<<PHP
<?php
declare( strict_types = 1 );

namespace Wikimedia\Zest;

// Generated file — do not edit directly. Regenerate with: composer build-stdenv

/**
 * Pre-parsed AST of src/builtin.jq for fast bootstrapping.
 *
 * @internal
 */
class JQBuiltin {
	// phpcs:ignore Generic.Files.LineLength.MaxExceeded
	public const AST = {$serialized};

	public static function getAst(): array {
		return unserialize( self::AST );
	}
}
PHP;

if ( file_put_contents( $outPath, $content ) === false ) {
	fwrite( STDERR, "Error: could not write $outPath\n" );
	exit( 1 );
}

echo "Written: $outPath\n";
