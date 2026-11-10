<?php
/**
 * SafeScript PHP v1.3 — @uses directive and type tests.
 * Tests SafeFiles and SafeHttp built-in types.
 */

require_once __DIR__ . '/src/Header.php';
require_once __DIR__ . '/src/SafeScript.php';

// Load all dependencies
foreach (glob(__DIR__ . '/src/*.php') as $f) {
    require_once $f;
}

use SafeScript\SafeScript;
use SafeScript\Header;

$passed = 0;
$failed = 0;

function assertTest(bool $cond, string $msg): void {
    global $passed, $failed;
    if ($cond) { $passed++; echo '.'; }
    else { $failed++; echo "\nFAIL: $msg"; }
}

echo "\n@uses Directive & Type Tests (PHP)\n";
echo str_repeat('=', 44) . "\n";

// -- TYPES constant --
assertTest(SafeScript::TYPES['SafeFiles'] === 'SafeFiles', 'TYPES SafeFiles');
assertTest(SafeScript::TYPES['SafeHttp'] === 'SafeHttp', 'TYPES SafeHttp');
assertTest(!isset(SafeScript::TYPES['SafeDOMDocument']), 'TYPES SafeDOMDocument removed');
assertTest(!isset(SafeScript::TYPES['SafeHTMLElement']), 'TYPES SafeHTMLElement removed');
assertTest(!isset(SafeScript::TYPES['SafeDocument']), 'TYPES SafeDocument legacy removed');
assertTest(!isset(SafeScript::TYPES['SafeHandle']), 'TYPES SafeHandle legacy removed');

// -- BUILTIN_TYPES registry (Header class) --
assertTest(Header::BUILTIN_TYPES['SafeFiles']['provider'] === 'files', 'BUILTIN_TYPES SafeFiles provider');
assertTest(Header::BUILTIN_TYPES['SafeFiles']['scopeKey'] === 'files', 'BUILTIN_TYPES SafeFiles scopeKey');
assertTest(Header::BUILTIN_TYPES['SafeHttp']['provider'] === 'http', 'BUILTIN_TYPES SafeHttp provider');
assertTest(Header::BUILTIN_TYPES['SafeHttp']['scopeKey'] === 'http', 'BUILTIN_TYPES SafeHttp scopeKey');

// -- No browser types in PHP --
assertTest(!isset(Header::BUILTIN_TYPES['SafeDOMDocument']), 'no SafeDOMDocument in PHP');
assertTest(!isset(Header::BUILTIN_TYPES['SafeHTMLElement']), 'no SafeHTMLElement in PHP');
assertTest(!isset(Header::BUILTIN_TYPES['SafeWindow']), 'no SafeWindow in PHP');
assertTest(!isset(Header::BUILTIN_TYPES['SafeConsole']), 'no SafeConsole in PHP');
assertTest(!isset(Header::BUILTIN_TYPES['SafeObservers']), 'no SafeObservers in PHP');
assertTest(!isset(Header::BUILTIN_TYPES['SafeCanvas']), 'no SafeCanvas in PHP');
assertTest(!isset(Header::BUILTIN_TYPES['SafeMedia']), 'no SafeMedia in PHP');
assertTest(!isset(Header::BUILTIN_TYPES['SafeNavigation']), 'no SafeNavigation in PHP');

// -- Header parsing of @uses --
$src1 = "/**\n * @safescript 1.3\n * @uses fs: SafeFiles\n */\nlet x = 1";
$caps1 = SafeScript::capabilities($src1);
assertTest(count($caps1['uses']) === 1, '@uses parsed one entry');
assertTest($caps1['uses'][0]['name'] === 'fs', '@uses name is "fs"');
assertTest($caps1['uses'][0]['type'] === 'SafeFiles', '@uses type is "SafeFiles"');

