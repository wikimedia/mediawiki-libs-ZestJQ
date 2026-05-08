export type JQValue =
  | null
  | boolean
  | number
  | string
  | JQValue[]
  | { [key: string]: JQValue };

// Public filter type: env already captured, takes input, yields outputs
export type JQFilter = ( input: JQValue ) => Generator<JQValue>;
