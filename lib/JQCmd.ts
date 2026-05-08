import { readFileSync } from 'fs';
import { parse, SyntaxError as JQSyntaxError } from './JQGrammar.js';
import type { ASTNode } from './JQGrammar.js';
import { JQCompile } from './JQCompile.js';
import { JQEnv } from './JQEnv.js';
import { JQError } from './JQError.js';
import { JQHaltException } from './JQHaltException.js';
import * as JQUtils from './JQUtils.js';
import type { JQFilter, JQValue } from './JQValue.js';

type JQCmdOptions = {
	nullInput: boolean;
	rawOutput: boolean;
	compactOutput: boolean;
	astOutput: boolean;
	showHelp: boolean;
};

/**
 * Command-line entry point for the zestjq tool.
 */
export class JQCmd {
	/**
	 * When true, stderr output is accumulated in err instead of
	 * written to process.stderr.
	 */
	public static runningTests: boolean = false;

	/**
	 * Accumulated stderr output in test mode; it is reset by each
	 * main() call.
	 */
	public static err: string = '';

	private static writeErr( msg: string ): void {
		if ( JQCmd.runningTests ) {
			JQCmd.err += msg;
		} else {
			process.stderr.write( msg );
		}
	}

	/**
	 * @param {string[]} argv - argument vector where the script name as been
	 *   trimmed from the start. Pass process.argv.slice(2) from Node.
	 * @return {number} exit code
	 */
	public static main( argv: string[] ): number {
		JQCmd.err = '';

		// Parse flags and positional args (minimal jq-compatible subset)
		const options = {
			nullInput: false,
			rawOutput: false,
			compactOutput: false,
			astOutput: false,
			showHelp: false,
		};

		const args: string[] = [];
		for ( let i = 0; i < argv.length; i++ ) {
			const arg = argv[ i ];
			if ( arg === '--' ) {
				args.push( ...argv.slice( i + 1 ) );
				break;
			} else if ( arg === '-n' || arg === '--null-input' ) {
				options.nullInput = true;
			} else if ( arg === '-r' || arg === '--raw-output' ) {
				options.rawOutput = true;
			} else if ( arg === '-c' || arg === '--compact-output' ) {
				options.compactOutput = true;
			} else if ( arg === '-h' || arg === '--help' ) {
				options.showHelp = true;
			} else if ( arg === '--ast' ) {
				options.astOutput = true;
			} else if ( arg[ 0 ] === '-' ) {
				JQCmd.writeErr( `zestjq: unknown option: ${arg}\n` );
				return 2;
			} else {
				args.push( arg );
			}
		}

		if ( args.length < 1 || options.showHelp ) {
			JQCmd.writeErr( 'Usage: zestjq [-n] [-r] [-c] [--ast] <filter> [file...]\n' );
			return 2;
		}

		const filterExpr = args[ 0 ];
		const files = args.slice( 1 );

		// Compile the filter once
		let ast: ASTNode;
		try {
			ast = parse( filterExpr, { filename: '<top-level>' } );
		} catch ( e ) {
			if ( e instanceof JQSyntaxError ) {
				JQCmd.writeErr( `zestjq: syntax error in filter: ${e.message}\n` );
				return 3;
			}
			throw e;
		}

		let exitCode = 0;
		try {
			// If we support IOContexts in the future, we'd create a new
			// JQIOEnv here with the user's IOContext and the
			// JQEnv::getStdEnv() as its parent, in order to evaluate the
			// code, ensuring that the user IOContext is separate from the
			// 'no op'/'no user input' IO context used to evaluate the
			// standard environment.
			const filter = JQCompile.compile( ast, JQEnv.getStdEnv() );
			if ( options.astOutput ) {
				const encoded = JQCmd.encodeOutput( ast as unknown as JQValue, options );
				process.stdout.write( encoded + '\n' );
			} else if ( options.nullInput ) {
				exitCode = JQCmd.runFilter( filter, null, options );
			} else if ( files.length > 0 ) {
				for ( const file of files ) {
					let raw: string;
					try {
						// eslint-disable-next-line security/detect-non-literal-fs-filename
						raw = readFileSync( file, 'utf8' );
					} catch ( _e ) {
						JQCmd.writeErr( `zestjq: cannot read file: ${file}\n` );
						exitCode = Math.max( exitCode, 2 );
						continue;
					}
					let input: JQValue;
					try {
						input = JQUtils.jsonDecode( raw );
					} catch ( _e ) {
						JQCmd.writeErr( `zestjq: invalid JSON in file: ${file}\n` );
						exitCode = Math.max( exitCode, 2 );
						continue;
					}
					exitCode = Math.max( exitCode,
						JQCmd.runFilter( filter, input, options ),
					);
				}
			} else {
				let raw: string;
				try {
					raw = readFileSync( 0, 'utf8' ); // fd 0 = stdin
				} catch ( _e ) {
					raw = '';
				}
				if ( raw.trim() === '' ) {
					JQCmd.writeErr( 'zestjq: no input\n' );
					return 2;
				}
				let input: JQValue;
				try {
					input = JQUtils.jsonDecode( raw );
				} catch ( _e ) {
					JQCmd.writeErr( 'zestjq: invalid JSON in stdin\n' );
					return 2;
				}
				exitCode = JQCmd.runFilter( filter, input, options );
			}
		} catch ( e ) {
			if ( e instanceof JQHaltException ) {
				if ( e.message !== '' ) {
					JQCmd.writeErr( e.message );
				}
				return e.exitCode;
			}
			throw e;
		}

		return exitCode;
	}

	private static encodeOutput(
		val: JQValue, options: JQCmdOptions,
	): string {
		if ( options.rawOutput && typeof val === 'string' ) {
			return val;
		}
		return options.compactOutput ?
			( JSON.stringify( val ) ?? 'null' ) :
			( JSON.stringify( val, null, 4 ) ?? 'null' );
	}

	private static runFilter(
		filter: JQFilter,
		input: JQValue,
		options: JQCmdOptions,
	): number {
		try {
			for ( const output of filter( input ) ) {
				process.stdout.write(
					JQCmd.encodeOutput( output, options ) + '\n',
				);
			}
		} catch ( e ) {
			if ( e instanceof JQError ) {
				JQCmd.writeErr( `zestjq: ${e.message}\n` );
				return 5;
			}
			throw e;
		}
		return 0;
	}
}