// -- resolveUses with SafeFiles --
$resolved1 = SafeScript::resolveUses($caps1);
assertTest($resolved1['files'] === true, 'resolveUses sets files=true for SafeFiles');
assertTest($resolved1['http'] === false, 'resolveUses keeps http=false when only SafeFiles');
assertTest(count($resolved1['usesEntries']) === 1, 'resolveUses returns one entry');
assertTest($resolved1['usesEntries'][0]['builtin']['provider'] === 'files', 'builtin provider is files');
assertTest($resolved1['usesEntries'][0]['builtin']['scopeKey'] === 'files', 'builtin scopeKey is files');

// -- resolveUses with SafeHttp --
$src2 = "/**\n * @uses api: SafeHttp\n */\nlet x = 1";
$caps2 = SafeScript::capabilities($src2);
$resolved2 = SafeScript::resolveUses($caps2);
assertTest($resolved2['http'] === true, 'resolveUses sets http=true for SafeHttp');
assertTest($resolved2['files'] === false, 'resolveUses keeps files=false when only SafeHttp');
assertTest(count($resolved2['usesEntries']) === 1, 'SafeHttp one usesEntry');
assertTest($resolved2['usesEntries'][0]['builtin']['scopeKey'] === 'http', 'SafeHttp entry scopeKey');

// -- Multiple @uses: both SafeFiles and SafeHttp --
$src3 = "/**\n * @uses fs: SafeFiles\n * @uses api: SafeHttp\n */\nlet x = 1";
$caps3 = SafeScript::capabilities($src3);
$resolved3 = SafeScript::resolveUses($caps3);
assertTest($resolved3['files'] === true, 'multi: files=true');
assertTest($resolved3['http'] === true, 'multi: http=true');
assertTest(count($resolved3['usesEntries']) === 2, 'multi: 2 usesEntries');

// -- No @uses -> nothing activated --
$src4 = "/**\n * @safescript 1.3\n */\nlet x = 1";
$caps4 = SafeScript::capabilities($src4);
$resolved4 = SafeScript::resolveUses($caps4);
assertTest($resolved4['files'] === false, 'no @uses: files=false');
assertTest($resolved4['http'] === false, 'no @uses: http=false');
assertTest(count($resolved4['usesEntries']) === 0, 'no @uses: empty entries');

// -- Unknown @uses type -> not in built-in registry --
$src5 = "/**\n * @uses db: Database\n */\nlet x = 1";
$caps5 = SafeScript::capabilities($src5);
$resolved5 = SafeScript::resolveUses($caps5);
assertTest($resolved5['files'] === false, 'unknown type does not activate files');
assertTest($resolved5['http'] === false, 'unknown type does not activate http');
assertTest(count($resolved5['usesEntries']) === 0, 'unknown type not in usesEntries');

// -- load() returns bundle with header --
$bundle = SafeScript::load($src1);
assertTest(is_array($bundle), 'load() returns array bundle');
assertTest(isset($bundle['header']), 'load() bundle has header');
assertTest(isset($bundle['source']), 'load() bundle has source');
assertTest(isset($bundle['scope']), 'load() bundle has scope');
assertTest(count($bundle['header']['uses']) === 1, 'bundle header has uses');
assertTest($bundle['header']['uses'][0]['type'] === 'SafeFiles', 'bundle header uses type');

// -- run() still works with basic code --
$result = SafeScript::run('return 42');
assertTest($result === 42, 'basic run still works');

// -- run(bundle) works --
$bundle2 = SafeScript::load("/**\n * @safescript 1.3\n */\nreturn 99");
$result2 = SafeScript::run($bundle2);
assertTest($result2 === 99, 'run(bundle) works with header');

// -- run() parses header from source --
$result3 = SafeScript::run("/**\n * @safescript 1.3\n */\nreturn 77");
assertTest($result3 === 77, 'run() with header in source works');

echo "\n\n";
echo "@uses Directive Tests (PHP): $passed passed, $failed failed out of " . ($passed + $failed) . "\n";
if ($failed === 0) echo "All tests passed!\n";
exit($failed > 0 ? 1 : 0);
