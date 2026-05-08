import type { JQValue } from './JQValue.js';
import type { JQEnv } from './JQEnv.js';
import { JQError } from './JQError.js';

// Inner filter: takes (input, env), returns a Generator of outputs
export type FilterFn = ( input: JQValue, env: JQEnv ) => Generator<JQValue>;

// Filter factory for n-arity functions: takes arg filters, returns a FilterFn
export type FilterFactory = ( args: FilterFn[] ) => FilterFn;

export function assertNotPath( value: JQValue, env: JQEnv ): void {
	if ( env.isPathMode() ) {
		throw new JQError( `Invalid path expression with result ${jsonEncode( value )}` );
	}
}

export function jsonDecode( s: string ): JQValue {
	const stripped = s.replace( /^\uFEFF/, '' ); // strip BOM
	try {
		return JSON.parse( stripped ) as JQValue;
	} catch {
		throw new JQError( `Invalid JSON: ${s}` );
	}
}

export function jsonEncode( v: JQValue ): string {
	return JSON.stringify( v ) ?? 'null';
}

export function typeName( v: JQValue ): string {
	if ( v === null ) {
		return 'null';
	}
	if ( typeof v === 'boolean' ) {
		return 'boolean';
	}
	if ( typeof v === 'number' ) {
		return 'number';
	}
	if ( typeof v === 'string' ) {
		return 'string';
	}
	if ( Array.isArray( v ) ) {
		return 'array';
	}
	return 'object';
}

export function isNumber( v: JQValue ): v is number {
	return typeof v === 'number';
}

export function toBoolean( v: JQValue ): boolean {
	return v !== null && v !== false;
}

// Total ordering: null(0) < false(1) < true(2) < number(3) < string(4) < array(5) < object(6).
// Returns negative, zero, or positive.
export function compare( a: JQValue, b: JQValue ): number {
	const order = ( v: JQValue ): number => {
		if ( v === null ) {
			return 0;
		}
		if ( v === false ) {
			return 1;
		}
		if ( v === true ) {
			return 2;
		}
		if ( typeof v === 'number' ) {
			return 3;
		}
		if ( typeof v === 'string' ) {
			return 4;
		}
		if ( Array.isArray( v ) ) {
			return 5;
		}
		return 6; // object
	};
	const ta = order( a );
	const tb = order( b );
	if ( ta !== tb ) {
		return ta - tb;
	}
	if ( ta <= 2 ) {
		return 0;
	} // null or a specific boolean; only one value of this rank
	if ( ta <= 4 ) {
		// number or string
		return ( a as number | string ) < ( b as number | string ) ? -1 :
			( a as number | string ) > ( b as number | string ) ? 1 :
				0;
	}
	if ( ta === 5 ) {
		// array: zip-compare then by length
		const aa = a as JQValue[];
		const ba = b as JQValue[];
		const len = Math.min( aa.length, ba.length );
		for ( let i = 0; i < len; i++ ) {
			const c = compare( aa[ i ], ba[ i ] );
			if ( c !== 0 ) {
				return c;
			}
		}
		return aa.length - ba.length;
	}
	// object: compare sorted keys, then values in key order
	const ao = a as Record<string, JQValue>;
	const bo = b as Record<string, JQValue>;
	const ka = Object.keys( ao ).sort();
	const kb = Object.keys( bo ).sort();
	const kc = compare( ka as unknown as JQValue, kb as unknown as JQValue );
	if ( kc !== 0 ) {
		return kc;
	}
	for ( const k of ka ) {
		const c = compare( ao[ k ], bo[ k ] );
		if ( c !== 0 ) {
			return c;
		}
	}
	return 0;
}
