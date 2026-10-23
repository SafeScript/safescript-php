<?php
/**
 * SafeScript tokenizer — PHP port of the tokenizer from parse.js.
 */

namespace SafeScript;

class Tokenizer
{
    // Token types
    public const T_IDENT    = 'Ident';
    public const T_NUMBER   = 'Number';
    public const T_STRING   = 'String';
    public const T_REGEX    = 'Regex';
    public const T_TEMPLATE = 'Template';
    public const T_KEYWORD  = 'Keyword';
    public const T_PUNCT    = 'Punct';
    public const T_EOF      = 'EOF';

    private string $src;
    private int $pos;
    private int $len;
    private int $line;
    private int $col;
    /** @var array<array{type:string,value:string,line:int,col:int}> */
    private array $tokens = [];

    // Punctuation maps
    private const PUNCT3 = [
        '===' => true, '!==' => true, '>>>' => true, '**=' => true,
        '<<=' => true, '>>=' => true, '&&=' => true, '||=' => true,
        '??=' => true, '...' => true,
    ];

    private const PUNCT2 = [
        '==' => true, '!=' => true, '>=' => true, '<=' => true,
        '&&' => true, '||' => true, '??' => true,
        '+=' => true, '-=' => true, '*=' => true, '/=' => true,
        '%=' => true, '**' => true, '++' => true, '--' => true,
        '=>' => true, '?.' => true, '<<' => true, '>>' => true,
        '&=' => true, '|=' => true, '^=' => true,
    ];

    private const PUNCT1 = [
        '(' => true, ')' => true, '{' => true, '}' => true,
        '[' => true, ']' => true, ';' => true, ',' => true,
        '.' => true, ':' => true, '?' => true, '!' => true,
        '+' => true, '-' => true, '*' => true, '/' => true,
        '%' => true, '=' => true, '<' => true, '>' => true,
        '&' => true, '|' => true, '^' => true, '~' => true,
    ];

    public function __construct(string $source)
    {
        $this->src  = $source;
        $this->pos  = 0;
        $this->len  = strlen($source);
        $this->line = 1;
        $this->col  = 0;
    }

    /**
     * Tokenize the source, returning array of token arrays.
     * @return array<array{type:string,value:string,line:int,col:int}>
     */
    public function tokenize(): array
    {
        $this->tokens = [];
        while ($this->pos < $this->len) {
            $this->_skipWhitespaceAndComments();
            if ($this->pos >= $this->len) break;
            $this->_tokenize();
        }
        $this->tokens[] = ['type' => self::T_EOF, 'value' => '', 'line' => $this->line, 'col' => $this->col];
        return $this->tokens;
    }

    private function _ch(): string
    {
        return $this->pos < $this->len ? $this->src[$this->pos] : '';
    }

    private function _peek(int $offset = 1): string
    {
        $p = $this->pos + $offset;
        return $p < $this->len ? $this->src[$p] : '';
    }

    private function _advance(): string
    {
        $ch = $this->src[$this->pos];
        $this->pos++;
        if ($ch === "\n") {
            $this->line++;
            $this->col = 0;
        } else {
            $this->col++;
        }
        return $ch;
    }

    private function _skipWhitespaceAndComments(): void
    {
        while ($this->pos < $this->len) {
            $ch = $this->_ch();
            if ($ch === ' ' || $ch === "\t" || $ch === "\n" || $ch === "\r") {
                $this->_advance();
                continue;
            }
            if ($ch === '/' && $this->_peek() === '/') {
                // line comment
                while ($this->pos < $this->len && $this->_ch() !== "\n") {
                    $this->_advance();
                }
                continue;
            }
            if ($ch === '/' && $this->_peek() === '*') {
                // block comment
                $this->_advance(); // /
                $this->_advance(); // *
                while ($this->pos < $this->len) {
                    if ($this->_ch() === '*' && $this->_peek() === '/') {
                        $this->_advance();
                        $this->_advance();
                        break;
                    }
                    $this->_advance();
                }
                continue;
            }
            break;
        }
    }

