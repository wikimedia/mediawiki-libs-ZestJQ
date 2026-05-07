import { readFileSync } from 'fs';

export interface TestCase {
	query: string;
	label: string;
	lineno: number;
	input?: string;
	expected?: string[];
	fail?: true;
}

const testCache = new Map<string, TestCase[]>();

/**
 * Parse a jq.test / local.test file into an array of test cases.
 * Port of JQGrammarTest::loadTests() from tests/phpunit/JQGrammarTest.php.
 *
 * @param {string} filename
 * @return {TestCase[]} test cases loaded from the given file
 */
export function loadTests( filename: string ): TestCase[] {
	if ( testCache.has( filename ) ) {
		return testCache.get( filename );
	}
	// eslint-disable-next-line security/detect-non-literal-fs-filename
	const lines = readFileSync( filename, 'utf8' ).split( '\n' );
	const tests: TestCase[] = [];
	let i = 0;
	const total = lines.length;

	while ( i < total ) {
		// Skip blank lines and comment lines between groups
		if ( lines[ i ].trim() === '' || lines[ i ].trimStart().startsWith( '#' ) ) {
			i++;
			continue;
		}

		// Collect all non-blank, non-comment lines of this group
		const groupLines: { line: string; lineno: number }[] = [];
		while ( i < total && lines[ i ].trim() !== '' ) {
			if ( !lines[ i ].trimStart().startsWith( '#' ) ) {
				groupLines.push( { line: lines[ i ], lineno: i + 1 } );
			}
			i++;
		}

		if ( groupLines.length === 0 ) {
			continue;
		}

		const first = groupLines[ 0 ].line;

		if ( first.startsWith( '%%FAIL' ) ) {
			// %%FAIL or %%FAIL IGNORE MSG — second line is the bad query
			if ( groupLines[ 1 ] ) {
				const { line: query, lineno } = groupLines[ 1 ];
				tests.push( {
					fail: true,
					query,
					label: `line ${lineno}: ${query}`,
					lineno,
				} );
			}
		} else {
			// Normal group: first line is the query, second is input, rest are expected outputs
			const { line: query, lineno } = groupLines[ 0 ];
			const expected = groupLines.slice( 2 ).map( g => g.line );
			tests.push( {
				query,
				label: `line ${lineno}: ${query}`,
				lineno,
				input: groupLines[ 1 ]?.line,
				expected,
			} );
		}
	}

	testCache.set( filename, tests );
	return tests;
}
