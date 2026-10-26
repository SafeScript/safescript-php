/**
 * Tests for SafeScript type registry — SafeScript.lib(Constructor, { allowMethods: [...] })
 *
 * Verifies:
 *  - Registered types: only whitelisted methods callable
 *  - Unregistered types: all methods blocked
 *  - Primitives/arrays/plain objects: unaffected
 *  - Error family: always allowed
 *  - registerType / unregisterType / clearTypeRegistry
 */
'use strict';

var SafeScript = require('./index');

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

async function run(src, scope) {
  return SafeScript.run(src, scope || {});
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
  SafeScript.unlib(Counter);
  SafeScript.unlib(Timer);

  // ── Unregistered type: all methods blocked ──

  await test('unregistered type — method call throws', async function() {
    var err;
    try {
      await run('return c.value()', { c: new Counter(5) });
    } catch(e) { err = e; }
    assert(err, 'should throw');
    assert(err.message.indexOf('not allowed') !== -1 || err.message.indexOf('unregistered') !== -1,
      'error should mention unregistered: ' + err.message);
  });

  await test('unregistered type — toString also blocked', async function() {
    var err;
    try {
      await run('return c.toString()', { c: new Counter(5) });
    } catch(e) { err = e; }
    assert(err, 'should throw for unregistered toString');
  });

  // ── Register type with whitelist ──

  SafeScript.lib(Counter, { allowMethods: ['value', 'increment', 'toString'] });

  await test('registered type — allowed method works', async function() {
    var result = await run('return c.value()', { c: new Counter(42) });
    assert(result === 42, 'expected 42, got ' + result);
  });

  await test('registered type — another allowed method', async function() {
    var c = new Counter(0);
    var result = await run('c.increment(); return c.value()', { c: c });
    assert(result === 1, 'expected 1, got ' + result);
  });

  await test('registered type — allowed toString', async function() {
    var result = await run('return c.toString()', { c: new Counter(7) });
    assert(result === 'Counter(7)', 'expected Counter(7), got ' + result);
  });

  await test('registered type — blocked method throws', async function() {
    var err;
    try {
      await run('return c.reset()', { c: new Counter(5) });
    } catch(e) { err = e; }
    assert(err, 'should throw for non-whitelisted method');
    assert(err.message.indexOf('reset') !== -1, 'error should mention method name');
    assert(err.message.indexOf('not allowed') !== -1, 'error should say not allowed');
  });

  await test('registered type — decrement blocked', async function() {
    var err;
    try {
      await run('c.decrement(); return c.value()', { c: new Counter(5) });
    } catch(e) { err = e; }
    assert(err, 'decrement should be blocked');
  });

  // ── Return from host function ──

  await test('type returned from host function — allowed', async function() {
    var result = await run('var c = makeCounter(10); return c.value()', {
      makeCounter: function(n) { return new Counter(n); }
    });
    assert(result === 10, 'expected 10, got ' + result);
  });

  await test('type returned from host function — blocked', async function() {
    var err;
    try {
      await run('var c = makeCounter(10); c.reset(); return c.value()', {
        makeCounter: function(n) { return new Counter(n); }
      });
    } catch(e) { err = e; }
    assert(err, 'reset should be blocked on returned instance');
  });

  // ── Chaining allowed methods ──

  await test('chaining allowed methods', async function() {
    var c = new Counter(0);
    var result = await run('c.increment(); c.increment(); return c.value()', { c: c });
    assert(result === 2, 'expected 2, got ' + result);
  });

  // ── Second type, independently registered ──

  SafeScript.lib(Timer, { allowMethods: ['start', 'isRunning'] });

  await test('second registered type — allowed method', async function() {
    var result = await run('t.start(); return t.isRunning()', { t: new Timer() });
    assert(result === true, 'expected true');
  });

  await test('second registered type — blocked method', async function() {
    var err;
    try {
      await run('t.start(); t.stop(); return t.isRunning()', { t: new Timer() });
    } catch(e) { err = e; }
    assert(err, 'stop should be blocked');
  });

  // ── Primitives still work (not affected by registry) ──

  await test('string methods still work', async function() {
    var result = await run('return "hello".toUpperCase()');
    assert(result === 'HELLO', 'expected HELLO');
  });

  await test('string split still works', async function() {
    var result = await run('return "a,b,c".split(",").length');
    assert(result === 3, 'expected 3');
  });

  await test('array methods still work', async function() {
    var result = await run('return [3,1,2].sort().join(",")');
    assert(result === '1,2,3', 'expected 1,2,3');
  });

  await test('array push/pop still works', async function() {
    var result = await run('var a = [1,2]; a.push(3); return a.join(",")');
    assert(result === '1,2,3', 'expected 1,2,3, got ' + result);
  });

  // ── Plain objects still work ──

  await test('plain object method call works', async function() {
    var result = await run('var o = { greet: (n) => "hi " + n }; return o.greet("world")');
    assert(result === 'hi world', 'expected "hi world"');
  });

  await test('plain object toString works', async function() {
    var result = await run('var o = {}; return o.toString()');
    assert(result === '[object Object]', 'expected [object Object], got ' + result);
  });

  // ── Error family always allowed ──

  await test('Error toString works', async function() {
    var result = await run('return e.toString()', { e: new Error('boom') });
    assert(result === 'Error: boom', 'expected "Error: boom", got ' + result);
  });

  await test('Error message property works', async function() {
    var result = await run('return e.message', { e: new TypeError('bad') });
    assert(result === 'bad', 'expected "bad"');
  });

  // ── Unregister ──

  await test('unregister — methods blocked after unlib', async function() {
    SafeScript.unlib(Timer);
    var err;
    try {
      await run('return t.isRunning()', { t: new Timer() });
    } catch(e) { err = e; }
    assert(err, 'should throw after unregistering Timer');
  });

  await test('Counter still registered after unlib Timer', async function() {
    var result = await run('return c.value()', { c: new Counter(99) });
    assert(result === 99, 'Counter should still work');
  });

  // ── clearTypeRegistry ──

  // Re-register Timer for clearing test
  SafeScript.lib(Timer, { allowMethods: ['start', 'isRunning'] });

  await test('clearTypeRegistry blocks all types', async function() {
    // Verify both work before clear
    await run('return c.value()', { c: new Counter(1) });
    await run('t.start(); return t.isRunning()', { t: new Timer() });

    // Clear
    // Access internal clear through the runtime
    var runtime = require('./src/runtime');
    runtime.clearTypeRegistry();

    var err1, err2;
    try { await run('return c.value()', { c: new Counter(1) }); } catch(e) { err1 = e; }
    try { await run('return t.isRunning()', { t: new Timer() }); } catch(e) { err2 = e; }
    assert(err1, 'Counter should be blocked after clear');
    assert(err2, 'Timer should be blocked after clear');
  });

  // ── Edge cases ──

  await test('lib() with non-function throws', function() {
    var err;
    try { SafeScript.lib(42, { allowMethods: ['foo'] }); } catch(e) { err = e; }
    assert(err, 'should throw for non-function constructor');
  });

  await test('lib() without allowMethods throws', function() {
    var err;
    try { SafeScript.lib(Counter, { }); } catch(e) { err = e; }
    assert(err, 'should throw without allowMethods');
  });

  await test('lib() with non-array allowMethods throws', function() {
    var err;
    try { SafeScript.lib(Counter, { allowMethods: 'value' }); } catch(e) { err = e; }
    assert(err, 'should throw with non-array allowMethods');
  });

  // ── Re-register and verify ──

  SafeScript.lib(Counter, { allowMethods: ['value', 'increment', 'decrement', 'toString'] });

  await test('re-register with wider whitelist works', async function() {
    var c = new Counter(5);
    var result = await run('c.decrement(); return c.value()', { c: c });
    assert(result === 4, 'expected 4, got ' + result);
  });

  await test('re-register — previously allowed reset now blocked (narrower list)', async function() {
    var err;
    try {
      await run('return c.reset()', { c: new Counter(5) });
    } catch(e) { err = e; }
    assert(err, 'reset should be blocked in new registration');
  });

  // ── Subclass handling ──

  class SpecialCounter extends Counter {
    double() { this._count *= 2; return this; }
  }

  await test('subclass inherits parent registration', async function() {
    var sc = new SpecialCounter(3);
    var result = await run('return c.value()', { c: sc });
    assert(result === 3, 'expected 3, got ' + result);
  });

  await test('subclass — parent-unlisted method blocked', async function() {
    var err;
    try {
      await run('return c.double()', { c: new SpecialCounter(3) });
    } catch(e) { err = e; }
    assert(err, 'double should be blocked (not in parent allowMethods)');
  });

  // Clean up
  SafeScript.unlib(Counter);

  // ── Report ──
  console.log('\n');
  console.log('Type Registry Tests: ' + passed + ' passed, ' + failed + ' failed out of ' + total);
  if (failed > 0) process.exit(1);
})();
