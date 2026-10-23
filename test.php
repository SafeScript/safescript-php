<?php
/**
 * SafeScript PHP test suite.
 * Covers: tokenizer, parser, validator, transform, emitter, runtime, full pipeline.
 */

// Simple autoloader
spl_autoload_register(function ($class) {
    $prefix = 'SafeScript\\';
    if (str_starts_with($class, $prefix)) {
        $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
        $file = __DIR__ . '/src/' . $relative . '.php';
        if (file_exists($file)) require_once $file;
    }
});

// Load SyntaxErrorEx first (it sets up class alias)
require_once __DIR__ . '/src/SyntaxErrorEx.php';

// Load Parser.php explicitly so the standalone parse() function is available
require_once __DIR__ . '/src/Tokenizer.php';
require_once __DIR__ . '/src/Parser.php';

use SafeScript\SafeScript;
use SafeScript\Tokenizer;
use SafeScript\Parser;
use SafeScript\Validator;
use SafeScript\ValidationError;
use SafeScript\Transform;
use SafeScript\EmitPhp;
use SafeScript\Compiler;
use SafeScript\Header;
use SafeScript\Defs;
use SafeScript\Runtime;

$passed = 0;
$failed = 0;
$errors = [];

function test(string $name, callable $fn): void
{
    global $passed, $failed, $errors;
    try {
        $fn();
        $passed++;
        echo ".";
    } catch (\Throwable $e) {
        $failed++;
        $errors[] = "$name: {$e->getMessage()} at {$e->getFile()}:{$e->getLine()}";
        echo "F";
    }
}

function eq($expected, $actual, string $msg = ''): void
{
    if ($expected !== $actual) {
        $e = var_export($expected, true);
        $a = var_export($actual, true);
        throw new \RuntimeException("Expected {$e}, got {$a}" . ($msg ? " — $msg" : ''));
    }
}

function throws(callable $fn, ?string $msgContains = null): void
{
    try {
        $fn();
        throw new \RuntimeException("Expected exception, none thrown");
    } catch (\RuntimeException $e) {
        if ($e->getMessage() === "Expected exception, none thrown") throw $e;
        if ($msgContains !== null && !str_contains($e->getMessage(), $msgContains)) {
            throw new \RuntimeException("Expected exception containing '$msgContains', got: {$e->getMessage()}");
        }
    } catch (\Throwable $e) {
        if ($msgContains !== null && !str_contains($e->getMessage(), $msgContains)) {
            throw new \RuntimeException("Expected exception containing '$msgContains', got: {$e->getMessage()}");
        }
    }
}

echo "SafeScript PHP Test Suite\n";
echo "========================\n\n";

// ═══════════════════════════════════════════════════════════════
// TOKENIZER TESTS
// ═══════════════════════════════════════════════════════════════
echo "Tokenizer: ";

test('tokenize numbers', function () {
    $t = new Tokenizer('42');
    $tokens = $t->tokenize();
    eq(Tokenizer::T_NUMBER, $tokens[0]['type']);
    eq('42', $tokens[0]['value']);
});

test('tokenize float', function () {
    $t = new Tokenizer('3.14');
    $tokens = $t->tokenize();
    eq(Tokenizer::T_NUMBER, $tokens[0]['type']);
    eq('3.14', $tokens[0]['value']);
});

test('tokenize string single', function () {
    $t = new Tokenizer("'hello'");
    $tokens = $t->tokenize();
    eq(Tokenizer::T_STRING, $tokens[0]['type']);
    eq("'hello'", $tokens[0]['value']);
});

test('tokenize string double', function () {
    $t = new Tokenizer('"world"');
    $tokens = $t->tokenize();
    eq(Tokenizer::T_STRING, $tokens[0]['type']);
    eq('"world"', $tokens[0]['value']);
});

test('tokenize identifier', function () {
    $t = new Tokenizer('foo');
    $tokens = $t->tokenize();
    eq(Tokenizer::T_IDENT, $tokens[0]['type']);
    eq('foo', $tokens[0]['value']);
});

test('tokenize keyword', function () {
    $t = new Tokenizer('var x = 1');
    $tokens = $t->tokenize();
    eq(Tokenizer::T_KEYWORD, $tokens[0]['type']);
    eq('var', $tokens[0]['value']);
    eq(Tokenizer::T_IDENT, $tokens[1]['type']);
    eq('x', $tokens[1]['value']);
});

