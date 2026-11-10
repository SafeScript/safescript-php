# Changelog

## [1.3.0] — 2026-10-25

### Added
- **`SafeFiles` provider** (`src/SafeFiles.php`) — sandboxed file I/O scoped to a single root folder. Methods: `readFile`, `writeFile`, `appendFile`, `exists`, `deleteFile`, `listFiles`, `isDir`, `makeDir`, `readDir`, `fileSize`. Enforces per-file and total-write size limits. Blocks directory traversal. Declared via `@uses fs: SafeFiles`.
- **`SafeHttp` provider** (`src/SafeHttp.php`) — sandboxed HTTP client restricted to an allowed-domain list. Methods: `get`, `post`, `put`, `delete`, `postJson`, `getJson`. Supports cURL and stream-context fallback. Enforces response size limits and timeouts. Subdomain matching supported. Declared via `@uses api: SafeHttp`.
- `SafeScript::TYPES` — built-in type name constants (`SafeFiles`, `SafeHttp`).
- `SafeScript::resolveUses($header)` — resolve `@uses` directives against built-in type registry. Returns provider flags: `files`, `http`.
- `Header::BUILTIN_TYPES` — registry of built-in type names and their providers (`files`, `http`).
- `Header::resolveUses($header)` — maps `@uses` type names to built-in providers.
- `load()` now returns `['source' => ..., 'scope' => ..., 'header' => ...]` bundles so `run()` can use the parsed header.
- `run()` now parses the header and passes it through via `$opts['header']`.
- Root-level convenience includes: `SafeFiles.php`, `SafeHttp.php`.
- `test-providers.php`: Integration tests for SafeFiles and SafeHttp providers.

### Removed
- DOM types (`SafeDOMDocument`, `SafeHTMLElement`) — browser DOM manipulation belongs in the JS repo, not PHP. Legacy aliases (`SafeDocument`, `SafeHandle`) also removed.

### Changed
- `src/SafeScript.php`: Updated `run()` to parse header from source or bundle. Updated `load()` to return bundle with header instead of immediately running.
- `src/Header.php`: Added `BUILTIN_TYPES` constant and `resolveUses()` static method.

## [1.2.0] — 2026-10-25

### Added
- `test-dom.php`: DOM safety layer test coverage, aligned with safescript JS v1.2.0.

### Changed
- All PHP source files unchanged from v1.1.0.

## [1.1.0] — 2026-10-25

### Added
- **Root-level convenience includes**: `Defs.php`, `EmitPhp.php`, `Header.php`, `Parser.php`, `Runtime.php`, `SafeScript.php`, `SyntaxErrorEx.php`, `Transform.php`, `Validator.php` — direct-include alternatives to the PSR-4 autoloader.
- `src/Sanitizer.php`: HTML sanitizer for safe output handling.
- `src/UrlPolicy.php`: URL validation policy.
- `test-type-header.php`, `test-type-registry.php`: Type system test coverage.

### Changed
- Updated all `src/*.php` files to align with safescript JS v1.1.0 feature set (safe globals, type registry, facades, `allow` option, `this` support).
- Updated `README.md` with v1.1 documentation.
- Updated `test.php` with expanded test coverage.

### Removed
- `LICENSE` (using top-level repo license).
