<?php
/**
 * SafeScript DOM Membrane — PHP Parity Tests
 *
 * Tests Sanitizer.php and UrlPolicy.php to ensure they match
 * the JS sanitizer and url-policy modules.
 */

require_once __DIR__ . '/src/Sanitizer.php';
require_once __DIR__ . '/src/UrlPolicy.php';

use SafeScript\Sanitizer;
use SafeScript\UrlPolicy;

$passed = 0;
$failed = 0;

function test($name, $fn) {
    global $passed, $failed;
    try {
        $fn();
        $passed++;
    } catch (\Exception $e) {
        $failed++;
        echo "  FAIL: $name\n";
        echo "        {$e->getMessage()}\n";
    }
}

function assert_true($cond, $msg = 'assertion failed') {
    if (!$cond) throw new \Exception($msg);
}

function assert_equals($expected, $actual, $msg = '') {
    if ($expected !== $actual) {
        throw new \Exception($msg . " expected '$expected', got '$actual'");
    }
}

// ── UrlPolicy tests ──────────────────────────────────────────────────

echo "── URL Policy ──\n";

test('classifyPurpose: img src is image', function() {
    assert_equals('image', UrlPolicy::classifyPurpose('img', 'src'));
});

test('classifyPurpose: a href is navigation', function() {
    assert_equals('navigation', UrlPolicy::classifyPurpose('a', 'href'));
});

test('classifyPurpose: video src is media', function() {
    assert_equals('media', UrlPolicy::classifyPurpose('video', 'src'));
});

test('classifyPurpose: form action is form', function() {
    assert_equals('form', UrlPolicy::classifyPurpose('form', 'action'));
});

test('classifyPurpose: link href is style', function() {
    assert_equals('style', UrlPolicy::classifyPurpose('link', 'href'));
});

test('classifyPurpose: unknown is other', function() {
    assert_equals('other', UrlPolicy::classifyPurpose('div', 'foo'));
});

test('Layer 1: javascript: denied', function() {
    $p = new UrlPolicy();
    $r = $p->check(array('url' => 'javascript:alert(1)', 'purpose' => 'navigation'));
    assert_true($r['allow'] === false, 'should deny javascript:');
});

test('Layer 1: javascript: mixed case denied', function() {
    $p = new UrlPolicy();
    $r = $p->check(array('url' => 'JaVaScRiPt:alert(1)', 'purpose' => 'navigation'));
    assert_true($r['allow'] === false);
});

test('Layer 1: vbscript: denied', function() {
    $p = new UrlPolicy();
    $r = $p->check(array('url' => 'vbscript:MsgBox("xss")', 'purpose' => 'navigation'));
    assert_true($r['allow'] === false);
});

test('Layer 1: data:text/html denied', function() {
    $p = new UrlPolicy();
    $r = $p->check(array('url' => 'data:text/html,<script>alert(1)</script>', 'purpose' => 'navigation'));
    assert_true($r['allow'] === false);
});

test('host hook can deny', function() {
    $p = new UrlPolicy(function() { return array('allow' => false); });
    $r = $p->check(array('url' => 'https://example.com/img.png', 'purpose' => 'image'));
    assert_true($r['allow'] === false);
});

test('host hook cannot override javascript: deny', function() {
    $p = new UrlPolicy(function() { return array('allow' => true); });
    $r = $p->check(array('url' => 'javascript:alert(1)', 'purpose' => 'navigation'));
    assert_true($r['allow'] === false, 'javascript: should be denied even with permissive hook');
});

// ── Sanitizer tests ──────────────────────────────────────────────────

echo "\n── Sanitizer ──\n";

test('strips script tags', function() {
    $s = new Sanitizer();
    $r = $s->sanitize('<div>hello<script>alert(1)</script></div>');
    assert_true(strpos($r, '<script') === false, 'script tag should be removed');
    assert_true(strpos($r, 'hello') !== false, 'text content should remain');
});

