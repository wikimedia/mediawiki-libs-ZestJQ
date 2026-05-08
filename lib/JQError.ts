export class JQError extends Error {
	public constructor( message: string ) {
		super( message );
		this.name = 'JQError';
	}
}
