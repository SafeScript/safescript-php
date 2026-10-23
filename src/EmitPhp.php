<?php
/**
 * SafeScript PHP emitter — emits PHP code from the AST.
 * This is NEW (not a port of emit.js which emits JS).
 *
 * Key differences from JS emitter:
 * - Variables become $var
 * - var/let/const are stripped (PHP doesn't need them in function scope)
 * - for-in → foreach($obj as $k => $_), for-of → foreach($arr as $v)
 * - try/catch → catch(\Throwable $e)
 * - typeof x → __typeof($x) with isset handling
 * - async/await are blocked keywords (auto-async model — handled at host level)
 * - Arrow functions → closures with use() for captured variables
 * - Functions need free variable analysis for use() declarations
 * - undefined → null
 * - === / !== → === / !== (same in PHP)
 * - == / != stay as loose equality (runtime helpers handle JS semantics)
 */

namespace SafeScript;

class EmitPhp
{
    public static function emit(array $node): string
    {
        return self::_emit($node);
    }

    /**
     * Collect free variables in a function body that need use() declarations.
     * Returns array of variable names referenced but not declared in this scope.
     */
    public static function freeVars(array $node, array $params = []): array
    {
        $declared = [];
        $referenced = [];
        foreach ($params as $p) {
            foreach (self::patternNames($p) as $name) {
                $declared[$name] = true;
            }
        }
        self::_collectVars($node, $declared, $referenced, false);
        $free = [];
        foreach ($referenced as $name => $_) {
            if (!isset($declared[$name])) {
                $free[] = $name;
            }
        }
        sort($free);
        return $free;
    }

    /**
     * Extract binding names from a pattern node.
     */
    private static function patternNames(array $node): array
    {
        $names = [];
        switch ($node['type'] ?? '') {
            case 'Identifier':
                $names[] = $node['name'];
                break;
            case 'ArrayPattern':
                foreach ($node['elements'] ?? [] as $el) {
                    if ($el !== null) $names = array_merge($names, self::patternNames($el));
                }
                break;
            case 'ObjectPattern':
                foreach ($node['properties'] ?? [] as $prop) {
                    if (($prop['type'] ?? '') === 'RestElement') {
                        $names = array_merge($names, self::patternNames($prop['argument']));
                    } else {
                        $names = array_merge($names, self::patternNames($prop['value']));
                    }
                }
                break;
            case 'AssignmentPattern':
                $names = array_merge($names, self::patternNames($node['left']));
                break;
            case 'RestElement':
                $names = array_merge($names, self::patternNames($node['argument']));
                break;
        }
        return $names;
    }

