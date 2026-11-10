# SafeScript for PHP

**JSON for code.** A safe computational subset of JavaScript, compiled to PHP.

This is the official PHP port of [SafeScript](https://github.com/nickg/safescript). It takes the same `.safe` files and SafeScript source code, compiles them through the same four-stage pipeline (tokenize → parse → validate → transform), then emits PHP instead of JavaScript. Zero dependencies, pure PHP 8.1+.

## Quick Start

```php
require_once 'vendor/autoload.php'; // or include src/ files directly

use SafeScript\SafeScript;

// Basic expression
$result = SafeScript::run('return x + y;', ['x' => 10, 'y' => 20]);
// → 30

// Safe globals are available by default — no injection needed
$result = SafeScript::run('return Math.floor(3.7);', []);   // → 3
$result = SafeScript::run('return Object.keys(obj);', ['obj' => ['a' => 1, 'b' => 2]]);  // → ['a', 'b']

// Math.random is deterministic by default (mulberry32 PRNG, seed 0)
$result = SafeScript::run('return Math.random();', []);  // → same value every time
```

## Install

### Composer

```bash
composer require qbix/safescript
```

### Manual

Copy the `src/` directory into your project and set up PSR-4 autoloading for the `SafeScript\` namespace, or include files directly:

```php
require_once 'src/Defs.php';
require_once 'src/SyntaxErrorEx.php';
require_once 'src/Tokenizer.php';
require_once 'src/Parser.php';
require_once 'src/Validator.php';
require_once 'src/Transform.php';
require_once 'src/EmitPhp.php';
require_once 'src/Runtime.php';
require_once 'src/Compiler.php';
require_once 'src/Header.php';
require_once 'src/SafeScript.php';
```

## API

### `SafeScript::run($source, $scope, $opts)`

Compile and execute SafeScript source. Returns the value from the `return` statement.

```php
// Positional arguments
$result = SafeScript::run('return n * 2;', ['n' => 21]);
// → 42

// Bundle form
$result = SafeScript::run([
    'source' => 'return n * 2;',
    'scope' => ['n' => 21]
]);

// With timeout (seconds) and operation limit
$result = SafeScript::run($source, $scope, ['timeout' => 5, 'maxOps' => 1000000]);
```

**Options:**
- `timeout` — max seconds (capped at 60). Always enforced even if omitted.
- `maxOps` — max operations / guard checks (capped at 100,000,000). Always enforced even if omitted.
- `hooks` — `['beforeCall' => fn, 'afterCall' => fn]` to intercept scope function calls. See [Hooks](#hooks).
- `seed` — PRNG seed. Default `0` (deterministic). Any integer for a custom seed. `null` for true `Math.random` (requires `env.Random`).
- `env` — `['Random' => true]` to approve environment capabilities.
- `facades` — per-method `this` facades.

**Scope** is an associative array of names available to the SafeScript code. Functions can be injected:

```php
$result = SafeScript::run('return greet("world");', [
    'greet' => function($name) { return "Hello, $name!"; }
]);
// → "Hello, world!"
```

### `SafeScript::compile($source)`

Compile SafeScript to PHP without executing. Returns `['code' => '...', 'hash' => '...']`.

### `SafeScript::validate($source)`

Check if source is valid SafeScript. Returns `true` or throws `SyntaxError` / `ValidationError`.

### `SafeScript::parse($source)`

Parse SafeScript source to an ESTree-like AST.

### `SafeScript::capabilities($source)`

Extract header metadata from a `.safe` file: `version`, `env`, `needs`, `uses`, `types`.

### `SafeScript::checkScope($source, $scope)`

Check if a scope satisfies the header's `@needs` declarations. Returns `['ok' => bool, 'missing' => [...]]`.

### `SafeScript::load($source, $resolver, $opts)`

Load a `.safe` file with automatic dependency resolution:

```php
$result = SafeScript::load($safeSource, function($name) {
    // Return the capability or null
    return match($name) {
        'db' => $myDbAdapter,
        'logger' => $myLogger,
        default => null,
    };
});
```

### `SafeScript::sanitize($value)`

Deep-copy a value, stripping blocked properties (`__proto__`, `constructor`, etc.) for safe injection into scope.

### `SafeScript::lib($name, $value)` / `SafeScript::lib($className, $config)` / `SafeScript::lib($typeName, $className, $config)`

Three forms:
- `lib($name, $value)` — register a named library (auto-resolved by `load()` for `@needs`/`@uses`)
- `lib($className, ['allowMethods' => [...]])` — register a type whitelist for runtime method enforcement
- `lib($typeName, $className, ['allowMethods' => [...]])` — register a named type (for `@type` header validation) and class whitelist (for runtime enforcement)

### `SafeScript::unlib($name)`

Unregister a named library, a type registration, or a named type mapping.

### `SafeScript::libs()`

Returns an array of registered library names.

### `SafeScript::clearTypeRegistry()`

Clear all type registrations (both class whitelists and named type mappings).

## The `.safe` File Format

SafeScript files use the `.safe` extension. A `.safe` file declares capabilities at the top:

```js
/**
 * @safescript 0.2
 * @needs request: Request
 * @needs db: Database
 * @uses logger: Logger
 * @type Response { json, text, status }
 */

var user = db.find(request.userId);
if (!user) {
    throw 'User not found';
}
return { name: user.name, email: user.email };
```

Header directives:
- `@safescript <version>` — language version
- `@needs <name>: <Type>` — required capability
- `@uses <name>: <Type>` — optional capability
- `@type <TypeName> { method1, method2 }` — declares a return type and the methods the script intends to call
- `@env <env1>, <env2>` — environment constraints

### Type Registry

When host functions return objects, SafeScript blocks all method calls on unregistered types. The type registry controls access:

```php
// Register a type with allowed methods
SafeScript::lib(Counter::class, ['allowMethods' => ['value', 'increment', 'toString']]);

// Now SafeScript code can call those methods — and only those
$result = SafeScript::run('return c.value()', ['c' => new Counter(42)]);
// → 42

// Named type registration (for @type header validation)
SafeScript::lib('Response', ResponseClass::class, ['allowMethods' => ['json', 'text', 'status']]);
```

### The `.safe` File as a Trust Unit

A `.safe` file is a unit of trust — one set of declarations, one sandbox boundary. If you want two functions with different access patterns, put them in two `.safe` files. Each gets its own scope, its own type declarations, and its own sandbox.

SafeScript protects the **host's** environment. User protection comes from a different layer — attestations about the host, not constraints in SafeScript. See [Safebots layers](https://safebots.ai/layers.html).

## What's Blocked

SafeScript blocks dangerous constructs at four levels:

1. **Token/grammar allowlist** — `eval`, `Function`, `import`, `require`, `new`, `delete`, `with`, `class`, `super`, `globalThis`, `window`, `document`, `process` are all blocked at tokenization. (`this` is allowed — it compiles to a safe internal identifier.)
2. **AST validation** — property access to `__proto__`, `constructor`, `prototype`, `__defineGetter__`, etc.
3. **Runtime guards** — bracket access goes through `__at()` / `__set()` which check property names at runtime
4. **Execution guards + hooks** — compile-time injected `__check()` enforces timeout/ops limits; hooks intercept calls to injected scope functions for resource throttling

## Execution Guards

The compiler injects `__check()` calls at the top of every function body and at the start of every loop iteration, catching infinite loops and infinite recursion at the source level.

```php
// Infinite loop → throws RuntimeException: "SafeScript: operation limit exceeded"
SafeScript::run('while (true) {}', []);

// Infinite recursion → throws RuntimeException: "SafeScript: operation limit exceeded"
SafeScript::run('function f() { return f(); } return f();', []);

// Custom limits
SafeScript::run($source, $scope, ['timeout' => 5, 'maxOps' => 50000]);
```

**Hardcoded ceilings.** Even if the host omits `timeout` or passes `0`, a ceiling of **60 seconds** is always enforced. Similarly, `maxOps` is capped at **100 million**. These ceilings cannot be raised.

## Hooks

Hooks let the host intercept every call to injected scope functions — throttle resource usage, enforce call limits, transform arguments or results:

```php
$result = SafeScript::run($source, $scope, [
    'hooks' => [
        'beforeCall' => function($name, $args) {
            // Block dangerous functions
            if ($name === 'db_drop') return false;
            // Transform arguments
            if ($name === 'db_query') return ['args' => [sanitize($args[0])]];
            // Short-circuit with cached value
            if ($name === 'loadData' && $rateLimited) return ['value' => null];
        },
        'afterCall' => function($name, $result, $args) {
            // Cap result size
            if (is_array($result) && count($result) > 1000) {
                return ['value' => array_slice($result, 0, 1000)];
            }
        }
    ]
]);
```

**`beforeCall($name, $args)`** returns: `false` (block), `['value' => x]` (short-circuit), `['args' => [...]]` (transform args), or anything else (proceed).

**`afterCall($name, $result, $args)`** returns: `false` (suppress), `['value' => x]` (replace result), or anything else (pass through).

## JS ↔ PHP Differences

| Feature | JS version | PHP version |
|---------|-----------|-------------|
| Execution | Compiled JS in `new Function()` | Compiled PHP in `eval()` with namespace sandbox |
| `async/await` | Supported (returns Promise) | Stripped (`await x` → `(x)`, `async function` → `function`) |
| Concurrency | Host-driven | Sequential; host objects handle concurrency internally |
| `load()` | Async (returns Promise) | Synchronous |
| Objects | JS objects | PHP associative arrays |
| `undefined` | `undefined` | `null` |
| Global shadowing | Required (security barrier) | Not needed (PHP functions don't see globals) |
| `timeout` option | Via `__check()` + `Promise.race` | Via `__check()` + `$GLOBALS` deadline |
| `maxOps` option | Via `__check()` closure counter | Via `__check()` + `$GLOBALS` counter |
| `hooks` option | `info` object: `{ name, args, callCount, elapsed }` | Params: `($name, $args)` / `($name, $result, $args)` |

## Running Tests

```bash
php test.php                 # main test suite (186+ tests)
php test-type-registry.php   # type registry — method whitelisting
php test-type-header.php     # @type header declarations + load() validation
```

## License

MIT
