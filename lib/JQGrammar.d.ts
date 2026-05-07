export interface ParseOptions {
	startRule?: string;
	filename?: string;
}

export type ASTNode = { type: string; [key: string]: unknown };

export interface JQSyntaxError extends Error {
	name: 'SyntaxError';
	expected: unknown[] | null;
	found: string | null;
	location: unknown;
}

export declare const SyntaxError: new ( ...args: unknown[] ) => JQSyntaxError;
export declare const DefaultTracer: new () => unknown;
export declare function parse( input: string, options?: ParseOptions ): ASTNode;
