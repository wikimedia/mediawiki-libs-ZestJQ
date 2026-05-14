/* This file defines the module loading order to avoid problems caused
 * circular dependencies.  See
 * https://medium.com/visual-development/how-to-fix-nasty-circular-dependency-issues-once-and-for-all-in-javascript-typescript-a04c987cf0de
 * for a description of this solution.
 *
 * Basically, this module imports and exports everything from every
 * local module in the project, and every other module in the project
 * will *only* import from this file, not directly from other modules.
 * That ensures that the module order in *this file* is the one source
 * of truth, and incidentally cleans up imports in other files.
 */

// leaf imports (do not import anything else)
export * from './UnreachableError.js';
export * from './JQValue.js';
export * from './IOContext.js';
export * from './JQBreak.js';
export * from './JQHaltException.js';
export { default as JQGrammar, ASTNode, ParseOptions } from './JQGrammar.js';
// JQError will use JQValue, but nothing else
export * from './JQError.js';
// JQBuiltin uses JQGrammar (ASTNode) but nothing else
export * from './JQBuiltin.js';
// JQEnv forward-references to JQCompile and JQ.
export * from './JQEnv.js';
// JQUtils uses JQValue, JQPathValue and JQError, but nothing else
export * as JQUtils from './JQUtils.js';
// JQTopLevelEnv uses JQEnv
export * from './JQTopLevelEnv.js';
// JQCompile uses JQEnv */
export * from './JQCompile.js';
// JQ uses JQCompile
export * from './JQ.js';
