#!/usr/bin/env php
<?php
declare( strict_types = 1 );

// Find the appropriate path for the composer autoloader
if ( isset( $GLOBALS['_composer_autoload_path'] ) ) {
	define( 'PHPUNIT_COMPOSER_INSTALL', $GLOBALS['_composer_autoload_path'] );

	unset( $GLOBALS['_composer_autoload_path'] );
} else {
	foreach ( [ __DIR__ . '/../../autoload.php', __DIR__ . '/../vendor/autoload.php', __DIR__ . '/vendor/autoload.php' ] as $file ) {
		if ( file_exists( $file ) ) {
			define( 'PHPUNIT_COMPOSER_INSTALL', $file );

			break;
		}
	}

	unset( $file );
}

if ( !defined( 'PHPUNIT_COMPOSER_INSTALL' ) ) {
	fwrite(
		STDERR,
		'You need to set up the project dependencies using Composer:' . PHP_EOL . PHP_EOL .
		'    composer install' . PHP_EOL . PHP_EOL .
		'You can learn all about Composer on https://getcomposer.org/.' . PHP_EOL
	);

	die( 1 );
}

require PHPUNIT_COMPOSER_INSTALL;

exit( \Wikimedia\ZestJQ\JQCmd::main( $argc - 1, array_slice( $argv, 1 ) ) );