test('tokenize punctuation', function () {
    $t = new Tokenizer('a + b');
    $tokens = $t->tokenize();
    eq(Tokenizer::T_IDENT, $tokens[0]['type']);
    eq(Tokenizer::T_PUNCT, $tokens[1]['type']);
    eq('+', $tokens[1]['value']);
});

test('tokenize multi-char punct', function () {
    $t = new Tokenizer('a === b');
    $tokens = $t->tokenize();
    eq('===', $tokens[1]['value']);
});

test('blocked identifier', function () {
    throws(function () {
        $t = new Tokenizer('eval');
        $t->tokenize();
    }, 'Blocked');
});

test('blocked keyword', function () {
    throws(function () {
        $t = new Tokenizer('new Foo()');
        $t->tokenize();
    }, 'Blocked');
});

test('tokenize comments', function () {
    $t = new Tokenizer("x // comment\ny");
    $tokens = $t->tokenize();
    eq('x', $tokens[0]['value']);
    eq('y', $tokens[1]['value']);
});

test('tokenize block comments', function () {
    $t = new Tokenizer("x /* comment */ y");
    $tokens = $t->tokenize();
    eq('x', $tokens[0]['value']);
    eq('y', $tokens[1]['value']);
});

test('tokenize regex', function () {
    $t = new Tokenizer('var r = /abc/g');
    $tokens = $t->tokenize();
    eq(Tokenizer::T_REGEX, $tokens[3]['type']);
    eq('/abc/g', $tokens[3]['value']);
});

test('tokenize hex number', function () {
    $t = new Tokenizer('0xFF');
    $tokens = $t->tokenize();
    eq('0xFF', $tokens[0]['value']);
});

test('tokenize binary number', function () {
    $t = new Tokenizer('0b1010');
    $tokens = $t->tokenize();
    eq('0b1010', $tokens[0]['value']);
});

test('tokenize template literal blocked', function () {
    throws(function () {
        $t = new Tokenizer('`hello`');
        $t->tokenize();
    });
});

test('tokenize string escapes', function () {
    $raw = "'he\\nllo'";
    $unescaped = Tokenizer::unescapeString($raw);
    eq("he\nllo", $unescaped);
});

test('tokenize arrow', function () {
    $t = new Tokenizer('(x) => x + 1');
    $tokens = $t->tokenize();
    eq('=>', $tokens[3]['value']);
});

test('tokenize optional chaining', function () {
    $t = new Tokenizer('a?.b');
    $tokens = $t->tokenize();
    eq('?.', $tokens[1]['value']);
});

test('tokenize nullish coalescing', function () {
    $t = new Tokenizer('a ?? b');
    $tokens = $t->tokenize();
    eq('??', $tokens[1]['value']);
});

echo "\n";

// ═══════════════════════════════════════════════════════════════
// PARSER TESTS
// ═══════════════════════════════════════════════════════════════
echo "Parser: ";

test('parse variable declaration', function () {
    $ast = \SafeScript\parse('var x = 42;');
    eq('Program', $ast['type']);
    eq('VariableDeclaration', $ast['body'][0]['type']);
    eq('x', $ast['body'][0]['declarations'][0]['id']['name']);
});

test('parse function declaration', function () {
    $ast = \SafeScript\parse('function foo(a, b) { return a + b; }');
    eq('FunctionDeclaration', $ast['body'][0]['type']);
    eq('foo', $ast['body'][0]['id']['name']);
    eq(2, count($ast['body'][0]['params']));
});

test('parse if statement', function () {
    $ast = \SafeScript\parse('if (x) { y; }');
    eq('IfStatement', $ast['body'][0]['type']);
});

test('parse for loop', function () {
    $ast = \SafeScript\parse('for (var i = 0; i < 10; i++) { x; }');
    eq('ForStatement', $ast['body'][0]['type']);
});

test('parse for-in', function () {
    $ast = \SafeScript\parse('for (var k in obj) { x; }');
    eq('ForInStatement', $ast['body'][0]['type']);
});

test('parse for-of', function () {
    $ast = \SafeScript\parse('for (var v of arr) { x; }');
    eq('ForOfStatement', $ast['body'][0]['type']);
});

test('parse arrow function', function () {
    $ast = \SafeScript\parse('var f = (x) => x + 1;');
    $init = $ast['body'][0]['declarations'][0]['init'];
    eq('ArrowFunctionExpression', $init['type']);
});

test('parse object literal', function () {
    $ast = \SafeScript\parse('var o = { a: 1, b: 2 };');
    $init = $ast['body'][0]['declarations'][0]['init'];
    eq('ObjectExpression', $init['type']);
    eq(2, count($init['properties']));
});

