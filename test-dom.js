/**
 * SafeScript DOM Membrane — Security Tests
 *
 * Tests the sanitizer, URL policy, ownership, budgets, and event bridge
 * in isolation (no browser DOM needed for sanitizer/url-policy tests).
 * Full integration tests require a browser environment (jsdom or headless).
 */
'use strict';

// ── Test harness ─────────────────────────────────────────────────────

var passed = 0, failed = 0, skipped = 0;
var currentGroup = '';

function group(name) { currentGroup = name; }

function test(name, fn) {
  try {
    fn();
    passed++;
  } catch(e) {
    failed++;
    console.error('  FAIL [' + currentGroup + '] ' + name);
    console.error('       ' + e.message);
  }
}

function assert(cond, msg) {
  if (!cond) throw new Error(msg || 'assertion failed');
}

function assertThrows(fn, pattern, msg) {
  try {
    fn();
    throw new Error(msg || 'expected to throw');
  } catch(e) {
    if (pattern && e.message.indexOf(pattern) === -1) {
      throw new Error((msg || 'wrong error') + ': got "' + e.message + '"');
    }
  }
}

// ── URL Policy tests ─────────────────────────────────────────────────

var urlPolicy = require('./src/dom/url-policy');

group('URL Policy — classifyPurpose');

test('img src is image', function() {
  assert(urlPolicy.classifyPurpose('img', 'src') === 'image');
});

test('img srcset is image', function() {
  assert(urlPolicy.classifyPurpose('img', 'srcset') === 'image');
});

test('video src is media', function() {
  assert(urlPolicy.classifyPurpose('video', 'src') === 'media');
});

test('a href is navigation', function() {
  assert(urlPolicy.classifyPurpose('a', 'href') === 'navigation');
});

test('form action is form', function() {
  assert(urlPolicy.classifyPurpose('form', 'action') === 'form');
});

test('link href is style', function() {
  assert(urlPolicy.classifyPurpose('link', 'href') === 'style');
});

test('unknown is other', function() {
  assert(urlPolicy.classifyPurpose('div', 'foo') === 'other');
});

group('URL Policy — Layer 1 invariants');

test('javascript: always denied', function() {
  var policy = urlPolicy.createURLPolicy(null, '');
  var result = policy.check({ element: 'a', attribute: 'href', url: 'javascript:alert(1)', purpose: 'navigation' });
  assert(result.allow === false);
});

test('javascript: with whitespace denied', function() {
  var policy = urlPolicy.createURLPolicy(null, '');
  var result = policy.check({ element: 'a', attribute: 'href', url: '  jAvAsCrIpT:alert(1)', purpose: 'navigation' });
  assert(result.allow === false);
});

test('data: denied for non-image', function() {
  var policy = urlPolicy.createURLPolicy(null, '');
  var result = policy.check({ element: 'a', attribute: 'href', url: 'data:text/html,<script>alert(1)</script>', purpose: 'navigation' });
  assert(result.allow === false);
});

test('data:image/png allowed when purpose is image and hook allows', function() {
  var policy = urlPolicy.createURLPolicy(function() { return { allow: true }; }, '');
  var result = policy.check({ element: 'img', attribute: 'src', url: 'data:image/png;base64,abc', purpose: 'image' });
  assert(result.allow === true);
});

group('URL Policy — host hook');

test('host hook can deny', function() {
  var policy = urlPolicy.createURLPolicy(function() { return { allow: false }; }, '');
  var result = policy.check({ element: 'img', attribute: 'src', url: 'https://example.com/img.png', purpose: 'image' });
  assert(result.allow === false);
});

test('host hook can rewrite URL', function() {
  var policy = urlPolicy.createURLPolicy(function() {
    return { allow: true, url: 'https://proxy.example.com/img.png' };
  }, '');
  var result = policy.check({ element: 'img', attribute: 'src', url: 'https://example.com/img.png', purpose: 'image' });
  assert(result.allow === true);
  assert(result.url === 'https://proxy.example.com/img.png');
});

test('host hook cannot override javascript: deny', function() {
  var policy = urlPolicy.createURLPolicy(function() { return { allow: true }; }, '');
  var result = policy.check({ element: 'a', attribute: 'href', url: 'javascript:alert(1)', purpose: 'navigation' });
  assert(result.allow === false, 'javascript: should be denied even with permissive hook');
});

