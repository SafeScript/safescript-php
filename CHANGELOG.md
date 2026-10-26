# Changelog

## [1.2.0] — 2026-10-25

### Added
- **DOM safety layer** (`src/dom/`): Safe wrappers for browser DOM manipulation — `safe-handle.js`, `safe-document.js`, ownership tracking, element budgets, HTML sanitizer, URL policy, event bridge, identity cache, safe timers, and sub-facades for classList, collection, dataset, and style.
- `browser.js`: Bundle builder for browser environments.
- `test-browser.js`, `test-dom.js`: Test coverage for the DOM safety layer.

### Changed
- All core compiler files unchanged from v1.1.0.

## [1.1.0] — 2026-10-25

### Added
- **`allow` option**: Host-provided scope identifiers now pass through the compiler. `SafeScript.run()` auto-builds the allow list from `Object.keys(scope)`, so hosts no longer need to work around the BLOCKED list for capabilities they explicitly provide.
- **`ThisExpression` transform**: `this` expressions are now rewritten to `__safeThis` during AST transformation, enabling safe `this` usage in facades.

### Changed
- `src/parse.js`: Tokenizer checks `_allow` before blocking an identifier.
- `src/validate.js`: Validator checks `allow` map before blocking.
- `src/runtime.js`: `buildFunction()`, `sanitize()`, `__call`, `applyFacades` updated for allow support.
- `index.js`: `run()` builds allow map from `Object.keys(scope)`.
- `src/compile.js`: Passes `opts` through the full pipeline.

### Removed
- Removed standalone documentation files (`Environments.md`, `Examples.md`, `RATIONALES.md`, `Specification.md`) — content consolidated into README.
- Removed pre-built browser bundles (`safescript.js`, `safescript.min.js`, `_safescript-bundle.js`, `browser.js`) — will return in v1.2 with the DOM safety layer.
- Removed superseded test files (`test-battle.js`, `test-browser.js`, `test-compiler.js`, `test-guards-hooks.js`, `test-header.js`) — coverage consolidated into `test.js`, `test-type-header.js`, `test-type-registry.js`.