    /**
     * Walk AST collecting declared and referenced variables.
     * $inNested = true means we're inside a nested function (don't add refs to parent scope).
     */
    private static function _collectVars(array $node, array &$declared, array &$referenced, bool $inNested): void
    {
        $type = $node['type'] ?? '';

        switch ($type) {
            case 'Identifier':
                if (!$inNested) {
                    $referenced[$node['name']] = true;
                }
                break;

            case 'VariableDeclaration':
                foreach ($node['declarations'] as $decl) {
                    foreach (self::patternNames($decl['id']) as $name) {
                        if (!$inNested) $declared[$name] = true;
                    }
                    if ($decl['init'] !== null) {
                        self::_collectVars($decl['init'], $declared, $referenced, $inNested);
                    }
                }
                break;

            case 'FunctionDeclaration':
                if (!$inNested) {
                    $declared[$node['id']['name']] = true;
                }
                // Don't recurse into nested function body for this scope
                break;

            case 'FunctionExpression':
            case 'ArrowFunctionExpression':
                // Don't recurse — these create their own scope
                break;

            case 'Program':
            case 'BlockStatement':
                foreach ($node['body'] as $child) {
                    self::_collectVars($child, $declared, $referenced, $inNested);
                }
                break;

            case 'ExpressionStatement':
                self::_collectVars($node['expression'], $declared, $referenced, $inNested);
                break;

            case 'ReturnStatement':
            case 'ThrowStatement':
                if ($node['argument'] !== null) {
                    self::_collectVars($node['argument'], $declared, $referenced, $inNested);
                }
                break;

            case 'IfStatement':
                self::_collectVars($node['test'], $declared, $referenced, $inNested);
                self::_collectVars($node['consequent'], $declared, $referenced, $inNested);
                if ($node['alternate'] !== null) {
                    self::_collectVars($node['alternate'], $declared, $referenced, $inNested);
                }
                break;

            case 'WhileStatement':
                self::_collectVars($node['test'], $declared, $referenced, $inNested);
                self::_collectVars($node['body'], $declared, $referenced, $inNested);
                break;

            case 'DoWhileStatement':
                self::_collectVars($node['body'], $declared, $referenced, $inNested);
                self::_collectVars($node['test'], $declared, $referenced, $inNested);
                break;

            case 'ForStatement':
                if ($node['init'] !== null) self::_collectVars($node['init'], $declared, $referenced, $inNested);
                if ($node['test'] !== null) self::_collectVars($node['test'], $declared, $referenced, $inNested);
                if ($node['update'] !== null) self::_collectVars($node['update'], $declared, $referenced, $inNested);
                self::_collectVars($node['body'], $declared, $referenced, $inNested);
                break;

            case 'ForInStatement':
            case 'ForOfStatement':
                self::_collectVars($node['left'], $declared, $referenced, $inNested);
                self::_collectVars($node['right'], $declared, $referenced, $inNested);
                self::_collectVars($node['body'], $declared, $referenced, $inNested);
                break;

            case 'BinaryExpression':
            case 'LogicalExpression':
            case 'AssignmentExpression':
                self::_collectVars($node['left'], $declared, $referenced, $inNested);
                self::_collectVars($node['right'], $declared, $referenced, $inNested);
                break;

            case 'UnaryExpression':
            case 'UpdateExpression':
            case 'SpreadElement':
                self::_collectVars($node['argument'], $declared, $referenced, $inNested);
                break;

            case 'ConditionalExpression':
                self::_collectVars($node['test'], $declared, $referenced, $inNested);
                self::_collectVars($node['consequent'], $declared, $referenced, $inNested);
                self::_collectVars($node['alternate'], $declared, $referenced, $inNested);
                break;

            case 'CallExpression':
                self::_collectVars($node['callee'], $declared, $referenced, $inNested);
                foreach ($node['arguments'] as $arg) {
                    self::_collectVars($arg, $declared, $referenced, $inNested);
                }
                break;

            case 'MemberExpression':
                self::_collectVars($node['object'], $declared, $referenced, $inNested);
                if ($node['computed']) {
                    self::_collectVars($node['property'], $declared, $referenced, $inNested);
                }
                break;

            case 'ArrayExpression':
                foreach ($node['elements'] as $el) {
                    if ($el !== null) self::_collectVars($el, $declared, $referenced, $inNested);
                }
                break;

            case 'ObjectExpression':
                foreach ($node['properties'] as $prop) {
                    if (($prop['type'] ?? '') === 'SpreadElement') {
                        self::_collectVars($prop, $declared, $referenced, $inNested);
                    } else {
                        if ($prop['computed'] ?? false) self::_collectVars($prop['key'], $declared, $referenced, $inNested);
                        self::_collectVars($prop['value'], $declared, $referenced, $inNested);
                    }
                }
                break;

            case 'SequenceExpression':
                foreach ($node['expressions'] as $expr) {
                    self::_collectVars($expr, $declared, $referenced, $inNested);
                }
                break;

            case 'TryStatement':
                self::_collectVars($node['block'], $declared, $referenced, $inNested);
                if ($node['handler'] !== null) {
                    if ($node['handler']['param'] !== null) {
                        foreach (self::patternNames($node['handler']['param']) as $name) {
                            if (!$inNested) $declared[$name] = true;
                        }
                    }
                    self::_collectVars($node['handler']['body'], $declared, $referenced, $inNested);
                }
                if ($node['finalizer'] !== null) {
                    self::_collectVars($node['finalizer'], $declared, $referenced, $inNested);
                }
                break;

            case 'SwitchStatement':
                self::_collectVars($node['discriminant'], $declared, $referenced, $inNested);
                foreach ($node['cases'] as $case) {
                    if ($case['test'] !== null) self::_collectVars($case['test'], $declared, $referenced, $inNested);
                    foreach ($case['consequent'] as $stmt) {
                        self::_collectVars($stmt, $declared, $referenced, $inNested);
                    }
                }
                break;

            case 'LabeledStatement':
                self::_collectVars($node['body'], $declared, $referenced, $inNested);
                break;

            case 'Literal':
            case 'BreakStatement':
            case 'ContinueStatement':
            case 'EmptyStatement':
                break;
        }
    }

    // ── Emit ─────────────────────────────────────────────────────

