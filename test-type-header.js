/**
 * Tests for @type header keyword and load()-time validation.
 */
'use strict';

var SafeScript = require('./index');
var header = require('./src/header');
var runtime = require('./src/runtime');

var passed = 0;
var failed = 0;
var total = 0;

async function test(name, fn) {
  total++;
  try {
    await fn();
    passed++;
    process.stdout.write('.');
  } catch (e) {
    failed++;
    console.log('\nFAIL: ' + name);
    console.log('  ' + e.message);
  }
}

function assert(cond, msg) {
  if (!cond) throw new Error(msg || 'assertion failed');
}

// ── Test host class ──

class Counter {
  constructor(n) { this._count = n || 0; }
  increment() { this._count++; return this; }
  decrement() { this._count--; return this; }
  value() { return this._count; }
  reset() { this._count = 0; return this; }
  toString() { return 'Counter(' + this._count + ')'; }
}

class Timer {
  constructor() { this._started = false; }
  start() { this._started = true; return this; }
  stop() { this._started = false; return this; }
  isRunning() { return this._started; }
}

// ── Tests ──

(async function() {
  // Clean slate
  runtime.clearTypeRegistry();

  // ── parseHeader: @type extraction ──

  await test('parseHeader extracts @type declarations', function() {
    var src = '/** @safescript 1.0\n * @type Response { json, text, status }\n */\nreturn 1';
    var h = header.parseHeader(src);
    assert(h.types.length === 1, 'expected 1 type, got ' + h.types.length);
    assert(h.types[0].name === 'Response', 'expected name Response');
    assert(h.types[0].methods.length === 3, 'expected 3 methods');
    assert(h.types[0].methods[0] === 'json');
    assert(h.types[0].methods[1] === 'text');
    assert(h.types[0].methods[2] === 'status');
  });

  await test('parseHeader with multiple @type lines', function() {
    var src = '/**\n * @safescript 1.0\n * @type Response { json, text }\n * @type Stream { get, set, fields }\n */\nreturn 1';
    var h = header.parseHeader(src);
    assert(h.types.length === 2, 'expected 2 types');
    assert(h.types[0].name === 'Response');
    assert(h.types[0].methods.length === 2);
    assert(h.types[1].name === 'Stream');
    assert(h.types[1].methods.length === 3);
    assert(h.types[1].methods[2] === 'fields');
  });

  await test('parseHeader with no @type returns empty types array', function() {
    var src = '/** @safescript 1.0\n * @needs fetch: function\n */\nreturn 1';
    var h = header.parseHeader(src);
    assert(Array.isArray(h.types), 'types should be array');
    assert(h.types.length === 0, 'expected 0 types');
  });

  await test('@type with single method', function() {
    var src = '/** @type Widget { render } */\nreturn 1';
    var h = header.parseHeader(src);
    assert(h.types.length === 1);
    assert(h.types[0].name === 'Widget');
    assert(h.types[0].methods.length === 1);
    assert(h.types[0].methods[0] === 'render');
  });

  await test('parseHeader mixes @needs, @uses, and @type', function() {
    var src = '/**\n * @safescript 1.0\n * @needs fetch: function\n * @uses log: function\n * @type Response { json, text }\n */\nreturn 1';
    var h = header.parseHeader(src);
    assert(h.needs.length === 1);
    assert(h.uses.length === 1);
    assert(h.types.length === 1);
    assert(h.types[0].name === 'Response');
  });

  // ── Three-arg lib() ──

  await test('three-arg lib() registers both name and constructor', async function() {
    SafeScript.lib('Counter', Counter, { allowMethods: ['value', 'increment'] });
    // Constructor should be registered — test by running
    var result = await SafeScript.run('return c.value()', { c: new Counter(42) });
    assert(result === 42, 'expected 42, got ' + result);
  });

  await test('three-arg lib() blocks unlisted methods', async function() {
    var err;
    try {
      await SafeScript.run('return c.reset()', { c: new Counter(5) });
    } catch(e) { err = e; }
    assert(err, 'reset should be blocked');
  });

  await test('three-arg lib() without config throws', function() {
    var err;
    try {
      SafeScript.lib('BadType', Counter);
    } catch(e) { err = e; }
    assert(err, 'should throw without config');
    assert(err.message.indexOf('allowMethods') !== -1, 'should mention allowMethods');
  });

  // ── load() with @type validation ──

  await test('load() with registered @type succeeds', async function() {
    // Counter is already registered as 'Counter' from above
    var src = '/**\n * @safescript 1.0\n * @type Counter { value, increment }\n */\nreturn 1';
    var bundle = await SafeScript.load(src);
    var result = await SafeScript.run(bundle);
    assert(result === 1, 'expected 1');
  });

  await test('load() with unregistered @type name throws', async function() {
    var src = '/**\n * @safescript 1.0\n * @type UnknownType { foo }\n */\nreturn 1';
    var err;
    try {
      await SafeScript.load(src);
    } catch(e) { err = e; }
    assert(err, 'should throw for unregistered type');
    assert(err.message.indexOf('UnknownType') !== -1, 'should mention type name: ' + err.message);
    assert(err.message.indexOf('not registered') !== -1, 'should say not registered: ' + err.message);
  });

  await test('load() with @type declaring method not in host allowMethods throws', async function() {
    // Counter allows ['value', 'increment'] — 'reset' is not allowed
    var src = '/**\n * @safescript 1.0\n * @type Counter { value, reset }\n */\nreturn 1';
    var err;
    try {
      await SafeScript.load(src);
    } catch(e) { err = e; }
    assert(err, 'should throw for unlisted method');
    assert(err.message.indexOf('reset') !== -1, 'should mention method name: ' + err.message);
    assert(err.message.indexOf('not allow') !== -1, 'should say not allowed: ' + err.message);
  });

  await test('load() with @type declaring only allowed methods succeeds', async function() {
    var src = '/**\n * @safescript 1.0\n * @type Counter { value }\n */\nreturn 1';
    var bundle = await SafeScript.load(src);
    var result = await SafeScript.run(bundle);
    assert(result === 1);
  });

  // ── capabilities() includes types ──

  await test('capabilities() includes types array', function() {
    var src = '/**\n * @safescript 1.0\n * @type Response { json, text }\n */\nreturn 1';
    var caps = SafeScript.capabilities(src);
    assert(Array.isArray(caps.types), 'types should be array');
    assert(caps.types.length === 1);
    assert(caps.types[0].name === 'Response');
  });

  // ── unlib() by name cleans up ──

  await test('unlib() by type name removes registration', async function() {
    // Register Timer as named type
    SafeScript.lib('Timer', Timer, { allowMethods: ['start', 'isRunning'] });
    // Verify it works
    var result = await SafeScript.run('t.start(); return t.isRunning()', { t: new Timer() });
    assert(result === true);

    // Unlib by name
    SafeScript.unlib('Timer');

    // Should now be blocked
    var err;
    try {
      await SafeScript.run('return t.isRunning()', { t: new Timer() });
    } catch(e) { err = e; }
    assert(err, 'Timer methods should be blocked after unlib by name');
  });

  await test('unlib() by constructor also removes name mapping', async function() {
    // Re-register Counter with name
    SafeScript.lib('Counter', Counter, { allowMethods: ['value', 'increment'] });

    // Unlib by constructor
    SafeScript.unlib(Counter);

    // load() with @type Counter should now fail
    var src = '/**\n * @safescript 1.0\n * @type Counter { value }\n */\nreturn 1';
    var err;
    try {
      await SafeScript.load(src);
    } catch(e) { err = e; }
    assert(err, 'should throw after unlib by constructor');
    assert(err.message.indexOf('Counter') !== -1, 'should mention Counter');
  });

  // ── Multiple @type validation in load() ──

  await test('load() validates all @type declarations', async function() {
    // Register both
    SafeScript.lib('Counter', Counter, { allowMethods: ['value', 'increment'] });
    SafeScript.lib('Timer', Timer, { allowMethods: ['start', 'isRunning'] });

    // Both types declared — should succeed
    var src = '/**\n * @safescript 1.0\n * @type Counter { value }\n * @type Timer { start, isRunning }\n */\nreturn 1';
    var bundle = await SafeScript.load(src);
    var result = await SafeScript.run(bundle);
    assert(result === 1);

    // Clean up
    SafeScript.unlib('Counter');
    SafeScript.unlib('Timer');
  });

  // ── Report ──
  console.log('\n');
  console.log('@type Header Tests: ' + passed + ' passed, ' + failed + ' failed out of ' + total);
  if (failed > 0) process.exit(1);
  console.log('All tests passed!');
})();
