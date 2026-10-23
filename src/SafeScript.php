<?php
/**
 * SafeScript — main API class.
 * PHP port of index.js.
 *
 * Usage:
 *   $result = SafeScript::run('return 2 + 2', []);
 *   $result = SafeScript::run('return x + y', ['x' => 1, 'y' => 2]);
 *   $result = SafeScript::run($source, $scope, ['timeout' => 5]);
 *   $result = SafeScript::run(['source' => $src, 'scope' => $scope]); // bundle form
 */

namespace SafeScript;

class SafeScript
{
    private static bool $_helpersLoaded = false;

    /**
     * Compile SafeScript source to PHP code.
     * @return array{code: string, hash: string}
     */
    public static function compile(string $source): array
    {
        return Compiler::compile($source);
    }

    // Hardcoded ceilings
    const MAX_TIMEOUT = 60;      // seconds
    const MAX_OPS = 100000000;   // 100 million

    /**
     * Run SafeScript source with given scope.
     * Accepts (source, scope, opts) or (bundle) where bundle = ['source'=>..., 'scope'=>...]
     *
     * Options:
     *   timeout  - max seconds (capped at MAX_TIMEOUT)
     *   maxOps   - max operations (capped at MAX_OPS)
     *   hooks    - ['beforeCall' => fn($name, $args), 'afterCall' => fn($name, $result, $args)]
     */
    public static function run($sourceOrBundle, array $scope = [], array $opts = [])
    {
        // Bundle overload
        if (is_array($sourceOrBundle)) {
            $source = $sourceOrBundle['source'] ?? '';
            $scope = $sourceOrBundle['scope'] ?? [];
            $opts = $sourceOrBundle['opts'] ?? $opts;
        } else {
            $source = $sourceOrBundle;
        }

        // Compile
        $compiled = Compiler::compile($source);
        $code = $compiled['code'];

        // Ensure runtime helpers are defined (once)
        self::_ensureHelpers();

        // Set up guards
        $timeout = $opts['timeout'] ?? 0;
        if ($timeout <= 0) {
            $timeout = self::MAX_TIMEOUT; // always enforce ceiling
        }
        $timeout = min($timeout, self::MAX_TIMEOUT);

        $maxOps = $opts['maxOps'] ?? 0;
        if ($maxOps <= 0) {
            $maxOps = self::MAX_OPS; // always enforce ceiling
        }
        $maxOps = min($maxOps, self::MAX_OPS);

        // Set globals for __check()
        $GLOBALS['__safescript_deadline'] = microtime(true) + $timeout;
        $GLOBALS['__safescript_ops'] = $maxOps;

        // Wrap scope with hooks if provided
        $hooks = $opts['hooks'] ?? null;
        if ($hooks !== null) {
            $scope = self::_wrapScopeWithHooks($scope, $hooks);
        }

        // Build executable PHP
        $phpCode = "";
        $phpCode .= Runtime::builtins() . "\n";
        $phpCode .= "extract(\$__scope);\n\n";
        $phpCode .= $code;
        $phpCode .= "\n";

        $wrapper = "namespace SafeScript\\Sandbox;\n";
        $wrapper .= "return (function() use (&\$__scope) {\n";
        $wrapper .= $phpCode;
        $wrapper .= "\n})();\n";

        try {
            $result = self::_execute($wrapper, $scope);
        } finally {
            // Clean up globals
            unset($GLOBALS['__safescript_deadline']);
            unset($GLOBALS['__safescript_ops']);
        }

        return $result;
    }

    /**
     * Validate SafeScript source — returns true or throws.
     */
    public static function validate(string $source): bool
    {
        return Compiler::validate($source);
    }

    /**
     * Parse SafeScript source — returns AST.
     */
    public static function parse(string $source): array
    {
        return Compiler::parse($source);
    }

    /**
     * Get capabilities (header info) from source.
     */
    public static function capabilities(string $source): array
    {
        return Header::parseHeader($source);
    }

    /**
     * Check if scope satisfies header requirements.
     */
    public static function checkScope(string $source, array $scope): array
    {
        return Header::checkScope($source, $scope);
    }

    /**
     * Load a .safe file with dependency resolution.
     * Unlike JS version, this is synchronous.
     */
    public static function load(string $source, callable $resolver, array $opts = [])
    {
        $header = Header::parseHeader($source);
        $scope = [];

        foreach ($header['needs'] as $need) {
            $resolved = $resolver($need['name']);
            if ($resolved === null) {
                throw new \RuntimeException("Missing required capability: {$need['name']}");
            }
            $scope[$need['name']] = $resolved;
        }

        foreach ($header['uses'] as $use) {
            $resolved = $resolver($use['name']);
            if ($resolved !== null) {
                $scope[$use['name']] = $resolved;
            }
        }

        return self::run($source, $scope, $opts);
    }

