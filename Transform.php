<?php
/**
 * SafeScript AST transform — PHP port of transform.js.
 * Rewrites bracket access: obj[key] → __at(obj, key), obj[key] = val → __set(obj, key, val)
 */

namespace SafeScript;

class Transform
{
    public static function transform(array $node): array
    {
        return self::visit($node);
    }

    private static function visit(array $node): array
    {
        $type = $node['type'] ?? null;

        // ── Compound patterns (check BEFORE recursing) ──

        // obj[key] = val → __set(obj, key, val)
        if ($type === 'AssignmentExpression' && ($node['left']['type'] ?? '') === 'MemberExpression' && ($node['left']['computed'] ?? false)) {
            $mem = $node['left'];
            $obj = self::visit($mem['object']);
            $key = self::visit($mem['property']);
            $val = self::visit($node['right']);
            $op = $node['operator'];

            if ($op === '=') {
                // obj[key] = val → __set(obj, key, val)
                return self::callNode('__set', [$obj, $key, $val], $node['loc']);
            }
            // obj[key] += val → __set(obj, key, __at(obj, key) OP val)
            $baseOp = substr($op, 0, -1); // strip '='
            $atCall = self::callNode('__at', [$obj, $key], $mem['loc']);
            $binExpr = [
                'type' => 'BinaryExpression',
                'operator' => $baseOp,
                'left' => $atCall,
                'right' => $val,
                'loc' => $node['loc'],
            ];
            return self::callNode('__set', [$obj, $key, $binExpr], $node['loc']);
        }

        // obj[key]++ / obj[key]-- → __set(obj, key, __at(obj, key) +/- 1)
        if ($type === 'UpdateExpression' && ($node['argument']['type'] ?? '') === 'MemberExpression' && ($node['argument']['computed'] ?? false)) {
            $mem = $node['argument'];
            $obj = self::visit($mem['object']);
            $key = self::visit($mem['property']);
            $op = $node['operator'] === '++' ? '+' : '-';
            $atCall = self::callNode('__at', [$obj, $key], $mem['loc']);
            $one = ['type' => 'Literal', 'value' => 1, 'raw' => '1', 'loc' => $node['loc']];
            $binExpr = [
                'type' => 'BinaryExpression',
                'operator' => $op,
                'left' => $atCall,
                'right' => $one,
                'loc' => $node['loc'],
            ];
            return self::callNode('__set', [$obj, $key, $binExpr], $node['loc']);
        }

        // obj.name = val — handled in emitter (needs PHP bracket lvalue for nested reference)
        // obj.name++ / obj.name-- — handled in emitter

        // ── ThisExpression → Identifier __safeThis ──
        if ($type === 'ThisExpression') {
            return ['type' => 'Identifier', 'name' => '__safeThis', 'loc' => $node['loc'] ?? ['line' => 0, 'col' => 0]];
        }

        // ── Recurse into children ──
        $node = self::recurse($node);

        // ── Post-recursion: obj[key] read → __at(obj, key) ──
        if ($type === 'MemberExpression' && ($node['computed'] ?? false) && !($node['optional'] ?? false)) {
            $obj = $node['object'];
            $key = $node['property'];
            return self::callNode('__at', [$obj, $key], $node['loc']);
        }

        return $node;
    }

