[![Latest Stable Version]](https://packagist.org/packages/wikimedia/zest-jq) [![License]](https://packagist.org/packages/wikimedia/zest-jq)

ZestJQ
======

A PHP implementation of the [`jq`](https://jqlang.org/) JSON query language.
Provides both a library API and a `zestjq` command-line tool.
`ZestJQ` uses the same license as the original `jq` code (MIT).
We implement `jq` version 1.8.

This is not a port of the original C codebase, but a reimplementation
using the manual and the extensive `jq.test` file as a guide.
Claude Sonnet 4.6 was used to speed portions of the implementation
but every line in this code was manually reviewed and I performed
extensive clean up and refactoring on Claude's output.  (Claude
became confused and calls me "the linter" because I was always
altering what it output.)

Claude was a big help porting the numerous built-in functions in the
`jq` standard library.  The date-parsing and other related functions
imported from C would not be nearly as complete if I had to port these
entirely by hand.

This implementation passes the upstream jq test suite (524 tests) with
the following exceptions:
* JSON cannot represent NaN or infinity, and the PHP `json_decode` and
  `json_encode` functions similarly refuse to emit or accept these
  values.  Upstream `jq` uses an extended version of JSON to allow it
  to parse and emit these values; we do not.
* Similarly, we use IEEE floating point, as implemented by PHP, to
  represent all values and arithmetic.  In some places upstream `jq`
  uses extended precision: exact int64 for integers and support for
  preserving input number literals exactly.  The PHP implementation
  defines the `jq` built-ins `have_literal_numbers` and `have_decnum`
  to `false` to reflect our implementation choices.
* We don't implement the module-level directives `module`, `include`,
  and `import`.
* Our error message strings are consistent for most type checking
  operations, and thus do not match upstream `jq` exactly.
* The `debug` and `input` built-ins are not implemented, although
  there is skeleton support for providing different IO contexts to
  the evaluator.
* We don't enforce JSON nesting and path depth limits, and our
  recursive implementation may use more stack that upstream `jq`
  for some operations.

We've also [fixed some bugs in delete-path
support](https://github.com/jqlang/jq/issues/3538). Since PHP is a
memory-safe language, we expect that we do not have any memory
errors either.

Additional documentation can be found on
[mediawiki.org](https://www.mediawiki.org/wiki/ZestJQ).


Installation
------------

```
composer require wikimedia/zest-jq
```

PHP ≥ 8.1 is required. `ext-mbstring` must be enabled.


Library usage
-------------

### Evaluate a filter against a JSON string

```php
use Wikimedia\ZestJQ\JQ;

// evalString() returns a Generator that yields each output value.
foreach ( JQ::evalString( '{"name":"jq","version":2}', '.name' ) as $val ) {
    echo $val; // "jq"
}
```

### Evaluate a filter against a decoded PHP value

```php
use Wikimedia\ZestJQ\JQ;
use Wikimedia\ZestJQ\JQUtils;

// Use JQUtils::jsonDecode() to ensure objects are stdClass (not
// arrays), and that all arrays are lists.
$input = JQUtils::jsonDecode( '{"items":[1,2,3]}' );

foreach ( JQ::eval( $input, '.items[]' ) as $val ) {
    echo $val, "\n"; // 1, 2, 3
}
```

### Compile once, evaluate many times

```php
use Wikimedia\ZestJQ\JQ;

$filter = JQ::compile( '.[] | select(. > 2)' );

foreach ( $filter( [1, 2, 3, 4] ) as $val ) {
    echo $val, "\n"; // 3, 4
}
foreach ( $filter( [5, 1, 6] ) as $val ) {
    echo $val, "\n"; // 5, 6
}
```

### Error handling

```php
use Wikimedia\ZestJQ\JQ;
use Wikimedia\ZestJQ\JQError;

try {
    foreach ( JQ::evalString( '"hello"', '.foo' ) as $val ) {
        // ...
    }
} catch ( JQError $e ) {
    echo $e->getMessage();
}
```

### Custom definitions

```php
use Wikimedia\ZestJQ\JQEnv;
use Wikimedia\ZestJQ\JQ;

$env = JQEnv::getStdEnv()->extendEnv( 'def double: . * 2;' );

foreach ( JQ::eval( 5, 'double', null, $env ) as $val ) {
    echo $val; // 10
}
```


Command-line tool
-----------------
Our command-line tool is compatible with the upstream `jq` binary,
although we do not implement many command-line options.

```
zestjq [options] <filter> [file ...]
```

| Option | Description |
|--------|-------------|
| `-n`, `--null-input` | Use `null` as the input instead of reading stdin/files |
| `-r`, `--raw-output` | Print strings without JSON quoting |
| `-c`, `--compact-output` | Compact JSON output (no pretty-printing) |
| `--ast` | Print the parsed AST of the filter and exit |

**Examples:**

```bash
# Field access
echo '{"name":"world"}' | zestjq '.name'
# → "world"

# Arithmetic with null input
zestjq -n '1 + 1'
# → 2

# Raw string output
echo '"hello"' | zestjq -r '.'
# → hello

# Compact output
echo '[1,2,3]' | zestjq -c 'map(. * 2)'
# → [2,4,6]

# Multiple outputs
echo 'null' | zestjq -n '1, 2, 3'
# → 1
# → 2
# → 3
```


Running tests
-------------

```
composer install
composer test
```

Individual test commands:

```bash
# PHPUnit only
vendor/bin/phpunit

# Single test file
vendor/bin/phpunit tests/phpunit/JQCompileTest.php

# Fix code style
composer fix
```


History
-------
Upstream `jq` was created by Stephen Dolan and is currently maintained
by the [jqlang](https://github.com/jqlang/jq) community.

ZestJQ was originally implemented by C. Scott Ananian.

For version history since the original implementation,
see [HISTORY.md](HISTORY.md).

## License and Credits

`jq` is copyright (C) 2012 Stephen Dolan and contributors.
ZestJQ is a clean reimplementation in PHP and does not incorporate
the original C source code, but it does include `src/builtin.jq`
and `tests/jq.test` from the upstream jq project.

The PHP implementation is copyright (C) 2026 Wikimedia Foundation.

Both the original jq codebase and this implementation are distributed
under the MIT license; see [LICENSE](LICENSE) for details.

---
[Latest Stable Version]: https://poser.pugx.org/wikimedia/zest-jq/v/stable.svg
[License]: https://poser.pugx.org/wikimedia/zest-jq/license.svg
