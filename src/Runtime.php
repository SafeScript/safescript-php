<?php
/**
 * SafeScript PHP runtime helpers.
 * These functions implement JavaScript semantics in PHP.
 * They are injected into the compiled code's scope.
 */

namespace SafeScript;

class Runtime
{
    /**
     * Returns the runtime helper function definitions as a PHP code string.
     * These are injected at the top of every compiled SafeScript function.
     */
    public static function helpers(): string
    {
        return <<<'PHP'
// ── SafeScript runtime helpers ──

function __at($obj, $key) {
    if ($obj === null) throw new \RuntimeException('Cannot read property "' . $key . '" of null');
    $k = (string)$key;
    static $blocked = ['constructor'=>1,'__proto__'=>1,'prototype'=>1,'__defineGetter__'=>1,'__defineSetter__'=>1,'__lookupGetter__'=>1,'__lookupSetter__'=>1,'caller'=>1,'callee'=>1,'arguments'=>1];
    if (isset($blocked[$k])) return null;
    if (is_array($obj)) {
        if (is_numeric($key)) return $obj[(int)$key] ?? null;
        return $obj[$k] ?? null;
    }
    if (is_object($obj)) return $obj->$k ?? null;
    if (is_string($obj)) {
        if ($k === 'length') return strlen($obj);
        if (is_numeric($key)) { $i = (int)$key; return ($i >= 0 && $i < strlen($obj)) ? $obj[$i] : null; }
    }
    return null;
}

function __at_opt($obj, $key) {
    if ($obj === null) return null;
    return __at($obj, $key);
}

function __set($obj, $key, $val) {
    if ($obj === null) throw new \RuntimeException('Cannot set property "' . $key . '" of null');
    $k = (string)$key;
    static $blocked = ['constructor'=>1,'__proto__'=>1,'prototype'=>1,'__defineGetter__'=>1,'__defineSetter__'=>1,'__lookupGetter__'=>1,'__lookupSetter__'=>1,'caller'=>1,'callee'=>1,'arguments'=>1];
    if (isset($blocked[$k])) throw new \RuntimeException('SafeScript: cannot set blocked property "' . $k . '"');
    if (is_array($obj)) {
        // Arrays are passed by value in PHP — caller must handle reference
        $obj[$k] = $val;
        return $val;
    }
    return $val;
}

function __prop($obj, $name) {
    if ($obj === null) throw new \RuntimeException('Cannot read property "' . $name . '" of null');
    if (is_array($obj)) return $obj[$name] ?? null;
    if (is_object($obj)) return $obj->$name ?? null;
    if (is_string($obj) && $name === 'length') return strlen($obj);
    return null;
}

function __prop_opt($obj, $name) {
    if ($obj === null) return null;
    return __prop($obj, $name);
}

function __set_prop(&$obj, $name, $val) {
    if ($obj === null) throw new \RuntimeException('Cannot set property "' . $name . '" of null');
    if (is_array($obj)) { $obj[$name] = $val; return $val; }
    if (is_object($obj)) { $obj->$name = $val; return $val; }
    return $val;
}

function __method($obj, $name, ...$args) {
    if ($obj === null) throw new \RuntimeException('Cannot read property "' . $name . '" of null');
    // String methods
    if (is_string($obj)) {
        switch ($name) {
            case 'indexOf': return strpos($obj, $args[0] ?? '') !== false ? strpos($obj, $args[0] ?? '') : -1;
            case 'includes': return str_contains($obj, $args[0] ?? '');
            case 'slice':
                $start = $args[0] ?? 0;
                $end = $args[1] ?? null;
                if ($start < 0) $start = max(0, strlen($obj) + $start);
                if ($end === null) return substr($obj, $start);
                if ($end < 0) $end = strlen($obj) + $end;
                return substr($obj, $start, max(0, $end - $start));
            case 'substring':
                $start = max(0, $args[0] ?? 0);
                $end = $args[1] ?? strlen($obj);
                if ($start > $end) { $t = $start; $start = $end; $end = $t; }
                return substr($obj, $start, $end - $start);
            case 'split':
                if (($args[0] ?? '') === '') return str_split($obj);
                return explode($args[0], $obj);
            case 'trim': return trim($obj);
            case 'trimStart': case 'trimLeft': return ltrim($obj);
            case 'trimEnd': case 'trimRight': return rtrim($obj);
            case 'toUpperCase': return strtoupper($obj);
            case 'toLowerCase': return strtolower($obj);
            case 'charAt': return ($args[0] ?? 0) < strlen($obj) ? $obj[(int)($args[0] ?? 0)] : '';
            case 'charCodeAt': $i = (int)($args[0] ?? 0); return $i < strlen($obj) ? ord($obj[$i]) : NAN;
            case 'startsWith': return str_starts_with($obj, $args[0] ?? '');
            case 'endsWith': return str_ends_with($obj, $args[0] ?? '');
            case 'repeat': return str_repeat($obj, (int)($args[0] ?? 0));
            case 'padStart': return str_pad($obj, (int)($args[0] ?? 0), $args[1] ?? ' ', STR_PAD_LEFT);
            case 'padEnd': return str_pad($obj, (int)($args[0] ?? 0), $args[1] ?? ' ', STR_PAD_RIGHT);
            case 'replace':
                $search = $args[0] ?? '';
                $replace = $args[1] ?? '';
                if (is_string($search)) {
                    $pos = strpos($obj, $search);
                    if ($pos === false) return $obj;
                    return substr_replace($obj, $replace, $pos, strlen($search));
                }
                return $obj;
            case 'replaceAll':
                return str_replace($args[0] ?? '', $args[1] ?? '', $obj);
            case 'match': case 'search': case 'matchAll':
                return null; // regex not fully supported
            case 'concat':
                return $obj . implode('', $args);
            case 'toString': return $obj;
        }
    }
    // Array methods
    if (is_array($obj)) {
        switch ($name) {
            case 'push':
                // Note: can't modify original without reference
                return count($obj) + count($args);
            case 'pop': return array_pop($obj);
            case 'shift': return array_shift($obj);
            case 'unshift': return array_unshift($obj, ...$args);
            case 'indexOf':
                $idx = array_search($args[0] ?? null, $obj, true);
                return $idx !== false ? $idx : -1;
            case 'includes': return in_array($args[0] ?? null, $obj, true);
            case 'join': return implode($args[0] ?? ',', $obj);
            case 'reverse': return array_reverse($obj);
            case 'slice':
                $start = $args[0] ?? 0;
                $end = $args[1] ?? null;
                if ($start < 0) $start = max(0, count($obj) + $start);
                if ($end === null) return array_slice($obj, $start);
                if ($end < 0) $end = count($obj) + $end;
                return array_slice($obj, $start, max(0, $end - $start));
            case 'concat':
                $result = $obj;
                foreach ($args as $a) {
                    if (is_array($a)) $result = array_merge($result, $a);
                    else $result[] = $a;
                }
                return $result;
            case 'map':
                return array_values(array_map(function($v, $i) use ($args, $obj) {
                    return ($args[0])($v, $i, $obj);
                }, $obj, array_keys($obj)));
            case 'filter':
                return array_values(array_filter($obj, function($v, $i) use ($args, $obj) {
                    return __truthy(($args[0])($v, $i, $obj));
                }, ARRAY_FILTER_USE_BOTH));
            case 'reduce':
                $fn = $args[0] ?? null;
                $acc = $args[1] ?? null;
                $started = isset($args[1]);
                foreach ($obj as $i => $v) {
                    if (!$started) { $acc = $v; $started = true; continue; }
                    $acc = $fn($acc, $v, $i, $obj);
                }
                return $acc;
            case 'find':
                foreach ($obj as $i => $v) {
                    if (__truthy(($args[0])($v, $i, $obj))) return $v;
                }
                return null;
            case 'findIndex':
                foreach ($obj as $i => $v) {
                    if (__truthy(($args[0])($v, $i, $obj))) return $i;
                }
                return -1;
            case 'some':
                foreach ($obj as $i => $v) {
                    if (__truthy(($args[0])($v, $i, $obj))) return true;
                }
                return false;
            case 'every':
                foreach ($obj as $i => $v) {
                    if (!__truthy(($args[0])($v, $i, $obj))) return false;
                }
                return true;
            case 'forEach':
                foreach ($obj as $i => $v) { ($args[0])($v, $i, $obj); }
                return null;
            case 'flat':
                $depth = $args[0] ?? 1;
                return __flat($obj, (int)$depth);
            case 'flatMap':
                $mapped = array_map(function($v, $i) use ($args, $obj) {
                    return ($args[0])($v, $i, $obj);
                }, $obj, array_keys($obj));
                return __flat($mapped, 1);
            case 'sort':
                $arr = $obj;
                if (!empty($args) && is_callable($args[0])) {
                    usort($arr, $args[0]);
                } else {
                    usort($arr, function($a, $b) { return strcmp((string)$a, (string)$b); });
                }
                return $arr;
            case 'fill':
                $val = $args[0] ?? null;
                $start = $args[1] ?? 0;
                $end = $args[2] ?? count($obj);
                if ($start < 0) $start = max(0, count($obj) + $start);
                if ($end < 0) $end = count($obj) + $end;
                for ($i = $start; $i < $end; $i++) $obj[$i] = $val;
                return $obj;
            case 'splice':
                $start = $args[0] ?? 0;
                $deleteCount = $args[1] ?? count($obj) - $start;
                $items = array_slice($args, 2);
                return array_splice($obj, $start, $deleteCount, $items);
            case 'keys': return array_keys($obj);
            case 'values': return array_values($obj);
            case 'entries':
                $r = [];
                foreach ($obj as $k => $v) $r[] = [$k, $v];
                return $r;
            case 'toString': return implode(',', $obj);
        }
    }
    // Object/callable methods
    if (is_array($obj) && isset($obj[$name]) && is_callable($obj[$name])) {
        return ($obj[$name])(...$args);
    }
    if (is_object($obj) && method_exists($obj, $name)) {
        return $obj->$name(...$args);
    }
    if (is_object($obj) && isset($obj->$name) && is_callable($obj->$name)) {
        return ($obj->$name)(...$args);
    }
    return null;
}

function __flat($arr, $depth) {
    $result = [];
    foreach ($arr as $item) {
        if (is_array($item) && $depth > 0) {
            $result = array_merge($result, __flat($item, $depth - 1));
        } else {
            $result[] = $item;
        }
    }
    return $result;
}

function __add($a, $b) {
    if (is_string($a) || is_string($b)) return (string)$a . (string)$b;
    if ($a === null) $a = 0;
    if ($b === null) $b = 0;
    return $a + $b;
}

function __typeof($val) {
    if ($val === null) return 'undefined';
    if (is_bool($val)) return 'boolean';
    if (is_int($val) || is_float($val)) return 'number';
    if (is_string($val)) return 'string';
    if (is_callable($val)) return 'function';
    return 'object';
}

function __truthy($val) {
    if ($val === null) return false;
    if ($val === false) return false;
    if ($val === 0 || $val === 0.0) return false;
    if ($val === '') return false;
    if (is_float($val) && is_nan($val)) return false;
    return true;
}

function __not($val) {
    return !__truthy($val);
}

function __or($a, $b) {
    return __truthy($a) ? $a : $b;
}

function __and($a, $b) {
    return __truthy($a) ? $b : $a;
}

function __eq($a, $b) {
    if ($a === null && $b === null) return true;
    if ($a === null || $b === null) return false;
    return $a == $b;
}

function __neq($a, $b) {
    return !__eq($a, $b);
}

function __in($key, $obj) {
    if (is_array($obj)) return array_key_exists($key, $obj);
    if (is_object($obj)) return property_exists($obj, $key);
    return false;
}

function __instanceof($val, $type) {
    if (is_string($type)) {
        switch ($type) {
            case 'Array': return is_array($val) && array_is_list($val);
            case 'Object': return is_array($val) || is_object($val);
            case 'Function': return is_callable($val);
            case 'RegExp': return false;
        }
    }
    if (is_object($val) && is_string($type)) return $val instanceof $type;
    return false;
}

function __arr(...$items) {
    return array_values($items);
}

function __obj(...$pairs) {
    // pairs come as key => value from the emitter via named args
    // But PHP doesn't support mixed spread + named easily
    // The emitter produces __obj('key' => val, ...)
    // PHP will pass these as a single assoc array
    return $pairs;
}

function __spread($val) {
    if (is_array($val)) return $val;
    if (is_string($val)) return str_split($val);
    return [];
}

function __throw($val) {
    if ($val instanceof \Throwable) return $val;
    return new \RuntimeException(is_string($val) ? $val : json_encode($val));
}

function __unsigned_rshift($a, $b) {
    if ($a >= 0) return $a >> $b;
    // Convert to unsigned 32-bit then shift
    return ($a & 0xFFFFFFFF) >> $b;
}

function __check() {
    $d = $GLOBALS['__safescript_deadline'] ?? 0;
    $o = &$GLOBALS['__safescript_ops'];
    if ($o !== null) {
        $o--;
        if ($o <= 0) {
            throw new \RuntimeException('SafeScript: operation limit exceeded');
        }
    }
    // Check time every 1000 ops to reduce overhead
    if ($d > 0 && ($o === null || ($o % 1000) === 0)) {
        if (microtime(true) >= $d) {
            throw new \RuntimeException('SafeScript: timeout exceeded');
        }
    }
}

PHP;
    }