    private static function _emit(array $node): string
    {
        $type = $node['type'] ?? '';

        switch ($type) {
            case 'Program':
                $out = '';
                foreach ($node['body'] as $stmt) {
                    $out .= self::_emit($stmt);
                }
                return $out;

            case 'Identifier':
                $name = $node['name'];
                if ($name === 'undefined') return 'null';
                if ($name === 'NaN') return 'NAN';
                if ($name === 'Infinity') return 'INF';
                // Runtime helper functions — no $ prefix
                if (str_starts_with($name, '__')) return $name;
                return '$' . $name;

            case 'Literal':
                if (isset($node['regex'])) {
                    return var_export($node['raw'], true); // emit regex as string
                }
                if ($node['value'] === null && ($node['raw'] ?? '') === 'null') return 'null';
                if ($node['value'] === true) return 'true';
                if ($node['value'] === false) return 'false';
                if (is_string($node['value'])) {
                    return var_export($node['value'], true);
                }
                return $node['raw'] ?? var_export($node['value'], true);

            case 'ExpressionStatement':
                return self::_emit($node['expression']) . ";\n";

            case 'BlockStatement':
                $out = "{\n";
                foreach ($node['body'] as $stmt) {
                    $out .= self::_emit($stmt);
                }
                $out .= "}\n";
                return $out;

            case 'EmptyStatement':
                return ";\n";

            case 'ReturnStatement':
                if ($node['argument'] === null) return "return;\n";
                return 'return ' . self::_emit($node['argument']) . ";\n";

            case 'ThrowStatement':
                // PHP throw needs an exception object; wrap non-exceptions
                return 'throw __throw(' . self::_emit($node['argument']) . ");\n";

            case 'IfStatement':
                $out = 'if (' . self::_emit($node['test']) . ') ';
                $out .= self::_emitBody($node['consequent']);
                if ($node['alternate'] !== null) {
                    $out = rtrim($out, "\n") . ' else ';
                    $out .= self::_emitBody($node['alternate']);
                }
                return $out;

            case 'WhileStatement':
                return 'while (' . self::_emit($node['test']) . ') ' . self::_emitLoopBody($node['body']);

            case 'DoWhileStatement':
                return 'do ' . self::_emitLoopBody($node['body']) . 'while (' . self::_emit($node['test']) . ");\n";

            case 'ForStatement':
                $init = $node['init'] !== null ? self::_emitForInit($node['init']) : '';
                $test = $node['test'] !== null ? self::_emit($node['test']) : '';
                $update = $node['update'] !== null ? self::_emit($node['update']) : '';
                return "for ({$init}; {$test}; {$update}) " . self::_emitLoopBody($node['body']);

            case 'ForInStatement':
                $left = self::_emitForLeft($node['left']);
                $right = self::_emit($node['right']);
                return "foreach ({$right} as {$left} => \$_) " . self::_emitLoopBody($node['body']);

            case 'ForOfStatement':
                $left = self::_emitForLeft($node['left']);
                $right = self::_emit($node['right']);
                return "foreach ({$right} as {$left}) " . self::_emitLoopBody($node['body']);

            case 'VariableDeclaration':
                return self::_emitVarDecl($node) . ";\n";

            case 'VariableDeclarator':
                $id = self::_emitPattern($node['id']);
                if ($node['init'] !== null) {
                    return $id . ' = ' . self::_emit($node['init']);
                }
                return $id . ' = null';

            case 'FunctionDeclaration':
                return self::_emitFunction($node, true);

            case 'FunctionExpression':
                return self::_emitFunction($node, false);

            case 'ArrowFunctionExpression':
                return self::_emitArrow($node);

            case 'BinaryExpression':
                $op = $node['operator'];
                $left = self::_emit($node['left']);
                $right = self::_emit($node['right']);
                if ($op === '**') return "pow({$left}, {$right})";
                if ($op === 'instanceof') return "__instanceof({$left}, {$right})";
                if ($op === 'in') return "__in({$left}, {$right})";
                if ($op === '+') return "__add({$left}, {$right})";
                if ($op === '==') return "__eq({$left}, {$right})";
                if ($op === '!=') return "__neq({$left}, {$right})";
                // >>> (unsigned right shift) — PHP doesn't have this
                if ($op === '>>>') return "__unsigned_rshift({$left}, {$right})";
                return "({$left} {$op} {$right})";

            case 'LogicalExpression':
                $left = self::_emit($node['left']);
                $right = self::_emit($node['right']);
                $op = $node['operator'];
                if ($op === '??') return "({$left} ?? {$right})";
                if ($op === '||') return "__or({$left}, {$right})";
                if ($op === '&&') return "__and({$left}, {$right})";
                return "({$left} {$op} {$right})";

            case 'UnaryExpression':
                $arg = self::_emit($node['argument']);
                $op = $node['operator'];
                if ($op === 'typeof') return "__typeof({$arg})";
                if ($op === 'void') {
                    // void expr → evaluate expr for side effects, return null
                    // For simple values like `void 0`, just return null
                    if ($node['argument']['type'] === 'Literal') return 'null';
                    return "(($arg) ? null : null)";
                }
                if ($op === '!') return "__not({$arg})";
                return "({$op}{$arg})";

            case 'UpdateExpression':
                $arg = self::_emit($node['argument']);
                $op = $node['operator'];
                if ($node['prefix']) return "{$op}{$arg}";
                return "{$arg}{$op}";

            case 'AssignmentExpression':
                $left = self::_emit($node['left']);
                $right = self::_emit($node['right']);
                $op = $node['operator'];
                if ($op === '+=') return "{$left} = __add({$left}, {$right})";
                if ($op === '||=') return "{$left} = __or({$left}, {$right})";
                if ($op === '&&=') return "{$left} = __and({$left}, {$right})";
                if ($op === '??=') return "{$left} = ({$left} ?? {$right})";
                if ($op === '**=') return "{$left} = pow({$left}, {$right})";
                if ($op === '>>>=') return "{$left} = __unsigned_rshift({$left}, {$right})";
                return "{$left} {$op} {$right}";

            case 'ConditionalExpression':
                return '(' . self::_emit($node['test']) . ' ? ' . self::_emit($node['consequent']) . ' : ' . self::_emit($node['alternate']) . ')';

            case 'CallExpression':
                // Check for method call pattern: obj.method(args) → __method(obj, 'method', args)
                $calleeNode = $node['callee'];
                if (($calleeNode['type'] ?? '') === 'MemberExpression' && !($calleeNode['computed'] ?? false)) {
                    $obj = self::_emit($calleeNode['object']);
                    $method = $calleeNode['property']['name'] ?? '';
                    $opt = $calleeNode['optional'] ?? false;
                    $args = [];
                    foreach ($node['arguments'] as $arg) {
                        if ($arg['type'] === 'SpreadElement') {
                            $args[] = '...' . self::_emit($arg['argument']);
                        } else {
                            $args[] = self::_emit($arg);
                        }
                    }
                    $argStr = implode(', ', $args);
                    $allArgs = $obj . ', ' . var_export($method, true);
                    if ($argStr !== '') $allArgs .= ', ' . $argStr;
                    if ($opt) {
                        return "({$obj} !== null ? __method({$allArgs}) : null)";
                    }
                    return "__method({$allArgs})";
                }

                $callee = self::_emit($node['callee']);
                // Optional call: obj?.()
                $opt = $node['optional'] ?? false;
                $args = [];
                foreach ($node['arguments'] as $arg) {
                    if ($arg['type'] === 'SpreadElement') {
                        $args[] = '...' . self::_emit($arg['argument']);
                    } else {
                        $args[] = self::_emit($arg);
                    }
                }
                $argStr = implode(', ', $args);
                if ($opt) {
                    return "({$callee} !== null ? {$callee}({$argStr}) : null)";
                }
                return "{$callee}({$argStr})";

            case 'MemberExpression':
                $obj = self::_emit($node['object']);
                $opt = $node['optional'] ?? false;
                if ($node['computed']) {
                    // Should be handled by transform, but fallback
                    $key = self::_emit($node['property']);
                    if ($opt) return "__at_opt({$obj}, {$key})";
                    return "__at({$obj}, {$key})";
                }
                $prop = $node['property']['name'] ?? '';
                if ($opt) {
                    return "__prop_opt({$obj}, " . var_export($prop, true) . ")";
                }
                return "__prop({$obj}, " . var_export($prop, true) . ")";

            case 'ArrayExpression':
                $elements = [];
                $hasSpread = false;
                foreach ($node['elements'] as $el) {
                    if ($el === null) {
                        $elements[] = 'null';
                    } elseif ($el['type'] === 'SpreadElement') {
                        $hasSpread = true;
                        $elements[] = '...__spread(' . self::_emit($el['argument']) . ')';
                    } else {
                        $elements[] = self::_emit($el);
                    }
                }
                if ($hasSpread) {
                    // array_values to reindex after spread
                    return 'array_values([' . implode(', ', $elements) . '])';
                }
                return '[' . implode(', ', $elements) . ']';

            case 'ObjectExpression':
                if (empty($node['properties'])) return '[]';
                $parts = [];
                $hasSpread = false;
                foreach ($node['properties'] as $prop) {
                    if ($prop['type'] === 'SpreadElement') {
                        $hasSpread = true;
                        $parts[] = '...__spread(' . self::_emit($prop['argument']) . ')';
                        continue;
                    }
                    $key = self::_emitObjKey($prop);
                    $value = self::_emit($prop['value']);
                    $parts[] = "{$key} => {$value}";
                }
                if ($hasSpread) {
                    return 'array_merge([' . implode(', ', $parts) . '])';
                }
                return '[' . implode(', ', $parts) . ']';

            case 'SpreadElement':
                return '...__spread(' . self::_emit($node['argument']) . ')';

            case 'SequenceExpression':
                $parts = [];
                foreach ($node['expressions'] as $expr) {
                    $parts[] = self::_emit($expr);
                }
                // Use a helper or just comma expr
                return '(' . implode(', ', $parts) . ')';

            case 'TryStatement':
                $out = "try " . self::_emit($node['block']);
                if ($node['handler'] !== null) {
                    $param = '$__e';
                    if ($node['handler']['param'] !== null) {
                        $param = self::_emitPattern($node['handler']['param']);
                    }
                    $out = rtrim($out, "\n") . " catch (\\Throwable {$param}) " . self::_emit($node['handler']['body']);
                }
                if ($node['finalizer'] !== null) {
                    $out = rtrim($out, "\n") . ' finally ' . self::_emit($node['finalizer']);
                }
                return $out;

            case 'SwitchStatement':
                $out = 'switch (' . self::_emit($node['discriminant']) . ") {\n";
                foreach ($node['cases'] as $case) {
                    if ($case['test'] !== null) {
                        $out .= 'case ' . self::_emit($case['test']) . ":\n";
                    } else {
                        $out .= "default:\n";
                    }
                    foreach ($case['consequent'] as $stmt) {
                        $out .= self::_emit($stmt);
                    }
                }
                $out .= "}\n";
                return $out;

            case 'LabeledStatement':
                return $node['label']['name'] . ': ' . self::_emit($node['body']);

            case 'BreakStatement':
                if ($node['label'] !== null) return 'break ' . $node['label']['name'] . ";\n";
                return "break;\n";

            case 'ContinueStatement':
                if ($node['label'] !== null) return 'continue ' . $node['label']['name'] . ";\n";
                return "continue;\n";

            default:
                return '/* unknown node: ' . $type . ' */';
        }
    }