test('parse array literal', function () {
    $ast = \SafeScript\parse('var a = [1, 2, 3];');
    $init = $ast['body'][0]['declarations'][0]['init'];
    eq('ArrayExpression', $init['type']);
    eq(3, count($init['elements']));
});

test('parse try-catch', function () {
    $ast = \SafeScript\parse('try { x; } catch (e) { y; }');
    eq('TryStatement', $ast['body'][0]['type']);
});

test('parse switch', function () {
    $ast = \SafeScript\parse('switch (x) { case 1: break; default: y; }');
    eq('SwitchStatement', $ast['body'][0]['type']);
    eq(2, count($ast['body'][0]['cases']));
});

test('parse while', function () {
    $ast = \SafeScript\parse('while (x) { y; }');
    eq('WhileStatement', $ast['body'][0]['type']);
});

test('parse do-while', function () {
    $ast = \SafeScript\parse('do { y; } while (x);');
    eq('DoWhileStatement', $ast['body'][0]['type']);
});

test('parse destructuring array', function () {
    $ast = \SafeScript\parse('var [a, b] = arr;');
    $id = $ast['body'][0]['declarations'][0]['id'];
    eq('ArrayPattern', $id['type']);
});

test('parse destructuring object', function () {
    $ast = \SafeScript\parse('var {x, y} = obj;');
    $id = $ast['body'][0]['declarations'][0]['id'];
    eq('ObjectPattern', $id['type']);
});

test('parse spread', function () {
    $ast = \SafeScript\parse('var a = [...b, 1];');
    $init = $ast['body'][0]['declarations'][0]['init'];
    eq('SpreadElement', $init['elements'][0]['type']);
});

test('parse ternary', function () {
    $ast = \SafeScript\parse('var x = a ? b : c;');
    $init = $ast['body'][0]['declarations'][0]['init'];
    eq('ConditionalExpression', $init['type']);
});

test('parse typeof', function () {
    $ast = \SafeScript\parse('var t = typeof x;');
    $init = $ast['body'][0]['declarations'][0]['init'];
    eq('UnaryExpression', $init['type']);
    eq('typeof', $init['operator']);
});

test('parse method call', function () {
    $ast = \SafeScript\parse('a.b(c);');
    $expr = $ast['body'][0]['expression'];
    eq('CallExpression', $expr['type']);
    eq('MemberExpression', $expr['callee']['type']);
});

test('parse chained access', function () {
    $ast = \SafeScript\parse('a.b.c;');
    $expr = $ast['body'][0]['expression'];
    eq('MemberExpression', $expr['type']);
});

test('async keyword is blocked', function () {
    throws(function () {
        \SafeScript\parse('async function foo() { return 1; }');
    }, 'async');
});

test('await keyword is blocked', function () {
    throws(function () {
        \SafeScript\parse('var x = await bar()');
    }, 'await');
});

test('parse labeled statement', function () {
    $ast = \SafeScript\parse('outer: for (var i = 0; i < 10; i++) { break outer; }');
    eq('LabeledStatement', $ast['body'][0]['type']);
});

test('parse optional chaining', function () {
    $ast = \SafeScript\parse('var x = a?.b;');
    $init = $ast['body'][0]['declarations'][0]['init'];
    eq('MemberExpression', $init['type']);
    eq(true, $init['optional']);
});

test('parse nullish coalescing', function () {
    $ast = \SafeScript\parse('var x = a ?? b;');
    $init = $ast['body'][0]['declarations'][0]['init'];
    eq('LogicalExpression', $init['type']);
    eq('??', $init['operator']);
});

test('parse rest params', function () {
    $ast = \SafeScript\parse('function foo(a, ...rest) { return rest; }');
    eq('RestElement', $ast['body'][0]['params'][1]['type']);
});

test('parse default params', function () {
    $ast = \SafeScript\parse('function foo(a = 1) { return a; }');
    eq('AssignmentPattern', $ast['body'][0]['params'][0]['type']);
});

echo "\n";

// ═══════════════════════════════════════════════════════════════
// VALIDATOR TESTS
// ═══════════════════════════════════════════════════════════════
echo "Validator: ";

test('validate clean code', function () {
    eq(true, SafeScript::validate('var x = 1 + 2;'));
});

test('validate blocks eval in reference', function () {
    throws(function () {
        SafeScript::validate('var x = eval;');
    }, 'Blocked');
});

test('validate blocks dot access to __proto__', function () {
    throws(function () {
        SafeScript::validate('var x = obj.__proto__;');
    }, 'Blocked');
});

