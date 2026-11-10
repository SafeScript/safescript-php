<?php
/**
 * SafeHttp — sandboxed HTTP client for SafeScript.
 *
 * Provides GET, POST, PUT, DELETE — all restricted to an allowed-domain list.
 * Uses cURL when available, falls back to file_get_contents with stream context.
 * Enforces response size limits and timeouts.
 */

namespace SafeScript;

class SafeHttp
{
    /** @var string[] Allowed domains (lowercase, no port) */
    private array $allowedDomains;

    private int $maxResponseSize;
    private int $timeout;

    /**
     * @param array  $allowedDomains  List of allowed domains, e.g. ['api.example.com', 'data.example.com']
     * @param array  $opts            Options:
     *   - maxResponseSize  int  Max bytes per response (default 2 MB)
     *   - timeout          int  Request timeout in seconds (default 10)
     */
    public function __construct(array $allowedDomains, array $opts = [])
    {
        if (empty($allowedDomains)) {
            throw new \RuntimeException("SafeHttp: at least one allowed domain is required");
        }
        $this->allowedDomains  = array_map('strtolower', $allowedDomains);
        $this->maxResponseSize = $opts['maxResponseSize'] ?? 2_097_152;  // 2 MB
        $this->timeout         = $opts['timeout']         ?? 10;
    }

    // ── public API (registered as allowMethods) ──

    /**
     * GET request. Returns ['status' => int, 'headers' => [...], 'body' => string].
     */
    public function get(string $url, array $headers = []): array
    {
        return $this->request('GET', $url, null, $headers);
    }

    /**
     * POST request with a body string. Returns ['status' => int, 'headers' => [...], 'body' => string].
     */
    public function post(string $url, string $body = '', array $headers = []): array
    {
        return $this->request('POST', $url, $body, $headers);
    }

    /**
     * PUT request. Returns ['status' => int, 'headers' => [...], 'body' => string].
     */
    public function put(string $url, string $body = '', array $headers = []): array
    {
        return $this->request('PUT', $url, $body, $headers);
    }

    /**
     * DELETE request. Returns ['status' => int, 'headers' => [...], 'body' => string].
     */
    public function delete(string $url, array $headers = []): array
    {
        return $this->request('DELETE', $url, null, $headers);
    }

    /**
     * POST JSON. Convenience: serializes $data, sets Content-Type.
     */
    public function postJson(string $url, $data, array $headers = []): array
    {
        $headers['Content-Type'] = 'application/json';
        $body = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return $this->request('POST', $url, $body, $headers);
    }

    /**
     * GET and decode JSON. Returns the decoded body (array/scalar), or throws on parse error.
     */
    public function getJson(string $url, array $headers = []): mixed
    {
        $resp = $this->get($url, $headers);
        $decoded = json_decode($resp['body'], true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException("SafeHttp: JSON parse error: " . json_last_error_msg());
        }
        return $decoded;
    }

    // ── internal ──

    /**
     * Core request method — validates domain, dispatches via cURL or stream.
     */
    private function request(string $method, string $url, ?string $body, array $headers): array
    {
        $this->validateUrl($url);

        if (function_exists('curl_init')) {
            return $this->curlRequest($method, $url, $body, $headers);
        }
        return $this->streamRequest($method, $url, $body, $headers);
    }

    /**
     * Validate that the URL's host is in the allowed list.
     */
    private function validateUrl(string $url): void
    {
        $parsed = parse_url($url);
        if ($parsed === false || empty($parsed['host'])) {
            throw new \RuntimeException("SafeHttp: invalid URL: {$url}");
        }

        $scheme = strtolower($parsed['scheme'] ?? '');
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new \RuntimeException("SafeHttp: only http/https allowed, got: {$scheme}");
        }

        $host = strtolower($parsed['host']);
        $allowed = false;
        foreach ($this->allowedDomains as $domain) {
            if ($host === $domain || str_ends_with($host, '.' . $domain)) {
                $allowed = true;
                break;
            }
        }
        if (!$allowed) {
            throw new \RuntimeException(
                "SafeHttp: domain '{$host}' is not in the allowed list"
            );
        }
    }

    /**
     * cURL-based request.
     */
    private function curlRequest(string $method, string $url, ?string $body, array $headers): array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HEADER         => true,
        ]);

        // Format headers
        $headerLines = [];
        foreach ($headers as $k => $v) {
            $headerLines[] = "{$k}: {$v}";
        }
        if (!empty($headerLines)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headerLines);
        }

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $response = curl_exec($ch);
        if ($response === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException("SafeHttp: request failed: {$err}");
        }

        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $responseHeaders = substr($response, 0, $headerSize);
        $responseBody = substr($response, $headerSize);

        if (strlen($responseBody) > $this->maxResponseSize) {
            throw new \RuntimeException(
                "SafeHttp: response too large (" . strlen($responseBody) .
                " bytes, limit {$this->maxResponseSize})"
            );
        }

        return [
            'status'  => (int) $status,
            'headers' => $this->parseResponseHeaders($responseHeaders),
            'body'    => $responseBody,
        ];
    }

    /**
     * Stream-context-based fallback.
     */
    private function streamRequest(string $method, string $url, ?string $body, array $headers): array
    {
        $headerLines = "";
        foreach ($headers as $k => $v) {
            $headerLines .= "{$k}: {$v}\r\n";
        }

        $contextOpts = [
            'http' => [
                'method'        => $method,
                'header'        => $headerLines,
                'timeout'       => $this->timeout,
                'max_redirects' => 3,
                'ignore_errors' => true,
            ],
        ];
        if ($body !== null) {
            $contextOpts['http']['content'] = $body;
        }

        $ctx = stream_context_create($contextOpts);
        $responseBody = @file_get_contents($url, false, $ctx);
        if ($responseBody === false) {
            throw new \RuntimeException("SafeHttp: request failed for: {$url}");
        }

        if (strlen($responseBody) > $this->maxResponseSize) {
            throw new \RuntimeException(
                "SafeHttp: response too large (" . strlen($responseBody) .
                " bytes, limit {$this->maxResponseSize})"
            );
        }

        // Parse status from $http_response_header (magic variable)
        $status = 0;
        $respHeaders = [];
        if (isset($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $h) {
                if (preg_match('/^HTTP\/[\d.]+ (\d+)/', $h, $m)) {
                    $status = (int) $m[1];
                } else {
                    $parts = explode(':', $h, 2);
                    if (count($parts) === 2) {
                        $respHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                    }
                }
            }
        }

        return [
            'status'  => $status,
            'headers' => $respHeaders,
            'body'    => $responseBody,
        ];
    }

    /**
     * Parse raw header string into associative array.
     */
    private function parseResponseHeaders(string $raw): array
    {
        $headers = [];
        foreach (explode("\r\n", $raw) as $line) {
            if (str_contains($line, ':')) {
                $parts = explode(':', $line, 2);
                $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
        }
        return $headers;
    }
}
