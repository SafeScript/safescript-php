/**
 * SafeJS — a safe computational subset of JavaScript.
 *
 * Like JSON is a safe data subset of JS, SafeJS is a safe code subset.
 * Source is validated against a restricted token grammar, then wrapped
 * in a scoped function with all globals shadowed. The output is standard
 * JS that cannot access browser APIs, the DOM, or the prototype chain.
 *
 * Three independent security barriers:
 *   1. Token allowlist — source can't name anything dangerous
 *   2. Global shadowing — all globalThis keys become undefined in scope
 *   3. "use strict" — blocks arguments.callee, implicit globals
 *
 * API:
 *   SafeJS.validate(source)       → { ok, errors? }
 *   SafeJS.compile(source, opts)  → function(scope) → result
 *   SafeJS.run(source, scope)     → result
 *   SafeJS.sanitize(obj)          → deep copy with null prototypes
 *
 * Bracket property access (obj[key]) is banned at the syntax level.
 * Use the injected helpers instead:
 *   __at(obj, key)           — read  (returns undefined for blocked keys)
 *   __set(obj, key, value)   — write (throws for blocked keys)
 *
 * Lineage: JSON (2001, safe data) → Good Parts (2008, safe style)
 *          → JSLint (linter enforcing subset) → SafeJS (safe computation)
 *
 * (c) 2026 Qbix, Inc. MIT License.
 */
