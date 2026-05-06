<?php
declare( strict_types = 1 );

$cfg = require __DIR__ . '/../vendor/mediawiki/mediawiki-phan-config/src/config.php';

$cfg['directory_list'] = [ 'src', '.phan/stubs', 'vendor/wikimedia/wikipeg', /*,'tests'*/ ];
$cfg['suppress_issue_types'] = [ 'UnusedPluginSuppression' ];

// Exclude peg-generated output
$cfg['exclude_file_list'][] = "src/JQGrammar.php";

return $cfg;
