<?php
/**
 * SafeScript PHP v1.3 — SafeFiles and SafeHttp provider tests.
 * Tests the actual sandboxed wrappers, not just the header plumbing.
 */

require_once __DIR__ . '/src/SafeFiles.php';
require_once __DIR__ . '/src/SafeHttp.php';
require_once __DIR__ . '/src/SafeScript.php';

// Load all dependencies
foreach (glob(__DIR__ . '/src/*.php') as $f) {
    require_once $f;
}

use SafeScript\SafeFiles;
use SafeScript\SafeHttp;
use SafeScript\SafeScript;

$passed = 0;
$failed = 0;

function assertTest(bool $cond, string $msg): void {
    global $passed, $failed;
    if ($cond) { $passed++; echo '.'; }
    else { $failed++; echo "\nFAIL: $msg"; }
}

function assertThrows(callable $fn, string $expectedMsg, string $label): void {
    global $passed, $failed;
    try {
        $fn();
        $failed++;
        echo "\nFAIL (no throw): $label";
    } catch (\Throwable $e) {
        if (str_contains($e->getMessage(), $expectedMsg)) {
            $passed++;
            echo '.';
        } else {
            $failed++;
            echo "\nFAIL: $label — got: " . $e->getMessage();
        }
    }
}

echo "\nSafeFiles & SafeHttp Provider Tests\n";
echo str_repeat('=', 44) . "\n";

// ── Setup: temp sandbox directory ──
$sandbox = sys_get_temp_dir() . '/safescript_test_' . getmypid();
if (is_dir($sandbox)) {
    // Clean up from a previous run
    array_map('unlink', glob("$sandbox/*"));
    array_map('unlink', glob("$sandbox/sub/*"));
    @rmdir("$sandbox/sub");
    rmdir($sandbox);
}
mkdir($sandbox, 0755, true);

// ══════════════════════════════════════════
// SafeFiles Tests
// ══════════════════════════════════════════
echo "\n-- SafeFiles --\n";

$fs = new SafeFiles($sandbox);

// -- writeFile + readFile --
$written = $fs->writeFile('hello.txt', 'Hello, SafeScript!');
assertTest($written === 18, 'writeFile returns byte count');
$content = $fs->readFile('hello.txt');
assertTest($content === 'Hello, SafeScript!', 'readFile returns content');

// -- exists --
assertTest($fs->exists('hello.txt') === true, 'exists returns true for file');
assertTest($fs->exists('nope.txt') === false, 'exists returns false for missing');

// -- appendFile --
$fs->appendFile('hello.txt', ' More.');
$content2 = $fs->readFile('hello.txt');
assertTest($content2 === 'Hello, SafeScript! More.', 'appendFile appends data');

// -- fileSize --
$size = $fs->fileSize('hello.txt');
assertTest($size === strlen('Hello, SafeScript! More.'), 'fileSize returns correct length');
assertTest($fs->fileSize('nope.txt') === null, 'fileSize returns null for missing');

// -- deleteFile --
$fs->writeFile('temp.txt', 'delete me');
assertTest($fs->deleteFile('temp.txt') === true, 'deleteFile returns true');
assertTest($fs->exists('temp.txt') === false, 'deleted file is gone');
assertTest($fs->deleteFile('temp.txt') === false, 'deleteFile returns false when missing');

// -- readFile missing --
assertTest($fs->readFile('nope.txt') === null, 'readFile returns null for missing');

// -- listFiles --
$fs->writeFile('a.txt', 'aaa');
$fs->writeFile('b.txt', 'bbb');
$list = $fs->listFiles('*.txt');
assertTest(in_array('a.txt', $list), 'listFiles finds a.txt');
assertTest(in_array('b.txt', $list), 'listFiles finds b.txt');
assertTest(in_array('hello.txt', $list), 'listFiles finds hello.txt');

// -- makeDir + isDir --
$fs->makeDir('sub');
assertTest($fs->isDir('sub') === true, 'makeDir creates directory');
assertTest($fs->isDir('hello.txt') === false, 'isDir false for file');

