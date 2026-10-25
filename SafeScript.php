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
    private static array $_libs = [];
    private static array $_typeRegistry = [];
    private static array $_typeNames = [];   // maps short type name → class name

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

        // Inject type registry for __method() to check
        $GLOBALS['__safescript_type_registry'] = self::$_typeRegistry;

        // Wrap scope with hooks if provided
        $hooks = $opts['hooks'] ?? null;
        if ($hooks !== null) {
            $hookState = ['callCount' => 0, 'startTime' => microtime(true)];
            $scope = self::_wrapScopeWithHooks($scope, $hooks, $hookState);
        }

        // Seed handling for deterministic RNG
        $seed = $opts['seed'] ?? 0;
        if (array_key_exists('seed', $opts) && $opts['seed'] === null) {
            // seed: null → needs env.Random authorization
            $env = $opts['env'] ?? [];
            if (!in_array('Random', $env, true)) {
                throw new \RuntimeException('SafeScript: seed:null requires env.Random authorization');
            }
            // Use truly random seed
            $seed = random_int(0, 0xFFFFFFFF);
        }
        $seed = intval($seed) & 0xFFFFFFFF;

        // Build executable PHP
        $phpCode = "";
        $phpCode .= "\$__rng_state = {$seed};\n";
        $phpCode .= Runtime::builtins() . "\n";
        $phpCode .= "\$__safeThis = null;\n";
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
            unset($GLOBALS['__safescript_type_registry']);
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
     * Auto-fills from registered libraries for any @needs/@uses not in scope.
     */
    public static function load(string $source, ?callable $resolver = null, array $opts = [])
    {
        $header = Header::parseHeader($source);
        $scope = [];

        foreach ($header['needs'] as $need) {
            $resolved = $resolver ? $resolver($need['name']) : null;
            if ($resolved === null && isset(self::$_libs[$need['name']])) {
                $resolved = self::$_libs[$need['name']];
            }
            if ($resolved === null) {
                throw new \RuntimeException("Missing required capability: {$need['name']}");
            }
            $scope[$need['name']] = $resolved;
        }

        foreach ($header['uses'] as $use) {
            $resolved = $resolver ? $resolver($use['name']) : null;
            if ($resolved === null && isset(self::$_libs[$use['name']])) {
                $resolved = self::$_libs[$use['name']];
            }
            if ($resolved !== null) {
                $scope[$use['name']] = $resolved;
            }
        }

        // Validate @type declarations against type registry
        if (!empty($header['types'])) {
            foreach ($header['types'] as $typeDef) {
                $typeName = $typeDef['name'];
                // Check if type is registered (by short name or class name)
                $className = null;
                if (isset(self::$_typeNames[$typeName])) {
                    $className = self::$_typeNames[$typeName];
                } elseif (isset(self::$_typeRegistry[$typeName])) {
                    $className = $typeName;
                }
                if ($className === null) {
                    throw new \RuntimeException(
                        "SafeScript: @type \"{$typeName}\" is not registered. " .
                        "Register with SafeScript::lib('{$typeName}', 'ClassName', ['allowMethods' => [...]])"
                    );
                }
                $hostMethods = self::$_typeRegistry[$className]['allowMethods'] ?? [];
                foreach ($typeDef['methods'] as $dm) {
                    if (!in_array($dm, $hostMethods, true)) {
                        throw new \RuntimeException(
                            "SafeScript: @type \"{$typeName}\" declares method \"{$dm}\" " .
                            "but host does not allow it. Allowed: " . implode(', ', $hostMethods)
                        );
                    }
                }
            }
        }

        return self::run($source, $scope, $opts);
    }

    /**
     * Register a named library or a type whitelist.
     *
     * Two forms:
     *   lib(name, value)      — register a named library (matches @needs <name>)
     *   lib(className, config) — register type whitelist: ['allowMethods' => ['m1', 'm2']]
     *
     * Type registration controls which methods SafeScript code can call on
     * instances returned from host functions. Unregistered types block all
     * method calls (sandbox violation).
     *
     * Three forms:
     *   lib(name, value)               — register a named library
     *   lib(className, config)          — register type whitelist (className must exist)
     *   lib(typeName, className, config) — register type with short name for @type headers
     *
     * @param string $nameOrClass Library name, class name, or short type name
     * @param mixed $value Library value, class name (3-arg), or config array with allowMethods
     * @param array|null $config Config with allowMethods (3-arg form only)
     */
    public static function lib(string $nameOrClass, $value = null, $config = null): void
    {
        if ($nameOrClass === '') {
            throw new \RuntimeException('SafeScript::lib: first argument must be a non-empty string');
        }
        // Three-arg form: lib('TypeName', 'ClassName', ['allowMethods' => [...]])
        if ($config !== null && is_string($value) && is_array($config) && isset($config['allowMethods'])) {
            if (!class_exists($value)) {
                throw new \RuntimeException("SafeScript::lib: class '{$value}' does not exist");
            }
            self::$_typeNames[$nameOrClass] = $value;
            self::$_typeRegistry[$value] = $config;
            return;
        }
        // Type registration: lib('ClassName', ['allowMethods' => [...]])
        if (is_array($value) && isset($value['allowMethods']) && is_array($value['allowMethods'])) {
            // Verify it's a real class
            if (!class_exists($nameOrClass)) {
                throw new \RuntimeException("SafeScript::lib: class '{$nameOrClass}' does not exist");
            }
            self::$_typeRegistry[$nameOrClass] = $value;
            return;
        }
        self::$_libs[$nameOrClass] = $value;
    }

    /**
     * Unregister a previously registered library or type.
     */
    public static function unlib(string $nameOrClass): void
    {
        // Check if it's a short type name mapping
        if (isset(self::$_typeNames[$nameOrClass])) {
            $className = self::$_typeNames[$nameOrClass];
            unset(self::$_typeNames[$nameOrClass]);
            unset(self::$_typeRegistry[$className]);
            return;
        }
        if (isset(self::$_typeRegistry[$nameOrClass])) {
            // Also clean up any _typeNames entries pointing to this class
            foreach (self::$_typeNames as $shortName => $className) {
                if ($className === $nameOrClass) {
                    unset(self::$_typeNames[$shortName]);
                }
            }
            unset(self::$_typeRegistry[$nameOrClass]);
            return;
        }
        unset(self::$_libs[$nameOrClass]);
    }

    /**
     * List registered library names.
     */
    public static function libs(): array
    {
        return array_keys(self::$_libs);
    }

    /**
     * Clear all type registrations.
     */
    public static function clearTypeRegistry(): void
    {
        self::$_typeRegistry = [];
        self::$_typeNames = [];
    }

    /**
     * Sanitize an object for safe injection into scope.
     */
    public static function sanitize($obj)
    {
        if ($obj === null || is_scalar($obj)) return $obj;
        if (self::_isCallable($obj)) return $obj;
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
     * Check whether a value is a PHP callable (not a scope-object array).
     * Distinguishes [$obj, 'method'] callables from associative scope arrays.
     */
    private static function _isCallable($value): bool
    {
        // Closures are always callable
        if ($value instanceof \Closure) return true;
        // String callables: 'strlen', 'MyClass::method'
        if (is_string($value) && is_callable($value)) return true;
        // Array callables: [$obj, 'method'] or ['ClassName', 'method']
        // These are exactly 2-element indexed arrays with string second element
        if (is_array($value) && count($value) === 2
            && array_key_exists(0, $value) && array_key_exists(1, $value)
            && is_string($value[1]) && is_callable($value)) {
            return true;
        }
        return false;
    }

    /**
     * Check whether an array is a scope object (associative, not a callable).
     */
    private static function _isScopeObject($value): bool
    {
        if (!is_array($value)) return false;
        if (self::_isCallable($value)) return false;
        // Non-empty associative array → scope object
        if (empty($value)) return false;
        return !array_is_list($value);
    }

    /**
     * Wrap all callable values in scope with hook interceptors.
     * Recurses into nested scope objects, building dotted name paths.
     */
    private static function _wrapScopeWithHooks(array $scope, array $hooks, array &$state, string $prefix = ''): array
    {
        $wrapped = [];
        foreach ($scope as $key => $value) {
            $path = $prefix !== '' ? $prefix . '.' . $key : $key;
            if (self::_isCallable($value)) {
                $wrapped[$key] = self::_wrapFnWithHooks($path, $value, $hooks, $state);
            } elseif (self::_isScopeObject($value)) {
                $wrapped[$key] = self::_wrapScopeWithHooks($value, $hooks, $state, $path);
            } else {
                $wrapped[$key] = $value;
            }
        }
        return $wrapped;
    }

    /**
     * Wrap a single function with beforeCall/afterCall hooks.
     *
     * Hook receives info array: ['name'=>..., 'args'=>..., 'callCount'=>..., 'elapsed'=>...]
     *
     * beforeCall(info) can return:
     *   false         → block the call (returns null)
     *   ['value'=>x]  → short-circuit, return x without calling
     *   ['args'=>[…]] → transform args, then call
     *   anything else → proceed normally
     *
     * afterCall(info) can return:
     *   ['value'=>x]  → replace result with x
     *   false         → block (return null)
     *   anything else → pass through original result
     */
    private static function _wrapFnWithHooks(string $name, callable $fn, array $hooks, array &$state): \Closure
    {
        $beforeCall = $hooks['beforeCall'] ?? null;
        $afterCall = $hooks['afterCall'] ?? null;

        return function () use ($name, $fn, $beforeCall, $afterCall, &$state) {
            $args = func_get_args();
            $state['callCount']++;
            $elapsed = microtime(true) - $state['startTime'];
            $info = ['name' => $name, 'args' => $args, 'callCount' => $state['callCount'], 'elapsed' => $elapsed];

            // beforeCall hook
            if ($beforeCall !== null) {
                $decision = $beforeCall($info);
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
                $info['result'] = $result;
                $info['elapsed'] = microtime(true) - $state['startTime'];
                $decision = $afterCall($info);
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
