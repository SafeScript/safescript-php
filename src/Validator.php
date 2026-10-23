<?php
/**
 * SafeScript AST validator — PHP port of validate.js.
 * Walks the AST and rejects blocked identifiers and property access.
 */

namespace SafeScript;

class ValidationError extends \Exception
{
    public ?int $sourceLine;
    public ?int $sourceCol;

    public function __construct(string $message = '', ?int $line = null, ?int $col = null)
    {
        parent::__construct($message);
        $this->sourceLine = $line;
        $this->sourceCol = $col;
    }
}

class Validator
{
    public static function validate(array $ast): void
    {
        self::walk($ast);
    }

    private static function walk(array $node): void
    {
        $type = $node['type'] ?? null;
        $loc = $node['loc'] ?? ['line' => 0, 'col' => 0];

        switch ($type) {
            case 'Program':
                foreach ($node['body'] as $stmt) self::walk($stmt);
                break;

            case 'Identifier':
                if (isset(Defs::BLOCKED[$node['name']])) {
                    throw new ValidationError(
                        "Blocked identifier \"{$node['name']}\" at line {$loc['line']}, col {$loc['col']}",
                        $loc['line'], $loc['col']
                    );
                }
                break;

            case 'Literal':
                break;

            case 'ExpressionStatement':
                self::walk($node['expression']);
                break;

            case 'BlockStatement':
                foreach ($node['body'] as $stmt) self::walk($stmt);
                break;

            case 'EmptyStatement':
                break;

            case 'ReturnStatement':
            case 'ThrowStatement':
                if ($node['argument'] !== null) self::walk($node['argument']);
                break;

            case 'IfStatement':
                self::walk($node['test']);
                self::walk($node['consequent']);
                if ($node['alternate'] !== null) self::walk($node['alternate']);
                break;

            case 'WhileStatement':
                self::walk($node['test']);
                self::walk($node['body']);
                break;

            case 'DoWhileStatement':
                self::walk($node['body']);
                self::walk($node['test']);
                break;

            case 'ForStatement':
                if ($node['init'] !== null) self::walk($node['init']);
                if ($node['test'] !== null) self::walk($node['test']);
                if ($node['update'] !== null) self::walk($node['update']);
                self::walk($node['body']);
                break;

            case 'ForInStatement':
            case 'ForOfStatement':
                self::walk($node['left']);
                self::walk($node['right']);
                self::walk($node['body']);
                break;

            case 'VariableDeclaration':
                foreach ($node['declarations'] as $decl) self::walk($decl);
                break;

            case 'VariableDeclarator':
                self::walkPattern($node['id']);
                if ($node['init'] !== null) self::walk($node['init']);
                break;

            case 'FunctionDeclaration':
            case 'FunctionExpression':
            case 'ArrowFunctionExpression':
                if (isset($node['id']) && $node['id'] !== null) {
                    self::walkPattern($node['id']);
                }
                foreach ($node['params'] as $param) self::walkPattern($param);
                self::walk($node['body']);
                break;

            case 'BinaryExpression':
            case 'LogicalExpression':
                self::walk($node['left']);
                self::walk($node['right']);
                break;

            case 'UnaryExpression':
                self::walk($node['argument']);
                break;

            case 'UpdateExpression':
                self::walk($node['argument']);
                break;

            case 'AssignmentExpression':
                self::walk($node['left']);
                self::walk($node['right']);
                break;

            case 'ConditionalExpression':
                self::walk($node['test']);
                self::walk($node['consequent']);
                self::walk($node['alternate']);
                break;

            case 'CallExpression':
                self::walk($node['callee']);
                foreach ($node['arguments'] as $arg) self::walk($arg);
                break;

            case 'MemberExpression':
                self::walk($node['object']);
                if (!$node['computed']) {
                    // Dot access: check blocked props
                    $propName = $node['property']['name'] ?? null;
                    if ($propName !== null && isset(Defs::BLOCKED_PROPS[$propName])) {
                        $ploc = $node['property']['loc'] ?? $loc;
                        throw new ValidationError(
                            "Blocked property \"{$propName}\" at line {$ploc['line']}, col {$ploc['col']}",
                            $ploc['line'], $ploc['col']
                        );
                    }
                } else {
                    self::walk($node['property']);
                }
                break;

            case 'ArrayExpression':
                foreach ($node['elements'] as $elem) {
                    if ($elem !== null) self::walk($elem);
                }
                break;

            case 'ObjectExpression':
                foreach ($node['properties'] as $prop) {
                    if ($prop['type'] === 'SpreadElement') {
                        self::walk($prop);
                    } else {
                        if ($prop['computed'] ?? false) self::walk($prop['key']);
                        self::walk($prop['value']);
                    }
                }
                break;

            case 'SpreadElement':
                self::walk($node['argument']);
                break;

            case 'SequenceExpression':
                foreach ($node['expressions'] as $expr) self::walk($expr);
                break;

            case 'TryStatement':
                self::walk($node['block']);
                if ($node['handler'] !== null) {
                    if ($node['handler']['param'] !== null) {
                        self::walkPattern($node['handler']['param']);
                    }
                    self::walk($node['handler']['body']);
                }
                if ($node['finalizer'] !== null) self::walk($node['finalizer']);
                break;

            case 'SwitchStatement':
                self::walk($node['discriminant']);
                foreach ($node['cases'] as $case) {
                    if ($case['test'] !== null) self::walk($case['test']);
                    foreach ($case['consequent'] as $stmt) self::walk($stmt);
                }
                break;

            case 'LabeledStatement':
                self::walk($node['body']);
                break;

            case 'BreakStatement':
            case 'ContinueStatement':
                break;

            default:
                // Unknown node type — ignore
                break;
        }
    }

    private static function walkPattern(array $node): void
    {
        $type = $node['type'] ?? null;
        $loc = $node['loc'] ?? ['line' => 0, 'col' => 0];

        switch ($type) {
            case 'Identifier':
                $name = $node['name'];
                if ($name === 'eval' || $name === 'arguments') {
                    throw new ValidationError(
                        "Cannot bind \"{$name}\" at line {$loc['line']}, col {$loc['col']}",
                        $loc['line'], $loc['col']
                    );
                }
                break;

            case 'ArrayPattern':
                foreach ($node['elements'] as $elem) {
                    if ($elem !== null) self::walkPattern($elem);
                }
                break;

            case 'ObjectPattern':
                foreach ($node['properties'] as $prop) {
                    if ($prop['type'] === 'RestElement') {
                        self::walkPattern($prop['argument']);
                    } else {
                        self::walkPattern($prop['value']);
                    }
                }
                break;

            case 'AssignmentPattern':
                self::walkPattern($node['left']);
                self::walk($node['right']);
                break;

            case 'RestElement':
                self::walkPattern($node['argument']);
                break;
        }
    }
}
