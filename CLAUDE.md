# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Commands

```bash
# Regenerate src/JQGrammar.php, needed after every change to src/JQGrammar.pegphp
composer regen-jq-parser

# Run full test suite (lint + phpunit + phan + style checks)
composer test

# Run only PHPUnit tests
vendor/bin/phpunit

# Run a single test class
vendor/bin/phpunit tests/ZestJQTest.php

# Run a single test method
vendor/bin/phpunit --filter testFind tests/ZestJQTest.php

# Fix code style issues
composer fix

# Static analysis only
composer phan
```

## Architecture

**`src/Zest.php`** — Thin static facade. Holds a singleton `ZestInst` and delegates all calls to it.

**`src/ZestInst.php`** — The core engine. Contains:
- `initRules()`: Builds the regex grammar for CSS selectors. Each grammar rule is a named regex fragment. `replace()` expands named placeholders inline. `makeInside()` creates a balanced-bracket content regex.
- `compile()` / `tok()`: The lexer/parser. `compile()` iterates over the selector string matching against `$rules->simple` (and combinators). `tok()` dispatches on capture groups (1=class/id, 2-3=pseudo, 4-6=attr, 7-8=jqattr) to return a `callable($el, $opts): bool`.
- Selector callables are cached in `$compileCache` keyed on the selector string.
- `find()` / `matches()`: Public entry points; `find()` uses optimization shortcuts (id, tag, class) when the selector is simple.

**`src/ZestJQ.php`** — JQ-style JSON attribute selectors. Added for the `[attr/jq_query]` selector syntax.
- `makeSelector()`: Returns a callable for use by `tok()`. Parses the query once at compile time; evaluates per element.

**`src/ZestFunc.php`** — Holds compiled selector state (used when compiling selectors with subjects).

**`tests/ZestTest.php`** — Main CSS selector test suite, driven by a `findProvider()` dataprovider.

**`tests/ZestJQTest.php`** — Tests for JQ selector syntax. Uses a static HTML fixture with JSON attributes (`data-mw`, `data-vals`). Tests are driven by `findProvider()` and call `Zest::find()` directly.

## JQ evaluation engine

### Files

**`src/JQGrammar.pegphp`** — PEG grammar source. After any edit, regenerate with `composer regen-jq-parser` to update `src/JQGrammar.php`.

**`src/JQCompile.php`** — Walks the AST and returns a `Closure(mixed $input, JQEnv $env): Generator`. Each `compileXxx()` method handles one node type. The dispatch table is `compileNode()`. Node types `import`, `include`, and `module` are not yet implemented; everything else the grammar can produce is handled.

**`src/JQTopLevelEnv.php`** — Native PHP builtins (arity-0 as bare Filters, arity-N as factory closures). Add new builtins here, not to `builtin.jq` (keep that close to upstream). The `builtins/0` snapshot is taken before adding itself, so new entries appear automatically.

**`src/JQUtils.php`** — Shared helpers: type checking (`checkString`, `checkNumber`, …), `typeName`, `toBoolean`, `isNumber`, `jsonEncode`/`jsonDecode`, `add`/`subtract`/`multiply`/`divide`, `compare`, `setAtPath`, `deleteAtPath`.

**`src/JQEnv.php`** / **`src/JQLazyEnv.php`** — Environment chain. `JQLazyEnv` defers loading `builtin.jq` until first use.

**`src/JQPathEnv.php`** — Subclass of `JQEnv` used when evaluating inside `path/1`. Overrides all path-mode methods so there are no runtime conditionals on `isPathMode()` — `JQEnv` methods implement the normal-mode behaviour directly and `JQPathEnv` methods implement the path-mode behaviour directly.

**`src/builtin.jq`** / **`tests/jq.test`** — Upstream jq files; do not modify.

### PHP representation of JQ types

| JQ type | PHP type |
|---------|----------|
| null | `null` |
| boolean | `bool` |
| number | `int` or `float` |
| string | `string` (UTF-8) |
| array | `array` with `array_is_list() === true` — enforced by `JQUtils::assertIsList()` |
| object | `stdClass` only |

### Key invariants

