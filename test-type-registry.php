<?php
/**
 * Tests for SafeScript PHP type registry — SafeScript::lib(className, ['allowMethods' => [...]])
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
        echo "\nFAIL: $name\n  {$e->getMessage()}\n";
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

class Counter {
    public int $_count;
    public function __construct(int $n = 0) { $this->_count = $n; }
    public function increment(): self { $this->_count++; return $this; }
    public function decrement(): self { $this->_count--; return $this; }
    public function value(): int { return $this->_count; }
    public function reset(): self { $this->_count = 0; return $this; }
    public function __toString(): string { return "Counter({$this->_count})"; }
}

class Timer {
    private bool $_started = false;
    public function start(): self { $this->_started = true; return $this; }
    public function stop(): self { $this->_started = false; return $this; }
    public function isRunning(): bool { return $this->_started; }
}

class SpecialCounter extends Counter {
    public function double(): self { $this->_count *= 2; return $this; }
}

// Clean slate
SafeScript::clearTypeRegistry();

echo "Type Registry (PHP):\n";

// ── Unregistered type: all methods blocked ──

test('unregistered type — method call throws', function() {
    assertThrows(function() {
        SafeScript::run('return c.value()', ['c' => new Counter(5)]);
    }, 'unregistered type');
});

test('unregistered type — toString also blocked', function() {
    assertThrows(function() {
        SafeScript::run('return c.toString()', ['c' => new Counter(5)]);
    });
});

// ── Register type with whitelist ──

SafeScript::lib(Counter::class, ['allowMethods' => ['value', 'increment', 'toString']]);

test('registered type — allowed method works', function() {
    $result = SafeScript::run('return c.value()', ['c' => new Counter(42)]);
    assertEq($result, 42);
});

test('registered type — another allowed method', function() {
    $c = new Counter(0);
    $result = SafeScript::run('c.increment(); return c.value()', ['c' => $c]);
    assertEq($result, 1);
});

test('registered type — allowed toString', function() {
    $result = SafeScript::run('return c.toString()', ['c' => new Counter(7)]);
    assertEq($result, 'Counter(7)');
});

test('registered type — blocked method throws', function() {
    assertThrows(function() {
        SafeScript::run('return c.reset()', ['c' => new Counter(5)]);
    }, 'reset');
});

test('registered type — decrement blocked', function() {
    assertThrows(function() {
        SafeScript::run('c.decrement(); return c.value()', ['c' => new Counter(5)]);
    }, 'decrement');
});

// ── Return from host function ──

test('type returned from host function — allowed', function() {
    $result = SafeScript::run('var c = makeCounter(10); return c.value()', [
        'makeCounter' => function(int $n) { return new Counter($n); }
    ]);
    assertEq($result, 10);
});

test('type returned from host function — blocked', function() {
    assertThrows(function() {
        SafeScript::run('var c = makeCounter(10); c.reset(); return c.value()', [
            'makeCounter' => function(int $n) { return new Counter($n); }
        ]);
    }, 'reset');
});

// ── Chaining allowed methods ──

test('chaining allowed methods', function() {
    $c = new Counter(0);
    $result = SafeScript::run('c.increment(); c.increment(); return c.value()', ['c' => $c]);
    assertEq($result, 2);
});

// ── Second type, independently registered ──

SafeScript::lib(Timer::class, ['allowMethods' => ['start', 'isRunning']]);

test('second registered type — allowed method', function() {
    $result = SafeScript::run('t.start(); return t.isRunning()', ['t' => new Timer()]);
    assertEq($result, true);
});

test('second registered type — blocked method', function() {
    assertThrows(function() {
        SafeScript::run('t.start(); t.stop(); return t.isRunning()', ['t' => new Timer()]);
    }, 'stop');
});

// ── Primitives still work ──

test('string methods still work', function() {
    $result = SafeScript::run('return "hello".toUpperCase()');
    assertEq($result, 'HELLO');
});

test('array methods still work', function() {
    $result = SafeScript::run('return [3,1,2].sort().join(",")');
    assertEq($result, '1,2,3');
});

// ── Plain objects (arrays) still work ──

test('plain associative array method call works', function() {
    $result = SafeScript::run('return o.greet("world")', [
        'o' => ['greet' => function($n) { return "hi $n"; }]
    ]);
    assertEq($result, 'hi world');
});

// ── Unregister ──

test('unregister — methods blocked after unlib', function() {
    SafeScript::unlib(Timer::class);
    assertThrows(function() {
        SafeScript::run('return t.isRunning()', ['t' => new Timer()]);
    }, 'unregistered type');
});

test('Counter still registered after unlib Timer', function() {
    $result = SafeScript::run('return c.value()', ['c' => new Counter(99)]);
    assertEq($result, 99);
});

// ── clearTypeRegistry ──

SafeScript::lib(Timer::class, ['allowMethods' => ['start', 'isRunning']]);

test('clearTypeRegistry blocks all types', function() {
    // Verify both work before clear
    SafeScript::run('return c.value()', ['c' => new Counter(1)]);
    SafeScript::run('t.start(); return t.isRunning()', ['t' => new Timer()]);

    // Clear
    SafeScript::clearTypeRegistry();

    assertThrows(function() {
        SafeScript::run('return c.value()', ['c' => new Counter(1)]);
    });
    assertThrows(function() {
        SafeScript::run('return t.isRunning()', ['t' => new Timer()]);
    });
});

// ── Edge cases ──

test('lib() with non-existent class throws', function() {
    assertThrows(function() {
        SafeScript::lib('NonExistentClass12345', ['allowMethods' => ['foo']]);
    }, 'does not exist');
});

test('lib() with empty name throws', function() {
    assertThrows(function() {
        SafeScript::lib('', ['allowMethods' => ['foo']]);
    });
});

// ── Re-register and verify ──

SafeScript::lib(Counter::class, ['allowMethods' => ['value', 'increment', 'decrement', 'toString']]);

test('re-register with wider whitelist works', function() {
    $c = new Counter(5);
    $result = SafeScript::run('c.decrement(); return c.value()', ['c' => $c]);
    assertEq($result, 4);
});

test('re-register — previously allowed reset now blocked', function() {
    assertThrows(function() {
        SafeScript::run('return c.reset()', ['c' => new Counter(5)]);
    }, 'reset');
});

// ── Subclass handling ──

test('subclass inherits parent registration', function() {
    $sc = new SpecialCounter(3);
    $result = SafeScript::run('return c.value()', ['c' => $sc]);
    assertEq($result, 3);
});

test('subclass — parent-unlisted method blocked', function() {
    assertThrows(function() {
        SafeScript::run('return c.double()', ['c' => new SpecialCounter(3)]);
    }, 'double');
});

// Clean up
SafeScript::clearTypeRegistry();

// ── Exception/Throwable family ──
echo "\nException family:\n";

test('caught exception — getMessage() works', function() {
    $result = SafeScript::run('
        var msg;
        try { throw "something broke"; }
        catch (e) { msg = e.getMessage(); }
        return msg;
    ');
    assertEq($result, 'something broke');
});

test('caught exception — e.message property works', function() {
    $result = SafeScript::run('
        var msg;
        try { throw "test error"; }
        catch (e) { msg = e.message; }
        return msg;
    ');
    assertEq($result, 'test error');
});

test('caught exception — e.name returns class name', function() {
    $result = SafeScript::run('
        var n;
        try { throw "oops"; }
        catch (e) { n = e.name; }
        return n;
    ');
    assertEq($result, 'RuntimeException');
});

test('caught exception — toString() works', function() {
    $result = SafeScript::run('
        var s;
        try { throw "fail"; }
        catch (e) { s = e.toString(); }
        return s;
    ');
    // PHP __toString on RuntimeException includes the class name and message
    assert(str_contains($result, 'fail'), "toString should contain the message");
});

test('host-thrown exception — getMessage() works', function() {
    $thrower = function() { throw new \RuntimeException('host error'); };
    $result = SafeScript::run('
        var msg;
        try { thrower(); }
        catch (e) { msg = e.getMessage(); }
        return msg;
    ', ['thrower' => $thrower]);
    assertEq($result, 'host error');
});

test('host-thrown exception — e.message property works', function() {
    $thrower = function() { throw new \RuntimeException('prop test'); };
    $result = SafeScript::run('
        var msg;
        try { thrower(); }
        catch (e) { msg = e.message; }
        return msg;
    ', ['thrower' => $thrower]);
    assertEq($result, 'prop test');
});

// ── Report ──
echo "\n\n";
echo "Type Registry (PHP): $passed passed, $failed failed out of $total\n";
if ($failed > 0) exit(1);
echo "All tests passed!\n";
