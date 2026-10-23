<?php
/**
 * SafeScript compiler pipeline — PHP port of compile.js.
 * parse → validate → transform → emit PHP
 */

namespace SafeScript;

class Compiler
{
    /**
     * Compile SafeScript source to PHP code string.
     * @param string $source SafeScript source code
     * @return array{code: string, hash: string}
     */
    public static function compile(string $source): array
    {
        // Strip header if present
        $body = Header::stripHeader($source);

        // Tokenize
        $tokenizer = new Tokenizer($body);
        $tokens = $tokenizer->tokenize();

        // Parse
        $parser = new Parser($tokens);
        $ast = $parser->parse();

        // Validate
        Validator::validate($ast);

        // Transform (bracket access → __at/__set)
        $ast = Transform::transform($ast);

        // Emit PHP
        $code = EmitPhp::emit($ast);

        // Hash
        $hash = hash('sha256', $code);

        return ['code' => $code, 'hash' => $hash];
    }

    /**
     * Parse only — return AST.
     */
    public static function parse(string $source): array
    {
        $body = Header::stripHeader($source);
        $tokenizer = new Tokenizer($body);
        $tokens = $tokenizer->tokenize();
        $parser = new Parser($tokens);
        return $parser->parse();
    }

    /**
     * Validate only — throws on error.
     */
    public static function validate(string $source): bool
    {
        $body = Header::stripHeader($source);
        $tokenizer = new Tokenizer($body);
        $tokens = $tokenizer->tokenize();
        $parser = new Parser($tokens);
        $ast = $parser->parse();
        Validator::validate($ast);
        return true;
    }
}