- **Truthiness**: only `null` and `false` are falsy — `0`, `""`, `[]`, `{}` are all truthy. Use `JQUtils::toBoolean()`.
- **Binop generator order**: right operand is the outer loop, left is the inner loop (matches jq semantics). `and`/`or` are the opposite (left-outer) with short-circuit.
- **Pattern variables** (`var_pattern`, `array_pattern`, `obj_pattern`, `alt_pattern`) are compiled by `compilePattern()`, not `compileNode()`.
- **`foreach EXPR as $pat (init; update; extract)`**: `$pat` is visible in `update` and `extract` but NOT in `init`.
- **`_strindices/1`** returns Unicode codepoint offsets (matching jq PR #3065, not the byte offsets of the jq 1.7 binary). Implemented with `explode()` for O(N) performance.

### Path mode

`path/1` is implemented by running its argument filter in **path mode**, represented by the `JQPathEnv` subclass of `JQEnv`.

**Representation.** In path mode, structural generators yield `[$pathEnv, $value]` pairs instead of bare values. `$pathEnv` is a `JQPathEnv` whose chain encodes the accumulated path as a linked list of single-key tail segments; `getPath()` walks the chain bottom-up (pushing to a reverse array, then reversing once) to reconstruct the full path array in O(N) time without O(N²) array spreading at each step.

`JQPathEnv` carries a single `$pathKey` field per node. The root `JQPathEnv` created by `enterPathMode()` uses `false` as a sentinel key so that `getPath()` can stop at the right level (it collects keys while `$p->parent->isPathMode()` is true, so the sentinel's own key is never included).

**Path mode API — `JQEnv` (normal mode) vs `JQPathEnv` (path mode):**

| Method | `JQEnv` (normal) | `JQPathEnv` (path) |
|--------|------------------|--------------------|
| `isPathMode()` | `false` | `true` |
| `enterPathMode()` | returns new `JQPathEnv` (sentinel root) | throws `LogicException` |
| `appendPath($key)` | returns `$this` (no-op, no allocation) | returns new `JQPathEnv` child storing `$key` |
| `leavePathMode()` | returns `$this` (no-op, no allocation) | returns new plain `JQEnv` |
| `getPath()` | throws `LogicException` | walks chain bottom-up, returns reversed array |
| `maybeWithPath($v)` | returns `$v` unchanged | returns `[$this, $v]` |
| `maybeUnwrapPath($item)` | returns `[$this, $item]` | returns `$item` (already a pair) |
| `extractPath($item)` | `$item[0]->getPath()` (same in both) | same |

**How compile\* methods participate:**
- `compileIdentity`: `yield $env->maybeWithPath($input)` — path does not extend.
- `compileField`, `compileIndex`, `compileIter`, `compileSlice`: call `$env->appendPath($key)->maybeWithPath($value)` so each access extends the path by one segment. The key/bound expressions for index and slice are evaluated with `leavePathMode()`.
- `compilePipe`: uses `maybeUnwrapPath` to unpack each left-side output and forward the path env to the right side.
- `compileIf`: evaluates the condition with `leavePathMode()` so that `select(f)` (defined as `if f then . else empty end`) works correctly inside path expressions.
- `compileTryCatch`: runs the catch handler with `leavePathMode()` since the error value is not a path output.
- `compileComma`, `compileDef`, `compileCall`: no changes needed — the path-mode flag flows naturally through `$env`.

**Filter parameters across `def` boundaries.** When a filter parameter `f` is called inside `path(f)`, the binding closure in `compileDef` normally discards the runtime env and uses the captured `$callEnv`. When `$env->isPathMode()` is true it instead re-roots the accumulated path onto `$callEnv` via `$callEnv->enterPathMode()` followed by `appendPath()` for each segment returned by `$env->getPath()`. This preserves both the call-site variable bindings and the path prefix (typically 0–2 segments deep).

**Slice paths.** `compileSlice` in path mode yields path keys of the form `(object)['start' => $from, 'end' => $to]` (raw unnormalized bounds). `deleteAtPath` recognises a `stdClass` key as a slice and calls `JQUtils::normalizeSliceIdx()` (now public) to resolve the bounds against the actual array length before splicing.

### Error messages

We do not try to match jq's error message wording exactly. When throwing
type errors, always use a `JQUtils::check*` method (e.g. `checkString`,
`checkNumber`, `checkArray`) rather than crafting a custom message — this
keeps wording consistent across builtins. If a `jq.test` case captures an
error message into its expected output and our wording differs, add an entry
to `JQCompileTest::normalizeErrors()` that rewrites the message as it
appears in the test output (either rewriting our message to match
jq's, or jq's message to match ours).

### Test coverage

**`tests/JQCompileTest.php`** — Driven by `tests/jq.test`. Tests marked
`fail` in the upstream file are excluded entirely. Tests with a known
bug are listed in `skipReason()` and run in a special mode: the test
body executes normally, and if it **fails** (throws or asserts false)
the test is marked skipped with the reason string; if it unexpectedly
**passes**, the test fails with "Test marked to skip, but it
unexpectedly passed!" — this is intentional, to keep the skip list
accurate as bugs are fixed.

**Workflow for fixing a bug covered by a skip entry:**
1. Remove the relevant line numbers from `skipReason()`.
2. Fix the bug.
3. Run `vendor/bin/phpunit tests/JQCompileTest.php` to confirm the
   formerly-skipped tests now pass and no new failures appear.
4. Commit both the fix and the skip-list update together.

**Workflow when a fix accidentally resolves additional skipped tests:**
Run the full PHPUnit suite; any skip entry whose test now passes will
surface as a `FAILURE: Test marked to skip, but it unexpectedly
passed!`. Remove those entries from `skipReason()` and include the
cleanup in the same commit as the fix.

**`tests/JQCmdTest.php`** — Hand-written integration tests for the `zestjq` CLI covering edge cases not easily expressed in the jq.test format (negative indices, null container promotion, `deleteAtPath` splice behavior, halt/error exit codes).

## Dependency notes

- `wikimedia/remex-html` is a **dev-only** dependency used in tests (`ZestTest::parseHtml()`). The library itself has no runtime HTML parser dependency.
- PHP ≥ 8.1 required. `ext-mbstring` and `ext-xml` are required; `ext-intl` is optional (used in `unichr()`).
- `src/builtin.jq` and `tests/jq.test` come from upstream jq, don't
  make any changes to them.  Instead add new built-ins to
  `src/JQTopLevelEnv.php` and new tests to `tests/JQCmdTest.php`.