    /**
     * Sanitize an object for safe injection into scope.
     */
    public static function sanitize($obj)
    {
        if ($obj === null || is_scalar($obj)) return $obj;
        if (is_callable($obj) && !is_array($obj)) return $obj;
        if (is_array($obj)) {
            $result = [];
            foreach ($obj as $k => $v) {
                if (isset(Defs::BLOCKED_PROPS[$k])) continue;
                $result[$k] = self::sanitize($v);
            }
            return $result;
        }
        return $obj;
    }

    // ── Private helpers ──

    /**
     * Define runtime helper functions once in the SafeScript\Sandbox namespace.
     */
    private static function _ensureHelpers(): void
    {
        if (self::$_helpersLoaded) return;

        $code = "namespace SafeScript\\Sandbox;\n\n";

        // Parse individual functions from helpers() and wrap each with guard
        $raw = Runtime::helpers();
        $lines = explode("\n", $raw);
        $inFunction = false;
        $braceDepth = 0;
        $currentFunc = '';
        $currentName = '';

        foreach ($lines as $line) {
            if (!$inFunction) {
                if (preg_match('/^function\s+(__\w+)\s*\(/', $line, $m)) {
                    $currentName = $m[1];
                    $currentFunc = $line . "\n";
                    $inFunction = true;
                    $braceDepth = substr_count($line, '{') - substr_count($line, '}');
                }
            } else {
                $currentFunc .= $line . "\n";
                $braceDepth += substr_count($line, '{') - substr_count($line, '}');
                if ($braceDepth <= 0) {
                    $fqn = "SafeScript\\Sandbox\\{$currentName}";
                    $code .= "if (!function_exists('{$fqn}')) {\n";
                    $code .= $currentFunc;
                    $code .= "}\n\n";
                    $inFunction = false;
                    $currentFunc = '';
                    $currentName = '';
                }
            }
        }

        eval($code);
        self::$_helpersLoaded = true;
    }

    private static function _execute(string $phpCode, array $scope)
    {
        $__scope = $scope;
        return eval($phpCode);
    }

    /**
     * Wrap all callable values in scope with hook interceptors.
     */
    private static function _wrapScopeWithHooks(array $scope, array $hooks): array
    {
        $wrapped = [];
        foreach ($scope as $name => $value) {
            if (is_callable($value) && !is_array($value)) {
                $wrapped[$name] = self::_wrapFnWithHooks($name, $value, $hooks);
            } else {
                $wrapped[$name] = $value;
            }
        }
        return $wrapped;
    }

    /**
     * Wrap a single function with beforeCall/afterCall hooks.
     *
     * beforeCall($name, $args) can return:
     *   false         → block the call (returns null)
     *   ['value'=>x]  → short-circuit, return x without calling
     *   ['args'=>[…]] → transform args, then call
     *   anything else → proceed normally
     *
     * afterCall($name, $result, $args) can return:
     *   ['value'=>x]  → replace result with x
     *   false         → block (return null)
     *   anything else → pass through original result
     */
    private static function _wrapFnWithHooks(string $name, callable $fn, array $hooks): \Closure
    {
        $beforeCall = $hooks['beforeCall'] ?? null;
        $afterCall = $hooks['afterCall'] ?? null;

        return function () use ($name, $fn, $beforeCall, $afterCall) {
            $args = func_get_args();

            // beforeCall hook
            if ($beforeCall !== null) {
                $decision = $beforeCall($name, $args);
                if ($decision === false) {
                    return null; // blocked
                }
                if (is_array($decision)) {
                    if (array_key_exists('value', $decision)) {
                        return $decision['value']; // short-circuit
                    }
                    if (array_key_exists('args', $decision)) {
                        $args = $decision['args']; // transform args
                    }
                }
            }

            // Call the real function
            $result = $fn(...$args);

            // afterCall hook
            if ($afterCall !== null) {
                $decision = $afterCall($name, $result, $args);
                if ($decision === false) {
                    return null; // blocked
                }
                if (is_array($decision) && array_key_exists('value', $decision)) {
                    return $decision['value']; // replace result
                }
            }

            return $result;
        };
    }
}
