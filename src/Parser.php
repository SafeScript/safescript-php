<?php
/**
 * SafeScript recursive descent parser — PHP port of parse.js Parser class.
 * Produces an ESTree-like AST from a token stream.
 */

namespace SafeScript;

class Parser
{
    /** @var array<array{type:string,value:string,line:int,col:int}> */
    private array $tokens;
    private int $pos;

    public function __construct(array $tokens)
    {
        $this->tokens = $tokens;
        $this->pos = 0;
    }

    // ── Helpers ──────────────────────────────────────────────────

    private function cur(): array { return $this->tokens[$this->pos]; }
    private function tt(): string { return $this->cur()['type']; }
    private function tv(): string { return $this->cur()['value']; }

    private function eat(string $type, ?string $value = null): array
    {
        $tok = $this->cur();
        if ($tok['type'] !== $type || ($value !== null && $tok['value'] !== $value)) {
            $this->unexpected($tok);
        }
        $this->pos++;
        return $tok;
    }

    private function match(string $type, ?string $value = null): bool
    {
        $tok = $this->cur();
        if ($tok['type'] !== $type) return false;
        return $value === null || $tok['value'] === $value;
    }

    private function expect(string $type, string $value): array
    {
        return $this->eat($type, $value);
    }

    private function unexpected(array $tok): void
    {
        throw new SyntaxErrorEx(
            "Unexpected {$tok['type']} '{$tok['value']}' at line {$tok['line']}, col {$tok['col']}",
            $tok['line'],
            $tok['col']
        );
    }

    private function node(string $type, int $line, int $col): array
    {
        return ['type' => $type, 'loc' => ['line' => $line, 'col' => $col]];
    }

    // ── Parse entry point ───────────────────────────────────────

    public function parse(): array
    {
        $line = $this->cur()['line'];
        $col  = $this->cur()['col'];
        $body = [];
        while ($this->tt() !== Tokenizer::T_EOF) {
            $body[] = $this->_parseStatement();
        }
        $n = $this->node('Program', $line, $col);
        $n['body'] = $body;
        return $n;
    }

    // ── Statements ──────────────────────────────────────────────

    private function _parseStatement(): array
    {
        $tok = $this->cur();
        $t = $tok['type'];
        $v = $tok['value'];

        if ($t === Tokenizer::T_KEYWORD) {
            switch ($v) {
                case 'var': case 'let': case 'const': return $this->_parseVarDecl();
                case 'if':       return $this->_parseIf();
                case 'for':      return $this->_parseFor();
                case 'while':    return $this->_parseWhile();
                case 'do':       return $this->_parseDoWhile();
                case 'return':   return $this->_parseReturn();
                case 'throw':    return $this->_parseThrow();
                case 'try':      return $this->_parseTry();
                case 'switch':   return $this->_parseSwitch();
                case 'break':    return $this->_parseBreak();
                case 'continue': return $this->_parseContinue();
                case 'function': return $this->_parseFunctionDecl();
                case 'async':
                    if ($this->_peekIs(Tokenizer::T_KEYWORD, 'function')) {
                        return $this->_parseFunctionDecl();
                    }
                    break;
            }
        }
        if ($t === Tokenizer::T_PUNCT && $v === '{') return $this->_parseBlock();
        if ($t === Tokenizer::T_PUNCT && $v === ';') return $this->_parseEmpty();

        // Labeled statement: IDENT ':'
        if ($t === Tokenizer::T_IDENT && $this->_peekIs(Tokenizer::T_PUNCT, ':')) {
            return $this->_parseLabeled();
        }

        return $this->_parseExprStatement();
    }

    private function _peekIs(string $type, ?string $value = null): bool
    {
        $next = $this->pos + 1 < count($this->tokens) ? $this->tokens[$this->pos + 1] : null;
        if (!$next || $next['type'] !== $type) return false;
        return $value === null || $next['value'] === $value;
    }

    private function _parseBlock(): array
    {
        $tok = $this->eat(Tokenizer::T_PUNCT, '{');
        $body = [];
        while (!$this->match(Tokenizer::T_PUNCT, '}')) {
            $body[] = $this->_parseStatement();
        }
        $this->eat(Tokenizer::T_PUNCT, '}');
        $n = $this->node('BlockStatement', $tok['line'], $tok['col']);
        $n['body'] = $body;
        return $n;
    }

    private function _parseEmpty(): array
    {
        $tok = $this->eat(Tokenizer::T_PUNCT, ';');
        return $this->node('EmptyStatement', $tok['line'], $tok['col']);
    }

    private function _parseVarDecl(): array
    {
        $tok = $this->cur();
        $kind = $this->eat(Tokenizer::T_KEYWORD)['value'];
        $decls = [];
        $decls[] = $this->_parseDeclarator();
        while ($this->match(Tokenizer::T_PUNCT, ',')) {
            $this->eat(Tokenizer::T_PUNCT, ',');
            $decls[] = $this->_parseDeclarator();
        }
        $this->_eatSemi();
        $n = $this->node('VariableDeclaration', $tok['line'], $tok['col']);
        $n['kind'] = $kind;
        $n['declarations'] = $decls;
        return $n;
    }

    private function _parseVarDeclNoSemi(): array
    {
        $tok = $this->cur();
        $kind = $this->eat(Tokenizer::T_KEYWORD)['value'];
        $decls = [];
        $decls[] = $this->_parseDeclarator();
        while ($this->match(Tokenizer::T_PUNCT, ',')) {
            $this->eat(Tokenizer::T_PUNCT, ',');
            $decls[] = $this->_parseDeclarator();
        }
        $n = $this->node('VariableDeclaration', $tok['line'], $tok['col']);
        $n['kind'] = $kind;
        $n['declarations'] = $decls;
        return $n;
    }

    private function _parseDeclarator(): array
    {
        $tok = $this->cur();
        $id = $this->_parsePattern();
        $init = null;
        if ($this->match(Tokenizer::T_PUNCT, '=')) {
            $this->eat(Tokenizer::T_PUNCT, '=');
            $init = $this->_parseAssignment();
        }
        $n = $this->node('VariableDeclarator', $tok['line'], $tok['col']);
        $n['id'] = $id;
        $n['init'] = $init;
        return $n;
    }

    private function _parseIf(): array
    {
        $tok = $this->eat(Tokenizer::T_KEYWORD, 'if');
        $this->eat(Tokenizer::T_PUNCT, '(');
        $test = $this->_parseExpression();
        $this->eat(Tokenizer::T_PUNCT, ')');
        $consequent = $this->_parseStatement();
        $alternate = null;
        if ($this->match(Tokenizer::T_KEYWORD, 'else')) {
            $this->eat(Tokenizer::T_KEYWORD, 'else');
            $alternate = $this->_parseStatement();
        }
        $n = $this->node('IfStatement', $tok['line'], $tok['col']);
        $n['test'] = $test;
        $n['consequent'] = $consequent;
        $n['alternate'] = $alternate;
        return $n;
    }

