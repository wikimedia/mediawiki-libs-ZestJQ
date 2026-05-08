#!/usr/bin/env node
'use strict';
const { JQCmd } = require( '../dist/JQCmd.js' );
process.exit( JQCmd.main( process.argv.slice( 2 ) ) );
