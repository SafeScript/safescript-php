# Changelog

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
