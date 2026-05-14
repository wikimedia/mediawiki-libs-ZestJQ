import { describe, it, expect } from 'vitest';
import { resolve } from 'path';
import { JQGrammar } from '../../lib/internal.js';
import { loadTests } from './jqTestLoader.js';

const root = resolve( __dirname, '../..' );
const tests = [
	...loadTests( resolve( root, 'tests/jq.test' ) ),
	...loadTests( resolve( root, 'tests/local.test' ) ),
];

// %%FAIL cases where jq enforces a semantic constraint, not a syntactic
// constraint. (This file tests only syntactic correctness.)
//
// Listed as line numbers in tests/jq.test.
const semanticFailureLines = new Set( [
	127, // {(0):1}                    — non-const object key
	139, // {non_const:., (0):1}       — non-const object key
	324, // . as $foo | break $foo     — break label must be $__label form
	560, // . as $foo | [$foo, $bar]   — unbound variable $bar
	566, // . as {(true):$foo} | $foo  — non-string key in object pattern
	1934, // module (.+1); 0            — module metadata must be a literal object
	1940, // module []; 0               — module metadata must be a literal object
	1964, // include "\(a)"; 0          — interpolated string in include path
	1982, // import "syntaxerror" as e; . — import path resolution (runtime, not syntax)
] );

const validTests = tests.filter( t => !t.fail );
const failTests = tests.filter( t => t.fail );

describe( 'JQGrammar — valid queries parse without error', () => {
	for ( const t of validTests ) {
		it( t.label, () => {
			const ast = JQGrammar.parse( t.query );
			expect( ast ).toHaveProperty( 'type' );
		} );
	}
} );

describe( 'JQGrammar — invalid queries throw SyntaxError', () => {
	for ( const t of failTests ) {
		const itFn = semanticFailureLines.has( t.lineno ) ? it.skip : it;
		itFn( t.label, () => {
			expect( () => JQGrammar.parse( t.query ) ).toThrow( JQGrammar.SyntaxError );
		} );
	}
} );