// -- readDir --
$entries = $fs->readDir('.');
assertTest(in_array('hello.txt', $entries), 'readDir lists files');
assertTest(in_array('sub', $entries), 'readDir lists dirs');
assertTest(!in_array('.', $entries), 'readDir excludes .');
assertTest(!in_array('..', $entries), 'readDir excludes ..');

// -- write into subdir --
$fs->writeFile('sub/nested.txt', 'nested content');
assertTest($fs->readFile('sub/nested.txt') === 'nested content', 'write/read in subdir');

// -- listFiles with subdir pattern --
$subList = $fs->listFiles('sub/*');
assertTest(in_array('sub/nested.txt', $subList), 'listFiles with subdir pattern');

// -- path traversal blocked --
assertThrows(
    fn() => $fs->readFile('../etc/passwd'),
    "'..' not allowed",
    'readFile blocks ..'
);
assertThrows(
    fn() => $fs->writeFile('../../escape.txt', 'bad'),
    "'..' not allowed",
    'writeFile blocks ..'
);
assertThrows(
    fn() => $fs->listFiles('../*'),
    "'..' not allowed",
    'listFiles blocks ..'
);

// -- size limits --
$smallFs = new SafeFiles($sandbox, ['maxFileSize' => 100, 'maxTotalWrite' => 300]);
$smallFs->writeFile('small.txt', str_repeat('x', 50));
assertTest($smallFs->readFile('small.txt') === str_repeat('x', 50), 'small write OK');

assertThrows(
    fn() => $smallFs->writeFile('big.txt', str_repeat('x', 200)),
    'data too large',
    'writeFile rejects oversized data'
);

// -- total write budget --
$budgetFs = new SafeFiles($sandbox, ['maxFileSize' => 1000, 'maxTotalWrite' => 100]);
$budgetFs->writeFile('w1.txt', str_repeat('a', 60));
assertThrows(
    fn() => $budgetFs->writeFile('w2.txt', str_repeat('b', 60)),
    'total write budget exceeded',
    'total write limit enforced'
);

// -- readFile respects size limit --
// hello.txt is 24 bytes; set limit to 10
$tinyFs = new SafeFiles($sandbox, ['maxFileSize' => 10]);
assertThrows(
    fn() => $tinyFs->readFile('hello.txt'),
    'file too large',
    'readFile rejects oversized file'
);

// -- appendFile respects combined size limit --
$appendFs = new SafeFiles($sandbox, ['maxFileSize' => 30]);
$appendFs->writeFile('app.txt', str_repeat('z', 25));
assertThrows(
    fn() => $appendFs->appendFile('app.txt', str_repeat('z', 10)),
    'file would exceed size limit',
    'appendFile rejects if combined too large'
);

// -- constructor rejects missing root --
assertThrows(
    fn() => new SafeFiles('/nonexistent/path/that/does/not/exist'),
    'root folder does not exist',
    'constructor rejects bad root'
);

// ══════════════════════════════════════════
// SafeFiles via SafeScript (integration)
// ══════════════════════════════════════════
echo "\n-- SafeFiles + SafeScript integration --\n";

// Register SafeFiles as a type
SafeScript::lib('SafeFiles', SafeFiles::class, [
    'allowMethods' => ['readFile', 'writeFile', 'appendFile', 'exists', 'deleteFile',
                       'listFiles', 'isDir', 'makeDir', 'readDir', 'fileSize'],
]);

$fsInstance = new SafeFiles($sandbox);
$result = SafeScript::run(
    "/**\n * @uses fs: SafeFiles\n */\nreturn fs.writeFile('from_ss.txt', 'via SafeScript')",
    ['fs' => $fsInstance]
);
assertTest($result === 14, 'SafeScript writes via SafeFiles');
assertTest($fsInstance->readFile('from_ss.txt') === 'via SafeScript', 'file content correct');

$result2 = SafeScript::run(
    "/**\n * @uses fs: SafeFiles\n */\nreturn fs.readFile('from_ss.txt')",
    ['fs' => $fsInstance]
);
assertTest($result2 === 'via SafeScript', 'SafeScript reads via SafeFiles');

