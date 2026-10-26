/**
 * SafeJS test suite — validates the security barriers.
 *
 * Run: node test.js
 */
var SafeJS = require('./safe.js');

var passed = 0, failed = 0, total = 0;

function assert(ok, label) {
  total++;
  if (ok) { passed++; }
  else { failed++; console.error('  FAIL: ' + label); }
}

function shouldPass(source, label) {
  var result = SafeJS.validate(source);
  assert(result.ok, label + ' — expected valid, got: ' +
    (result.errors ? result.errors.map(function(e) { return e.message; }).join('; ') : 'ok'));
}

function shouldFail(source, label, expectedMsg) {
  var result = SafeJS.validate(source);
  assert(!result.ok, label + ' — expected invalid, got ok');
  if (expectedMsg && result.errors) {
    var found = result.errors.some(function(e) {
      return e.message.indexOf(expectedMsg) >= 0;
    });
    assert(found, label + ' — expected error containing "' + expectedMsg + '"');
  }
}

function shouldRun(source, scope, expected, label) {
  try {
    var result = SafeJS.run(source, scope);
    assert(result === expected, label + ' — expected ' + expected + ', got ' + result);
  } catch(e) {
    assert(false, label + ' — threw: ' + e.message);
  }
}

function shouldThrowOnRun(source, scope, label) {
  try {
    SafeJS.run(source, scope);
    assert(false, label + ' — expected throw, but succeeded');
  } catch(e) {
    assert(true, label);
  }
}

// =====================================================================
console.log('\n═══ SafeJS Test Suite ═══\n');

// ── 1. Validation: should PASS ──────────────────────────────────────
console.log('── Should pass validation ──');

shouldPass('var x = 1 + 2', 'simple arithmetic');
shouldPass('var x = "hello"', 'string literal');
shouldPass("var x = 'world'", 'single-quoted string');
shouldPass('var x = 1; var y = 2; var z = x + y', 'multiple statements');
shouldPass('if (x > 0) { y = 1; } else { y = 2; }', 'if/else');
shouldPass('for (var i = 0; i < 10; i++) { }', 'for loop');
shouldPass('while (x > 0) { x = x - 1; }', 'while loop');
shouldPass('function add(a, b) { return a + b; }', 'function declaration');
shouldPass('var add = function(a, b) { return a + b; }', 'function expression');
shouldPass('var arr = [1, 2, 3]', 'array literal');
shouldPass('var obj = { a: 1, b: 2 }', 'object literal');
shouldPass('items.forEach(function(item) { })', 'method call');
shouldPass('items.map(function(m) { return m.name; })', 'map with dot access');
shouldPass(
  'var handlers = members.map(function(m) { return function() { streams.message(m, "Hello"); }; })',
  'closures returning functions'
);
shouldPass('state.on("cart.total", function(newVal, oldVal) { })', 'event listener');
shouldPass('var x = a > b ? a : b', 'ternary');
shouldPass('try { x(); } catch(e) { y = e; } finally { z = 1; }', 'try/catch/finally');
shouldPass('switch(x) { case 1: break; default: break; }', 'switch');
shouldPass('typeof x === "string"', 'typeof');
shouldPass('var x = /^hello$/gi', 'regex literal');
shouldPass('var x = a || b', 'logical or');
shouldPass('var x = a && b', 'logical and');
shouldPass('var x = a ?? b', 'nullish coalescing');
shouldPass('var x = obj?.prop', 'optional chaining');
shouldPass('var x = a !== b', 'strict inequality');
shouldPass('var x = !flag', 'logical not');
shouldPass('let x = 1; const y = 2', 'let and const');
shouldPass('var x = (a, b) => a + b', 'arrow function');
shouldPass(
  'var result = "the constructor keyword is fine inside strings"',
  'blocked word inside string literal is OK'
);
shouldPass(
  "var x = 'eval is fine in strings too'",
  'eval inside single-quoted string is OK'
);
shouldPass('x instanceof y', 'instanceof');
shouldPass('for (var k in obj) { }', 'for-in');
shouldPass('for (var v of arr) { }', 'for-of');
shouldPass('void 0', 'void operator');
shouldPass('// this is a comment\nvar x = 1', 'comment containing blocked word');
shouldPass('/* eval constructor */ var x = 1', 'block comment containing blocked words');
shouldPass('var x = { "constructor": 1 }', 'blocked word as string property key');

// ── 2. Validation: should FAIL ──────────────────────────────────────
console.log('── Should fail validation ──');

