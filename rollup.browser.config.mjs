import resolve from '@rollup/plugin-node-resolve';
import terser from '@rollup/plugin-terser';

const input = 'dist/index.js';
const name = 'ZestJQ';

export default [
	{ input, output: { file: 'dist/browser/zestjq.iife.js', format: 'iife', name }, plugins: [ resolve() ] },
	{ input, output: { file: 'dist/browser/zestjq.iife.min.js', format: 'iife', name }, plugins: [ resolve(), terser() ] },
	{ input, output: { file: 'dist/browser/zestjq.esm.js', format: 'es' }, plugins: [ resolve() ] },
];