test('validate blocks dot access to constructor', function () {
    throws(function () {
        SafeScript::validate('var x = obj.constructor;');
    }, 'Blocked');
});

test('validate blocks binding eval', function () {
    throws(function () {
        // eval as variable name is caught by tokenizer as blocked ident
        SafeScript::validate('var eval = 1;');
    });
});

test('validate allows normal code', function () {
    eq(true, SafeScript::validate('function add(a, b) { return a + b; }'));
});

test('validate blocks this', function () {
    throws(function () {
        SafeScript::validate('var x = this;');
    }, 'Blocked');
});

test('validate blocks arguments', function () {
    throws(function () {
        SafeScript::validate('var x = arguments;');
    }, 'Blocked');
});

echo "\n";

// ═══════════════════════════════════════════════════════════════
// COMPILER & RUNTIME TESTS (full pipeline)
// ═══════════════════════════════════════════════════════════════
echo "Compile: ";

test('compile simple', function () {
    $result = Compiler::compile('var x = 1;');
    eq(true, isset($result['code']));
    eq(true, isset($result['hash']));
    eq(true, strlen($result['hash']) === 64);
});

test('compile transforms bracket access', function () {
    $result = Compiler::compile('var x = obj[key];');
    eq(true, str_contains($result['code'], '__at'));
});

test('compile transforms bracket set', function () {
    $result = Compiler::compile('obj[key] = val;');
    eq(true, str_contains($result['code'], '__set'));
});

echo "\n";
echo "Runtime (run): ";

test('run return literal', function () {
    eq(42, SafeScript::run('return 42', []));
});

test('run return string', function () {
    eq('hello', SafeScript::run("return 'hello'", []));
});

test('run arithmetic', function () {
    eq(7, SafeScript::run('return 3 + 4', []));
});

test('run scope variable', function () {
    eq(10, SafeScript::run('return x', ['x' => 10]));
});

test('run scope addition', function () {
    eq(30, SafeScript::run('return x + y', ['x' => 10, 'y' => 20]));
});

test('run var declaration', function () {
    eq(5, SafeScript::run('var x = 5; return x', []));
});

test('run multiple vars', function () {
    eq(15, SafeScript::run('var a = 5; var b = 10; return a + b', []));
});

test('run function call from scope', function () {
    eq(9, SafeScript::run('return square(3)', ['square' => fn($x) => $x * $x]));
});

test('run if true', function () {
    eq('yes', SafeScript::run("if (true) { return 'yes' } return 'no'", []));
});

test('run if false', function () {
    eq('no', SafeScript::run("if (false) { return 'yes' } return 'no'", []));
});

test('run if-else', function () {
    eq('big', SafeScript::run("var x = 10; if (x > 5) { return 'big' } else { return 'small' }", []));
});

test('run for loop', function () {
    eq(10, SafeScript::run('var sum = 0; for (var i = 0; i < 5; i++) { sum = sum + i } return sum', []));
});

test('run while loop', function () {
    eq(5, SafeScript::run('var i = 0; while (i < 5) { i = i + 1 } return i', []));
});

test('run string concatenation', function () {
    eq('hello world', SafeScript::run("return 'hello' + ' ' + 'world'", []));
});

test('run boolean operators', function () {
    eq(true, SafeScript::run('return true === true', []));
});

test('run comparison', function () {
    eq(true, SafeScript::run('return 5 > 3', []));
});

test('run null', function () {
    eq(null, SafeScript::run('return null', []));
});

test('run undefined', function () {
    eq(null, SafeScript::run('return undefined', []));
});

test('run typeof number', function () {
    eq('number', SafeScript::run('return typeof 42', []));
});

test('run typeof string', function () {
    eq('string', SafeScript::run("return typeof 'hello'", []));
});

test('run typeof boolean', function () {
    eq('boolean', SafeScript::run('return typeof true', []));
});

test('run ternary', function () {
    eq(1, SafeScript::run('return true ? 1 : 2', []));
    eq(2, SafeScript::run('return false ? 1 : 2', []));
});

