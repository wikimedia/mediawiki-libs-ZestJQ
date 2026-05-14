import { JQEnv, JQCompile, JQError, JQUtils, JQGrammar } from './internal.js';
import type { JQFilter, JQValue } from './internal.js';

/**
 * Main entry point for the ZestJQ compiler/evaluator.
 */
export class JQ {

	/**
	 * Parse and evaluate a jq filter against a JSON input string.
	 *
	 * @param {string} input The input to the jq filter; must be a valid JSON string.
	 * @param {string} filter The jq filter expression
	 * @param {string} [filename] Optional source name for the filter expression, used
	 *   in syntax-error messages and the $__loc__ built-in.
	 * @param {JQEnv} [env] Optional extended environment; see JQEnv.extendEnv().
	 * @throws {JQError}
	 * @return {Generator<JQValue>}
	 */
	public static evalString(
		input: string, filter: string,
		filename?: string, env?: JQEnv,
	): Generator<JQValue> {
		return JQ.eval( JQUtils.jsonDecode( input ), filter, filename, env );
	}

	/**
	 * Parse and evaluate a jq filter against an input value.
	 *
	 * Note that all arrays must be lists (no string keys); use
	 * JQUtils.jsonDecode() to parse JSON into the required format.
	 *
	 * @param {JQValue} input The input to the jq filter.
	 * @param {string} filter The jq filter expression
	 * @param {string} [filename] Optional source name for the filter expression, used
	 *   in syntax-error messages and the $__loc__ built-in.
	 * @param {JQEnv} [env] Optional extended environment; see JQEnv.extendEnv().
	 * @throws {JQError}
	 * @return {Generator<JQValue>}
	 */
	public static eval(
		input: JQValue, filter: string,
		filename?: string, env?: JQEnv,
	): Generator<JQValue> {
		return JQ.compile( filter, filename, env )( input );
	}

	/**
	 * Parse and compile a jq filter into a reusable function.
	 *
	 * Compiling once and calling many times is more efficient than calling
	 * eval() repeatedly with the same filter.
	 *
	 * @param {string} filter The jq filter expression
	 * @param {string} [filename] Optional source name for the filter expression, used
	 *   in syntax-error messages and the $__loc__ built-in.
	 * @param {JQEnv} [env] Optional extended environment; see JQEnv.extendEnv().
	 * @param {boolean} [deferSyntaxError] If true, any syntax error in the filter is
	 *   not thrown until the compiled filter is called against an input.
	 * @throws {JQError}
	 * @return {JQFilter}
	 */
	public static compile(
		filter: string, filename?: string,
		env?: JQEnv, deferSyntaxError = false,
	): JQFilter {
		const effectiveFilename = filename ?? filter;
		try {
			const ast = JQGrammar.parse( filter );
			return JQCompile.compile( ast, env ?? JQEnv.getStdEnv() );
		} catch ( e ) {
			if ( e instanceof JQGrammar.SyntaxError ) {
				const loc = e.location as { start: { line: number; column: number } };
				const locStr = `${loc.start.line}:${loc.start.column}`;
				const msg = `Syntax error in ${effectiveFilename} (${locStr}): ${e.message}`;
				if ( deferSyntaxError ) {
					return ( _input: JQValue ) => {
						throw new JQError( msg );
					};
				}
				throw new JQError( msg );
			}
			throw e;
		}
	}
}
