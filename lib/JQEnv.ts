import type { FilterFn, FilterFactory } from './JQUtils.js';
import { JQError } from './JQError.js';

export type Binding = FilterFn | FilterFactory;

export abstract class JQEnv {
	public abstract lookup( name: string, arity: number ): Binding | null;

	public isPathMode(): boolean {
		return false;
	}

	public bind( name: string, arity: number, fn: Binding ): JQEnv {
		// eslint-disable-next-line no-use-before-define
		return new JQBindEnv( this, `${name}/${arity}`, fn );
	}

	// Path-mode stubs — implemented when path/1 is ported
	public enterPathMode(): never {
		throw new JQError( 'unimplemented: path mode' );
	}

	public static getStdEnv(): JQEnv {
		// eslint-disable-next-line no-use-before-define
		return new JQBaseEnv();
	}
}

class JQBindEnv extends JQEnv {
	public constructor(
		private readonly parent: JQEnv,
		private readonly key: string,
		private readonly fn: Binding,
	) {
		super();
	}

	public lookup( name: string, arity: number ): Binding | null {
		return `${name}/${arity}` === this.key ? this.fn : this.parent.lookup( name, arity );
	}
}

class JQBaseEnv extends JQEnv {
	public lookup( _name: string, _arity: number ): Binding | null {
		return null;
	}
}