    private function _parseFor(): array
    {
        $tok = $this->eat(Tokenizer::T_KEYWORD, 'for');
        $this->eat(Tokenizer::T_PUNCT, '(');

        // for (var/let/const ... in/of ...)
        if ($this->match(Tokenizer::T_KEYWORD, 'var') || $this->match(Tokenizer::T_KEYWORD, 'let') || $this->match(Tokenizer::T_KEYWORD, 'const')) {
            $save = $this->pos;
            $decl = $this->_parseVarDeclNoSemi();
            if ($this->match(Tokenizer::T_KEYWORD, 'in')) {
                $this->eat(Tokenizer::T_KEYWORD, 'in');
                $right = $this->_parseExpression();
                $this->eat(Tokenizer::T_PUNCT, ')');
                $body = $this->_parseStatement();
                $n = $this->node('ForInStatement', $tok['line'], $tok['col']);
                $n['left'] = $decl;
                $n['right'] = $right;
                $n['body'] = $body;
                return $n;
            }
            if ($this->match(Tokenizer::T_KEYWORD, 'of')) {
                $this->eat(Tokenizer::T_KEYWORD, 'of');
                $right = $this->_parseAssignment();
                $this->eat(Tokenizer::T_PUNCT, ')');
                $body = $this->_parseStatement();
                $n = $this->node('ForOfStatement', $tok['line'], $tok['col']);
                $n['left'] = $decl;
                $n['right'] = $right;
                $n['body'] = $body;
                return $n;
            }
            // regular for
            $init = $decl;
            $this->eat(Tokenizer::T_PUNCT, ';');
            $test = $this->match(Tokenizer::T_PUNCT, ';') ? null : $this->_parseExpression();
            $this->eat(Tokenizer::T_PUNCT, ';');
            $update = $this->match(Tokenizer::T_PUNCT, ')') ? null : $this->_parseExpression();
            $this->eat(Tokenizer::T_PUNCT, ')');
            $body = $this->_parseStatement();
            $n = $this->node('ForStatement', $tok['line'], $tok['col']);
            $n['init'] = $init;
            $n['test'] = $test;
            $n['update'] = $update;
            $n['body'] = $body;
            return $n;
        }

        // for (expr; ...) or for (expr in/of ...)
        $init = $this->match(Tokenizer::T_PUNCT, ';') ? null : $this->_parseExpression();
        if ($init !== null && $this->match(Tokenizer::T_KEYWORD, 'in')) {
            $this->eat(Tokenizer::T_KEYWORD, 'in');
            $right = $this->_parseExpression();
            $this->eat(Tokenizer::T_PUNCT, ')');
            $body = $this->_parseStatement();
            $n = $this->node('ForInStatement', $tok['line'], $tok['col']);
            $n['left'] = $init;
            $n['right'] = $right;
            $n['body'] = $body;
            return $n;
        }
        if ($init !== null && $this->match(Tokenizer::T_KEYWORD, 'of')) {
            $this->eat(Tokenizer::T_KEYWORD, 'of');
            $right = $this->_parseAssignment();
            $this->eat(Tokenizer::T_PUNCT, ')');
            $body = $this->_parseStatement();
            $n = $this->node('ForOfStatement', $tok['line'], $tok['col']);
            $n['left'] = $init;
            $n['right'] = $right;
            $n['body'] = $body;
            return $n;
        }

        $this->eat(Tokenizer::T_PUNCT, ';');
        $test = $this->match(Tokenizer::T_PUNCT, ';') ? null : $this->_parseExpression();
        $this->eat(Tokenizer::T_PUNCT, ';');
        $update = $this->match(Tokenizer::T_PUNCT, ')') ? null : $this->_parseExpression();
        $this->eat(Tokenizer::T_PUNCT, ')');
        $body = $this->_parseStatement();
        $n = $this->node('ForStatement', $tok['line'], $tok['col']);
        $n['init'] = $init;
        $n['test'] = $test;
        $n['update'] = $update;
        $n['body'] = $body;
        return $n;
    }

    private function _parseWhile(): array
    {
        $tok = $this->eat(Tokenizer::T_KEYWORD, 'while');
        $this->eat(Tokenizer::T_PUNCT, '(');
        $test = $this->_parseExpression();
        $this->eat(Tokenizer::T_PUNCT, ')');
        $body = $this->_parseStatement();
        $n = $this->node('WhileStatement', $tok['line'], $tok['col']);
        $n['test'] = $test;
        $n['body'] = $body;
        return $n;
    }

    private function _parseDoWhile(): array
    {
        $tok = $this->eat(Tokenizer::T_KEYWORD, 'do');
        $body = $this->_parseStatement();
        $this->eat(Tokenizer::T_KEYWORD, 'while');
        $this->eat(Tokenizer::T_PUNCT, '(');
        $test = $this->_parseExpression();
        $this->eat(Tokenizer::T_PUNCT, ')');
        $this->_eatSemi();
        $n = $this->node('DoWhileStatement', $tok['line'], $tok['col']);
        $n['body'] = $body;
        $n['test'] = $test;
        return $n;
    }

    private function _parseReturn(): array
    {
        $tok = $this->eat(Tokenizer::T_KEYWORD, 'return');
        $arg = null;
        if (!$this->match(Tokenizer::T_PUNCT, ';') && !$this->match(Tokenizer::T_PUNCT, '}') && $this->tt() !== Tokenizer::T_EOF) {
            // Check if next token is on the same line (ASI)
            if ($this->cur()['line'] === $tok['line']) {
                $arg = $this->_parseExpression();
            }
        }
        $this->_eatSemi();
        $n = $this->node('ReturnStatement', $tok['line'], $tok['col']);
        $n['argument'] = $arg;
        return $n;
    }

    private function _parseThrow(): array
    {
        $tok = $this->eat(Tokenizer::T_KEYWORD, 'throw');
        $arg = $this->_parseExpression();
        $this->_eatSemi();
        $n = $this->node('ThrowStatement', $tok['line'], $tok['col']);
        $n['argument'] = $arg;
        return $n;
    }

    private function _parseTry(): array
    {
        $tok = $this->eat(Tokenizer::T_KEYWORD, 'try');
        $block = $this->_parseBlock();
        $handler = null;
        $finalizer = null;
        if ($this->match(Tokenizer::T_KEYWORD, 'catch')) {
            $ctok = $this->eat(Tokenizer::T_KEYWORD, 'catch');
            $param = null;
            if ($this->match(Tokenizer::T_PUNCT, '(')) {
                $this->eat(Tokenizer::T_PUNCT, '(');
                $param = $this->_parsePattern();
                $this->eat(Tokenizer::T_PUNCT, ')');
            }
            $cbody = $this->_parseBlock();
            $handler = $this->node('CatchClause', $ctok['line'], $ctok['col']);
            $handler['param'] = $param;
            $handler['body'] = $cbody;
        }
        if ($this->match(Tokenizer::T_KEYWORD, 'finally')) {
            $this->eat(Tokenizer::T_KEYWORD, 'finally');
            $finalizer = $this->_parseBlock();
        }
        $n = $this->node('TryStatement', $tok['line'], $tok['col']);
        $n['block'] = $block;
        $n['handler'] = $handler;
        $n['finalizer'] = $finalizer;
        return $n;
    }