// Blocked identifiers
shouldFail('globalThis.x', 'globalThis access', 'Blocked identifier: globalThis');
shouldFail('window.location', 'window access', 'Blocked identifier: window');
shouldFail('document.cookie', 'document access', 'Blocked identifier: document');
shouldFail('obj.constructor', 'constructor access', 'Blocked identifier: constructor');
shouldFail('obj.__proto__', '__proto__ access', 'Blocked identifier: __proto__');
shouldFail('obj.prototype', 'prototype access', 'Blocked identifier: prototype');
shouldFail('x = this', 'this', 'Blocked identifier: this');
shouldFail('arguments[0]', 'arguments', 'Blocked identifier: arguments');
shouldFail('fn.caller', 'caller', 'Blocked identifier: caller');
shouldFail('eval("alert(1)")', 'eval', 'Blocked identifier: eval');
shouldFail('var f = Function("return 1")', 'Function constructor', 'Blocked identifier: Function');
shouldFail('Proxy', 'Proxy', 'Blocked identifier: Proxy');
shouldFail('Reflect.get(obj, "x")', 'Reflect', 'Blocked identifier: Reflect');
shouldFail('Symbol.toPrimitive', 'Symbol', 'Blocked identifier: Symbol');
shouldFail('fetch("/api")', 'fetch', 'Blocked identifier: fetch');
shouldFail('var x = XMLHttpRequest', 'XMLHttpRequest', 'Blocked identifier: XMLHttpRequest');
shouldFail('navigator.userAgent', 'navigator', 'Blocked identifier: navigator');
shouldFail('require("fs")', 'require', 'Blocked identifier: require');
shouldFail('process.exit()', 'process', 'Blocked identifier: process');
shouldFail('setTimeout(fn, 0)', 'setTimeout', 'Blocked identifier: setTimeout');

// Blocked keywords
shouldFail('new Object()', 'new keyword', 'Blocked keyword: new');
shouldFail('delete obj.x', 'delete keyword', 'Blocked keyword: delete');
shouldFail('class Foo {}', 'class keyword', 'Blocked keyword: class');
shouldFail('async function f() {}', 'async keyword', 'Blocked keyword: async');
shouldFail('var x = await promise', 'await keyword', 'Blocked keyword: await');
shouldFail('function* gen() { yield 1; }', 'yield keyword', 'Blocked keyword: yield');
shouldFail('import("module")', 'import keyword', 'Blocked keyword: import');
shouldFail('export var x = 1', 'export keyword', 'Blocked keyword: export');
shouldFail('debugger', 'debugger keyword', 'Blocked keyword: debugger');
shouldFail('with (obj) { }', 'with keyword', 'Blocked keyword: with');

// Bracket property access
shouldFail('items[0]', 'bracket access on ident', 'Bracket property access');
shouldFail('items[i]', 'bracket access with variable', 'Bracket property access');
shouldFail('foo()[0]', 'bracket access after call', 'Bracket property access');
shouldFail('x = a[b]', 'bracket access in expression', 'Bracket property access');

// Template literals
shouldFail('var x = `hello`', 'template literal', 'Template literals not allowed');
shouldFail('var x = `hello ${name}`', 'template literal with expression', 'Template literals not allowed');

// Generator functions
shouldFail('function* gen() {}', 'generator function', 'Generator functions not allowed');

// ── 3. Compilation and execution ─────────────────────────────────────
console.log('── Should run correctly ──');

shouldRun('return 1 + 2', {}, 3, 'simple return');
shouldRun('return x + y', { x: 10, y: 20 }, 30, 'scope variables');
shouldRun('return msg.length', { msg: 'hello' }, 5, 'dot property access on scope');
shouldRun(
  'var sum = 0; for (var i = 0; i < items.length; i++) { sum = sum + __at(items, i); } return sum',
  { items: [1, 2, 3, 4] },
  10,
  '__at for array index access'
);
shouldRun(
  'return fn(2, 3)',
  { fn: function(a, b) { return a * b; } },
  6,
  'calling injected function'
);
shouldRun(
  'var double = function(n) { return n * 2; }; return double(5)',
  {},
  10,
  'local function definition'
);
shouldRun(
  'var result = []; items.forEach(function(item) { result.push(item * 2); }); return result.length',
  { items: [1, 2, 3] },
  3,
  'forEach with closure'
);
shouldRun(
  'return typeof x',
  {},
  'undefined',
  'typeof on undefined var'
);
shouldRun(
  'return a > b ? "yes" : "no"',
  { a: 5, b: 3 },
  'yes',
  'ternary expression'
);

// ── 4. Security: runtime escapes should be blocked ───────────────────
console.log('── Should block runtime escapes ──');

// Even if someone smuggles a constructor reference through a scope object,
// __at blocks it
shouldRun(
  'return __at(obj, "constructor")',
  { obj: { constructor: 'gotcha', name: 'safe' } },
  undefined,
  '__at blocks constructor key'
);