$result3 = SafeScript::run(
    "/**\n * @uses fs: SafeFiles\n */\nreturn fs.exists('from_ss.txt')",
    ['fs' => $fsInstance]
);
assertTest($result3 === true, 'SafeScript checks exists via SafeFiles');

// Traversal from SafeScript
$caught = false;
try {
    SafeScript::run(
        "/**\n * @uses fs: SafeFiles\n */\nreturn fs.readFile('../../../etc/passwd')",
        ['fs' => $fsInstance]
    );
} catch (\Throwable $e) {
    if (str_contains($e->getMessage(), "'..' not allowed")) {
        $caught = true;
    }
}
assertTest($caught, 'SafeScript traversal attempt blocked');

// ══════════════════════════════════════════
// SafeHttp Tests
// ══════════════════════════════════════════
echo "\n-- SafeHttp --\n";

$http = new SafeHttp(['httpbin.org', 'example.com']);

// -- domain validation --
assertThrows(
    fn() => $http->get('https://evil.com/steal'),
    'not in the allowed list',
    'blocks disallowed domain'
);

assertThrows(
    fn() => $http->get('ftp://httpbin.org/anything'),
    'only http/https allowed',
    'blocks non-http scheme'
);

assertThrows(
    fn() => $http->get('not-a-url'),
    'invalid URL',
    'blocks invalid URL'
);

// -- subdomain matching --
assertThrows(
    fn() => $http->get('https://nothttpbin.org/anything'),
    'not in the allowed list',
    'blocks domain that ends with allowed but is not subdomain'
);

// -- constructor rejects empty list --
assertThrows(
    fn() => new SafeHttp([]),
    'at least one allowed domain',
    'constructor rejects empty domain list'
);

// -- SafeHttp type registration --
SafeScript::lib('SafeHttp', SafeHttp::class, [
    'allowMethods' => ['get', 'post', 'put', 'delete', 'postJson', 'getJson'],
]);

// -- SafeScript blocks disallowed domain --
$httpInstance = new SafeHttp(['example.com']);
$caught2 = false;
try {
    SafeScript::run(
        "/**\n * @uses api: SafeHttp\n */\nreturn api.get('https://evil.com/')",
        ['api' => $httpInstance]
    );
} catch (\Throwable $e) {
    if (str_contains($e->getMessage(), 'not in the allowed list')) {
        $caught2 = true;
    }
}
assertTest($caught2, 'SafeScript HTTP domain restriction works');

// -- Real HTTP test (example.com returns HTML) --
// Only run if network is available
$networkOk = false;
try {
    $resp = $http->get('https://example.com/');
    $networkOk = true;
    assertTest($resp['status'] === 200, 'HTTP GET returns 200');
    assertTest(str_contains($resp['body'], 'Example Domain'), 'HTTP GET body has expected content');
    assertTest(is_array($resp['headers']), 'HTTP GET returns headers array');
} catch (\Throwable $e) {
    // Network unavailable — skip but don't fail
    echo "\n  [SKIP: network unavailable — " . $e->getMessage() . "]";
}

if ($networkOk) {
    // Test via SafeScript too
    $httpEx = new SafeHttp(['example.com']);
    $statusFromSS = SafeScript::run(
        "/**\n * @uses api: SafeHttp\n */\nlet r = api.get('https://example.com/'); return r.status",
        ['api' => $httpEx]
    );
    assertTest($statusFromSS === 200, 'SafeScript HTTP GET via SafeHttp returns 200');
}

// ══════════════════════════════════════════
// Cleanup
// ══════════════════════════════════════════
// Remove test files
foreach (glob("$sandbox/sub/*") as $f) @unlink($f);
@rmdir("$sandbox/sub");
foreach (glob("$sandbox/*") as $f) @unlink($f);
@rmdir($sandbox);

// Unregister types
SafeScript::unlib('SafeFiles');
SafeScript::unlib('SafeHttp');

echo "\n\n";
echo "SafeFiles & SafeHttp Tests: $passed passed, $failed failed out of " . ($passed + $failed) . "\n";
if ($failed === 0) echo "All tests passed!\n";
exit($failed > 0 ? 1 : 0);