    private function _parseSwitch(): array
    {
        $tok = $this->eat(Tokenizer::T_KEYWORD, 'switch');
        $this->eat(Tokenizer::T_PUNCT, '(');
        $disc = $this->_parseExpression();
        $this->eat(Tokenizer::T_PUNCT, ')');
        $this->eat(Tokenizer::T_PUNCT, '{');
        $cases = [];
        while (!$this->match(Tokenizer::T_PUNCT, '}')) {
            if ($this->match(Tokenizer::T_KEYWORD, 'case')) {
                $ctok = $this->eat(Tokenizer::T_KEYWORD, 'case');
                $test = $this->_parseExpression();
                $this->eat(Tokenizer::T_PUNCT, ':');
                $cons = [];
                while (!$this->match(Tokenizer::T_KEYWORD, 'case') && !$this->match(Tokenizer::T_KEYWORD, 'default') && !$this->match(Tokenizer::T_PUNCT, '}')) {
                    $cons[] = $this->_parseStatement();
                }
                $c = $this->node('SwitchCase', $ctok['line'], $ctok['col']);
                $c['test'] = $test;
                $c['consequent'] = $cons;
                $cases[] = $c;
            } elseif ($this->match(Tokenizer::T_KEYWORD, 'default')) {
                $ctok = $this->eat(Tokenizer::T_KEYWORD, 'default');
                $this->eat(Tokenizer::T_PUNCT, ':');
                $cons = [];
                while (!$this->match(Tokenizer::T_KEYWORD, 'case') && !$this->match(Tokenizer::T_KEYWORD, 'default') && !$this->match(Tokenizer::T_PUNCT, '}')) {
                    $cons[] = $this->_parseStatement();
                }
                $c = $this->node('SwitchCase', $ctok['line'], $ctok['col']);
                $c['test'] = null;
                $c['consequent'] = $cons;
                $cases[] = $c;
            } else {
                $this->unexpected($this->cur());
            }
        }
        $this->eat(Tokenizer::T_PUNCT, '}');
        $n = $this->node('SwitchStatement', $tok['line'], $tok['col']);
        $n['discriminant'] = $disc;
        $n['cases'] = $cases;
        return $n;
    }

    private function _parseBreak(): array
    {
        $tok = $this->eat(Tokenizer::T_KEYWORD, 'break');
        $label = null;
        if ($this->tt() === Tokenizer::T_IDENT && $this->cur()['line'] === $tok['line']) {
            $label = $this->_parseIdentifier();
        }
        $this->_eatSemi();
        $n = $this->node('BreakStatement', $tok['line'], $tok['col']);
        $n['label'] = $label;
        return $n;
    }

    private function _parseContinue(): array
    {
        $tok = $this->eat(Tokenizer::T_KEYWORD, 'continue');
        $label = null;
        if ($this->tt() === Tokenizer::T_IDENT && $this->cur()['line'] === $tok['line']) {
            $label = $this->_parseIdentifier();
        }
        $this->_eatSemi();
        $n = $this->node('ContinueStatement', $tok['line'], $tok['col']);
        $n['label'] = $label;
        return $n;
    }

    private function _parseFunctionDecl(): array
    {
        $tok = $this->cur();
        $async = false;
        if ($this->match(Tokenizer::T_KEYWORD, 'async')) {
            $async = true;
            $this->eat(Tokenizer::T_KEYWORD, 'async');
        }
        $this->eat(Tokenizer::T_KEYWORD, 'function');
        $id = $this->_parseIdentifier();
        $this->eat(Tokenizer::T_PUNCT, '(');
        $params = $this->_parseParams();
        $this->eat(Tokenizer::T_PUNCT, ')');
        $body = $this->_parseBlock();
        $n = $this->node('FunctionDeclaration', $tok['line'], $tok['col']);
        $n['id'] = $id;
        $n['params'] = $params;
        $n['body'] = $body;
        $n['async'] = $async;
        return $n;
    }

    private function _parseLabeled(): array
    {
        $tok = $this->cur();
        $label = $this->_parseIdentifier();
        $this->eat(Tokenizer::T_PUNCT, ':');
        $body = $this->_parseStatement();
        $n = $this->node('LabeledStatement', $tok['line'], $tok['col']);
        $n['label'] = $label;
        $n['body'] = $body;
        return $n;
    }

    private function _parseExprStatement(): array
    {
        $tok = $this->cur();
        $expr = $this->_parseExpression();
        $this->_eatSemi();
        $n = $this->node('ExpressionStatement', $tok['line'], $tok['col']);
        $n['expression'] = $expr;
        return $n;
    }

    // ── Parameters ──────────────────────────────────────────────

    private function _parseParams(): array
    {
        $params = [];
        while (!$this->match(Tokenizer::T_PUNCT, ')')) {
            if (!empty($params)) $this->eat(Tokenizer::T_PUNCT, ',');
            if ($this->match(Tokenizer::T_PUNCT, ')')) break;
            if ($this->match(Tokenizer::T_PUNCT, '...')) {
                $tok = $this->eat(Tokenizer::T_PUNCT, '...');
                $arg = $this->_parsePattern();
                $rest = $this->node('RestElement', $tok['line'], $tok['col']);
                $rest['argument'] = $arg;
                $params[] = $rest;
                break;
            }
            $param = $this->_parsePattern();
            if ($this->match(Tokenizer::T_PUNCT, '=')) {
                $this->eat(Tokenizer::T_PUNCT, '=');
                $def = $this->_parseAssignment();
                $ap = $this->node('AssignmentPattern', $param['loc']['line'], $param['loc']['col']);
                $ap['left'] = $param;
                $ap['right'] = $def;
                $params[] = $ap;
            } else {
                $params[] = $param;
            }
        }
        return $params;
    }

    // ── Patterns (destructuring) ────────────────────────────────

    private function _parsePattern(): array
    {
        if ($this->match(Tokenizer::T_PUNCT, '[')) return $this->_parseArrayPattern();
        if ($this->match(Tokenizer::T_PUNCT, '{')) return $this->_parseObjectPattern();
        return $this->_parseIdentifier();
    }