    private function _tokenize(): void
    {
        $ch = $this->_ch();
        $line = $this->line;
        $col  = $this->col;

        // String
        if ($ch === '"' || $ch === "'") {
            $this->_readString($ch, $line, $col);
            return;
        }

        // Template literal — blocked
        if ($ch === '`') {
            $this->_error('Template literals are not allowed in SafeScript', $line, $col);
        }

        // Number
        if ($ch >= '0' && $ch <= '9') {
            $this->_readNumber($line, $col);
            return;
        }
        // .5 style number
        if ($ch === '.' && $this->_peek() >= '0' && $this->_peek() <= '9') {
            $this->_readDecimal($line, $col);
            return;
        }

        // Identifier or keyword
        if ($this->_isIdentStart($ch)) {
            $this->_readIdent($line, $col);
            return;
        }

        // Division or regex
        if ($ch === '/') {
            if (!$this->_lastIsExprEnd()) {
                $this->_readRegex($line, $col);
                return;
            }
        }

        // Punctuation
        $this->_readPunct($line, $col);
    }

    private function _readString(string $quote, int $line, int $col): void
    {
        $this->_advance(); // opening quote
        $raw = $quote;
        while ($this->pos < $this->len) {
            $ch = $this->_ch();
            if ($ch === '\\') {
                $raw .= $this->_advance();
                if ($this->pos < $this->len) {
                    $raw .= $this->_advance();
                }
                continue;
            }
            if ($ch === $quote) {
                $raw .= $this->_advance();
                $this->tokens[] = ['type' => self::T_STRING, 'value' => $raw, 'line' => $line, 'col' => $col];
                return;
            }
            if ($ch === "\n") {
                $this->_error('Unterminated string literal', $line, $col);
            }
            $raw .= $this->_advance();
        }
        $this->_error('Unterminated string literal', $line, $col);
    }

    private function _readNumber(int $line, int $col): void
    {
        $num = '';
        $ch = $this->_ch();
        if ($ch === '0') {
            $num .= $this->_advance();
            $next = $this->_ch();
            // hex
            if ($next === 'x' || $next === 'X') {
                $num .= $this->_advance();
                while ($this->pos < $this->len && $this->_isHexDigit($this->_ch())) {
                    $num .= $this->_advance();
                }
                // BigInt suffix
                if ($this->pos < $this->len && $this->_ch() === 'n') {
                    $num .= $this->_advance();
                }
                $this->tokens[] = ['type' => self::T_NUMBER, 'value' => $num, 'line' => $line, 'col' => $col];
                return;
            }
            // binary
            if ($next === 'b' || $next === 'B') {
                $num .= $this->_advance();
                while ($this->pos < $this->len && ($this->_ch() === '0' || $this->_ch() === '1' || $this->_ch() === '_')) {
                    $num .= $this->_advance();
                }
                if ($this->pos < $this->len && $this->_ch() === 'n') {
                    $num .= $this->_advance();
                }
                $this->tokens[] = ['type' => self::T_NUMBER, 'value' => $num, 'line' => $line, 'col' => $col];
                return;
            }
            // octal
            if ($next === 'o' || $next === 'O') {
                $num .= $this->_advance();
                while ($this->pos < $this->len && $this->_ch() >= '0' && $this->_ch() <= '7') {
                    if ($this->_ch() === '_') { $num .= $this->_advance(); continue; }
                    $num .= $this->_advance();
                }
                if ($this->pos < $this->len && $this->_ch() === 'n') {
                    $num .= $this->_advance();
                }
                $this->tokens[] = ['type' => self::T_NUMBER, 'value' => $num, 'line' => $line, 'col' => $col];
                return;
            }
        }

        // decimal digits
        while ($this->pos < $this->len && (($this->_ch() >= '0' && $this->_ch() <= '9') || $this->_ch() === '_')) {
            $num .= $this->_advance();
        }

        // decimal point
        if ($this->pos < $this->len && $this->_ch() === '.' && $this->_peek() !== '.') {
            $num .= $this->_advance();
            while ($this->pos < $this->len && (($this->_ch() >= '0' && $this->_ch() <= '9') || $this->_ch() === '_')) {
                $num .= $this->_advance();
            }
        }

        // exponent
        if ($this->pos < $this->len && ($this->_ch() === 'e' || $this->_ch() === 'E')) {
            $num .= $this->_advance();
            if ($this->pos < $this->len && ($this->_ch() === '+' || $this->_ch() === '-')) {
                $num .= $this->_advance();
            }
            while ($this->pos < $this->len && (($this->_ch() >= '0' && $this->_ch() <= '9') || $this->_ch() === '_')) {
                $num .= $this->_advance();
            }
        }

        // BigInt
        if ($this->pos < $this->len && $this->_ch() === 'n') {
            $num .= $this->_advance();
        }

        $this->tokens[] = ['type' => self::T_NUMBER, 'value' => $num, 'line' => $line, 'col' => $col];
    }

