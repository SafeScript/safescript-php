<?php
/**
 * Tests for SafeScript PHP @type header declarations.
 * Tests header parsing, three-arg lib(), and load()-time validation.
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
require_once __DIR__ . '/src/SyntaxErrorEx.php';

use SafeScript\SafeScript;
use SafeScript\Header;

$passed = 0;
$failed = 0;
$total = 0;

function test(string $name, callable $fn): void {
    global $passed, $failed, $total;
    $total++;
    try {
        $fn();
        $passed++;
        echo '.';
    } catch (\Throwable $e) {
        $failed++;
        echo "\nFAIL: $name\n  {$e->getMessage()}\n  {$e->getFile()}:{$e->getLine()}\n";
    }
}

function assertEq($actual, $expected, string $msg = ''): void {
    if ($actual !== $expected) {
        throw new \RuntimeException(
            ($msg ? "$msg: " : '') . "expected " . var_export($expected, true) .
            ", got " . var_export($actual, true)
        );
    }
}

function assertThrows(callable $fn, string $needle = ''): void {
    try {
        $fn();
        throw new \RuntimeException('Expected exception, got none');
    } catch (\RuntimeException $e) {
        if ($needle !== '' && strpos($e->getMessage(), $needle) === false) {
            throw new \RuntimeException(
                "Expected error containing '$needle', got: " . $e->getMessage()
            );
        }
    }
}

// ── Test host classes ──

class TypeHeaderCounter {
    public int $_count;
    public function __construct(int $n = 0) { $this->_count = $n; }
    public function increment(): self { $this->_count++; return $this; }
    public function decrement(): self { $this->_count--; return $this; }
    public function value(): int { return $this->_count; }
    public function reset(): self { $this->_count = 0; return $this; }
    public function __toString(): string { return "Counter({$this->_count})"; }
}

class TypeHeaderStream {
    private array $_data = [];
    public function get(string $key): mixed { return $this->_data[$key] ?? null; }
    public function set(string $key, mixed $val): self { $this->_data[$key] = $val; return $this; }
    public function fields(): array { return array_keys($this->_data); }
    public function clear(): self { $this->_data = []; return $this; }
}

// Clean slate
SafeScript::clearTypeRegistry();

echo "@type Header (PHP):\n";

// ── Header parsing tests ──

test('parseHeader extracts @type declarations', function() {
    $src = '/**
     * @safescript 1.0
     * @type Response { json, text, status }
     */
    return 1';
    $h = Header::parseHeader($src);
    assertEq(count($h['types']), 1);
    assertEq($h['types'][0]['name'], 'Response');
    assertEq($h['types'][0]['methods'], ['json', 'text', 'status']);
});

test('parseHeader with multiple @type lines', function() {
    $src = '/**
     * @safescript 1.0
     * @type Response { json, text }
     * @type Stream { get, set, fields }
     */
    return 1';
    $h = Header::parseHeader($src);
    assertEq(count($h['types']), 2);
    assertEq($h['types'][0]['name'], 'Response');
    assertEq($h['types'][0]['methods'], ['json', 'text']);
    assertEq($h['types'][1]['name'], 'Stream');
    assertEq($h['types'][1]['methods'], ['get', 'set', 'fields']);
});

test('parseHeader with no @type — types array empty', function() {
    $src = '/**
     * @safescript 1.0
     * @needs fetch: function
     */
    return 1';
    $h = Header::parseHeader($src);
    assertEq($h['types'], []);
});

test('@type with single method', function() {
    $src = '/**
     * @type Counter { value }
     */
    return 1';
    $h = Header::parseHeader($src);
    assertEq(count($h['types']), 1);
    assertEq($h['types'][0]['methods'], ['value']);
});

test('@type coexists with @needs and @uses', function() {
    $src = '/**
     * @safescript 1.0
     * @needs fetch: function
     * @uses log: function
     * @type Response { json, text }
     */
    return 1';
    $h = Header::parseHeader($src);
    assertEq(count($h['needs']), 1);
    assertEq(count($h['uses']), 1);
    assertEq(count($h['types']), 1);
    assertEq($h['needs'][0]['name'], 'fetch');
    assertEq($h['types'][0]['name'], 'Response');
});

test('capabilities() includes types array', function() {
    $src = '/**
     * @safescript 1.0
     * @type Counter { value, increment }
     */
    return 1';
    $caps = SafeScript::capabilities($src);
    assertEq(count($caps['types']), 1);
    assertEq($caps['types'][0]['name'], 'Counter');
    assertEq($caps['types'][0]['methods'], ['value', 'increment']);
});

// ── Three-arg lib() tests ──

test('three-arg lib() registers both name and class', function() {
    SafeScript::clearTypeRegistry();
    SafeScript::lib('Counter', TypeHeaderCounter::class, ['allowMethods' => ['value', 'increment']]);
    // Verify it works by running code
    $c = new TypeHeaderCounter(10);
    $result = SafeScript::run('return c.value()', ['c' => $c]);
    assertEq($result, 10);
});