    private function _parseArrayPattern(): array
    {
        $tok = $this->eat(Tokenizer::T_PUNCT, '[');
        $elements = [];
        while (!$this->match(Tokenizer::T_PUNCT, ']')) {
            if (!empty($elements) || $this->match(Tokenizer::T_PUNCT, ',')) {
                // handle elision
                if ($this->match(Tokenizer::T_PUNCT, ',') && (empty($elements) || true)) {
                    if ($this->match(Tokenizer::T_PUNCT, ',')) {
                        $this->eat(Tokenizer::T_PUNCT, ',');
                        if ($this->match(Tokenizer::T_PUNCT, ',') || $this->match(Tokenizer::T_PUNCT, ']')) {
                            $elements[] = null;
                            continue;
                        }
                    }
                } else {
                    $this->eat(Tokenizer::T_PUNCT, ',');
                }
            }
            if ($this->match(Tokenizer::T_PUNCT, ']')) break;
            if ($this->match(Tokenizer::T_PUNCT, '...')) {
                $rtok = $this->eat(Tokenizer::T_PUNCT, '...');
                $arg = $this->_parsePattern();
                $rest = $this->node('RestElement', $rtok['line'], $rtok['col']);
                $rest['argument'] = $arg;
                $elements[] = $rest;
                break;
            }
            $elem = $this->_parsePattern();
            if ($this->match(Tokenizer::T_PUNCT, '=')) {
                $this->eat(Tokenizer::T_PUNCT, '=');
                $def = $this->_parseAssignment();
                $ap = $this->node('AssignmentPattern', $elem['loc']['line'], $elem['loc']['col']);
                $ap['left'] = $elem;
                $ap['right'] = $def;
                $elements[] = $ap;
            } else {
                $elements[] = $elem;
            }
        }
        $this->eat(Tokenizer::T_PUNCT, ']');
        $n = $this->node('ArrayPattern', $tok['line'], $tok['col']);
        $n['elements'] = $elements;
        return $n;
    }

    private function _parseObjectPattern(): array
    {
        $tok = $this->eat(Tokenizer::T_PUNCT, '{');
        $props = [];
        while (!$this->match(Tokenizer::T_PUNCT, '}')) {
            if (!empty($props)) $this->eat(Tokenizer::T_PUNCT, ',');
            if ($this->match(Tokenizer::T_PUNCT, '}')) break;
            if ($this->match(Tokenizer::T_PUNCT, '...')) {
                $rtok = $this->eat(Tokenizer::T_PUNCT, '...');
                $arg = $this->_parsePattern();
                $rest = $this->node('RestElement', $rtok['line'], $rtok['col']);
                $rest['argument'] = $arg;
                $props[] = $rest;
                break;
            }
            $ptok = $this->cur();
            $computed = false;
            if ($this->match(Tokenizer::T_PUNCT, '[')) {
                $computed = true;
                $this->eat(Tokenizer::T_PUNCT, '[');
                $key = $this->_parseAssignment();
                $this->eat(Tokenizer::T_PUNCT, ']');
            } elseif ($this->tt() === Tokenizer::T_STRING || $this->tt() === Tokenizer::T_NUMBER) {
                $key = $this->_parseLiteral();
            } else {
                $key = $this->_parseIdentifier();
            }
            if ($this->match(Tokenizer::T_PUNCT, ':')) {
                $this->eat(Tokenizer::T_PUNCT, ':');
                $value = $this->_parsePattern();
                if ($this->match(Tokenizer::T_PUNCT, '=')) {
                    $this->eat(Tokenizer::T_PUNCT, '=');
                    $def = $this->_parseAssignment();
                    $ap = $this->node('AssignmentPattern', $value['loc']['line'], $value['loc']['col']);
                    $ap['left'] = $value;
                    $ap['right'] = $def;
                    $value = $ap;
                }
            } else {
                // shorthand: { x } or { x = default }
                $value = $key; // key IS the identifier
                if ($this->match(Tokenizer::T_PUNCT, '=')) {
                    $this->eat(Tokenizer::T_PUNCT, '=');
                    $def = $this->_parseAssignment();
                    $ap = $this->node('AssignmentPattern', $key['loc']['line'], $key['loc']['col']);
                    $ap['left'] = $key;
                    $ap['right'] = $def;
                    $value = $ap;
                }
            }
            $prop = $this->node('Property', $ptok['line'], $ptok['col']);
            $prop['key'] = $key;
            $prop['value'] = $value;
            $prop['computed'] = $computed;
            $prop['shorthand'] = ($key === $value || ($value['type'] === 'AssignmentPattern' && $value['left'] === $key));
            $props[] = $prop;
        }
        $this->eat(Tokenizer::T_PUNCT, '}');
        $n = $this->node('ObjectPattern', $tok['line'], $tok['col']);
        $n['properties'] = $props;
        return $n;
    }

    // ── Expressions (precedence climbing) ───────────────────────

    private function _parseExpression(): array
    {
        $expr = $this->_parseAssignment();
        if ($this->match(Tokenizer::T_PUNCT, ',')) {
            $exprs = [$expr];
            while ($this->match(Tokenizer::T_PUNCT, ',')) {
                $this->eat(Tokenizer::T_PUNCT, ',');
                $exprs[] = $this->_parseAssignment();
            }
            $n = $this->node('SequenceExpression', $expr['loc']['line'], $expr['loc']['col']);
            $n['expressions'] = $exprs;
            return $n;
        }
        return $expr;
    }

    private function _parseAssignment(): array
    {
        $left = $this->_parseConditional();
        if ($this->match(Tokenizer::T_PUNCT)) {
            $v = $this->tv();
            if (in_array($v, ['=', '+=', '-=', '*=', '/=', '%=', '**=', '<<=', '>>=', '>>>=', '&=', '|=', '^=', '&&=', '||=', '??='], true)) {
                $op = $this->eat(Tokenizer::T_PUNCT)['value'];
                $right = $this->_parseAssignment();
                $n = $this->node('AssignmentExpression', $left['loc']['line'], $left['loc']['col']);
                $n['operator'] = $op;
                $n['left'] = $left;
                $n['right'] = $right;
                return $n;
            }
        }
        return $left;
    }

    private function _parseConditional(): array
    {
        $test = $this->_parseNullishCoalescing();
        if ($this->match(Tokenizer::T_PUNCT, '?') && !$this->match(Tokenizer::T_PUNCT, '?.') && !$this->match(Tokenizer::T_PUNCT, '??')) {
            $this->eat(Tokenizer::T_PUNCT, '?');
            $consequent = $this->_parseAssignment();
            $this->eat(Tokenizer::T_PUNCT, ':');
            $alternate = $this->_parseAssignment();
            $n = $this->node('ConditionalExpression', $test['loc']['line'], $test['loc']['col']);
            $n['test'] = $test;
            $n['consequent'] = $consequent;
            $n['alternate'] = $alternate;
            return $n;
        }
        return $test;
    }

    private function _parseNullishCoalescing(): array
    {
        $left = $this->_parseLogicalOr();
        while ($this->match(Tokenizer::T_PUNCT, '??')) {
            $this->eat(Tokenizer::T_PUNCT, '??');
            $right = $this->_parseLogicalOr();
            $n = $this->node('LogicalExpression', $left['loc']['line'], $left['loc']['col']);
            $n['operator'] = '??';
            $n['left'] = $left;
            $n['right'] = $right;
            $left = $n;
        }
        return $left;
    }