shouldRun(
  'return __at(obj, "__proto__")',
  { obj: {} },
  undefined,
  '__at blocks __proto__ key'
);

shouldRun(
  'return __at(obj, "prototype")',
  { obj: {} },
  undefined,
  '__at blocks prototype key'
);

// __set should throw for blocked keys
shouldThrowOnRun(
  '__set(obj, "constructor", "evil"); return true',
  { obj: {} },
  '__set throws on constructor'
);

shouldThrowOnRun(
  '__set(obj, "__proto__", {}); return true',
  { obj: {} },
  '__set throws on __proto__'
);

// Null/undefined access
shouldThrowOnRun(
  'return __at(null, "x")',
  {},
  '__at throws on null'
);

shouldThrowOnRun(
  '__set(undefined, "x", 1); return true',
  {},
  '__set throws on undefined'
);

// Globals are shadowed (Object, Array, etc. are all undefined inside)
shouldRun(
  'return typeof Object',
  {},
  'undefined',
  'Object is shadowed'
);

shouldRun(
  'return typeof Array',
  {},
  'undefined',
  'Array is shadowed'
);

shouldRun(
  'return typeof console',
  {},
  'undefined',
  'console is shadowed'
);

shouldRun(
  'return typeof JSON',
  {},
  'undefined',
  'JSON is shadowed'
);

// ── 5. Sanitizer ─────────────────────────────────────────────────────
console.log('── Sanitizer ──');

(function() {
  var obj = { a: 1, b: { c: 2 } };
  var s = SafeJS.sanitize(obj);
  assert(s.a === 1, 'sanitize preserves values');
  assert(s.b.c === 2, 'sanitize preserves nested values');
  assert(s.constructor === undefined, 'sanitized obj has no constructor');
  assert(s.b.constructor === undefined, 'sanitized nested obj has no constructor');
  assert(Object.getPrototypeOf(s) === null, 'sanitized obj has null prototype');
  assert(Object.getPrototypeOf(s.b) === null, 'sanitized nested obj has null prototype');

  var arr = [1, { x: 2 }, [3]];
  var sa = SafeJS.sanitize(arr);
  assert(Array.isArray(sa), 'sanitize preserves arrays');
  assert(sa[1].x === 2, 'sanitize preserves array element values');
  assert(Object.getPrototypeOf(sa[1]) === null, 'sanitized array element has null prototype');

  // Cycle protection
  var cycle = { a: 1 };
  cycle.self = cycle;
  var sc = SafeJS.sanitize(cycle);
  assert(sc.a === 1, 'sanitize handles cycle — value preserved');
  assert(sc.self === sc, 'sanitize handles cycle — self-reference preserved');
})();

// ── 6. Edge cases ────────────────────────────────────────────────────
console.log('── Edge cases ──');

// Array literals are fine ([ not preceded by expression)
shouldPass('var arr = [1, 2, 3]', 'array literal is OK');
shouldPass('return [1, 2]', 'return array literal');
shouldPass('fn([1, 2])', 'array literal as argument');
shouldPass('var x = { a: [1, 2] }', 'array literal in object');

// But bracket access is not
shouldFail('arr[0]', 'bracket after ident is access', 'Bracket property access');
shouldFail('"str"[0]', 'bracket after string is access', 'Bracket property access');

// String concatenation to bypass blocked words — the VALIDATOR catches the
// identifier, not the string contents, so this is about runtime, not validation.
// The key test: can you build "constructor" at runtime and use it?
// Answer: no, because [] is banned and __at blocks it.
shouldRun(
  'var key = "con" + "structor"; return __at(obj, key)',
  { obj: { name: 'test' } },
  undefined,
  'string concat to build "constructor" — __at blocks it'
);

// Functions in scope pass through unsanitized (they're trusted API)
shouldRun(
  'return api("hello")',
  { api: function(s) { return s.toUpperCase(); } },
  'HELLO',
  'injected function works normally'
);

// Nested scope objects
shouldRun(
  'return config.db.host',
  { config: { db: { host: 'localhost' } } },
  'localhost',
  'nested scope objects accessible via dot notation'
);

// ── 7. Compile-time validation errors ────────────────────────────────
console.log('── Compile errors ──');

(function() {
  try {
    SafeJS.compile('eval("bad")');
    assert(false, 'compile should throw for blocked code');
  } catch(e) {
    assert(e instanceof SyntaxError, 'compile throws SyntaxError');
    assert(e.errors && e.errors.length > 0, 'compile error has errors array');
  }
})();

// ── Results ──────────────────────────────────────────────────────────
console.log('\n═══════════════════════════');
console.log('  Total:  ' + total);
console.log('  Passed: ' + passed);
console.log('  Failed: ' + failed);
console.log('═══════════════════════════\n');

if (failed > 0) process.exit(1);