    private static function _emitBody(array $node): string
    {
        if ($node['type'] === 'BlockStatement') {
            return self::_emit($node);
        }
        return "{\n" . self::_emit($node) . "}\n";
    }

    /**
     * Emit a block statement with __check() prepended (for function bodies).
     */
    private static function _emitBodyWithCheck(array $node): string
    {
        if ($node['type'] === 'BlockStatement') {
            $out = "{\n__check();\n";
            foreach ($node['body'] as $stmt) {
                $out .= self::_emit($stmt);
            }
            $out .= "}\n";
            return $out;
        }
        return "{\n__check();\n" . self::_emit($node) . "}\n";
    }

    /**
     * Emit a loop body with __check() prepended at start of each iteration.
     */
    private static function _emitLoopBody(array $node): string
    {
        if ($node['type'] === 'BlockStatement') {
            $out = "{\n__check();\n";
            foreach ($node['body'] as $stmt) {
                $out .= self::_emit($stmt);
            }
            $out .= "}\n";
            return $out;
        }
        return "{\n__check();\n" . self::_emit($node) . "}\n";
    }

    private static function _emitVarDecl(array $node): string
    {
        $parts = [];
        foreach ($node['declarations'] as $decl) {
            $parts[] = self::_emit($decl);
        }
        return implode('; ', $parts);
    }