    private function _parseLogicalOr(): array
    {
        $left = $this->_parseLogicalAnd();
        while ($this->match(Tokenizer::T_PUNCT, '||')) {
            $this->eat(Tokenizer::T_PUNCT, '||');
            $right = $this->_parseLogicalAnd();
            $n = $this->node('LogicalExpression', $left['loc']['line'], $left['loc']['col']);
            $n['operator'] = '||';
            $n['left'] = $left;
            $n['right'] = $right;
            $left = $n;
        }
        return $left;
    }

    private function _parseLogicalAnd(): array
    {
        $left = $this->_parseBitwiseOr();
        while ($this->match(Tokenizer::T_PUNCT, '&&')) {
            $this->eat(Tokenizer::T_PUNCT, '&&');
            $right = $this->_parseBitwiseOr();
            $n = $this->node('LogicalExpression', $left['loc']['line'], $left['loc']['col']);
            $n['operator'] = '&&';
            $n['left'] = $left;
            $n['right'] = $right;
            $left = $n;
        }
        return $left;
    }

    private function _parseBitwiseOr(): array
    {
        $left = $this->_parseBitwiseXor();
        while ($this->match(Tokenizer::T_PUNCT, '|') && !$this->_peekIs(Tokenizer::T_PUNCT, '|')) {
            $this->eat(Tokenizer::T_PUNCT, '|');
            $right = $this->_parseBitwiseXor();
            $n = $this->node('BinaryExpression', $left['loc']['line'], $left['loc']['col']);
            $n['operator'] = '|';
            $n['left'] = $left;
            $n['right'] = $right;
            $left = $n;
        }
        return $left;
    }

    private function _parseBitwiseXor(): array
    {
        $left = $this->_parseBitwiseAnd();
        while ($this->match(Tokenizer::T_PUNCT, '^')) {
            $this->eat(Tokenizer::T_PUNCT, '^');
            $right = $this->_parseBitwiseAnd();
            $n = $this->node('BinaryExpression', $left['loc']['line'], $left['loc']['col']);
            $n['operator'] = '^';
            $n['left'] = $left;
            $n['right'] = $right;
            $left = $n;
        }
        return $left;
    }

    private function _parseBitwiseAnd(): array
    {
        $left = $this->_parseEquality();
        while ($this->match(Tokenizer::T_PUNCT, '&') && !$this->_peekIs(Tokenizer::T_PUNCT, '&')) {
            $this->eat(Tokenizer::T_PUNCT, '&');
            $right = $this->_parseEquality();
            $n = $this->node('BinaryExpression', $left['loc']['line'], $left['loc']['col']);
            $n['operator'] = '&';
            $n['left'] = $left;
            $n['right'] = $right;
            $left = $n;
        }
        return $left;
    }

    private function _parseEquality(): array
    {
        $left = $this->_parseRelational();
        while ($this->match(Tokenizer::T_PUNCT) && in_array($this->tv(), ['==', '!=', '===', '!=='], true)) {
            $op = $this->eat(Tokenizer::T_PUNCT)['value'];
            $right = $this->_parseRelational();
            $n = $this->node('BinaryExpression', $left['loc']['line'], $left['loc']['col']);
            $n['operator'] = $op;
            $n['left'] = $left;
            $n['right'] = $right;
            $left = $n;
        }
        return $left;
    }

    private function _parseRelational(): array
    {
        $left = $this->_parseShift();
        while (true) {
            if ($this->match(Tokenizer::T_PUNCT) && in_array($this->tv(), ['<', '>', '<=', '>='], true)) {
                $op = $this->eat(Tokenizer::T_PUNCT)['value'];
                $right = $this->_parseShift();
                $n = $this->node('BinaryExpression', $left['loc']['line'], $left['loc']['col']);
                $n['operator'] = $op;
                $n['left'] = $left;
                $n['right'] = $right;
                $left = $n;
            } elseif ($this->match(Tokenizer::T_KEYWORD, 'instanceof')) {
                $this->eat(Tokenizer::T_KEYWORD, 'instanceof');
                $right = $this->_parseShift();
                $n = $this->node('BinaryExpression', $left['loc']['line'], $left['loc']['col']);
                $n['operator'] = 'instanceof';
                $n['left'] = $left;
                $n['right'] = $right;
                $left = $n;
            } elseif ($this->match(Tokenizer::T_KEYWORD, 'in')) {
                $this->eat(Tokenizer::T_KEYWORD, 'in');
                $right = $this->_parseShift();
                $n = $this->node('BinaryExpression', $left['loc']['line'], $left['loc']['col']);
                $n['operator'] = 'in';
                $n['left'] = $left;
                $n['right'] = $right;
                $left = $n;
            } else {
                break;
            }
        }
        return $left;
    }

    private function _parseShift(): array
    {
        $left = $this->_parseAdditive();
        while ($this->match(Tokenizer::T_PUNCT) && in_array($this->tv(), ['<<', '>>', '>>>'], true)) {
            $op = $this->eat(Tokenizer::T_PUNCT)['value'];
            $right = $this->_parseAdditive();
            $n = $this->node('BinaryExpression', $left['loc']['line'], $left['loc']['col']);
            $n['operator'] = $op;
            $n['left'] = $left;
            $n['right'] = $right;
            $left = $n;
        }
        return $left;
    }

    private function _parseAdditive(): array
    {
        $left = $this->_parseMultiplicative();
        while ($this->match(Tokenizer::T_PUNCT) && ($this->tv() === '+' || $this->tv() === '-') && !$this->_peekIs(Tokenizer::T_PUNCT, $this->tv())) {
            // Make sure it's not ++ or --
            $v = $this->tv();
            if ($this->_peekIs(Tokenizer::T_PUNCT, $v)) break; // skip ++ --
            $op = $this->eat(Tokenizer::T_PUNCT)['value'];
            $right = $this->_parseMultiplicative();
            $n = $this->node('BinaryExpression', $left['loc']['line'], $left['loc']['col']);
            $n['operator'] = $op;
            $n['left'] = $left;
            $n['right'] = $right;
            $left = $n;
        }
        return $left;
    }

    private function _parseMultiplicative(): array
    {
        $left = $this->_parseExponential();
        while ($this->match(Tokenizer::T_PUNCT) && in_array($this->tv(), ['*', '/', '%'], true)) {
            $op = $this->eat(Tokenizer::T_PUNCT)['value'];
            $right = $this->_parseExponential();
            $n = $this->node('BinaryExpression', $left['loc']['line'], $left['loc']['col']);
            $n['operator'] = $op;
            $n['left'] = $left;
            $n['right'] = $right;
            $left = $n;
        }
        return $left;
    }

    private function _parseExponential(): array
    {
        $base = $this->_parseUnary();
        if ($this->match(Tokenizer::T_PUNCT, '**')) {
            $this->eat(Tokenizer::T_PUNCT, '**');
            $exp = $this->_parseExponential(); // right-assoc
            $n = $this->node('BinaryExpression', $base['loc']['line'], $base['loc']['col']);
            $n['operator'] = '**';
            $n['left'] = $base;
            $n['right'] = $exp;
            return $n;
        }
        return $base;
    }

