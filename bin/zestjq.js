#!/usr/bin/env node
'use strict';
import { JQCmd } from '../dist/internal.js';
process.exit( JQCmd.main( process.argv.slice( 2 ) ) );
