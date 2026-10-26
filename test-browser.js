/**
 * SafeScript v1.2 — Headless Browser Integration Tests
 *
 * Exercises the full DOM membrane against real Chromium via Playwright.
 * Every test creates a real DOM, runs SafeScript code through the membrane,
 * and verifies the actual DOM state.
 */
'use strict';

var chromium = require('playwright').chromium;
var fs = require('fs');
var path = require('path');

// ── Build the browser bundle ────────────────────────────────────────

var DOM_FILES = [
  'src/dom/url-policy.js',
  'src/dom/budgets.js',
  'src/dom/identity-cache.js',
  'src/dom/ownership.js',
  'src/dom/sub-facades/classList.js',
  'src/dom/sub-facades/style.js',
  'src/dom/sub-facades/dataset.js',
  'src/dom/sub-facades/collection.js',
  'src/dom/safe-handle.js',
  'src/dom/sanitizer.js',
  'src/dom/safe-timers.js',
  'src/dom/event-bridge.js',
  'src/dom/safe-document.js',
  'src/dom/index.js'
];

function buildBrowserBundle() {
  var bundle = '(function() {\n';
  bundle += 'var _modules = {};\nvar _cache = {};\n';

  DOM_FILES.forEach(function(filePath) {
    var src = fs.readFileSync(path.join(__dirname, filePath), 'utf8');
    bundle += '_modules["' + filePath + '"] = function(module, exports, require) {\n' + src + '\n};\n\n';
  });

  bundle += 'function _require(from, id) {\n';
  bundle += '  if (id.charAt(0) === ".") {\n';
  bundle += '    var base = from.split("/"); base.pop();\n';
  bundle += '    var parts = id.split("/");\n';
  bundle += '    for (var i = 0; i < parts.length; i++) {\n';
  bundle += '      if (parts[i] === "..") base.pop();\n';
  bundle += '      else if (parts[i] !== ".") base.push(parts[i]);\n';
  bundle += '    }\n';
  bundle += '    id = base.join("/");\n';
  bundle += '    if (!id.match(/\\.js$/)) id += ".js";\n';
  bundle += '  }\n';
  bundle += '  if (_cache[id]) return _cache[id].exports;\n';
  bundle += '  if (!_modules[id]) throw new Error("Module not found: " + id + " (from " + from + ")");\n';
  bundle += '  var mod = { exports: {} };\n';
  bundle += '  _cache[id] = mod;\n';
  bundle += '  _modules[id](mod, mod.exports, function(childId) { return _require(id, childId); });\n';
  bundle += '  return mod.exports;\n';
  bundle += '}\n\n';

  bundle += 'var domIndex = _require("", "src/dom/index.js");\n';
  bundle += 'window.SafeScriptDOM = {\n';
  bundle += '  createDOMContext: domIndex.createDOMContext,\n';
  bundle += '  mergePolicy: domIndex.mergePolicy,\n';
  bundle += '  DEFAULT_TAGS: domIndex.DEFAULT_TAGS,\n';
  bundle += '  DEFAULT_ATTRIBUTES: domIndex.DEFAULT_ATTRIBUTES,\n';
  bundle += '  DEFAULT_EVENTS: domIndex.DEFAULT_EVENTS\n';
  bundle += '};\n})();\n';

  return bundle;
}

// ── Test Framework ──────────────────────────────────────────────────

var passed = 0, failed = 0, total = 0, failures = [];

async function test(name, fn) {
  total++;
  try {
    await fn();
    passed++;
  } catch (e) {
    failed++;
    failures.push({ name: name, error: e.message || String(e) });
    console.log('  FAIL: ' + name);
    console.log('        ' + (e.message || e));
  }
}

function assert(cond, msg) { if (!cond) throw new Error(msg || 'assertion failed'); }
function assertEqual(expected, actual, msg) {
  if (expected !== actual) throw new Error((msg ? msg + ': ' : '') + 'expected ' + JSON.stringify(expected) + ', got ' + JSON.stringify(actual));
}

// ── Main ────────────────────────────────────────────────────────────