    private function _parseUnary(): array
    {
        if ($this->match(Tokenizer::T_KEYWORD, 'typeof')) {
            $tok = $this->eat(Tokenizer::T_KEYWORD, 'typeof');
            $arg = $this->_parseUnary();
            $n = $this->node('UnaryExpression', $tok['line'], $tok['col']);
            $n['operator'] = 'typeof';
            $n['prefix'] = true;
            $n['argument'] = $arg;
            return $n;
        }
        if ($this->match(Tokenizer::T_KEYWORD, 'void')) {
            $tok = $this->eat(Tokenizer::T_KEYWORD, 'void');
            $arg = $this->_parseUnary();
            $n = $this->node('UnaryExpression', $tok['line'], $tok['col']);
            $n['operator'] = 'void';
            $n['prefix'] = true;
            $n['argument'] = $arg;
            return $n;
        }
        if ($this->match(Tokenizer::T_KEYWORD, 'await')) {
            $tok = $this->eat(Tokenizer::T_KEYWORD, 'await');
            $arg = $this->_parseUnary();
            $n = $this->node('AwaitExpression', $tok['line'], $tok['col']);
            $n['argument'] = $arg;
            return $n;
        }
        if ($this->match(Tokenizer::T_PUNCT)) {
            $v = $this->tv();
            if ($v === '!' || $v === '~' || $v === '+' || $v === '-') {
                // Make sure + and - aren't ++ or --
                if (($v === '+' || $v === '-') && $this->_peekIs(Tokenizer::T_PUNCT, $v)) {
                    // prefix ++ or --
                    $tok = $this->cur();
                    $op = $this->eat(Tokenizer::T_PUNCT)['value'] . $this->eat(Tokenizer::T_PUNCT)['value'];
                    $arg = $this->_parseUnary();
                    $n = $this->node('UpdateExpression', $tok['line'], $tok['col']);
                    $n['operator'] = $op;
                    $n['prefix'] = true;
                    $n['argument'] = $arg;
                    return $n;
                }
                $tok = $this->eat(Tokenizer::T_PUNCT);
                $arg = $this->_parseUnary();
                $n = $this->node('UnaryExpression', $tok['line'], $tok['col']);
                $n['operator'] = $tok['value'];
                $n['prefix'] = true;
                $n['argument'] = $arg;
                return $n;
            }
            if ($v === '++' || $v === '--') {
                $tok = $this->eat(Tokenizer::T_PUNCT);
                $arg = $this->_parseUnary();
                $n = $this->node('UpdateExpression', $tok['line'], $tok['col']);
                $n['operator'] = $tok['value'];
                $n['prefix'] = true;
                $n['argument'] = $arg;
                return $n;
            }
        }
        return $this->_parsePostfix();
    }

    private function _parsePostfix(): array
    {
        $expr = $this->_parseCallMember();
        if ($this->match(Tokenizer::T_PUNCT, '++') || $this->match(Tokenizer::T_PUNCT, '--')) {
            // Must be on same line
            if ($this->cur()['line'] === $expr['loc']['line']) {
                $op = $this->eat(Tokenizer::T_PUNCT)['value'];
                $n = $this->node('UpdateExpression', $expr['loc']['line'], $expr['loc']['col']);
                $n['operator'] = $op;
                $n['prefix'] = false;
                $n['argument'] = $expr;
                return $n;
            }
        }
        return $expr;
    }

    private function _parseCallMember(): array
    {
        $expr = $this->_parsePrimary();
        while (true) {
            if ($this->match(Tokenizer::T_PUNCT, '(')) {
                $this->eat(Tokenizer::T_PUNCT, '(');
                $args = $this->_parseArguments();
                $this->eat(Tokenizer::T_PUNCT, ')');
                $n = $this->node('CallExpression', $expr['loc']['line'], $expr['loc']['col']);
                $n['callee'] = $expr;
                $n['arguments'] = $args;
                $expr = $n;
            } elseif ($this->match(Tokenizer::T_PUNCT, '.')) {
                $this->eat(Tokenizer::T_PUNCT, '.');
                $prop = $this->_parseIdentOrKeyword();
                $n = $this->node('MemberExpression', $expr['loc']['line'], $expr['loc']['col']);
                $n['object'] = $expr;
                $n['property'] = $prop;
                $n['computed'] = false;
                $n['optional'] = false;
                $expr = $n;
            } elseif ($this->match(Tokenizer::T_PUNCT, '?.')) {
                $this->eat(Tokenizer::T_PUNCT, '?.');
                if ($this->match(Tokenizer::T_PUNCT, '(')) {
                    // optional call: obj?.()
                    $this->eat(Tokenizer::T_PUNCT, '(');
                    $args = $this->_parseArguments();
                    $this->eat(Tokenizer::T_PUNCT, ')');
                    $n = $this->node('CallExpression', $expr['loc']['line'], $expr['loc']['col']);
                    $n['callee'] = $expr;
                    $n['arguments'] = $args;
                    $n['optional'] = true;
                    $expr = $n;
                } elseif ($this->match(Tokenizer::T_PUNCT, '[')) {
                    $this->eat(Tokenizer::T_PUNCT, '[');
                    $prop = $this->_parseExpression();
                    $this->eat(Tokenizer::T_PUNCT, ']');
                    $n = $this->node('MemberExpression', $expr['loc']['line'], $expr['loc']['col']);
                    $n['object'] = $expr;
                    $n['property'] = $prop;
                    $n['computed'] = true;
                    $n['optional'] = true;
                    $expr = $n;
                } else {
                    $prop = $this->_parseIdentOrKeyword();
                    $n = $this->node('MemberExpression', $expr['loc']['line'], $expr['loc']['col']);
                    $n['object'] = $expr;
                    $n['property'] = $prop;
                    $n['computed'] = false;
                    $n['optional'] = true;
                    $expr = $n;
                }
            } elseif ($this->match(Tokenizer::T_PUNCT, '[')) {
                $this->eat(Tokenizer::T_PUNCT, '[');
                $prop = $this->_parseExpression();
                $this->eat(Tokenizer::T_PUNCT, ']');
                $n = $this->node('MemberExpression', $expr['loc']['line'], $expr['loc']['col']);
                $n['object'] = $expr;
                $n['property'] = $prop;
                $n['computed'] = true;
                $n['optional'] = false;
                $expr = $n;
            } else {
                break;
            }
        }
        return $expr;
    }

    private function _parseArguments(): array
    {
        $args = [];
        while (!$this->match(Tokenizer::T_PUNCT, ')')) {
            if (!empty($args)) $this->eat(Tokenizer::T_PUNCT, ',');
            if ($this->match(Tokenizer::T_PUNCT, ')')) break;
            if ($this->match(Tokenizer::T_PUNCT, '...')) {
                $tok = $this->eat(Tokenizer::T_PUNCT, '...');
                $arg = $this->_parseAssignment();
                $spread = $this->node('SpreadElement', $tok['line'], $tok['col']);
                $spread['argument'] = $arg;
                $args[] = $spread;
            } else {
                $args[] = $this->_parseAssignment();
            }
        }
        return $args;
    }