    private static function recurse(array $node): array
    {
        $type = $node['type'] ?? null;

        switch ($type) {
            case 'Program':
            case 'BlockStatement':
                $node['body'] = array_map([self::class, 'visit'], $node['body']);
                break;

            case 'ExpressionStatement':
                $node['expression'] = self::visit($node['expression']);
                break;

            case 'ReturnStatement':
            case 'ThrowStatement':
                if ($node['argument'] !== null) $node['argument'] = self::visit($node['argument']);
                break;

            case 'IfStatement':
                $node['test'] = self::visit($node['test']);
                $node['consequent'] = self::visit($node['consequent']);
                if ($node['alternate'] !== null) $node['alternate'] = self::visit($node['alternate']);
                break;

            case 'WhileStatement':
                $node['test'] = self::visit($node['test']);
                $node['body'] = self::visit($node['body']);
                break;

            case 'DoWhileStatement':
                $node['body'] = self::visit($node['body']);
                $node['test'] = self::visit($node['test']);
                break;

            case 'ForStatement':
                if ($node['init'] !== null) $node['init'] = self::visit($node['init']);
                if ($node['test'] !== null) $node['test'] = self::visit($node['test']);
                if ($node['update'] !== null) $node['update'] = self::visit($node['update']);
                $node['body'] = self::visit($node['body']);
                break;

            case 'ForInStatement':
            case 'ForOfStatement':
                $node['left'] = self::visit($node['left']);
                $node['right'] = self::visit($node['right']);
                $node['body'] = self::visit($node['body']);
                break;

            case 'VariableDeclaration':
                $node['declarations'] = array_map([self::class, 'visit'], $node['declarations']);
                break;

            case 'VariableDeclarator':
                if ($node['init'] !== null) $node['init'] = self::visit($node['init']);
                break;

            case 'FunctionDeclaration':
            case 'FunctionExpression':
            case 'ArrowFunctionExpression':
                $node['body'] = self::visit($node['body']);
                break;

            case 'BinaryExpression':
            case 'LogicalExpression':
            case 'AssignmentExpression':
                $node['left'] = self::visit($node['left']);
                $node['right'] = self::visit($node['right']);
                break;

            case 'UnaryExpression':
            case 'UpdateExpression':
            case 'AwaitExpression':
                $node['argument'] = self::visit($node['argument']);
                break;

            case 'ConditionalExpression':
                $node['test'] = self::visit($node['test']);
                $node['consequent'] = self::visit($node['consequent']);
                $node['alternate'] = self::visit($node['alternate']);
                break;

            case 'CallExpression':
                $node['callee'] = self::visit($node['callee']);
                $node['arguments'] = array_map([self::class, 'visit'], $node['arguments']);
                break;

            case 'MemberExpression':
                $node['object'] = self::visit($node['object']);
                if ($node['computed']) $node['property'] = self::visit($node['property']);
                break;

            case 'ArrayExpression':
                $node['elements'] = array_map(function ($e) {
                    return $e !== null ? self::visit($e) : null;
                }, $node['elements']);
                break;

            case 'ObjectExpression':
                $node['properties'] = array_map(function ($p) {
                    if ($p['type'] === 'SpreadElement') {
                        return self::visit($p);
                    }
                    if ($p['computed'] ?? false) $p['key'] = self::visit($p['key']);
                    $p['value'] = self::visit($p['value']);
                    return $p;
                }, $node['properties']);
                break;

            case 'SpreadElement':
                $node['argument'] = self::visit($node['argument']);
                break;

            case 'SequenceExpression':
                $node['expressions'] = array_map([self::class, 'visit'], $node['expressions']);
                break;

            case 'TryStatement':
                $node['block'] = self::visit($node['block']);
                if ($node['handler'] !== null) {
                    $node['handler']['body'] = self::visit($node['handler']['body']);
                }
                if ($node['finalizer'] !== null) $node['finalizer'] = self::visit($node['finalizer']);
                break;

            case 'SwitchStatement':
                $node['discriminant'] = self::visit($node['discriminant']);
                $node['cases'] = array_map(function ($c) {
                    if ($c['test'] !== null) $c['test'] = self::visit($c['test']);
                    $c['consequent'] = array_map([self::class, 'visit'], $c['consequent']);
                    return $c;
                }, $node['cases']);
                break;

            case 'LabeledStatement':
                $node['body'] = self::visit($node['body']);
                break;
        }

        return $node;
    }

    private static function callNode(string $name, array $args, array $loc): array
    {
        return [
            'type' => 'CallExpression',
            'callee' => [
                'type' => 'Identifier',
                'name' => $name,
                'loc' => $loc,
            ],
            'arguments' => $args,
            'loc' => $loc,
        ];
    }
}