async function main() {
  console.log('Building browser bundle...');
  var bundleCode = buildBrowserBundle();

  console.log('Launching Chromium...');
  var browser = await chromium.launch({
    executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome'
  });
  var ctx = await browser.newContext();

  async function freshPage(rootHTML) {
    var page = await ctx.newPage();
    await page.setContent('<html><body><div id="root">' + (rootHTML || '') + '</div></body></html>');
    await page.evaluate(bundleCode);
    return page;
  }

  // ── Section 1: Basic DOM Context ──────────────────────────────────

  console.log('\n── Basic DOM Context ──');

  await test('createDOMContext returns scope with document, timers, console', async function() {
    var page = await freshPage();
    var r = await page.evaluate(function() {
      var root = document.getElementById('root');
      var c = SafeScriptDOM.createDOMContext(root);
      return {
        doc: typeof c.scope.document === 'object',
        st: typeof c.scope.setTimeout === 'function',
        si: typeof c.scope.setInterval === 'function',
        ct: typeof c.scope.clearTimeout === 'function',
        ci: typeof c.scope.clearInterval === 'function',
        con: typeof c.scope.console === 'object'
      };
    });
    assert(r.doc && r.st && r.si && r.ct && r.ci && r.con, 'scope should have all expected members');
    await page.close();
  });

  await test('document.body maps to root element', async function() {
    var page = await freshPage('<span>hello</span>');
    var r = await page.evaluate(function() {
      var root = document.getElementById('root');
      var c = SafeScriptDOM.createDOMContext(root);
      return { tag: c.scope.document.body.tagName };
    });
    assertEqual('DIV', r.tag);
    await page.close();
  });

  // ── Section 2: Element Creation ───────────────────────────────────

  console.log('\n── Element Creation ──');

  await test('createElement creates allowed elements', async function() {
    var page = await freshPage();
    var r = await page.evaluate(function() {
      var c = SafeScriptDOM.createDOMContext(document.getElementById('root'));
      return { div: c.scope.document.createElement('div').tagName, span: c.scope.document.createElement('span').tagName };
    });
    assertEqual('DIV', r.div); assertEqual('SPAN', r.span);
    await page.close();
  });

  await test('createElement rejects script tag', async function() {
    var page = await freshPage();
    var r = await page.evaluate(function() {
      var c = SafeScriptDOM.createDOMContext(document.getElementById('root'));
      try { c.scope.document.createElement('script'); return false; } catch(e) { return true; }
    });
    assert(r, 'should throw for script tag');
    await page.close();
  });

  await test('createElement rejects iframe', async function() {
    var page = await freshPage();
    var r = await page.evaluate(function() {
      var c = SafeScriptDOM.createDOMContext(document.getElementById('root'));
      try { c.scope.document.createElement('iframe'); return false; } catch(e) { return true; }
    });
    assert(r, 'should throw for iframe');
    await page.close();
  });

  await test('appendChild adds child to real DOM', async function() {
    var page = await freshPage();
    var r = await page.evaluate(function() {
      var root = document.getElementById('root');
      var c = SafeScriptDOM.createDOMContext(root);
      var p = c.scope.document.createElement('p');
      p.textContent = 'Hello from SafeScript';
      c.scope.document.body.appendChild(p);
      return root.querySelector('p').textContent;
    });
    assertEqual('Hello from SafeScript', r);
    await page.close();
  });

  await test('createTextNode creates real text in DOM', async function() {
    var page = await freshPage();
    var r = await page.evaluate(function() {
      var root = document.getElementById('root');
      var c = SafeScriptDOM.createDOMContext(root);
      var d = c.scope.document.createElement('div');
      d.appendChild(c.scope.document.createTextNode('text node'));
      c.scope.document.body.appendChild(d);
      return root.querySelector('div').textContent;
    });
    assertEqual('text node', r);
    await page.close();
  });

  // ── Section 3: Attribute Policy ───────────────────────────────────

  console.log('\n── Attribute Policy ──');

  await test('setAttribute allows safe attributes', async function() {
    var page = await freshPage();
    var r = await page.evaluate(function() {
      var root = document.getElementById('root');
      var c = SafeScriptDOM.createDOMContext(root);
      var d = c.scope.document.createElement('div');
      d.setAttribute('class', 'tc'); d.setAttribute('id', 'ti'); d.setAttribute('title', 'tt');
      c.scope.document.body.appendChild(d);
      var a = root.querySelector('#ti');
      return { cls: a.className, title: a.title };
    });
    assertEqual('tc', r.cls); assertEqual('tt', r.title);
    await page.close();
  });

  await test('setAttribute blocks on* event handlers', async function() {
    var page = await freshPage();
    var r = await page.evaluate(function() {
      var c = SafeScriptDOM.createDOMContext(document.getElementById('root'));
      try { c.scope.document.createElement('div').setAttribute('onclick', 'alert(1)'); return false; } catch(e) { return true; }
    });
    assert(r, 'should throw for onclick');
    await page.close();
  });

  await test('SECURITY: setAttribute blocks javascript: URLs without host URL hook', async function() {
    var page = await freshPage();
    var r = await page.evaluate(function() {
      var c = SafeScriptDOM.createDOMContext(document.getElementById('root'));
      var a = c.scope.document.createElement('a');
      try { a.setAttribute('href', 'javascript:alert(1)'); return { threw: false }; }
      catch(e) { return { threw: true, msg: e.message }; }
    });
    assert(r.threw, 'should throw for javascript: URL even without host URL hook');
    assert(r.msg.indexOf('javascript:') !== -1, 'error should mention javascript:');
    await page.close();
  });

  await test('SECURITY: setAttribute blocks javascript: mixed case', async function() {
    var page = await freshPage();
    var r = await page.evaluate(function() {
      var c = SafeScriptDOM.createDOMContext(document.getElementById('root'));
      var a = c.scope.document.createElement('a');
      try { a.setAttribute('href', 'JaVaScRiPt:alert(1)'); return false; } catch(e) { return true; }
    });
    assert(r, 'should throw for mixed-case javascript:');
    await page.close();
  });

  await test('SECURITY: setAttribute blocks data:text/html URLs', async function() {
    var page = await freshPage();
    var r = await page.evaluate(function() {
      var c = SafeScriptDOM.createDOMContext(document.getElementById('root'));
      var a = c.scope.document.createElement('a');
      try { a.setAttribute('href', 'data:text/html,<script>alert(1)</script>'); return false; } catch(e) { return true; }
    });
    assert(r, 'should throw for data:text/html');
    await page.close();
  });

  await test('setAttribute blocks srcdoc', async function() {
    var page = await freshPage();
    var r = await page.evaluate(function() {
      var c = SafeScriptDOM.createDOMContext(document.getElementById('root'));
      try { c.scope.document.createElement('div').setAttribute('srcdoc', 'evil'); return false; } catch(e) { return true; }
    });
    assert(r, 'srcdoc should be blocked');
    await page.close();
  });

  // ── Section 4: HTML Sanitization ──────────────────────────────────

  console.log('\n── HTML Sanitization ──');

  await test('innerHTML sanitizes script tags', async function() {
    var page = await freshPage();
    var r = await page.evaluate(function() {
      var root = document.getElementById('root');
      var c = SafeScriptDOM.createDOMContext(root);
      var d = c.scope.document.createElement('div');
      d.innerHTML = '<p>safe</p><script>alert(1)</script>';
      c.scope.document.body.appendChild(d);
      var html = root.querySelector('div:last-child').innerHTML;
      return { noScript: html.indexOf('<script') === -1, hasSafe: html.indexOf('safe') !== -1 };
    });
    assert(r.noScript, 'script tag should be sanitized');
    assert(r.hasSafe, 'safe content should remain');
    await page.close();
  });

  await test('innerHTML sanitizes on* handlers on elements', async function() {
    var page = await freshPage();
    var r = await page.evaluate(function() {
      var root = document.getElementById('root');
      var c = SafeScriptDOM.createDOMContext(root);
      var d = c.scope.document.createElement('div');
      d.innerHTML = '<img onerror="alert(1)" src="x.png">';
      c.scope.document.body.appendChild(d);
      var img = root.querySelector('img');
      return img ? !img.hasAttribute('onerror') : false;
    });
    assert(r, 'onerror should be stripped');
    await page.close();
  });

  await test('innerHTML strips iframes', async function() {
    var page = await freshPage();
    var r = await page.evaluate(function() {
      var root = document.getElementById('root');
      var c = SafeScriptDOM.createDOMContext(root);
      var d = c.scope.document.createElement('div');
      d.innerHTML = '<p>text</p><iframe src="evil.html"></iframe>';
      c.scope.document.body.appendChild(d);
      return root.querySelector('div:last-child').innerHTML.indexOf('<iframe') === -1;
    });
    assert(r, 'iframe should be stripped');
    await page.close();
  });

  await test('innerHTML strips javascript: in hrefs', async function() {
    var page = await freshPage();
    var r = await page.evaluate(function() {
      var root = document.getElementById('root');
      var c = SafeScriptDOM.createDOMContext(root);
      var d = c.scope.document.createElement('div');
      d.innerHTML = '<a href="javascript:alert(1)">click</a>';
      c.scope.document.body.appendChild(d);
      var a = root.querySelector('a');
      return a ? (a.getAttribute('href') || '').indexOf('javascript:') === -1 : true;
    });
    assert(r, 'javascript: href should be stripped from innerHTML');
    await page.close();
  });

  await test('innerHTML: nested attack vector — combined onerror + script + js href', async function() {
    var page = await freshPage();
    var r = await page.evaluate(function() {
      var root = document.getElementById('root');
      var c = SafeScriptDOM.createDOMContext(root);
      var d = c.scope.document.createElement('div');
      d.innerHTML = '<div><img src=x onerror=alert(1)><a href="javascript:void(0)">link</a><script>alert(2)</script></div>';
      c.scope.document.body.appendChild(d);
      var html = root.querySelector('div:last-child').innerHTML;
      return { noOnerror: html.indexOf('onerror') === -1, noScript: html.indexOf('<script') === -1, noJs: html.indexOf('javascript:') === -1 };
    });
    assert(r.noOnerror && r.noScript && r.noJs, 'all attack vectors should be stripped');
    await page.close();
  });

  // ── Section 5: Ownership & Scoping ────────────────────────────────

  console.log('\n── Ownership & Scoping ──');

  await test('querySelector scoped to root subtree', async function() {
    var page = await freshPage('<span class="inside">in</span>');
    await page.evaluate(function() {
      var o = document.createElement('span'); o.className = 'outside'; document.body.appendChild(o);
    });
    var r = await page.evaluate(function() {
      var c = SafeScriptDOM.createDOMContext(document.getElementById('root'));
      return { inside: c.scope.document.querySelector('.inside') !== null, outside: c.scope.document.querySelector('.outside') === null };
    });
    assert(r.inside, 'should find inside'); assert(r.outside, 'should NOT find outside');
    await page.close();
  });

  await test('querySelectorAll only returns owned nodes', async function() {
    var page = await freshPage('<p>a</p><p>b</p>');
    await page.evaluate(function() { var o = document.createElement('p'); o.textContent = 'out'; document.body.appendChild(o); });
    var r = await page.evaluate(function() {
      var c = SafeScriptDOM.createDOMContext(document.getElementById('root'));
      return c.scope.document.querySelectorAll('p').length;
    });
    assertEqual(2, r, 'should only find 2 paragraphs inside root');
    await page.close();
  });

  await test('parentElement returns null at root boundary', async function() {
    var page = await freshPage('<span>hi</span>');
    var r = await page.evaluate(function() {
      var c = SafeScriptDOM.createDOMContext(document.getElementById('root'));
      return c.scope.document.body.parentElement === null;
    });
    assert(r, 'parentElement of root should be null');
    await page.close();
  });

  // ── Section 6: Event Bridge ───────────────────────────────────────

  console.log('\n── Event Bridge ──');

  await test('addEventListener receives safe frozen event on click', async function() {
    var page = await freshPage();
    var r = await page.evaluate(function() {
      return new Promise(function(resolve) {
        var root = document.getElementById('root');
        var c = SafeScriptDOM.createDOMContext(root);
        var btn = c.scope.document.createElement('button');
        btn.setAttribute('id', 'tb');
        c.scope.document.body.appendChild(btn);
        btn.addEventListener('click', function(e) {
          resolve({ type: e.type, tag: e.target.tagName, frozen: Object.isFrozen(e) });
        });
        document.getElementById('tb').click();
      });
    });
    assertEqual('click', r.type); assertEqual('BUTTON', r.tag); assert(r.frozen, 'event should be frozen');
    await page.close();
  });

  await test('addEventListener rejects string handler', async function() {
    var page = await freshPage();
    var r = await page.evaluate(function() {
      var c = SafeScriptDOM.createDOMContext(document.getElementById('root'));
      try { c.scope.document.createElement('div').addEventListener('click', 'alert(1)'); return false; } catch(e) { return true; }
    });
    assert(r, 'string handler should throw');
    await page.close();
  });

  await test('removeEventListener stops callback', async function() {
    var page = await freshPage();
    var r = await page.evaluate(function() {
      return new Promise(function(resolve) {
        var root = document.getElementById('root');
        var c = SafeScriptDOM.createDOMContext(root);
        var btn = c.scope.document.createElement('button');
        btn.setAttribute('id', 'rb');
        c.scope.document.body.appendChild(btn);
        var count = 0;
        var h = function() { count++; };
        btn.addEventListener('click', h);
        document.getElementById('rb').click();
        btn.removeEventListener('click', h);
        document.getElementById('rb').click();
        setTimeout(function() { resolve(count); }, 50);
      });
    });
    assertEqual(1, r, 'handler should fire once then be removed');
    await page.close();
  });

  // ── Section 7: Sub-Facades ────────────────────────────────────────

  console.log('\n── Sub-Facades ──');

  await test('classList.add/remove/contains on real DOM', async function() {
    var page = await freshPage();
    var r = await page.evaluate(function() {
      var root = document.getElementById('root');
      var c = SafeScriptDOM.createDOMContext(root);
      var d = c.scope.document.createElement('div');
      d.setAttribute('id', 'clt');
      c.scope.document.body.appendChild(d);
      d.classList.add('foo', 'bar');
      var f1 = d.classList.contains('foo');
      d.classList.remove('foo');
      var f2 = d.classList.contains('foo');
      return { f1: f1, f2: f2, actual: document.getElementById('clt').className };
    });
    assert(r.f1, 'should have foo after add'); assert(!r.f2, 'should not have foo after remove');
    assertEqual('bar', r.actual);
    await page.close();
  });

  await test('style facade validates and applies CSS', async function() {
    var page = await freshPage();
    var r = await page.evaluate(function() {
      var root = document.getElementById('root');
      var c = SafeScriptDOM.createDOMContext(root);
      var d = c.scope.document.createElement('div');
      d.setAttribute('id', 'st');
      c.scope.document.body.appendChild(d);
      d.style.color = 'red';
      d.style.fontSize = '16px';
      var urlBlocked = false;
      try { d.style.background = 'url(evil.com)'; } catch(e) { urlBlocked = true; }
      var a = document.getElementById('st');
      return { color: a.style.color, fs: a.style.fontSize, urlBlocked: urlBlocked };
    });
    assertEqual('red', r.color); assertEqual('16px', r.fs);
    assert(r.urlBlocked, 'url() should be blocked');
    await page.close();
  });

  await test('expression() in style blocked', async function() {
    var page = await freshPage();
    var r = await page.evaluate(function() {
      var c = SafeScriptDOM.createDOMContext(document.getElementById('root'));
      try { c.scope.document.createElement('div').style.width = 'expression(alert(1))'; return false; } catch(e) { return true; }
    });
    assert(r, 'expression() should throw');
    await page.close();
  });

  await test('dataset facade get/set on real DOM', async function() {
    var page = await freshPage();
    var r = await page.evaluate(function() {
      var root = document.getElementById('root');
      var c = SafeScriptDOM.createDOMContext(root);
      var d = c.scope.document.createElement('div');
      d.setAttribute('id', 'ds');
      c.scope.document.body.appendChild(d);
      d.dataset.set('userId', '42');
      return { v: d.dataset.get('userId'), actual: document.getElementById('ds').getAttribute('data-user-id'), has: d.dataset.has('userId') };
    });
    assertEqual('42', r.v); assertEqual('42', r.actual); assert(r.has);
    await page.close();
  });

  // ── Section 8: Resource Budgets ───────────────────────────────────

  console.log('\n── Resource Budgets ──');

  await test('node budget enforced', async function() {
    var page = await freshPage();
    var r = await page.evaluate(function() {
      var c = SafeScriptDOM.createDOMContext(document.getElementById('root'), { limits: { maxNodes: 5 } });
      try { for (var i = 0; i < 20; i++) c.scope.document.createElement('div'); return false; }
      catch(e) { return e.message.indexOf('node budget') !== -1; }
    });
    assert(r, 'should throw on node budget');
    await page.close();
  });

  await test('listener budget enforced', async function() {
    var page = await freshPage();
    var r = await page.evaluate(function() {
      var c = SafeScriptDOM.createDOMContext(document.getElementById('root'), { limits: { maxListeners: 3 } });
      var d = c.scope.document.createElement('div');
      c.scope.document.body.appendChild(d);
      try { for (var i = 0; i < 10; i++) d.addEventListener('click', function(){}); return false; }
      catch(e) { return e.message.indexOf('listener budget') !== -1; }
    });
    assert(r, 'should throw on listener budget');
    await page.close();
  });

  await test('text size budget enforced', async function() {
    var page = await freshPage();
    var r = await page.evaluate(function() {
      var c = SafeScriptDOM.createDOMContext(document.getElementById('root'), { limits: { maxTextBytes: 100 } });
      try { c.scope.document.createTextNode(new Array(200).join('x')); return false; }
      catch(e) { return e.message.indexOf('text size') !== -1; }
    });
    assert(r, 'should throw on text size budget');
    await page.close();
  });

  // ── Section 9: Safe Timers ────────────────────────────────────────

  console.log('\n── Safe Timers ──');

  await test('setTimeout fires callback', async function() {
    var page = await freshPage();
    var r = await page.evaluate(function() {
      return new Promise(function(resolve) {
        var c = SafeScriptDOM.createDOMContext(document.getElementById('root'));
        c.scope.setTimeout(function() { resolve(true); }, 50);
      });
    });
    assert(r, 'setTimeout should fire');
    await page.close();
  });

  await test('clearTimeout cancels pending timer', async function() {
    var page = await freshPage();
    var r = await page.evaluate(function() {
      return new Promise(function(resolve) {
        var c = SafeScriptDOM.createDOMContext(document.getElementById('root'));
        var fired = false;
        var id = c.scope.setTimeout(function() { fired = true; }, 50);
        c.scope.clearTimeout(id);
        setTimeout(function() { resolve(fired); }, 150);
      });
    });
    assert(!r, 'cleared timer should not fire');
    await page.close();
  });

  await test('timer budget enforced', async function() {
    var page = await freshPage();
    var r = await page.evaluate(function() {
      var c = SafeScriptDOM.createDOMContext(document.getElementById('root'), { limits: { maxActiveTimers: 2 } });
      try { for (var i = 0; i < 10; i++) c.scope.setTimeout(function(){}, 10000); return false; }
      catch(e) { return e.message.indexOf('timer budget') !== -1; }
    });
    assert(r, 'should throw on timer budget');
    await page.close();
  });

  // ── Section 10: Identity & Freezing ───────────────────────────────

  console.log('\n── Identity & Freezing ──');

  await test('same node returns same SafeHandle', async function() {
    var page = await freshPage('<span id="s1">test</span>');
    var r = await page.evaluate(function() {
      var c = SafeScriptDOM.createDOMContext(document.getElementById('root'));
      return c.scope.document.querySelector('#s1') === c.scope.document.querySelector('#s1');
    });
    assert(r, 'identity cache should return same handle');
    await page.close();
  });

  await test('SafeHandle is frozen', async function() {
    var page = await freshPage();
    var r = await page.evaluate(function() {
      var c = SafeScriptDOM.createDOMContext(document.getElementById('root'));
      var d = c.scope.document.createElement('div');
      return Object.isFrozen(d);
    });
    assert(r, 'SafeHandle should be frozen');
    await page.close();
  });

  // ── Section 11: Cleanup ───────────────────────────────────────────

  console.log('\n── Cleanup ──');

  await test('cleanup() removes listeners and timers', async function() {
    var page = await freshPage();
    var r = await page.evaluate(function() {
      return new Promise(function(resolve) {
        var root = document.getElementById('root');
        var c = SafeScriptDOM.createDOMContext(root);
        var d = c.scope.document.createElement('div');
        c.scope.document.body.appendChild(d);
        var cf = false, tf = false;
        d.addEventListener('click', function() { cf = true; });
        c.scope.setTimeout(function() { tf = true; }, 50);
        c.cleanup();
        root.querySelector('div').click();
        setTimeout(function() { resolve({ cf: cf, tf: tf }); }, 150);
      });
    });
    assert(!r.cf, 'click should not fire after cleanup');
    assert(!r.tf, 'timer should not fire after cleanup');
    await page.close();
  });

  // ── Section 12: Security Attack Vectors ───────────────────────────

  console.log('\n── Security Attacks ──');

  await test('__proto__ access returns undefined', async function() {
    var page = await freshPage();
    var r = await page.evaluate(function() {
      var c = SafeScriptDOM.createDOMContext(document.getElementById('root'));
      return c.scope.document.createElement('div').__proto__ === undefined;
    });
    assert(r, '__proto__ should be undefined');
    await page.close();
  });

  await test('ownerDocument access returns undefined', async function() {
    var page = await freshPage();
    var r = await page.evaluate(function() {
      var c = SafeScriptDOM.createDOMContext(document.getElementById('root'));
      return c.scope.document.createElement('div').ownerDocument === undefined;
    });
    assert(r, 'ownerDocument should be undefined');
    await page.close();
  });

  await test('SafeDocument is frozen', async function() {
    var page = await freshPage();
    var r = await page.evaluate(function() {
      var c = SafeScriptDOM.createDOMContext(document.getElementById('root'));
      return Object.isFrozen(c.scope.document);
    });
    assert(r, 'SafeDocument should be frozen');
    await page.close();
  });

  // ── Section 13: DOM Tree Manipulation ─────────────────────────────

  console.log('\n── DOM Tree Manipulation ──');

  await test('removeChild removes from real DOM', async function() {
    var page = await freshPage('<p id="rm">bye</p>');
    var r = await page.evaluate(function() {
      var root = document.getElementById('root');
      var c = SafeScriptDOM.createDOMContext(root);
      c.scope.document.body.removeChild(c.scope.document.querySelector('#rm'));
      return root.querySelector('#rm') === null;
    });
    assert(r, 'removed child should be gone');
    await page.close();
  });

  await test('insertBefore works in real DOM', async function() {
    var page = await freshPage('<p id="ex">existing</p>');
    var r = await page.evaluate(function() {
      var root = document.getElementById('root');
      var c = SafeScriptDOM.createDOMContext(root);
      var n = c.scope.document.createElement('span');
      n.textContent = 'inserted';
      c.scope.document.body.insertBefore(n, c.scope.document.querySelector('#ex'));
      return { tag: root.firstElementChild.tagName, text: root.firstElementChild.textContent };
    });
    assertEqual('SPAN', r.tag); assertEqual('inserted', r.text);
    await page.close();
  });

  await test('replaceChild works in real DOM', async function() {
    var page = await freshPage('<p id="old">old</p>');
    var r = await page.evaluate(function() {
      var root = document.getElementById('root');
      var c = SafeScriptDOM.createDOMContext(root);
      var n = c.scope.document.createElement('span');
      n.textContent = 'new';
      c.scope.document.body.replaceChild(n, c.scope.document.querySelector('#old'));
      return { gone: root.querySelector('#old') === null, newTag: root.querySelector('span').textContent };
    });
    assert(r.gone, 'old should be gone'); assertEqual('new', r.newTag);
    await page.close();
  });

  // ════════════════════════════════════════════════════════════════════

  await browser.close();

  console.log('\n══════════════════════════════════════════════════');
  console.log('SafeScript v1.2 — Headless Browser Integration Tests');
  console.log('══════════════════════════════════════════════════');
  console.log('  Passed:  ' + passed);
  console.log('  Failed:  ' + failed);
  console.log('  Total:   ' + total);
  console.log('══════════════════════════════════════════════════');

  if (failures.length > 0) {
    console.log('\nFailures:');
    failures.forEach(function(f) { console.log('  * ' + f.name + ': ' + f.error); });
  }

  process.exit(failed > 0 ? 1 : 0);
}

main().catch(function(err) { console.error('Fatal:', err); process.exit(1); });