    private function _parsePrimary(): array
    {
        $tok = $this->cur();
        $t = $tok['type'];
        $v = $tok['value'];

        // Identifier
        if ($t === Tokenizer::T_IDENT) {
            return $this->_parseIdentifier();
        }

        // Number / String / Regex literals
        if ($t === Tokenizer::T_NUMBER || $t === Tokenizer::T_STRING || $t === Tokenizer::T_REGEX) {
            return $this->_parseLiteral();
        }

        // Keywords acting as values
        if ($t === Tokenizer::T_KEYWORD) {
            if ($v === 'true' || $v === 'false') {
                $this->pos++;
                $n = $this->node('Literal', $tok['line'], $tok['col']);
                $n['value'] = ($v === 'true');
                $n['raw'] = $v;
                return $n;
            }
            if ($v === 'null') {
                $this->pos++;
                $n = $this->node('Literal', $tok['line'], $tok['col']);
                $n['value'] = null;
                $n['raw'] = 'null';
                return $n;
            }
            if ($v === 'undefined') {
                $this->pos++;
                $n = $this->node('Identifier', $tok['line'], $tok['col']);
                $n['name'] = 'undefined';
                return $n;
            }
            if ($v === 'function') {
                return $this->_parseFunctionExpr();
            }
            if ($v === 'async') {
                // async function expr or async arrow
                if ($this->_peekIs(Tokenizer::T_KEYWORD, 'function')) {
                    return $this->_parseFunctionExpr();
                }
                // async arrow: async (params) => ...  or  async ident => ...
                return $this->_parseAsyncArrow();
            }
            if ($v === 'typeof' || $v === 'void') {
                // These are handled in _parseUnary, shouldn't get here normally
                // but just in case:
                return $this->_parseUnary();
            }
        }

        // Array literal
        if ($t === Tokenizer::T_PUNCT && $v === '[') {
            return $this->_parseArrayLiteral();
        }

        // Object literal
        if ($t === Tokenizer::T_PUNCT && $v === '{') {
            return $this->_parseObjectLiteral();
        }

        // Parenthesized expression or arrow function
        if ($t === Tokenizer::T_PUNCT && $v === '(') {
            return $this->_parseParenOrArrow();
        }

        $this->unexpected($tok);
        // @phpstan-ignore-next-line
        return [];
    }

    private function _parseFunctionExpr(): array
    {
        $tok = $this->cur();
        $async = false;
        if ($this->match(Tokenizer::T_KEYWORD, 'async')) {
            $async = true;
            $this->eat(Tokenizer::T_KEYWORD, 'async');
        }
        $this->eat(Tokenizer::T_KEYWORD, 'function');
        $id = null;
        if ($this->tt() === Tokenizer::T_IDENT) {
            $id = $this->_parseIdentifier();
        }
        $this->eat(Tokenizer::T_PUNCT, '(');
        $params = $this->_parseParams();
        $this->eat(Tokenizer::T_PUNCT, ')');
        $body = $this->_parseBlock();
        $n = $this->node('FunctionExpression', $tok['line'], $tok['col']);
        $n['id'] = $id;
        $n['params'] = $params;
        $n['body'] = $body;
        $n['async'] = $async;
        return $n;
    }

    private function _parseAsyncArrow(): array
    {
        $tok = $this->cur();
        $this->eat(Tokenizer::T_KEYWORD, 'async');
        // async ident => ...
        if ($this->tt() === Tokenizer::T_IDENT) {
            $param = $this->_parseIdentifier();
            $this->eat(Tokenizer::T_PUNCT, '=>');
            $body = $this->match(Tokenizer::T_PUNCT, '{') ? $this->_parseBlock() : $this->_parseAssignment();
            $n = $this->node('ArrowFunctionExpression', $tok['line'], $tok['col']);
            $n['params'] = [$param];
            $n['body'] = $body;
            $n['async'] = true;
            $n['expression'] = !isset($body['type']) || $body['type'] !== 'BlockStatement';
            return $n;
        }
        // async (params) => ...
        $this->eat(Tokenizer::T_PUNCT, '(');
        $params = $this->_parseParams();
        $this->eat(Tokenizer::T_PUNCT, ')');
        $this->eat(Tokenizer::T_PUNCT, '=>');
        $body = $this->match(Tokenizer::T_PUNCT, '{') ? $this->_parseBlock() : $this->_parseAssignment();
        $n = $this->node('ArrowFunctionExpression', $tok['line'], $tok['col']);
        $n['params'] = $params;
        $n['body'] = $body;
        $n['async'] = true;
        $n['expression'] = !isset($body['type']) || $body['type'] !== 'BlockStatement';
        return $n;
    }

    private function _parseArrayLiteral(): array
    {
        $tok = $this->eat(Tokenizer::T_PUNCT, '[');
        $elements = [];
        while (!$this->match(Tokenizer::T_PUNCT, ']')) {
            if (!empty($elements) || $this->match(Tokenizer::T_PUNCT, ',')) {
                if ($this->match(Tokenizer::T_PUNCT, ',')) {
                    $this->eat(Tokenizer::T_PUNCT, ',');
                    if ($this->match(Tokenizer::T_PUNCT, ']')) {
                        break;
                    }
                    if ($this->match(Tokenizer::T_PUNCT, ',')) {
                        $elements[] = null; // elision
                        continue;
                    }
                }
            }
            if ($this->match(Tokenizer::T_PUNCT, '...')) {
                $stok = $this->eat(Tokenizer::T_PUNCT, '...');
                $arg = $this->_parseAssignment();
                $spread = $this->node('SpreadElement', $stok['line'], $stok['col']);
                $spread['argument'] = $arg;
                $elements[] = $spread;
            } else {
                $elements[] = $this->_parseAssignment();
            }
        }
        $this->eat(Tokenizer::T_PUNCT, ']');
        $n = $this->node('ArrayExpression', $tok['line'], $tok['col']);
        $n['elements'] = $elements;
        return $n;
    }