    private static function _emitForInit(array $node): string
    {
        if ($node['type'] === 'VariableDeclaration') {
            return self::_emitVarDecl($node);
        }
        return self::_emit($node);
    }

    private static function _emitForLeft(array $node): string
    {
        if ($node['type'] === 'VariableDeclaration') {
            $decl = $node['declarations'][0];
            return self::_emitPattern($decl['id']);
        }
        return self::_emit($node);
    }

    private static function _emitPattern(array $node): string
    {
        switch ($node['type'] ?? '') {
            case 'Identifier':
                return '$' . $node['name'];
            case 'ArrayPattern':
                $parts = [];
                foreach ($node['elements'] as $el) {
                    if ($el === null) {
                        $parts[] = '';
                    } elseif ($el['type'] === 'RestElement') {
                        // PHP doesn't have rest in list destructuring; we'll handle at runtime
                        $parts[] = '...' . self::_emitPattern($el['argument']);
                    } else {
                        $parts[] = self::_emitPattern($el);
                    }
                }
                return '[' . implode(', ', $parts) . ']';
            case 'ObjectPattern':
                // PHP doesn't have native object destructuring; emit as variable
                // This would need runtime support — for now, emit a comment
                $names = self::patternNames($node);
                return '$' . ($names[0] ?? '_');
            case 'AssignmentPattern':
                return self::_emitPattern($node['left']);
            case 'RestElement':
                return '...' . self::_emitPattern($node['argument']);
            default:
                return self::_emit($node);
        }
    }