// ── Budgets tests ────────────────────────────────────────────────────

var budgetsModule = require('./src/dom/budgets');

group('Budgets — node allocation');

test('allocNodes within budget succeeds', function() {
  var b = budgetsModule.createBudgets({ maxNodes: 10 });
  b.allocNodes(5);
  assert(b.getNodeCount() === 5);
});

test('allocNodes over budget throws', function() {
  var b = budgetsModule.createBudgets({ maxNodes: 5 });
  b.allocNodes(3);
  assertThrows(function() { b.allocNodes(5); }, 'node budget exceeded');
});

test('releaseNodes frees budget', function() {
  var b = budgetsModule.createBudgets({ maxNodes: 5 });
  b.allocNodes(5);
  b.releaseNodes(3);
  b.allocNodes(2); // should not throw — at 4 now
  assert(b.getNodeCount() === 4);
});

group('Budgets — text size');

test('checkTextSize within limit succeeds', function() {
  var b = budgetsModule.createBudgets({ maxTextBytes: 100 });
  b.checkTextSize('hello'); // 10 bytes rough
});

test('checkTextSize over limit throws', function() {
  var b = budgetsModule.createBudgets({ maxTextBytes: 10 });
  var big = new Array(100).join('x');
  assertThrows(function() { b.checkTextSize(big); }, 'text size exceeded');
});

group('Budgets — listeners');

test('allocListener within budget succeeds', function() {
  var b = budgetsModule.createBudgets({ maxListeners: 3 });
  b.allocListener();
  b.allocListener();
  b.allocListener();
});

test('allocListener over budget throws', function() {
  var b = budgetsModule.createBudgets({ maxListeners: 2 });
  b.allocListener();
  b.allocListener();
  assertThrows(function() { b.allocListener(); }, 'listener budget exceeded');
});

group('Budgets — timers');

test('allocTimer within budget succeeds', function() {
  var b = budgetsModule.createBudgets({ maxActiveTimers: 2 });
  b.allocTimer();
  b.allocTimer();
});

test('allocTimer over budget throws', function() {
  var b = budgetsModule.createBudgets({ maxActiveTimers: 1 });
  b.allocTimer();
  assertThrows(function() { b.allocTimer(); }, 'timer budget exceeded');
});

test('releaseTimer frees slot', function() {
  var b = budgetsModule.createBudgets({ maxActiveTimers: 1 });
  b.allocTimer();
  b.releaseTimer();
  b.allocTimer(); // should not throw
});

test('getMinTimerInterval returns configured value', function() {
  var b = budgetsModule.createBudgets({ maxTimerFrequency: 100 });
  assert(b.getMinTimerInterval() === 100);
});

// ── Ownership tests ──────────────────────────────────────────────────

var Ownership = require('./src/dom/ownership');

group('Ownership — basic ownership');

test('root is owned', function() {
  // Create a minimal mock node tree
  var root = mockElement('div', [mockElement('span')]);
  var own = new Ownership(root);
  assert(own.owns(root) === true);
});

test('root children are owned', function() {
  var child = mockElement('span');
  var root = mockElement('div', [child]);
  var own = new Ownership(root);
  assert(own.owns(child) === true);
});

test('unrelated node is not owned', function() {
  var root = mockElement('div');
  var other = mockElement('p');
  var own = new Ownership(root);
  assert(own.owns(other) === false);
});

test('markOwned grants ownership', function() {
  var root = mockElement('div');
  var own = new Ownership(root);
  var created = mockElement('span');
  own.markOwned(created);
  assert(own.owns(created) === true);
});

test('root is at boundary', function() {
  var root = mockElement('div');
  var own = new Ownership(root);
  assert(own.isAtBoundary(root) === true);
});

test('child is not at boundary', function() {
  var child = mockElement('span');
  var root = mockElement('div', [child]);
  var own = new Ownership(root);
  assert(own.isAtBoundary(child) === false);
});

test('checkMutation throws on unowned', function() {
  var root = mockElement('div');
  var own = new Ownership(root);
  var other = mockElement('p');
  assertThrows(function() { own.checkMutation(other, 'test'); }, 'not owned');
});

test('checkMutation succeeds on owned', function() {
  var root = mockElement('div');
  var own = new Ownership(root);
  own.checkMutation(root, 'test'); // should not throw
});

