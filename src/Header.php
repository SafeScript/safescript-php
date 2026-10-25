<?php
/**
 * SafeScript header parser — PHP port of header.js.
 * Parses capability headers from .safe files.
 */

namespace SafeScript;

class Header
{
    /**
     * Extract raw header text from source.
     * Header must be the very first thing in the file: /** ... *​/
     */
    public static function extractHeader(string $source): ?string
    {
        $trimmed = ltrim($source);
        if (!str_starts_with($trimmed, '/**')) return null;
        $end = strpos($trimmed, '*/');
        if ($end === false) return null;
        return substr($trimmed, 3, $end - 3);
    }

    /**
     * Parse header into structured data.
     * @return array{version: ?string, env: string[], needs: array, uses: array}
     */
    public static function parseHeader(string $source): array
    {
        $result = ['version' => null, 'env' => null, 'needs' => [], 'uses' => [], 'types' => []];
        $raw = self::extractHeader($source);
        if ($raw === null) return $result;

        $lines = explode("\n", $raw);
        foreach ($lines as $line) {
            $line = trim($line);
            // Strip leading * from block comment lines
            $line = preg_replace('/^\*\s?/', '', $line);
            $line = trim($line);
            if ($line === '') continue;

            if (preg_match('/@safescript\s+(.+)/', $line, $m)) {
                $result['version'] = trim($m[1]);
            } elseif (preg_match('/@needs\s+(\w+)\s*:\s*(.+)/', $line, $m)) {
                $result['needs'][] = ['name' => trim($m[1]), 'type' => trim($m[2])];
            } elseif (preg_match('/@needs\s+(\w+)\s*$/', $line, $m)) {
                $result['needs'][] = ['name' => trim($m[1]), 'type' => null];
            } elseif (preg_match('/@uses\s+(\w+)\s*:\s*(.+)/', $line, $m)) {
                $result['uses'][] = ['name' => trim($m[1]), 'type' => trim($m[2])];
            } elseif (preg_match('/@uses\s+(\w+)\s*$/', $line, $m)) {
                $result['uses'][] = ['name' => trim($m[1]), 'type' => null];
            } elseif (preg_match('/@env\s+(.+)/', $line, $m)) {
                $result['env'] = array_map('trim', explode(',', $m[1]));
            } elseif (preg_match('/@type\s+(\w+)\s*\{\s*([^}]*)\}/', $line, $m)) {
                $methods = array_map('trim', explode(',', $m[2]));
                $methods = array_filter($methods, fn($s) => $s !== '');
                $methods = array_values($methods);
                $result['types'][] = ['name' => trim($m[1]), 'methods' => $methods];
            }
        }

        return $result;
    }

    /**
     * Check if scope satisfies header requirements.
     * @return array{ok: bool, missing: string[]}
     */
    public static function checkScope(string $source, array $scope): array
    {
        $header = self::parseHeader($source);
        $missing = [];
        foreach ($header['needs'] as $need) {
            if (!array_key_exists($need['name'], $scope)) {
                $missing[] = $need['name'];
            }
        }
        return ['ok' => empty($missing), 'missing' => $missing];
    }

    /**
     * Strip the header from source, returning the code body.
     */
    public static function stripHeader(string $source): string
    {
        $trimmed = ltrim($source);
        if (!str_starts_with($trimmed, '/**')) return $source;
        $end = strpos($trimmed, '*/');
        if ($end === false) return $source;
        return substr($trimmed, $end + 2);
    }
}