test('three-arg lib() — blocked method still throws', function() {
    assertThrows(function() {
        SafeScript::run('return c.reset()', ['c' => new TypeHeaderCounter(5)]);
    }, 'reset');
});

test('three-arg lib() with non-existent class throws', function() {
    assertThrows(function() {
        SafeScript::lib('Foo', 'NonExistentClass99999', ['allowMethods' => ['bar']]);
    }, 'does not exist');
});

// ── load() @type validation tests ──

test('load() with registered @type succeeds', function() {
    SafeScript::clearTypeRegistry();
    SafeScript::lib('Counter', TypeHeaderCounter::class, ['allowMethods' => ['value', 'increment', 'toString']]);

    $src = '/**
     * @safescript 1.0
     * @needs c: object
     * @type Counter { value, increment }
     */
    c.increment()
    return c.value()';

    $result = SafeScript::load($src, function($name) {
        if ($name === 'c') return new TypeHeaderCounter(5);
        return null;
    });
    assertEq($result, 6);
});

test('load() with unregistered @type name throws', function() {
    SafeScript::clearTypeRegistry();
    $src = '/**
     * @safescript 1.0
     * @type UnknownType { foo, bar }
     */
    return 1';

    assertThrows(function() use ($src) {
        SafeScript::load($src);
    }, 'not registered');
});

test('load() with @type declaring method not in host allowMethods throws', function() {
    SafeScript::clearTypeRegistry();
    SafeScript::lib('Counter', TypeHeaderCounter::class, ['allowMethods' => ['value', 'increment']]);

    $src = '/**
     * @safescript 1.0
     * @type Counter { value, reset }
     */
    return 1';

    assertThrows(function() use ($src) {
        SafeScript::load($src);
    }, 'reset');
});

test('load() with @type — all declared methods allowed passes', function() {
    SafeScript::clearTypeRegistry();
    SafeScript::lib('Counter', TypeHeaderCounter::class, ['allowMethods' => ['value', 'increment', 'decrement', 'toString']]);

    $src = '/**
     * @safescript 1.0
     * @type Counter { value, increment, decrement }
     */
    return 1';

    // Should not throw
    $result = SafeScript::load($src);
    assertEq($result, 1);
});

// ── Two-arg lib() still works for direct class registration (backward compat) ──

test('two-arg lib() still works for direct class registration', function() {
    SafeScript::clearTypeRegistry();
    SafeScript::lib(TypeHeaderCounter::class, ['allowMethods' => ['value', 'increment']]);

    $c = new TypeHeaderCounter(42);
    $result = SafeScript::run('return c.value()', ['c' => $c]);
    assertEq($result, 42);
});

test('load() validates @type against direct class registration too', function() {
    SafeScript::clearTypeRegistry();
    // Register directly by class name
    SafeScript::lib(TypeHeaderCounter::class, ['allowMethods' => ['value', 'increment']]);

    $src = '/**
     * @safescript 1.0
     * @type TypeHeaderCounter { value }
     */
    return 1';

    // Should succeed — TypeHeaderCounter is in type registry directly
    $result = SafeScript::load($src);
    assertEq($result, 1);
});

// ── Multiple @type declarations in load() ──

test('load() validates multiple @type declarations', function() {
    SafeScript::clearTypeRegistry();
    SafeScript::lib('Counter', TypeHeaderCounter::class, ['allowMethods' => ['value', 'increment']]);
    SafeScript::lib('Stream', TypeHeaderStream::class, ['allowMethods' => ['get', 'set', 'fields']]);

    $src = '/**
     * @safescript 1.0
     * @type Counter { value }
     * @type Stream { get, set }
     */
    return 1';

    $result = SafeScript::load($src);
    assertEq($result, 1);
});

test('load() fails if second @type has disallowed method', function() {
    SafeScript::clearTypeRegistry();
    SafeScript::lib('Counter', TypeHeaderCounter::class, ['allowMethods' => ['value', 'increment']]);
    SafeScript::lib('Stream', TypeHeaderStream::class, ['allowMethods' => ['get', 'set', 'fields']]);

    $src = '/**
     * @safescript 1.0
     * @type Counter { value }
     * @type Stream { get, clear }
     */
    return 1';

    assertThrows(function() use ($src) {
        SafeScript::load($src);
    }, 'clear');
});

// ── unlib() by short name ──

test('unlib() by short name cleans up type name mapping', function() {
    SafeScript::clearTypeRegistry();
    SafeScript::lib('Counter', TypeHeaderCounter::class, ['allowMethods' => ['value', 'increment']]);

    // Should work before unlib
    $result = SafeScript::run('return c.value()', ['c' => new TypeHeaderCounter(7)]);
    assertEq($result, 7);

    // Unregister by short name
    SafeScript::unlib('Counter');

    // Should now throw (unregistered type)
    assertThrows(function() {
        SafeScript::run('return c.value()', ['c' => new TypeHeaderCounter(7)]);
    }, 'unregistered type');
});

// Clean up
SafeScript::clearTypeRegistry();

// ── Report ──
echo "\n\n";
echo "@type Header (PHP): $passed passed, $failed failed out of $total\n";
if ($failed > 0) exit(1);
echo "All tests passed!\n";
