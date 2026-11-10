<?php
/**
 * SafeFiles — sandboxed file I/O for SafeScript.
 *
 * Provides file_get_contents, file_put_contents, file_exists, glob, unlink,
 * is_dir, mkdir, readdir — all scoped to a single root folder.
 * Every path is resolved and checked to prevent directory traversal.
 * Size limits cap individual reads/writes and total bytes written.
 */

namespace SafeScript;

class SafeFiles
{
    private string $root;
    private int $maxFileSize;
    private int $maxTotalWrite;
    private int $totalWritten = 0;

    /**
     * @param string $root           Absolute path to the sandbox folder (must exist)
     * @param array  $opts           Options:
     *   - maxFileSize   int  Max bytes per read/write (default 1 MB)
     *   - maxTotalWrite int  Max total bytes written in this session (default 10 MB)
     */
    public function __construct(string $root, array $opts = [])
    {
        $real = realpath($root);
        if ($real === false || !is_dir($real)) {
            throw new \RuntimeException("SafeFiles: root folder does not exist: {$root}");
        }
        $this->root = rtrim($real, '/');
        $this->maxFileSize   = $opts['maxFileSize']   ?? 1_048_576;    // 1 MB
        $this->maxTotalWrite = $opts['maxTotalWrite']  ?? 10_485_760;   // 10 MB
    }

    // ── public API (registered as allowMethods) ──

    /**
     * Read a file's contents. Returns string or null if not found.
     */
    public function readFile(string $path): ?string
    {
        $full = $this->resolve($path);
        if (!is_file($full)) return null;

        clearstatcache(true, $full);
        $size = filesize($full);
        if ($size > $this->maxFileSize) {
            throw new \RuntimeException(
                "SafeFiles: file too large ({$size} bytes, limit {$this->maxFileSize})"
            );
        }
        return file_get_contents($full);
    }

    /**
     * Write data to a file (creates or overwrites). Returns bytes written.
     */
    public function writeFile(string $path, string $data): int
    {
        $len = strlen($data);
        if ($len > $this->maxFileSize) {
            throw new \RuntimeException(
                "SafeFiles: data too large ({$len} bytes, limit {$this->maxFileSize})"
            );
        }
        if ($this->totalWritten + $len > $this->maxTotalWrite) {
            throw new \RuntimeException(
                "SafeFiles: total write budget exceeded (limit {$this->maxTotalWrite} bytes)"
            );
        }

        $full = $this->resolveForWrite($path);
        $written = file_put_contents($full, $data);
        if ($written === false) {
            throw new \RuntimeException("SafeFiles: could not write to {$path}");
        }
        $this->totalWritten += $written;
        return $written;
    }

    /**
     * Append data to a file. Returns bytes written.
     */
    public function appendFile(string $path, string $data): int
    {
        $len = strlen($data);
        if ($len > $this->maxFileSize) {
            throw new \RuntimeException(
                "SafeFiles: data too large ({$len} bytes, limit {$this->maxFileSize})"
            );
        }
        if ($this->totalWritten + $len > $this->maxTotalWrite) {
            throw new \RuntimeException(
                "SafeFiles: total write budget exceeded (limit {$this->maxTotalWrite} bytes)"
            );
        }

        $full = $this->resolveForWrite($path);

        // Check combined size won't exceed per-file limit
        if (is_file($full)) {
            clearstatcache(true, $full);
            $existing = filesize($full);
            if ($existing + $len > $this->maxFileSize) {
                throw new \RuntimeException(
                    "SafeFiles: file would exceed size limit (" .
                    ($existing + $len) . " bytes, limit {$this->maxFileSize})"
                );
            }
        }

        $written = file_put_contents($full, $data, FILE_APPEND);
        if ($written === false) {
            throw new \RuntimeException("SafeFiles: could not append to {$path}");
        }
        $this->totalWritten += $written;
        return $written;
    }

    /**
     * Check if a path exists (file or directory).
     */
    public function exists(string $path): bool
    {
        $full = $this->resolve($path);
        return file_exists($full);
    }

    /**
     * Delete a file. Returns true if deleted, false if it didn't exist.
     */
    public function deleteFile(string $path): bool
    {
        $full = $this->resolve($path);
        if (!is_file($full)) return false;
        return unlink($full);
    }

    /**
     * List files matching a glob pattern (relative to root).
     * Returns array of relative paths.
     */
    public function listFiles(string $pattern = '*'): array
    {
        // Prevent traversal in patterns
        if (str_contains($pattern, '..')) {
            throw new \RuntimeException("SafeFiles: '..' not allowed in patterns");
        }
        $full = $this->root . '/' . ltrim($pattern, '/');
        $matches = glob($full);
        if ($matches === false) return [];

        $rootLen = strlen($this->root) + 1;
        $result = [];
        foreach ($matches as $m) {
            // Double-check each match is inside root
            $real = realpath($m);
            if ($real !== false && str_starts_with($real, $this->root . '/')) {
                $result[] = substr($real, $rootLen);
            }
        }
        return $result;
    }

    /**
     * Check if a path is a directory.
     */
    public function isDir(string $path): bool
    {
        $full = $this->resolve($path);
        return is_dir($full);
    }

    /**
     * Create a directory (with parents). Returns true on success.
     */
    public function makeDir(string $path): bool
    {
        $full = $this->resolveForWrite($path);
        if (is_dir($full)) return true;
        return mkdir($full, 0755, true);
    }

    /**
     * List directory entries. Returns array of names (no . or ..).
     */
    public function readDir(string $path = '.'): array
    {
        $full = $this->resolve($path);
        if (!is_dir($full)) {
            throw new \RuntimeException("SafeFiles: not a directory: {$path}");
        }
        $entries = scandir($full);
        if ($entries === false) return [];
        return array_values(array_filter($entries, fn($e) => $e !== '.' && $e !== '..'));
    }

    /**
     * Get file size in bytes, or null if not found.
     */
    public function fileSize(string $path): ?int
    {
        $full = $this->resolve($path);
        if (!is_file($full)) return null;
        clearstatcache(true, $full);
        return filesize($full);
    }

    // ── path safety ──

    /**
     * Resolve a relative path to an absolute one inside root.
     * Throws on traversal attempts.
     */
    private function resolve(string $path): string
    {
        if ($path === '' || $path === '.') return $this->root;

        // Block obvious traversal
        $norm = str_replace('\\', '/', $path);
        if (str_contains($norm, '..')) {
            throw new \RuntimeException("SafeFiles: '..' not allowed in paths");
        }
        // Remove leading slash — all paths are relative to root
        $norm = ltrim($norm, '/');

        $full = $this->root . '/' . $norm;

        // If the file exists, use realpath for final check
        $real = realpath($full);
        if ($real !== false) {
            if (!str_starts_with($real, $this->root)) {
                throw new \RuntimeException("SafeFiles: path escapes sandbox");
            }
            return $real;
        }

        // File doesn't exist yet — validate parent
        return $full;
    }

    /**
     * Resolve for a write operation — also validates parent directory exists.
     */
    private function resolveForWrite(string $path): string
    {
        $full = $this->resolve($path);
        $dir = dirname($full);
        if (!is_dir($dir)) {
            throw new \RuntimeException(
                "SafeFiles: parent directory does not exist for: {$path}"
            );
        }
        // Final realpath check on the parent
        $realDir = realpath($dir);
        if ($realDir === false || !str_starts_with($realDir, $this->root)) {
            throw new \RuntimeException("SafeFiles: path escapes sandbox");
        }
        return $realDir . '/' . basename($full);
    }
}