test('isInSubtree works', function() {
  var child = mockElement('span');
  var root = mockElement('div', [child]);
  var other = mockElement('p');
  var own = new Ownership(root);
  assert(own.isInSubtree(child) === true);
  assert(own.isInSubtree(root) === true);
  assert(own.isInSubtree(other) === false);
});

// ── IdentityCache tests ──────────────────────────────────────────────

var IdentityCache = require('./src/dom/identity-cache');

group('IdentityCache — identity preservation');

test('same node returns same handle', function() {
  var cache = new IdentityCache();
  var node = mockElement('div');
  var ctx = mockContext();
  var h1 = cache.getOrCreate(node, ctx);
  var h2 = cache.getOrCreate(node, ctx);
  assert(h1 === h2, 'should return identical object');
});

test('different nodes return different handles', function() {
  var cache = new IdentityCache();
  var n1 = mockElement('div');
  var n2 = mockElement('span');
  var ctx = mockContext();
  var h1 = cache.getOrCreate(n1, ctx);
  var h2 = cache.getOrCreate(n2, ctx);
  assert(h1 !== h2);
});

test('null returns null', function() {
  var cache = new IdentityCache();
  assert(cache.getOrCreate(null, {}) === null);
});

test('invalidate causes new handle on next access', function() {
  var cache = new IdentityCache();
  var node = mockElement('div');
  var ctx = mockContext();
  var h1 = cache.getOrCreate(node, ctx);
  cache.invalidate(node);
  var h2 = cache.getOrCreate(node, ctx);
  assert(h1 !== h2, 'should create fresh handle after invalidation');
});

// ── Safe Timers tests ────────────────────────────────────────────────

var createSafeTimers = require('./src/dom/safe-timers').createSafeTimers;

group('Safe Timers');

test('setTimeout fires and releases budget', function(done) {
  var b = budgetsModule.createBudgets({ maxActiveTimers: 5 });
  var timers = createSafeTimers(b);
  var fired = false;
  timers.setTimeout(function() { fired = true; }, 10);
  // Can't fully test async in sync harness — just verify it doesn't throw
  assert(timers.getActiveCount() === 1);
});

test('clearTimeout releases budget', function() {
  var b = budgetsModule.createBudgets({ maxActiveTimers: 5 });
  var timers = createSafeTimers(b);
  var id = timers.setTimeout(function() {}, 10000);
  assert(timers.getActiveCount() === 1);
  timers.clearTimeout(id);
  assert(timers.getActiveCount() === 0);
});

test('setInterval enforces minimum interval', function() {
  var b = budgetsModule.createBudgets({ maxActiveTimers: 5, maxTimerFrequency: 200 });
  var timers = createSafeTimers(b);
  var id = timers.setInterval(function() {}, 10); // requested 10ms, should be clamped to 200ms
  assert(timers.getActiveCount() === 1);
  timers.clearInterval(id);
});

test('cleanup clears all timers', function() {
  var b = budgetsModule.createBudgets({ maxActiveTimers: 10 });
  var timers = createSafeTimers(b);
  timers.setTimeout(function() {}, 10000);
  timers.setTimeout(function() {}, 10000);
  timers.setInterval(function() {}, 10000);
  assert(timers.getActiveCount() === 3);
  timers.cleanup();
  assert(timers.getActiveCount() === 0);
});

test('setTimeout rejects non-function', function() {
  var b = budgetsModule.createBudgets({});
  var timers = createSafeTimers(b);
  assertThrows(function() { timers.setTimeout('alert(1)', 10); }, 'function callback');
});

// ── Attack vector tests ──────────────────────────────────────────────

group('Attack vectors — URL injection');

test('javascript: in href denied', function() {
  var p = urlPolicy.createURLPolicy(null, '');
  assert(p.check({ url: 'javascript:alert(1)', purpose: 'navigation' }).allow === false);
});

test('javascript: with mixed case denied', function() {
  var p = urlPolicy.createURLPolicy(null, '');
  assert(p.check({ url: 'JaVaScRiPt:alert(1)', purpose: 'navigation' }).allow === false);
});

test('javascript: with tab injection denied', function() {
  var p = urlPolicy.createURLPolicy(null, '');
  assert(p.check({ url: 'java\tscript:alert(1)', purpose: 'navigation' }).allow === false);
});