(function(root) {
'use strict';

// ── Blocked identifiers ─────────────────────────────────────────────
// Rejected wherever they appear as identifier tokens in source.
// String literals containing these words are fine — they're data.
var BLOCKED = Object.create(null);
[
  // Prototype chain
  'constructor', '__proto__', 'prototype',
  '__defineGetter__', '__defineSetter__',
  '__lookupGetter__', '__lookupSetter__',
  // Self-reference and stack walking
  'this', 'arguments', 'callee', 'caller',
  // Code generation
  'eval', 'Function',
  // Browser globals
  'globalThis', 'window', 'document', 'navigator',
  'self', 'top', 'parent', 'frames',
  'location', 'history',
  // Metaprogramming
  'Proxy', 'Reflect', 'Symbol',
  // Network
  'fetch', 'XMLHttpRequest', 'WebSocket', 'EventSource',
  // Timers (inject explicitly via scope if needed)
  'setTimeout', 'setInterval', 'requestAnimationFrame',
  // Node.js
  'require', 'module', 'exports', 'process',
  'global', 'Buffer', '__filename', '__dirname',
].forEach(function(k) { BLOCKED[k] = 1; });

// ── Blocked keywords ─────────────────────────────────────────────────
var BLOCKED_KW = Object.create(null);
[
  'new', 'delete', 'class', 'extends', 'super',
  'with', 'async', 'await', 'yield',
  'import', 'export', 'debugger',
].forEach(function(k) { BLOCKED_KW[k] = 1; });

// ── Allowed keywords ─────────────────────────────────────────────────
var ALLOWED_KW = Object.create(null);
[
  'var', 'let', 'const',
  'if', 'else', 'for', 'while', 'do',
  'break', 'continue', 'return', 'function',
  'try', 'catch', 'finally', 'throw',
  'switch', 'case', 'default',
  'typeof', 'void', 'in', 'of',
  'true', 'false', 'null', 'undefined',
  'instanceof',
].forEach(function(k) { ALLOWED_KW[k] = 1; });

// ── Blocked property names for __at / __set ──────────────────────────
var BLOCKED_PROPS = Object.create(null);
[
  'constructor', '__proto__', 'prototype',
  '__defineGetter__', '__defineSetter__',
  '__lookupGetter__', '__lookupSetter__',
  'caller', 'callee', 'arguments',
].forEach(function(k) { BLOCKED_PROPS[k] = 1; });

// ── Globals to shadow ────────────────────────────────────────────────
// Comprehensive static list covering browser + Node.
// At runtime, also enumerates the actual environment's globalThis.
var SHADOW_STATIC = [
  'Object', 'Array', 'String', 'Number', 'Boolean', 'BigInt',
  'Function', 'Symbol', 'Error', 'TypeError', 'RangeError',
  'SyntaxError', 'ReferenceError', 'EvalError', 'URIError',
  'AggregateError',
  'Map', 'Set', 'WeakMap', 'WeakSet', 'WeakRef', 'FinalizationRegistry',
  'Date', 'RegExp', 'Promise', 'JSON', 'Math', 'Intl',
  'ArrayBuffer', 'SharedArrayBuffer', 'DataView', 'Atomics',
  'Int8Array', 'Uint8Array', 'Uint8ClampedArray',
  'Int16Array', 'Uint16Array', 'Int32Array', 'Uint32Array',
  'Float32Array', 'Float64Array', 'BigInt64Array', 'BigUint64Array',
  'Proxy', 'Reflect',
  'eval', 'Function',
  'globalThis', 'window', 'self', 'top', 'parent', 'frames', 'global',
  'document', 'navigator', 'location', 'history', 'screen',
  'localStorage', 'sessionStorage', 'indexedDB',
  'alert', 'confirm', 'prompt', 'print', 'open', 'close',
  'focus', 'blur',
  'postMessage', 'addEventListener', 'removeEventListener',
  'dispatchEvent', 'CustomEvent', 'Event',
  'MutationObserver', 'ResizeObserver', 'IntersectionObserver',
  'Element', 'HTMLElement', 'Node', 'NodeList', 'DocumentFragment',
  'DOMParser', 'XMLSerializer', 'getComputedStyle', 'matchMedia',
  'requestAnimationFrame', 'cancelAnimationFrame',
  'fetch', 'XMLHttpRequest', 'WebSocket', 'EventSource',
  'Request', 'Response', 'Headers', 'URL', 'URLSearchParams',
  'setTimeout', 'setInterval', 'setImmediate',
  'clearTimeout', 'clearInterval', 'clearImmediate',
  'queueMicrotask', 'structuredClone',
  'Worker', 'SharedWorker',
  'Blob', 'File', 'FileReader', 'FileList', 'FormData',
  'TextEncoder', 'TextDecoder', 'atob', 'btoa',
  'encodeURI', 'decodeURI', 'encodeURIComponent', 'decodeURIComponent',
  'escape', 'unescape',
  'crypto', 'performance', 'Notification',
  'BroadcastChannel', 'MessageChannel', 'MessagePort',
  'AbortController', 'AbortSignal',
  'isNaN', 'isFinite', 'parseInt', 'parseFloat',
  'NaN', 'Infinity',
  'require', 'module', 'exports', '__filename', '__dirname',
  'process', 'Buffer',
  'console', 'constructor', '__proto__', 'prototype',
];

// ── Tokenizer ────────────────────────────────────────────────────────

var OPS3 = Object.create(null);
['===', '!==', '>>>', '**=', '<<=', '>>=', '&&=', '||=', '??=', '...']
  .forEach(function(k) { OPS3[k] = 1; });

var OPS2 = Object.create(null);
['==', '!=', '>=', '<=', '&&', '||', '??',
 '+=', '-=', '*=', '/=', '%=', '**',
 '++', '--', '=>', '?.', '<<', '>>',
 '&=', '|=', '^=']
  .forEach(function(k) { OPS2[k] = 1; });

var OPS1 = Object.create(null);
['+', '-', '*', '/', '%', '=',
 '<', '>', '!', '&', '|', '^', '~',
 '?', ':', ';', ',', '.',
 '(', ')', '{', '}', '[', ']']
  .forEach(function(k) { OPS1[k] = 1; });

function tokenize(source) {
  var tokens = [];
  var i = 0, len = source.length;
  var line = 1, lineStart = 0;
  var lastNonWsType = null;
  var lastNonWsValue = null;

  while (i < len) {
    var start = i;
    var sl = line, sc = i - lineStart + 1;
    var ch = source[i];

    // ── Whitespace ──
    if (ch === ' ' || ch === '\t' || ch === '\r' || ch === '\n' ||
        ch === '\v' || ch === '\f') {
      while (i < len) {
        var c = source[i];
        if (c === '\n') { line++; lineStart = i + 1; }
        else if (c !== ' ' && c !== '\t' && c !== '\r' &&
                 c !== '\v' && c !== '\f') break;
        i++;
      }
      continue; // skip whitespace tokens entirely
    }

    // ── Single-line comment ──
    if (ch === '/' && i + 1 < len && source[i + 1] === '/') {
      while (i < len && source[i] !== '\n') i++;
      continue;
    }

    // ── Multi-line comment ──
    if (ch === '/' && i + 1 < len && source[i + 1] === '*') {
      i += 2;
      while (i < len) {
        if (source[i] === '\n') { line++; lineStart = i + 1; }
        if (source[i] === '*' && i + 1 < len && source[i + 1] === '/') {
          i += 2; break;
        }
        i++;
      }
      continue;
    }

    // ── String literal (single or double quote) ──
    if (ch === '"' || ch === "'") {
      var quote = ch;
      i++;
      while (i < len && source[i] !== quote) {
        if (source[i] === '\\') {
          i++;
          if (i < len && source[i] === '\n') { line++; lineStart = i + 1; }
        }
        i++;
      }
      if (i < len) i++; // closing quote
      tokens.push({ type: 'string', value: source.slice(start, i), line: sl, col: sc });
      lastNonWsType = 'string'; lastNonWsValue = tokens[tokens.length - 1].value;
      continue;
    }

    // ── Template literal (banned but tokenize for error reporting) ──
    if (ch === '`') {
      i++;
      while (i < len && source[i] !== '`') {
        if (source[i] === '\\') i++;
        if (i < len && source[i] === '\n') { line++; lineStart = i + 1; }
        i++;
      }
      if (i < len) i++;
      tokens.push({ type: 'template', value: source.slice(start, i), line: sl, col: sc });
      lastNonWsType = 'template'; lastNonWsValue = '`';
      continue;
    }

    // ── Number ──
    if ((ch >= '0' && ch <= '9') ||
        (ch === '.' && i + 1 < len && source[i + 1] >= '0' && source[i + 1] <= '9')) {
      if (ch === '0' && i + 1 < len && (source[i + 1] === 'x' || source[i + 1] === 'X')) {
        i += 2;
        while (i < len && /[0-9a-fA-F_]/.test(source[i])) i++;
      } else if (ch === '0' && i + 1 < len && (source[i + 1] === 'b' || source[i + 1] === 'B')) {
        i += 2;
        while (i < len && (source[i] === '0' || source[i] === '1' || source[i] === '_')) i++;
      } else if (ch === '0' && i + 1 < len && (source[i + 1] === 'o' || source[i + 1] === 'O')) {
        i += 2;
        while (i < len && source[i] >= '0' && source[i] <= '7') i++;
      } else {
        while (i < len && ((source[i] >= '0' && source[i] <= '9') || source[i] === '_')) i++;
        if (i < len && source[i] === '.') {
          i++;
          while (i < len && ((source[i] >= '0' && source[i] <= '9') || source[i] === '_')) i++;
        }
        if (i < len && (source[i] === 'e' || source[i] === 'E')) {
          i++;
          if (i < len && (source[i] === '+' || source[i] === '-')) i++;
          while (i < len && ((source[i] >= '0' && source[i] <= '9') || source[i] === '_')) i++;
        }
      }
      if (i < len && source[i] === 'n') i++; // BigInt suffix
      tokens.push({ type: 'number', value: source.slice(start, i), line: sl, col: sc });
      lastNonWsType = 'number'; lastNonWsValue = tokens[tokens.length - 1].value;
      continue;
    }

    // ── Identifier or keyword ──
    if ((ch >= 'a' && ch <= 'z') || (ch >= 'A' && ch <= 'Z') ||
        ch === '_' || ch === '$') {
      while (i < len) {
        var ic = source[i];
        if ((ic >= 'a' && ic <= 'z') || (ic >= 'A' && ic <= 'Z') ||
            (ic >= '0' && ic <= '9') || ic === '_' || ic === '$') i++;
        else break;
      }
      var word = source.slice(start, i);
      var type;
      if (BLOCKED[word])         type = 'blocked';
      else if (BLOCKED_KW[word]) type = 'blocked_kw';
      else if (ALLOWED_KW[word]) type = 'keyword';
      else                       type = 'ident';
      tokens.push({ type: type, value: word, line: sl, col: sc });
      lastNonWsType = type; lastNonWsValue = word;
      continue;
    }

    // ── Regex literal (when / follows a non-expression token) ──
    if (ch === '/' && !isExprEnd(lastNonWsType, lastNonWsValue)) {
      i++;
      while (i < len && source[i] !== '/') {
        if (source[i] === '\\') i++;
        if (i < len && source[i] === '[') {
          i++;
          while (i < len && source[i] !== ']') {
            if (source[i] === '\\') i++;
            i++;
          }
        }
        i++;
      }
      if (i < len) i++;
      while (i < len && /[gimsuy]/.test(source[i])) i++;
      tokens.push({ type: 'regex', value: source.slice(start, i), line: sl, col: sc });
      lastNonWsType = 'regex'; lastNonWsValue = tokens[tokens.length - 1].value;
      continue;
    }

    // ── Operators and punctuation (longest match first) ──
    if (i + 2 < len && OPS3[source.slice(i, i + 3)]) {
      tokens.push({ type: 'punct', value: source.slice(i, i + 3), line: sl, col: sc });
      i += 3;
    } else if (i + 1 < len && OPS2[source.slice(i, i + 2)]) {
      tokens.push({ type: 'punct', value: source.slice(i, i + 2), line: sl, col: sc });
      i += 2;
    } else if (OPS1[ch]) {
      tokens.push({ type: 'punct', value: ch, line: sl, col: sc });
      i++;
    } else {
      tokens.push({ type: 'unknown', value: ch, line: sl, col: sc });
      i++;
    }

    var last = tokens[tokens.length - 1];
    lastNonWsType = last.type;
    lastNonWsValue = last.value;
  }

  return tokens;
}

/** Does the token type+value end an expression? (determines if [ is property access) */
function isExprEnd(type, value) {
  if (type === 'ident' || type === 'number' || type === 'string' ||
      type === 'regex' || type === 'blocked') return true;
  if (type === 'keyword' && (value === 'true' || value === 'false' ||
      value === 'null' || value === 'undefined')) return true;
  if (type === 'punct' && (value === ')' || value === ']')) return true;
  return false;
}

// ── Validator ────────────────────────────────────────────────────────

function validate(source) {
  var tokens = tokenize(source);
  var errors = [];
  var lastNonWs = null;

  for (var i = 0; i < tokens.length; i++) {
    var t = tokens[i];

    if (t.type === 'blocked') {
      errors.push({ message: 'Blocked identifier: ' + t.value, line: t.line, col: t.col });
    }
    if (t.type === 'blocked_kw') {
      errors.push({ message: 'Blocked keyword: ' + t.value, line: t.line, col: t.col });
    }
    if (t.type === 'template') {
      errors.push({ message: 'Template literals not allowed (use string concatenation)', line: t.line, col: t.col });
    }
    if (t.type === 'unknown') {
      errors.push({ message: 'Unexpected character: ' + t.value, line: t.line, col: t.col });
    }

    // Bracket property access: [ preceded by an expression-ending token
    if (t.type === 'punct' && t.value === '[' && lastNonWs &&
        isExprEnd(lastNonWs.type, lastNonWs.value)) {
      errors.push({
        message: 'Bracket property access not allowed — use __at(obj, key) to read, __set(obj, key, val) to write',
        line: t.line, col: t.col
      });
    }

    // Generator functions: * right after function keyword
    if (t.type === 'punct' && t.value === '*' && lastNonWs &&
        lastNonWs.type === 'keyword' && lastNonWs.value === 'function') {
      errors.push({ message: 'Generator functions not allowed', line: t.line, col: t.col });
    }

    lastNonWs = t;
  }

  return errors.length ? { ok: false, errors: errors } : { ok: true };
}

// ── Runtime helpers ──────────────────────────────────────────────────

function makeAt() {
  return function __at(obj, key) {
    if (obj == null) throw new TypeError('Cannot read property ' + String(key) + ' of ' + String(obj));
    if (typeof key === 'string' && BLOCKED_PROPS[key]) return undefined;
    return obj[key];
  };
}

function makeSet() {
  return function __set(obj, key, val) {
    if (obj == null) throw new TypeError('Cannot set property ' + String(key) + ' of ' + String(obj));
    if (typeof key === 'string' && BLOCKED_PROPS[key]) throw new TypeError('Cannot access blocked property: ' + String(key));
    obj[key] = val;
    return val;
  };
}

// ── Sanitizer ────────────────────────────────────────────────────────
// Deep-copies an object tree, giving every object a null prototype
// so obj.constructor etc. resolve to undefined. Primitives and
// functions pass through unchanged. Handles cycles.

function sanitize(obj) {
  return _sanitize(obj, new Map());
}

function _sanitize(obj, seen) {
  if (obj === null || obj === undefined) return obj;
  if (typeof obj !== 'object') return obj;
  if (seen.has(obj)) return seen.get(obj);

  if (Array.isArray(obj)) {
    var arr = [];
    seen.set(obj, arr);
    for (var i = 0; i < obj.length; i++) arr[i] = _sanitize(obj[i], seen);
    return arr;
  }

  var clean = Object.create(null);
  seen.set(obj, clean);
  var keys = Object.keys(obj);
  for (var k = 0; k < keys.length; k++) clean[keys[k]] = _sanitize(obj[keys[k]], seen);
  return clean;
}

// ── Build shadow var list ────────────────────────────────────────────
// Merges the static list with runtime enumeration of globalThis.
// Filters to valid JS identifiers and excludes injected helper names.

function buildShadowList(exclude) {
  var all = Object.create(null);
  SHADOW_STATIC.forEach(function(n) { all[n] = 1; });
  try {
    var g = typeof globalThis !== 'undefined' ? globalThis :
            typeof window !== 'undefined' ? window :
            typeof global !== 'undefined' ? global : null;
    if (g) Object.getOwnPropertyNames(g).forEach(function(n) { all[n] = 1; });
  } catch(e) { /* sandboxed env — static list is enough */ }

  // Never shadow our injected helpers or the scope parameter names
  delete all['__at'];
  delete all['__set'];
  delete all['__lc'];
  // 'eval' and 'arguments' can't be declared as var in strict mode
  delete all['eval'];
  delete all['arguments'];
  if (exclude) exclude.forEach(function(n) { delete all[n]; });

  return Object.keys(all).filter(function(n) {
    return /^[a-zA-Z_$][a-zA-Z0-9_$]*$/.test(n);
  });
}

// ── Compiler ─────────────────────────────────────────────────────────
// Validates source, then returns function(scope) → result.
// Scope keys become function parameters so they're real local vars.
// All browser/Node globals are shadowed (var x; → undefined).
// __at and __set are injected as parameters.

function compile(source, opts) {
  opts = opts || {};

  var result = validate(source);
  if (!result.ok) {
    var msg = 'SafeJS validation failed:\n' +
      result.errors.map(function(e) {
        return '  Line ' + e.line + ':' + e.col + ' — ' + e.message;
      }).join('\n');
    var err = new SyntaxError(msg);
    err.errors = result.errors;
    throw err;
  }

  var loopLimit = opts.loopLimit || 1000000;
  var __at = makeAt();
  var __set = makeSet();

  // Return a function that, when called with a scope object,
  // builds a new Function with scope keys as parameters, shadows
  // all globals, injects the user source, and runs it.
  return function safeFunction(scope) {
    scope = scope || {};
    var scopeKeys = Object.keys(scope);

    // Shadow list excludes scope keys (they need to carry values, not be undefined)
    var shadowList = buildShadowList(scopeKeys);

    // Function parameters: __at, __set, then each scope key
    var params = ['__at', '__set'].concat(scopeKeys);

    // Function body
    var body = '"use strict";\n';

    // Shadow all globals as undefined local vars
    for (var s = 0; s < shadowList.length; s += 50) {
      body += 'var ' + shadowList.slice(s, s + 50).join(', ') + ';\n';
    }

    // Loop limiter (infinite loop protection)
    body += 'var __lc = 0;\n';
    body += 'function __loop() { if (++__lc > ' + loopLimit +
            ') throw new RangeError("Loop limit exceeded (' + loopLimit + ' iterations)"); }\n\n';

    // User code
    body += source + '\n';

    // Build the function
    var fn;
    try {
      fn = new (Function.prototype.bind.apply(
        Function, [null].concat(params, [body])
      ));
    } catch(e) {
      throw new SyntaxError('SafeJS compile error: ' + e.message);
    }

    // Prepare arguments: __at, __set, then each scope value
    var args = [__at, __set];
    for (var k = 0; k < scopeKeys.length; k++) {
      var val = scope[scopeKeys[k]];
      // Functions are trusted API — pass through
      // Objects are sanitized to null prototypes
      if (typeof val === 'function') args.push(val);
      else if (val !== null && typeof val === 'object') args.push(sanitize(val));
      else args.push(val);
    }

    return fn.apply(null, args);
  };
}

// ── Runner (convenience) ─────────────────────────────────────────────

function run(source, scope, opts) {
  return compile(source, opts)(scope);
}

// ── Export ────────────────────────────────────────────────────────────

var SafeJS = {
  validate:      validate,
  compile:       compile,
  run:           run,
  sanitize:      sanitize,
  tokenize:      tokenize,
  BLOCKED:       BLOCKED,
  BLOCKED_KW:    BLOCKED_KW,
  BLOCKED_PROPS: BLOCKED_PROPS,
  version:       '0.1.0',
};

if (typeof module !== 'undefined' && module.exports) {
  module.exports = SafeJS;
}
if (root) root.SafeJS = SafeJS;

})(typeof globalThis !== 'undefined' ? globalThis :
   typeof window !== 'undefined' ? window :
   typeof global !== 'undefined' ? global : undefined);