    private function _parseObjectLiteral(): array
    {
        $tok = $this->eat(Tokenizer::T_PUNCT, '{');
        $props = [];
        while (!$this->match(Tokenizer::T_PUNCT, '}')) {
            if (!empty($props)) $this->eat(Tokenizer::T_PUNCT, ',');
            if ($this->match(Tokenizer::T_PUNCT, '}')) break;

            // Spread
            if ($this->match(Tokenizer::T_PUNCT, '...')) {
                $stok = $this->eat(Tokenizer::T_PUNCT, '...');
                $arg = $this->_parseAssignment();
                $spread = $this->node('SpreadElement', $stok['line'], $stok['col']);
                $spread['argument'] = $arg;
                $props[] = $spread;
                continue;
            }

            $ptok = $this->cur();
            $computed = false;
            $method = false;
            $shorthand = false;
            $async = false;

            // async method: { async foo() {} }
            if ($this->match(Tokenizer::T_KEYWORD, 'async') && !$this->_peekIs(Tokenizer::T_PUNCT, ':') && !$this->_peekIs(Tokenizer::T_PUNCT, ',') && !$this->_peekIs(Tokenizer::T_PUNCT, '}')) {
                $async = true;
                $this->eat(Tokenizer::T_KEYWORD, 'async');
            }

            // Computed key
            if ($this->match(Tokenizer::T_PUNCT, '[')) {
                $computed = true;
                $this->eat(Tokenizer::T_PUNCT, '[');
                $key = $this->_parseAssignment();
                $this->eat(Tokenizer::T_PUNCT, ']');
            } elseif ($this->tt() === Tokenizer::T_STRING || $this->tt() === Tokenizer::T_NUMBER) {
                $key = $this->_parseLiteral();
            } else {
                $key = $this->_parseIdentOrKeyword();
            }

            // Method shorthand
            if ($this->match(Tokenizer::T_PUNCT, '(')) {
                $method = true;
                $this->eat(Tokenizer::T_PUNCT, '(');
                $params = $this->_parseParams();
                $this->eat(Tokenizer::T_PUNCT, ')');
                $body = $this->_parseBlock();
                $value = $this->node('FunctionExpression', $key['loc']['line'], $key['loc']['col']);
                $value['id'] = null;
                $value['params'] = $params;
                $value['body'] = $body;
                $value['async'] = $async;
            } elseif ($this->match(Tokenizer::T_PUNCT, ':')) {
                $this->eat(Tokenizer::T_PUNCT, ':');
                $value = $this->_parseAssignment();
            } else {
                // Shorthand: { x }
                $shorthand = true;
                $value = $key;
            }

            $prop = $this->node('Property', $ptok['line'], $ptok['col']);
            $prop['key'] = $key;
            $prop['value'] = $value;
            $prop['computed'] = $computed;
            $prop['method'] = $method;
            $prop['shorthand'] = $shorthand;
            $props[] = $prop;
        }
        $this->eat(Tokenizer::T_PUNCT, '}');
        $n = $this->node('ObjectExpression', $tok['line'], $tok['col']);
        $n['properties'] = $props;
        return $n;
    }

    private function _parseParenOrArrow(): array
    {
        // Save position for backtracking
        $save = $this->pos;
        $tok = $this->cur();

        // Try to parse as arrow: (params) => ...
        try {
            $this->eat(Tokenizer::T_PUNCT, '(');
            $params = $this->_parseParams();
            $this->eat(Tokenizer::T_PUNCT, ')');
            if ($this->match(Tokenizer::T_PUNCT, '=>')) {
                $this->eat(Tokenizer::T_PUNCT, '=>');
                $body = $this->match(Tokenizer::T_PUNCT, '{') ? $this->_parseBlock() : $this->_parseAssignment();
                $n = $this->node('ArrowFunctionExpression', $tok['line'], $tok['col']);
                $n['params'] = $params;
                $n['body'] = $body;
                $n['async'] = false;
                $n['expression'] = !isset($body['type']) || $body['type'] !== 'BlockStatement';
                return $n;
            }
        } catch (\Throwable $e) {
            // Not an arrow, fall through
        }

        // Backtrack and parse as parenthesized expression
        $this->pos = $save;
        $this->eat(Tokenizer::T_PUNCT, '(');
        $expr = $this->_parseExpression();
        $this->eat(Tokenizer::T_PUNCT, ')');

        // Check for arrow after single-ident paren: (x) => ...
        if ($this->match(Tokenizer::T_PUNCT, '=>')) {
            $this->eat(Tokenizer::T_PUNCT, '=>');
            // Convert expr to params
            $params = [];
            if ($expr['type'] === 'Identifier') {
                $params = [$expr];
            } elseif ($expr['type'] === 'SequenceExpression') {
                $params = $expr['expressions'];
            } else {
                $params = [$expr];
            }
            $body = $this->match(Tokenizer::T_PUNCT, '{') ? $this->_parseBlock() : $this->_parseAssignment();
            $n = $this->node('ArrowFunctionExpression', $tok['line'], $tok['col']);
            $n['params'] = $params;
            $n['body'] = $body;
            $n['async'] = false;
            $n['expression'] = !isset($body['type']) || $body['type'] !== 'BlockStatement';
            return $n;
        }

        return $expr;
    }

    private function _parseIdentifier(): array
    {
        $tok = $this->eat(Tokenizer::T_IDENT);
        $n = $this->node('Identifier', $tok['line'], $tok['col']);
        $n['name'] = $tok['value'];
        return $n;
    }

    private function _parseIdentOrKeyword(): array
    {
        $tok = $this->cur();
        if ($tok['type'] === Tokenizer::T_IDENT || $tok['type'] === Tokenizer::T_KEYWORD) {
            $this->pos++;
            $n = $this->node('Identifier', $tok['line'], $tok['col']);
            $n['name'] = $tok['value'];
            return $n;
        }
        $this->unexpected($tok);
        return []; // unreachable
    }

    private function _parseLiteral(): array
    {
        $tok = $this->cur();
        $this->pos++;
        $n = $this->node('Literal', $tok['line'], $tok['col']);
        if ($tok['type'] === Tokenizer::T_STRING) {
            $n['value'] = Tokenizer::unescapeString($tok['value']);
            $n['raw'] = $tok['value'];
        } elseif ($tok['type'] === Tokenizer::T_NUMBER) {
            $raw = $tok['value'];
            // Strip underscores and BigInt suffix for parsing
            $clean = str_replace('_', '', $raw);
            $isBigInt = str_ends_with($clean, 'n');
            if ($isBigInt) $clean = substr($clean, 0, -1);
            if (str_starts_with($clean, '0x') || str_starts_with($clean, '0X')) {
                $n['value'] = $isBigInt ? $clean : intval($clean, 16);
            } elseif (str_starts_with($clean, '0b') || str_starts_with($clean, '0B')) {
                $n['value'] = $isBigInt ? $clean : intval($clean, 2);
            } elseif (str_starts_with($clean, '0o') || str_starts_with($clean, '0O')) {
                $n['value'] = $isBigInt ? $clean : intval($clean, 8);
            } else {
                $n['value'] = $isBigInt ? $clean : (str_contains($clean, '.') || str_contains(strtolower($clean), 'e') ? (float)$clean : (int)$clean);
            }
            $n['raw'] = $raw;
            $n['bigint'] = $isBigInt;
        } elseif ($tok['type'] === Tokenizer::T_REGEX) {
            $n['value'] = null; // regex object not representable
            $n['raw'] = $tok['value'];
            $n['regex'] = true;
        }
        return $n;
    }

    private function _eatSemi(): void
    {
        if ($this->match(Tokenizer::T_PUNCT, ';')) {
            $this->eat(Tokenizer::T_PUNCT, ';');
        }
        // ASI: don't require semicolons
    }
}

// ── Public entry point ─────────────────────────────────────────

function parse(string $source): array
{
    $tokenizer = new Tokenizer($source);
    $tokens = $tokenizer->tokenize();
    $parser = new Parser($tokens);
    return $parser->parse();
}