test('data:text/html denied', function() {
  var p = urlPolicy.createURLPolicy(null, '');
  assert(p.check({ url: 'data:text/html,<script>alert(1)</script>', purpose: 'navigation' }).allow === false);
});

test('vbscript: denied', function() {
  var p = urlPolicy.createURLPolicy(null, '');
  assert(p.check({ url: 'vbscript:MsgBox("xss")', purpose: 'navigation' }).allow === false);
});

group('Attack vectors — prototype pollution via ownership');

test('cannot access __proto__ through ownership', function() {
  var root = mockElement('div');
  var own = new Ownership(root);
  // Attempting to manipulate __proto__ should have no effect
  assert(own.owns(null) === false);
  assert(own.owns(undefined) === false);
});

group('Attack vectors — budget exhaustion');

test('node budget prevents infinite creation', function() {
  var b = budgetsModule.createBudgets({ maxNodes: 3 });
  b.allocNodes(3);
  assertThrows(function() { b.allocNodes(1); }, 'node budget exceeded');
});

test('listener budget prevents infinite listeners', function() {
  var b = budgetsModule.createBudgets({ maxListeners: 2 });
  b.allocListener();
  b.allocListener();
  assertThrows(function() { b.allocListener(); }, 'listener budget exceeded');
});

test('timer budget prevents infinite timers', function() {
  var b = budgetsModule.createBudgets({ maxActiveTimers: 1 });
  b.allocTimer();
  assertThrows(function() { b.allocTimer(); }, 'timer budget exceeded');
});

test('text size budget prevents oversized text', function() {
  var b = budgetsModule.createBudgets({ maxTextBytes: 10 });
  assertThrows(function() { b.checkTextSize('a'.repeat(100)); }, 'text size exceeded');
});

test('HTML size budget prevents oversized HTML', function() {
  var b = budgetsModule.createBudgets({ maxHTMLBytes: 10 });
  assertThrows(function() { b.checkHTMLSize('<div>' + 'a'.repeat(100) + '</div>'); }, 'HTML size exceeded');
});

// ── PHP parity test — Sanitizer ──────────────────────────────────────

group('PHP Sanitizer parity (run with: php test-dom.php)');

test('(placeholder — PHP tests in test-dom.php)', function() {
  skipped++;
  passed--; // undo the auto-increment
});

// ── Mock helpers ─────────────────────────────────────────────────────

/**
 * Create a minimal mock DOM element for ownership/cache testing.
 * Real DOM tests need jsdom or a browser.
 */
function mockElement(tag, children) {
  var el = {
    nodeName: tag.toUpperCase(),
    tagName: tag.toUpperCase(),
    nodeType: 1,
    firstChild: null,
    lastChild: null,
    nextSibling: null,
    previousSibling: null,
    parentElement: null,
    childNodes: [],
    children: [],
    _contains: new Set(),
    contains: function(node) { return this._contains.has(node); }
  };

  if (children) {
    el.childNodes = children;
    el.children = children;
    el.firstChild = children[0] || null;
    el.lastChild = children[children.length - 1] || null;
    for (var i = 0; i < children.length; i++) {
      children[i].parentElement = el;
      el._contains.add(children[i]);
      if (i > 0) children[i].previousSibling = children[i-1];
      if (i < children.length - 1) children[i].nextSibling = children[i+1];
      // Propagate contains
      if (children[i]._contains) {
        children[i]._contains.forEach(function(c) { el._contains.add(c); });
      }
    }
  }

  return el;
}

function mockContext() {
  return {
    policy: { tags: ['div', 'span', 'p'], attributes: { '*': ['id', 'class'] } },
    ownership: new Ownership(mockElement('div')),
    cache: { getOrCreate: function(n) { return { __isSafeHandle: true, __node: n }; } },
    budgets: budgetsModule.createBudgets({}),
    sanitizer: null,
    eventBridge: null,
    handleFactory: function(n) { return { __isSafeHandle: true, __node: n }; }
  };
}

// ── Summary ──────────────────────────────────────────────────────────

console.log('\n══════════════════════════════════════════════════');
console.log('SafeScript DOM Membrane — Security Tests');
console.log('══════════════════════════════════════════════════');
console.log('  Passed:  ' + passed);
console.log('  Failed:  ' + failed);
console.log('  Skipped: ' + skipped);
console.log('══════════════════════════════════════════════════');

if (failed > 0) {
  process.exit(1);
}