    private function _readDecimal(int $line, int $col): void
    {
        $num = $this->_advance(); // .
        while ($this->pos < $this->len && (($this->_ch() >= '0' && $this->_ch() <= '9') || $this->_ch() === '_')) {
            $num .= $this->_advance();
        }
        // exponent
        if ($this->pos < $this->len && ($this->_ch() === 'e' || $this->_ch() === 'E')) {
            $num .= $this->_advance();
            if ($this->pos < $this->len && ($this->_ch() === '+' || $this->_ch() === '-')) {
                $num .= $this->_advance();
            }
            while ($this->pos < $this->len && (($this->_ch() >= '0' && $this->_ch() <= '9') || $this->_ch() === '_')) {
                $num .= $this->_advance();
            }
        }
        $this->tokens[] = ['type' => self::T_NUMBER, 'value' => $num, 'line' => $line, 'col' => $col];
    }

    private function _readIdent(int $line, int $col): void
    {
        $id = '';
        while ($this->pos < $this->len && $this->_isIdentPart($this->_ch())) {
            $id .= $this->_advance();
        }

        // Check blocked
        if (isset(Defs::BLOCKED[$id])) {
            $this->_error("Blocked identifier: $id", $line, $col);
        }
        if (isset(Defs::BLOCKED_KW[$id])) {
            $this->_error("Blocked keyword: $id", $line, $col);
        }

        // Generator check
        if ($id === 'function' && $this->pos < $this->len) {
            $save = $this->pos;
            $this->_skipWhitespaceAndComments();
            if ($this->pos < $this->len && $this->_ch() === '*') {
                $this->_error('Generator functions are not allowed in SafeScript', $line, $col);
            }
            $this->pos = $save;
        }

        if (isset(Defs::ALLOWED_KW[$id])) {
            $this->tokens[] = ['type' => self::T_KEYWORD, 'value' => $id, 'line' => $line, 'col' => $col];
        } else {
            $this->tokens[] = ['type' => self::T_IDENT, 'value' => $id, 'line' => $line, 'col' => $col];
        }
    }

    private function _readRegex(int $line, int $col): void
    {
        $raw = $this->_advance(); // /
        $inClass = false;
        while ($this->pos < $this->len) {
            $ch = $this->_ch();
            if ($ch === '\\') {
                $raw .= $this->_advance();
                if ($this->pos < $this->len) $raw .= $this->_advance();
                continue;
            }
            if ($ch === '[') $inClass = true;
            if ($ch === ']') $inClass = false;
            if ($ch === '/' && !$inClass) {
                $raw .= $this->_advance();
                // flags
                while ($this->pos < $this->len && $this->_isIdentPart($this->_ch())) {
                    $raw .= $this->_advance();
                }
                $this->tokens[] = ['type' => self::T_REGEX, 'value' => $raw, 'line' => $line, 'col' => $col];
                return;
            }
            if ($ch === "\n") {
                $this->_error('Unterminated regex', $line, $col);
            }
            $raw .= $this->_advance();
        }
        $this->_error('Unterminated regex', $line, $col);
    }

    private function _readPunct(int $line, int $col): void
    {
        // Try 3-char
        if ($this->pos + 2 < $this->len) {
            $tri = $this->src[$this->pos] . $this->src[$this->pos + 1] . $this->src[$this->pos + 2];
            if (isset(self::PUNCT3[$tri])) {
                $this->_advance(); $this->_advance(); $this->_advance();
                // >>>= is 4 chars
                if ($tri === '>>>' && $this->pos < $this->len && $this->_ch() === '=') {
                    $tri .= $this->_advance();
                }
                $this->tokens[] = ['type' => self::T_PUNCT, 'value' => $tri, 'line' => $line, 'col' => $col];
                return;
            }
        }

        // Try 2-char
        if ($this->pos + 1 < $this->len) {
            $bi = $this->src[$this->pos] . $this->src[$this->pos + 1];
            if (isset(self::PUNCT2[$bi])) {
                $this->_advance(); $this->_advance();
                $this->tokens[] = ['type' => self::T_PUNCT, 'value' => $bi, 'line' => $line, 'col' => $col];
                return;
            }
        }

        // Try 1-char
        $ch = $this->_ch();
        if (isset(self::PUNCT1[$ch])) {
            $this->_advance();
            $this->tokens[] = ['type' => self::T_PUNCT, 'value' => $ch, 'line' => $line, 'col' => $col];
            return;
        }

        $this->_error("Unexpected character: $ch", $line, $col);
    }

