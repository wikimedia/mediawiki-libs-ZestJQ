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

export interface Api {
	SyntaxError: new ( ...args: unknown[] ) => JQSyntaxError;
	DefaultTracer: new () => unknown;
	parse( input: string, options?: ParseOptions ): ASTNode;
}

declare const api: Api;

export default api;