    private static function _emitFunction(array $node, bool $isDecl): string
    {
        $params = self::_emitParams($node['params']);

        // Free variable analysis for use() — needed for ALL functions in PHP
        $free = self::freeVars($node['body'], $node['params']);
        // Filter out runtime helpers and built-in names
        $free = array_filter($free, function ($v) {
            return !str_starts_with($v, '__') && $v !== 'NaN' && $v !== 'Infinity';
        });

        // For declarations, the function's own name is needed for recursion
        // It will be captured by reference via use(&$name)

        $useClause = '';
        if (!empty($free)) {
            $useVars = array_map(fn($v) => '&$' . $v, array_values($free));
            $useClause = ' use (' . implode(', ', $useVars) . ')';
        }

        if ($isDecl && $node['id'] !== null) {
            $out = '$' . $node['id']['name'] . ' = function (' . $params . ')' . $useClause . ' ';
            $out .= self::_emitBodyWithCheck($node['body']);
            return rtrim($out) . ";\n";
        }

        // Function expression (unnamed or named)
        $name = '';
        if ($node['id'] !== null) {
            $name = $node['id']['name'];
        }
        $out = 'function ' . $name . '(' . $params . ')' . $useClause . ' ';
        $out .= self::_emitBodyWithCheck($node['body']);
        return $out;
    }

    private static function _emitArrow(array $node): string
    {
        $params = self::_emitParams($node['params']);

        // Free variable analysis
        $free = self::freeVars(
            $node['body']['type'] === 'BlockStatement' ? $node['body'] : ['type' => 'ReturnStatement', 'argument' => $node['body']],
            $node['params']
        );
        $free = array_filter($free, function ($v) {
            return !str_starts_with($v, '__') && $v !== 'NaN' && $v !== 'Infinity';
        });
        $useClause = '';
        if (!empty($free)) {
            $useVars = array_map(fn($v) => '&$' . $v, $free);
            $useClause = ' use (' . implode(', ', $useVars) . ')';
        }

        if (($node['expression'] ?? false) && ($node['body']['type'] ?? '') !== 'BlockStatement') {
            // Concise body: => expr  →  function() use(...) { __check(); return expr; }
            return 'function (' . $params . ')' . $useClause . " {\n__check();\nreturn " . self::_emit($node['body']) . ";\n}\n";
        }
        return 'function (' . $params . ')' . $useClause . ' ' . self::_emitBodyWithCheck($node['body']);
    }

    private static function _emitParams(array $params): string
    {
        $parts = [];
        foreach ($params as $param) {
            if ($param['type'] === 'RestElement') {
                $parts[] = '...' . self::_emitPattern($param['argument']);
            } elseif ($param['type'] === 'AssignmentPattern') {
                $parts[] = self::_emitPattern($param['left']) . ' = ' . self::_emit($param['right']);
            } else {
                $parts[] = self::_emitPattern($param);
            }
        }
        return implode(', ', $parts);
    }

    private static function _emitObjKey(array $prop): string
    {
        if ($prop['computed'] ?? false) {
            return self::_emit($prop['key']);
        }
        if (($prop['key']['type'] ?? '') === 'Identifier') {
            return var_export($prop['key']['name'], true);
        }
        if (($prop['key']['type'] ?? '') === 'Literal') {
            if (is_string($prop['key']['value'])) {
                return var_export($prop['key']['value'], true);
            }
            return var_export($prop['key']['value'], true);
        }
        return var_export($prop['key']['name'] ?? '', true);
    }
}