    /**
     * Returns code string for Object/Array/Math/JSON/String/Number statics.
     */
    public static function builtins(): string
    {
        return <<<'PHP'
// ── SafeScript built-in objects (static methods) ──

$Object = [
    'keys' => function($obj) { return is_array($obj) ? array_keys($obj) : []; },
    'values' => function($obj) { return is_array($obj) ? array_values($obj) : []; },
    'entries' => function($obj) {
        if (!is_array($obj)) return [];
        $r = [];
        foreach ($obj as $k => $v) $r[] = [$k, $v];
        return $r;
    },
    'assign' => function($target, ...$sources) {
        foreach ($sources as $src) {
            if (is_array($src)) foreach ($src as $k => $v) $target[$k] = $v;
        }
        return $target;
    },
    'freeze' => function($obj) { return $obj; },
    'create' => function($proto) { return []; },
    'fromEntries' => function($entries) {
        $r = [];
        foreach ($entries as $e) $r[$e[0]] = $e[1];
        return $r;
    },
    'hasOwn' => function($obj, $key) {
        return is_array($obj) && array_key_exists($key, $obj);
    },
];
$Array = [
    'isArray' => function($val) { return is_array($val); },
    'from' => function($val, $mapFn = null) {
        $arr = is_array($val) ? $val : (is_string($val) ? str_split($val) : []);
        if ($mapFn !== null) return array_map($mapFn, $arr, array_keys($arr));
        return $arr;
    },
    'of' => function(...$args) { return $args; },
];
$Math = [
    'abs' => function($x) { return abs($x); },
    'ceil' => function($x) { return (int)ceil($x); },
    'floor' => function($x) { return (int)floor($x); },
    'round' => function($x) { return (int)round($x); },
    'max' => function(...$args) { return max($args); },
    'min' => function(...$args) { return min($args); },
    'pow' => function($base, $exp) { return pow($base, $exp); },
    'sqrt' => function($x) { return sqrt($x); },
    'random' => function() { return mt_rand() / mt_getrandmax(); },
    'log' => function($x) { return log($x); },
    'log2' => function($x) { return log($x, 2); },
    'log10' => function($x) { return log10($x); },
    'sin' => function($x) { return sin($x); },
    'cos' => function($x) { return cos($x); },
    'tan' => function($x) { return tan($x); },
    'PI' => M_PI,
    'E' => M_E,
    'trunc' => function($x) { return (int)$x; },
    'sign' => function($x) { return $x > 0 ? 1 : ($x < 0 ? -1 : 0); },
    'hypot' => function(...$args) { return sqrt(array_sum(array_map(fn($x) => $x * $x, $args))); },
];
$JSON = [
    'parse' => function($str) { return json_decode($str, true); },
    'stringify' => function($val, $replacer = null, $space = null) {
        $opts = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
        if ($space) $opts |= JSON_PRETTY_PRINT;
        return json_encode($val, $opts);
    },
];
$String = [
    'fromCharCode' => function(...$codes) {
        $s = '';
        foreach ($codes as $c) $s .= chr($c);
        return $s;
    },
];
$Number = [
    'isFinite' => function($val) { return is_numeric($val) && is_finite($val); },
    'isInteger' => function($val) { return is_int($val); },
    'isNaN' => function($val) { return is_float($val) && is_nan($val); },
    'parseInt' => function($str, $radix = 10) { return intval($str, $radix); },
    'parseFloat' => function($str) { return floatval($str); },
    'MAX_SAFE_INTEGER' => 9007199254740991,
    'MIN_SAFE_INTEGER' => -9007199254740991,
];
$console = [
    'log' => function(...$args) { /* no-op in sandbox */ },
    'error' => function(...$args) { /* no-op in sandbox */ },
    'warn' => function(...$args) { /* no-op in sandbox */ },
];
$parseInt = function($str, $radix = 10) { return intval($str, $radix); };
$parseFloat = function($str) { return floatval($str); };
$isNaN = function($val) { return is_float($val) && is_nan($val); };
$isFinite = function($val) { return is_numeric($val) && is_finite($val); };
$encodeURIComponent = function($str) { return rawurlencode($str); };
$decodeURIComponent = function($str) { return rawurldecode($str); };

PHP;
    }
}