    private function _lastIsExprEnd(): bool
    {
        if (empty($this->tokens)) return false;
        $last = $this->tokens[count($this->tokens) - 1];
        $t = $last['type'];
        $v = $last['value'];
        if ($t === self::T_IDENT || $t === self::T_NUMBER || $t === self::T_STRING || $t === self::T_REGEX) return true;
        if ($t === self::T_KEYWORD && ($v === 'true' || $v === 'false' || $v === 'null' || $v === 'undefined')) return true;
        if ($t === self::T_PUNCT && ($v === ')' || $v === ']' || $v === '}' || $v === '++' || $v === '--')) return true;
        return false;
    }

    private function _isIdentStart(string $ch): bool
    {
        return ($ch >= 'a' && $ch <= 'z') || ($ch >= 'A' && $ch <= 'Z') || $ch === '_' || $ch === '$';
    }

    private function _isIdentPart(string $ch): bool
    {
        return $this->_isIdentStart($ch) || ($ch >= '0' && $ch <= '9');
    }

    private function _isHexDigit(string $ch): bool
    {
        return ($ch >= '0' && $ch <= '9') || ($ch >= 'a' && $ch <= 'f') || ($ch >= 'A' && $ch <= 'F') || $ch === '_';
    }

    private function _error(string $msg, int $line, int $col): void
    {
        throw new \SyntaxError("$msg at line $line, col $col");
    }

    /**
     * Unescape a JS string literal (including quotes) to its value.
     */
    public static function unescapeString(string $raw): string
    {
        $quote = $raw[0];
        $inner = substr($raw, 1, -1);
        $out = '';
        $len = strlen($inner);
        for ($i = 0; $i < $len; $i++) {
            if ($inner[$i] !== '\\') {
                $out .= $inner[$i];
                continue;
            }
            $i++;
            if ($i >= $len) break;
            $ch = $inner[$i];
            switch ($ch) {
                case 'n': $out .= "\n"; break;
                case 't': $out .= "\t"; break;
                case 'r': $out .= "\r"; break;
                case 'b': $out .= "\x08"; break;
                case 'f': $out .= "\x0C"; break;
                case 'v': $out .= "\x0B"; break;
                case '0': $out .= "\0"; break;
                case '\\': $out .= '\\'; break;
                case "'": $out .= "'"; break;
                case '"': $out .= '"'; break;
                case "\n": break; // line continuation
                case "\r":
                    if ($i + 1 < $len && $inner[$i + 1] === "\n") $i++;
                    break;
                case 'x':
                    if ($i + 2 < $len) {
                        $hex = substr($inner, $i + 1, 2);
                        $out .= chr((int)hexdec($hex));
                        $i += 2;
                    }
                    break;
                case 'u':
                    if ($i + 1 < $len && $inner[$i + 1] === '{') {
                        $end = strpos($inner, '}', $i + 2);
                        if ($end !== false) {
                            $cp = hexdec(substr($inner, $i + 2, $end - $i - 2));
                            $out .= mb_chr((int)$cp, 'UTF-8');
                            $i = $end;
                        }
                    } elseif ($i + 4 < $len) {
                        $hex = substr($inner, $i + 1, 4);
                        $out .= mb_chr((int)hexdec($hex), 'UTF-8');
                        $i += 4;
                    }
                    break;
                default:
                    $out .= $ch;
            }
        }
        return $out;
    }
}

/**
 * PHP doesn't have a built-in SyntaxError class, so define one.
 */
if (!class_exists('SyntaxError', false)) {
    class_alias('SafeScript\\SyntaxErrorEx', 'SyntaxError', false);
}
