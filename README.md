[![Latest Stable Version]](https://packagist.org/packages/wikimedia/zest-jq) [![License]](https://packagist.org/packages/wikimedia/zest-jq)

ZestJQ
======

A PHP and TypeScript implementation of the [`jq`](https://jqlang.org/)
JSON query language.  Provides both a library API and a `zestjq`
command-line tool.  `ZestJQ` uses the same license as the original
`jq` code (MIT).  We implement `jq` version 1.8.x (validated against
upstream test cases as of May 2026).

This is not a port of the original C codebase, but a reimplementation
using the manual and the extensive `jq.test` file as a guide.
Claude Sonnet 4.6 was used to speed portions of the implementation
but every line in this code was manually reviewed and I performed
extensive clean up and refactoring on Claude's output.  (Claude
became confused and began to call me "the linter" because I was always
altering what it output...)

Claude was a big help porting the numerous built-in functions in the
`jq` standard library.  The date-parsing and other related functions
imported from C would not be nearly as complete if I had to port these
entirely by hand.  After the PHP implementation was substantially
complete, Claude was used to assist the mostly-mechanical transformation
from PHP to TypeScript, although again I reviewed every line and
made numerous refinements.

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
support](https://github.com/jqlang/jq/issues/3538). Since
PHP/TypeScript are memory-safe languages, we expect that we do not
have any memory errors either.

Additional documentation can be found on
[mediawiki.org](https://www.mediawiki.org/wiki/ZestJQ).


PHP installation
----------------

```
composer require wikimedia/zest-jq
```

PHP ≥ 8.1 is required. `ext-mbstring` must be enabled.


PHP library usage
-----------------

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

Running PHP tests
-----------------

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

TypeScript installation
-----------------------

```
npm install @wikimedia/zest-jq
```


TypeScript library usage (node)
-------------------------------

### Evaluate a filter against a JSON string

```typescript
import { JQ } from '@wikimedia/zest-jq';

// evalString() returns a Generator that yields each output value.
for ( const val of JQ.evalString( '{"name":"jq","version":2}', '.name' ) ) {
    console.log( val ); // "jq"
}
```

### Evaluate a filter against a decoded value

```typescript
import { JQ } from '@wikimedia/zest-jq';

const input = { items: [ 1, 2, 3 ] };

for ( const val of JQ.eval( input, '.items[]' ) ) {
    console.log( val ); // 1, 2, 3
}
```

### Compile once, evaluate many times

```typescript
import { JQ } from '@wikimedia/zest-jq';

const filter = JQ.compile( '.[] | select(. > 2)' );

for ( const val of filter( [ 1, 2, 3, 4 ] ) ) {
    console.log( val ); // 3, 4
}
for ( const val of filter( [ 5, 1, 6 ] ) ) {
    console.log( val ); // 5, 6
}
```

### Error handling

```typescript
import { JQ, JQError } from '@wikimedia/zest-jq';

try {
    for ( const val of JQ.evalString( '"hello"', '.foo' ) ) {
        // ...
    }
} catch ( e ) {
    if ( e instanceof JQError ) {
        console.error( e.message );
    }
}
```


TypeScript library usage (browser)
----------------------------------

Build the browser bundle from the project root:

```bash
fresh-node -- npm run build:browser
```

This produces three files in `dist/browser/`:
- `zestjq.iife.js` — unminified IIFE bundle (for debugging)
- `zestjq.iife.min.js` — minified IIFE bundle (recommended for production)
- `zestjq.esm.js` — ES module bundle (for use with `<script type="module">`)

### Via `<script>` tag (IIFE)

The IIFE bundle exposes all exports as properties of `window.ZestJQ`:

```html
<script src="zestjq.iife.min.js"></script>
<script>
const { JQ, JQError } = ZestJQ;

for ( const val of JQ.evalString( '{"name":"jq"}', '.name' ) ) {
    console.log( val ); // "jq"
}
</script>
```

### Via ES module

```html
<script type="module">
import { JQ, JQError } from './zestjq.esm.js';

for ( const val of JQ.evalString( '{"name":"jq"}', '.name' ) ) {
    console.log( val ); // "jq"
}
</script>
```

### Compile once, evaluate many times (browser)

```html
<script type="module">
import { JQ } from './zestjq.esm.js';

const filter = JQ.compile( '.[] | select(. > 2)' );

for ( const val of filter( [ 1, 2, 3, 4 ] ) ) {
    console.log( val ); // 3, 4
}
</script>
```


Running TypeScript tests
------------------------

```bash
fresh-node -- npm test
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


Cookbook
--------

Common query patterns using a classic store inventory document.

```json
{
  "store": {
    "book": [
      { "category": "reference", "author": "Nigel Rees",
        "title": "Sayings of the Century", "price": 8.95 },
      { "category": "fiction",   "author": "Evelyn Waugh",
        "title": "Sword of Honour",        "price": 12.99 },
      { "category": "fiction",   "author": "Herman Melville",
        "title": "Moby Dick", "isbn": "0-553-21311-3", "price": 8.99 }
    ],
    "bicycle": { "color": "red", "price": 19.95 }
  }
}
```

Setup — replace `$json` / `json` with the JSON string above:

```bash
STORE='{"store":{"book":[{"category":"reference","author":"Nigel Rees","title":"Sayings of the Century","price":8.95},{"category":"fiction","author":"Evelyn Waugh","title":"Sword of Honour","price":12.99},{"category":"fiction","author":"Herman Melville","title":"Moby Dick","isbn":"0-553-21311-3","price":8.99}],"bicycle":{"color":"red","price":19.95}}}'
```

```php
/* PHP */
use Wikimedia\ZestJQ\JQ;
use Wikimedia\ZestJQ\JQUtils;
$store = JQUtils::jsonDecode( $json );
```

```typescript
/* JavaScript */
import { JQ } from '@wikimedia/zest-jq';
const store = JSON.parse( json );
```

**Get all authors:**

```bash
echo "$STORE" | zestjq -c '[.store.book[].author]'
# → ["Nigel Rees","Evelyn Waugh","Herman Melville"]
```

```php
/* PHP */
foreach ( JQ::eval( $store, '.store.book[].author' ) as $val ) {
    var_export( $val ); echo "\n";
}
// → 'Nigel Rees'
// → 'Evelyn Waugh'
// → 'Herman Melville'
```

```typescript
/* JavaScript */
for ( const val of JQ.eval( store, '.store.book[].author' ) ) {
    console.log( val );
}
// → Nigel Rees
// → Evelyn Waugh
// → Herman Melville
```

**Get the first book's title:**

```bash
echo "$STORE" | zestjq '.store.book[0].title'
# → "Sayings of the Century"
```

```php
/* PHP */
var_export( JQ::eval( $store, '.store.book[0].title' )->current() );
// → 'Sayings of the Century'
```

```typescript
/* JavaScript */
const [val] = JQ.eval( store, '.store.book[0].title' );
console.log( val );
// → Sayings of the Century
```

**Every `price` field at any nesting depth:**

```bash
echo "$STORE" | zestjq -c '[.. | .price? // empty]'
# → [8.95,12.99,8.99,19.95]
```

```php
/* PHP */
foreach ( JQ::eval( $store, '.. | .price? // empty' ) as $val ) {
    var_export( $val ); echo "\n";
}
// → 8.95
// → 12.99
// → 8.99
// → 19.95
```

```typescript
/* JavaScript */
for ( const val of JQ.eval( store, '.. | .price? // empty' ) ) {
    console.log( val );
}
// → 8.95
// → 12.99
// → 8.99
// → 19.95
```

**Books cheaper than $10:**

```bash
echo "$STORE" | zestjq -c '[.store.book[] | select(.price < 10)]'
# → [{"category":"reference","author":"Nigel Rees","title":"Sayings of the Century","price":8.95},
# →  {"category":"fiction","author":"Herman Melville","title":"Moby Dick","isbn":"0-553-21311-3","price":8.99}]
```

```php
/* PHP */
foreach ( JQ::eval( $store, '.store.book[] | select(.price < 10)' ) as $val ) {
    var_export( $val ); echo "\n";
}
// → (object) array(
// →    'category' => 'reference',
// →    'author' => 'Nigel Rees',
// →    'title' => 'Sayings of the Century',
// →    'price' => 8.95,
// → )
// → (object) array(
// →    'category' => 'fiction',
// →    'author' => 'Herman Melville',
// →    'title' => 'Moby Dick',
// →    'isbn' => '0-553-21311-3',
// →    'price' => 8.99,
// → )
```

```typescript
/* JavaScript */
for ( const val of JQ.eval( store, '.store.book[] | select(.price < 10)' ) ) {
    console.log( val );
}
// → {
// →   category: 'reference',
// →   author: 'Nigel Rees',
// →   title: 'Sayings of the Century',
// →   price: 8.95
// → }
// → {
// →   category: 'fiction',
// →   author: 'Herman Melville',
// →   title: 'Moby Dick',
// →   isbn: '0-553-21311-3',
// →   price: 8.99
// → }
```

**Books that have an ISBN:**

```bash
echo "$STORE" | zestjq -c '[.store.book[] | select(has("isbn"))]'
# → [{"category":"fiction","author":"Herman Melville","title":"Moby Dick","isbn":"0-553-21311-3","price":8.99}]
```

```php
/* PHP */
foreach ( JQ::eval( $store, '.store.book[] | select(has("isbn"))' ) as $val ) {
    var_export( $val ); echo "\n";
}
// → (object) array(
// →    'category' => 'fiction',
// →    'author' => 'Herman Melville',
// →    'title' => 'Moby Dick',
// →    'isbn' => '0-553-21311-3',
// →    'price' => 8.99,
// → )
```

```typescript
/* JavaScript */
for ( const val of JQ.eval( store, '.store.book[] | select(has("isbn"))' ) ) {
    console.log( val );
}
// → {
// →   category: 'fiction',
// →   author: 'Herman Melville',
// →   title: 'Moby Dick',
// →   isbn: '0-553-21311-3',
// →   price: 8.99
// → }
```

**First two books:**

```bash
echo "$STORE" | zestjq -c '.store.book[:2]'
# → [{"category":"reference","author":"Nigel Rees","title":"Sayings of the Century","price":8.95},
# →  {"category":"fiction","author":"Evelyn Waugh","title":"Sword of Honour","price":12.99}]
```

```php
/* PHP */
var_export( JQ::eval( $store, '.store.book[:2]' )->current() );
// → array (
// →   0 =>
// →   (object) array(
// →      'category' => 'reference',
// →      'author' => 'Nigel Rees',
// →      'title' => 'Sayings of the Century',
// →      'price' => 8.95,
// →   ),
// →   1 =>
// →   (object) array(
// →      'category' => 'fiction',
// →      'author' => 'Evelyn Waugh',
// →      'title' => 'Sword of Honour',
// →      'price' => 12.99,
// →   ),
// → )
```

```typescript
/* JavaScript */
const [val] = JQ.eval( store, '.store.book[:2]' );
console.log( val );
// → [
// →   {
// →     category: 'reference',
// →     author: 'Nigel Rees',
// →     title: 'Sayings of the Century',
// →     price: 8.95
// →   },
// →   {
// →     category: 'fiction',
// →     author: 'Evelyn Waugh',
// →     title: 'Sword of Honour',
// →     price: 12.99
// →   }
// → ]
```

**First and last book:**

```bash
echo "$STORE" | zestjq -c '.store.book[0,-1]'
# → {"category":"reference","author":"Nigel Rees","title":"Sayings of the Century","price":8.95}
# → {"category":"fiction","author":"Herman Melville","title":"Moby Dick","isbn":"0-553-21311-3","price":8.99}
```

```php
/* PHP */
foreach ( JQ::eval( $store, '.store.book[0,-1]' ) as $val ) {
    var_export( $val ); echo "\n";
}
// → (object) array(
// →    'category' => 'reference',
// →    'author' => 'Nigel Rees',
// →    'title' => 'Sayings of the Century',
// →    'price' => 8.95,
// → )
// → (object) array(
// →    'category' => 'fiction',
// →    'author' => 'Herman Melville',
// →    'title' => 'Moby Dick',
// →    'isbn' => '0-553-21311-3',
// →    'price' => 8.99,
// → )
```

```typescript
/* JavaScript */
for ( const val of JQ.eval( store, '.store.book[0,-1]' ) ) {
    console.log( val );
}
// → {
// →   category: 'reference',
// →   author: 'Nigel Rees',
// →   title: 'Sayings of the Century',
// →   price: 8.95
// → }
// → {
// →   category: 'fiction',
// →   author: 'Herman Melville',
// →   title: 'Moby Dick',
// →   isbn: '0-553-21311-3',
// →   price: 8.99
// → }
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
