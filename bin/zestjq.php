#!/usr/bin/env php
<?php
declare( strict_types = 1 );

require_once __DIR__ . '/../vendor/autoload.php';

exit( \Wikimedia\ZestJQ\JQCmd::main( $argc - 1, array_slice( $argv, 1 ) ) );