test('strips iframe tags', function() {
    $s = new Sanitizer();
    $r = $s->sanitize('<div>text<iframe src="evil.html"></iframe></div>');
    assert_true(strpos($r, '<iframe') === false, 'iframe should be removed');
});

test('strips on* event handlers', function() {
    $s = new Sanitizer();
    $r = $s->sanitize('<div onclick="alert(1)">click</div>');
    assert_true(strpos($r, 'onclick') === false, 'onclick should be stripped');
    assert_true(strpos($r, 'click') !== false, 'text should remain');
});

test('strips onerror handler', function() {
    $s = new Sanitizer();
    $r = $s->sanitize('<img onerror="alert(1)" src="x.png">');
    assert_true(strpos($r, 'onerror') === false, 'onerror should be stripped');
});

test('strips javascript: URLs in href', function() {
    $s = new Sanitizer();
    $r = $s->sanitize('<a href="javascript:alert(1)">click</a>');
    assert_true(strpos($r, 'javascript:') === false, 'javascript: should be removed');
});

test('strips srcdoc', function() {
    $s = new Sanitizer();
    $r = $s->sanitize('<div srcdoc="<script>alert(1)</script>">text</div>');
    assert_true(strpos($r, 'srcdoc') === false, 'srcdoc should be stripped');
});

test('strips style elements', function() {
    $s = new Sanitizer();
    $r = $s->sanitize('<style>body{background:url(evil.com)}</style><p>text</p>');
    assert_true(strpos($r, '<style') === false, 'style element should be removed');
    assert_true(strpos($r, 'text') !== false);
});

test('sanitizes inline styles — removes url()', function() {
    $s = new Sanitizer();
    $r = $s->sanitize('<div style="background: url(evil.com); color: red;">text</div>');
    assert_true(strpos($r, 'url(') === false, 'url() should be removed from style');
    assert_true(strpos($r, 'color') !== false, 'safe properties should remain');
});

test('sanitizes inline styles — removes expression()', function() {
    $s = new Sanitizer();
    $r = $s->sanitize('<div style="width: expression(alert(1));">text</div>');
    assert_true(strpos($r, 'expression') === false, 'expression() should be removed');
});

test('promotes children of disallowed tags', function() {
    $s = new Sanitizer(array('tags' => array('div', 'span', 'p')));
    $r = $s->sanitize('<section><p>inside</p></section>');
    assert_true(strpos($r, '<section') === false, 'section should be removed');
    assert_true(strpos($r, '<p>inside</p>') !== false, 'p child should be promoted');
});

test('strips object/embed/applet', function() {
    $s = new Sanitizer();
    $r = $s->sanitize('<object data="evil.swf"><embed src="evil.swf"><applet code="evil.class">');
    assert_true(strpos($r, '<object') === false);
    assert_true(strpos($r, '<embed') === false);
    assert_true(strpos($r, '<applet') === false);
});

test('adds rel=noopener to target=_blank links', function() {
    $s = new Sanitizer();
    $r = $s->sanitize('<a href="https://example.com" target="_blank">link</a>');
    assert_true(strpos($r, 'noopener') !== false, 'should add rel=noopener');
});

test('strips DOM clobbering names', function() {
    $s = new Sanitizer();
    $r = $s->sanitize('<div id="cookie">test</div>');
    assert_true(strpos($r, 'id="cookie"') === false, 'clobbering id should be stripped');
    assert_true(strpos($r, 'test') !== false);
});

test('empty input returns empty', function() {
    $s = new Sanitizer();
    assert_equals('', $s->sanitize(''));
});

test('plain text passes through', function() {
    $s = new Sanitizer();
    $r = $s->sanitize('hello world');
    assert_true(strpos($r, 'hello world') !== false);
});

// ── Summary ──────────────────────────────────────────────────────────

echo "\n══════════════════════════════════════════════════\n";
echo "SafeScript DOM — PHP Parity Tests\n";
echo "══════════════════════════════════════════════════\n";
echo "  Passed:  $passed\n";
echo "  Failed:  $failed\n";
echo "══════════════════════════════════════════════════\n";

if ($failed > 0) exit(1);
