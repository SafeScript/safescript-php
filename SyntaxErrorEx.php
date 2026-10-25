<?php
/**
 * SyntaxError exception for SafeScript.
 * PHP doesn't have a built-in SyntaxError like JS.
 */

namespace SafeScript;

class SyntaxErrorEx extends \Exception
{
    public ?int $sourceLine;
    public ?int $sourceCol;

    public function __construct(string $message = '', ?int $line = null, ?int $col = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->sourceLine = $line;
        $this->sourceCol = $col;
    }
}

// Register global alias
if (!class_exists('SyntaxError', false)) {
    class_alias(SyntaxErrorEx::class, 'SyntaxError');
}