test('run nested function', function () {
    eq(12, SafeScript::run('
        function multiply(a, b) { return a * b }
        return multiply(3, 4)
    ', []));
});

test('run try-catch', function () {
    eq('caught', SafeScript::run("
        try { throw 'error' } catch (e) { return 'caught' }
    ", []));
});

test('run switch', function () {
    eq('two', SafeScript::run("
        var x = 2;
        switch (x) {
            case 1: return 'one';
            case 2: return 'two';
            default: return 'other';
        }
    ", []));
});

test('run do-while', function () {
    eq(3, SafeScript::run('
        var i = 0;
        do { i = i + 1 } while (i < 3);
        return i
    ', []));
});

test('run break', function () {
    eq(3, SafeScript::run('
        var i = 0;
        while (true) {
            if (i === 3) break;
            i = i + 1;
        }
        return i
    ', []));
});

test('run continue', function () {
    eq(20, SafeScript::run('
        var sum = 0;
        for (var i = 0; i < 10; i++) {
            if (i % 2 !== 0) continue;
            sum = sum + i;
        }
        return sum
    ', []));
});

test('run void', function () {
    eq(null, SafeScript::run('return void 0', []));
});

test('run unary minus', function () {
    eq(-5, SafeScript::run('return -5', []));
});

test('run unary not', function () {
    eq(true, SafeScript::run('return !false', []));
});

test('run modulo', function () {
    eq(1, SafeScript::run('return 7 % 3', []));
});

test('run power', function () {
    eq(8, SafeScript::run('return 2 ** 3', []));
});

test('run bitwise and', function () {
    eq(0, SafeScript::run('return 5 & 2', []));
});

test('run bitwise or', function () {
    eq(7, SafeScript::run('return 5 | 2', []));
});

test('run left shift', function () {
    eq(20, SafeScript::run('return 5 << 2', []));
});

test('run right shift', function () {
    eq(2, SafeScript::run('return 10 >> 2', []));
});

test('run nullish coalescing', function () {
    eq(5, SafeScript::run('var x = null; return x ?? 5', []));
    eq(3, SafeScript::run('var x = 3; return x ?? 5', []));
});

test('run logical or (JS semantics)', function () {
    eq(5, SafeScript::run('return 0 || 5', []));
    eq(3, SafeScript::run('return 3 || 5', []));
});

test('run logical and (JS semantics)', function () {
    eq(0, SafeScript::run('return 0 && 5', []));
    eq(5, SafeScript::run('return 3 && 5', []));
});

test('run compound assignment', function () {
    eq(15, SafeScript::run('var x = 10; x += 5; return x', []));
});

test('run subtract assignment', function () {
    eq(5, SafeScript::run('var x = 10; x -= 5; return x', []));
});

test('run multiply assignment', function () {
    eq(50, SafeScript::run('var x = 10; x *= 5; return x', []));
});

test('run prefix increment', function () {
    eq(6, SafeScript::run('var x = 5; return ++x', []));
});

test('run postfix increment', function () {
    eq(5, SafeScript::run('var x = 5; return x++', []));
});

test('run bundle overload', function () {
    eq(42, SafeScript::run(['source' => 'return x', 'scope' => ['x' => 42]]));
});

test('run rejects async keyword', function () {
    throws(function () {
        SafeScript::run('async function foo() { return 42 } return foo()', []);
    }, 'async');
});

test('run rejects await keyword', function () {
    throws(function () {
        SafeScript::run('var x = await 10; return x', []);
    }, 'await');
});

echo "\n";

// ═══════════════════════════════════════════════════════════════
// SECURITY TESTS
// ═══════════════════════════════════════════════════════════════
echo "Security: ";

test('blocks eval', function () {
    throws(function () { SafeScript::run('eval("1+1")', []); });
});

test('blocks Function', function () {
    throws(function () { SafeScript::run('Function("return 1")', []); });
});

test('blocks globalThis', function () {
    throws(function () { SafeScript::run('return globalThis', []); });
});

test('blocks window', function () {
    throws(function () { SafeScript::run('return window', []); });
});

test('blocks require', function () {
    throws(function () { SafeScript::run('var x = require("fs")', []); });
});

test('blocks process', function () {
    throws(function () { SafeScript::run('return process', []); });
});

test('blocks new', function () {
    throws(function () { SafeScript::run('return new Object()', []); });
});

test('blocks class', function () {
    throws(function () { SafeScript::run('class Foo {}', []); });
});

test('blocks import', function () {
    throws(function () { SafeScript::run('import x from "y"', []); });
});

test('blocks delete', function () {
    throws(function () { SafeScript::run('delete x.y', []); });
});

test('blocks __proto__ access', function () {
    throws(function () { SafeScript::run('var x = obj.__proto__', ['obj' => []]); });
});

test('blocks constructor access', function () {
    throws(function () { SafeScript::run('var x = obj.constructor', ['obj' => []]); });
});

test('blocks template literals', function () {
    throws(function () { SafeScript::run('return `hello`', []); });
});

echo "\n";

// ═══════════════════════════════════════════════════════════════
// HEADER TESTS
// ═══════════════════════════════════════════════════════════════
echo "Header: ";

test('parse header version', function () {
    $h = Header::parseHeader("/**\n * @safescript 0.2\n */\nreturn 1");
    eq('0.2', $h['version']);
});

test('parse header needs', function () {
    $h = Header::parseHeader("/**\n * @needs db: Database\n */\nreturn 1");
    eq(1, count($h['needs']));
    eq('db', $h['needs'][0]['name']);
    eq('Database', $h['needs'][0]['type']);
});

test('parse header uses', function () {
    $h = Header::parseHeader("/**\n * @uses logger: Logger\n */\nreturn 1");
    eq(1, count($h['uses']));
    eq('logger', $h['uses'][0]['name']);
});

test('parse header env', function () {
    $h = Header::parseHeader("/**\n * @env browser, node\n */\nreturn 1");
    eq(['browser', 'node'], $h['env']);
});

test('checkScope passes', function () {
    $result = Header::checkScope("/**\n * @needs x: Num\n */\nreturn x", ['x' => 1]);
    eq(true, $result['ok']);
});

test('checkScope fails', function () {
    $result = Header::checkScope("/**\n * @needs x: Num\n */\nreturn x", []);
    eq(false, $result['ok']);
    eq(['x'], $result['missing']);
});

test('no header returns defaults', function () {
    $h = Header::parseHeader('return 1');
    eq(null, $h['version']);
    eq([], $h['needs']);
});

test('strip header', function () {
    $code = Header::stripHeader("/**\n * @safescript 0.2\n */\nreturn 1");
    eq(true, str_contains($code, 'return 1'));
    eq(false, str_contains($code, '@safescript'));
});

echo "\n";

// ═══════════════════════════════════════════════════════════════
// CAPABILITIES / API TESTS
// ═══════════════════════════════════════════════════════════════
echo "API: ";

test('capabilities returns header', function () {
    $c = SafeScript::capabilities("/**\n * @safescript 0.2\n * @needs db: Database\n */\nreturn 1");
    eq('0.2', $c['version']);
    eq(1, count($c['needs']));
});

test('checkScope API', function () {
    $r = SafeScript::checkScope("/**\n * @needs x: Num\n */\nreturn x", ['x' => 1]);
    eq(true, $r['ok']);
});

test('parse API returns AST', function () {
    $ast = SafeScript::parse('var x = 1;');
    eq('Program', $ast['type']);
});

test('validate API returns true', function () {
    eq(true, SafeScript::validate('var x = 1;'));
});

test('compile API returns code and hash', function () {
    $r = SafeScript::compile('return 1');
    eq(true, isset($r['code']));
    eq(true, isset($r['hash']));
});

test('sanitize strips blocked props', function () {
    $obj = ['a' => 1, 'constructor' => 'evil', 'b' => 2];
    $clean = SafeScript::sanitize($obj);
    eq(false, array_key_exists('constructor', $clean));
    eq(1, $clean['a']);
});

test('sanitize deep', function () {
    $obj = ['nested' => ['__proto__' => 'bad', 'ok' => true]];
    $clean = SafeScript::sanitize($obj);
    eq(false, array_key_exists('__proto__', $clean['nested']));
    eq(true, $clean['nested']['ok']);
});

test('load with resolver', function () {
    $source = "/**\n * @needs x: Num\n */\nreturn x + 1";
    $result = SafeScript::load($source, function ($name) {
        if ($name === 'x') return 10;
        return null;
    });
    eq(11, $result);
});

echo "\n";

// ═══════════════════════════════════════════════════════════════
// EXAMPLES FROM SPEC (real-world patterns)
// ═══════════════════════════════════════════════════════════════
echo "Examples: ";

test('fibonacci', function () {
    $result = SafeScript::run('
        function fib(n) {
            if (n <= 1) return n;
            return fib(n - 1) + fib(n - 2);
        }
        return fib(10)
    ', []);
    eq(55, $result);
});

test('array operations with scope fn', function () {
    $result = SafeScript::run('
        var nums = [1, 2, 3, 4, 5];
        var sum = 0;
        for (var i = 0; i < 5; i++) {
            sum = sum + nums[i];
        }
        return sum
    ', []);
    eq(15, $result);
});

test('string manipulation', function () {
    $result = SafeScript::run("
        var s = 'hello world';
        var upper = s.toUpperCase();
        return upper
    ", []);
    eq('HELLO WORLD', $result);
});

test('object creation and access', function () {
    $result = SafeScript::run("
        var person = { name: 'Alice', age: 30 };
        return person.name
    ", []);
    eq('Alice', $result);
});

test('nested function scope', function () {
    $result = SafeScript::run('
        function outer() {
            var x = 10;
            function inner() {
                return x + 5;
            }
            return inner();
        }
        return outer()
    ', []);
    eq(15, $result);
});

test('accumulator pattern', function () {
    $result = SafeScript::run('
        var total = 0;
        for (var i = 1; i <= 100; i++) {
            total = total + i;
        }
        return total
    ', []);
    eq(5050, $result);
});

test('recursive factorial', function () {
    $result = SafeScript::run('
        function factorial(n) {
            if (n <= 1) return 1;
            return n * factorial(n - 1);
        }
        return factorial(10)
    ', []);
    eq(3628800, $result);
});

test('scope injection with callback', function () {
    $result = SafeScript::run('
        var result = transform(5);
        return result
    ', ['transform' => fn($x) => $x * 2 + 1]);
    eq(11, $result);
});

test('error propagation', function () {
    throws(function () {
        SafeScript::run("throw 'custom error'", []);
    }, 'custom error');
});

test('try-catch-finally', function () {
    $result = SafeScript::run("
        var log = '';
        try {
            log = log + 'try ';
            throw 'err';
        } catch (e) {
            log = log + 'catch ';
        } finally {
            log = log + 'finally';
        }
        return log
    ", []);
    eq('try catch finally', $result);
});

echo "\n";

// ═══════════════════════════════════════════════════════════════
// GUARDS (__check) — infinite loop / recursion protection
// ═══════════════════════════════════════════════════════════════
echo "\nGuards: ";

test('__check injected in while loop — prevents infinite loop', function () {
    throws(function () {
        SafeScript::run('
            var i = 0
            while (true) { i = i + 1 }
            return i
        ', [], ['maxOps' => 1000]);
    }, 'operation limit exceeded');
});

test('__check injected in for loop — prevents infinite loop', function () {
    throws(function () {
        SafeScript::run('
            for (var i = 0; ; i = i + 1) { }
            return i
        ', [], ['maxOps' => 1000]);
    }, 'operation limit exceeded');
});

test('__check injected in do-while — prevents infinite loop', function () {
    throws(function () {
        SafeScript::run('
            do { } while (true)
        ', [], ['maxOps' => 1000]);
    }, 'operation limit exceeded');
});

test('__check in function body — prevents infinite recursion', function () {
    throws(function () {
        SafeScript::run('
            function recurse() { return recurse() }
            return recurse()
        ', [], ['maxOps' => 1000]);
    }, 'operation limit exceeded');
});

test('__check in arrow function — prevents infinite recursion', function () {
    throws(function () {
        SafeScript::run('
            var recurse = () => recurse()
            return recurse()
        ', [], ['maxOps' => 1000]);
    }, 'operation limit exceeded');
});

test('__check timeout — prevents long-running script', function () {
    throws(function () {
        SafeScript::run('
            while (true) { }
        ', [], ['timeout' => 0.1, 'maxOps' => 999999999]);
    }, 'timeout exceeded');
});

test('maxOps ceiling enforced — 0 gets capped to MAX_OPS', function () {
    // A simple script should NOT hit the default ceiling
    $result = SafeScript::run('return 42', []);
    eq(42, $result);
});

test('normal script completes within default limits', function () {
    $result = SafeScript::run('
        var sum = 0
        for (var i = 0; i < 100; i = i + 1) {
            sum = sum + i
        }
        return sum
    ', []);
    eq(4950, $result);
});

test('for-in loop has __check', function () {
    throws(function () {
        // This will loop over keys of obj, but with maxOps=5 even a small loop triggers
        SafeScript::run('
            function looper(obj) {
                var result = []
                for (var k in obj) {
                    result.push(k)
                }
                return result
            }
            // Recurse to exhaust ops
            function evil() { return evil() }
            return evil()
        ', [], ['maxOps' => 50]);
    }, 'operation limit exceeded');
});

echo "\n";

// ═══════════════════════════════════════════════════════════════
// HOOKS — beforeCall / afterCall interceptors
// ═══════════════════════════════════════════════════════════════
echo "\nHooks: ";

test('hooks beforeCall — intercept scope function calls', function () {
    $log = [];
    $result = SafeScript::run('
        return greet("world")
    ', [
        'greet' => function ($name) { return "hello $name"; },
    ], [
        'hooks' => [
            'beforeCall' => function ($name, $args) use (&$log) {
                $log[] = "before:$name:" . implode(',', $args);
                return null; // proceed normally
            },
        ],
    ]);
    eq('hello world', $result);
    eq(['before:greet:world'], $log);
});

test('hooks beforeCall — block call (return false)', function () {
    $result = SafeScript::run('
        return dangerous("attack")
    ', [
        'dangerous' => function ($x) { return "EXECUTED: $x"; },
    ], [
        'hooks' => [
            'beforeCall' => function ($name, $args) {
                if ($name === 'dangerous') return false; // block
                return null;
            },
        ],
    ]);
    eq(null, $result);
});

test('hooks beforeCall — short-circuit with value', function () {
    $result = SafeScript::run('
        return fetchData("key1")
    ', [
        'fetchData' => function ($key) { return "real:$key"; },
    ], [
        'hooks' => [
            'beforeCall' => function ($name, $args) {
                if ($name === 'fetchData') return ['value' => 'cached:' . $args[0]];
                return null;
            },
        ],
    ]);
    eq('cached:key1', $result);
});

test('hooks beforeCall — transform args', function () {
    $result = SafeScript::run('
        return add(1, 2)
    ', [
        'add' => function ($a, $b) { return $a + $b; },
    ], [
        'hooks' => [
            'beforeCall' => function ($name, $args) {
                // Double all args
                return ['args' => array_map(fn($a) => $a * 2, $args)];
            },
        ],
    ]);
    eq(6, $result); // (1*2) + (2*2) = 6
});

test('hooks afterCall — inspect and replace result', function () {
    $result = SafeScript::run('
        return compute(5)
    ', [
        'compute' => function ($x) { return $x * $x; },
    ], [
        'hooks' => [
            'afterCall' => function ($name, $result, $args) {
                // Cap result at 20
                if ($result > 20) return ['value' => 20];
                return null;
            },
        ],
    ]);
    eq(20, $result); // 5*5=25, capped to 20
});

test('hooks afterCall — block result', function () {
    $result = SafeScript::run('
        return getData()
    ', [
        'getData' => function () { return ['secret' => 'password']; },
    ], [
        'hooks' => [
            'afterCall' => function ($name, $result, $args) {
                return false; // block
            },
        ],
    ]);
    eq(null, $result);
});

test('hooks — both beforeCall and afterCall together', function () {
    $log = [];
    $result = SafeScript::run('
        return transform("input")
    ', [
        'transform' => function ($x) { return strtoupper($x); },
    ], [
        'hooks' => [
            'beforeCall' => function ($name, $args) use (&$log) {
                $log[] = "before:$name";
                return null;
            },
            'afterCall' => function ($name, $result, $args) use (&$log) {
                $log[] = "after:$name:$result";
                return null;
            },
        ],
    ]);
    eq('INPUT', $result);
    eq(['before:transform', 'after:transform:INPUT'], $log);
});

test('hooks — non-callable scope values are not wrapped', function () {
    $result = SafeScript::run('
        return x + y
    ', [
        'x' => 10,
        'y' => 20,
    ], [
        'hooks' => [
            'beforeCall' => function ($name, $args) {
                throw new \RuntimeException("Should not be called for non-functions");
            },
        ],
    ]);
    eq(30, $result);
});

test('hooks — resource throttling use case', function () {
    $callCount = 0;
    $maxCalls = 3;
    throws(function () use (&$callCount, $maxCalls) {
        SafeScript::run('
            var results = []
            for (var i = 0; i < 10; i = i + 1) {
                results.push(loadData(i))
            }
            return results
        ', [
            'loadData' => function ($id) { return "data:$id"; },
        ], [
            'hooks' => [
                'beforeCall' => function ($name, $args) use (&$callCount, $maxCalls) {
                    if ($name === 'loadData') {
                        $callCount++;
                        if ($callCount > $maxCalls) {
                            throw new \RuntimeException('SafeScript: resource limit — too many loadData calls');
                        }
                    }
                    return null;
                },
            ],
        ]);
    }, 'too many loadData calls');
    eq(4, $callCount); // 3 succeeded + 1 that threw
});

echo "\n";

// ═══════════════════════════════════════════════════════════════
// SUMMARY
// ═══════════════════════════════════════════════════════════════
echo "\n";
echo "════════════════════════════════\n";
echo "Results: {$passed} passed, {$failed} failed\n";
echo "════════════════════════════════\n";

if ($failed > 0) {
    echo "\nFailed tests:\n";
    foreach ($errors as $err) {
        echo "  ✗ {$err}\n";
    }
    exit(1);
}

echo "\nAll tests passed! ✓\n";
