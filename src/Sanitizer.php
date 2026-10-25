<?php
/**
 * SafeScript v1.2 — HTML Sanitizer (PHP parity)
 *
 * Tree-walking sanitizer using DOMDocument.
 * Enforces Layer 1 invariants (non-negotiable) and Layer 2 host policy.
 *
 * PHP port of src/dom/sanitizer.js — same two-layer architecture,
 * same attack-vector coverage, same policy format.
 */

namespace SafeScript;

class Sanitizer
{
    // ── Layer 1 invariants — always stripped, host cannot relax ──────

    /**
     * Elements that are ALWAYS removed regardless of the host tag allowlist.
     */
    private const LAYER1_DENY_TAGS = [
        'script', 'iframe', 'object', 'embed', 'applet',
        'base', 'link', 'noscript', 'foreignobject',
        // Fix #5: SVG animation tags
        'animate', 'set', 'animatetransform', 'animatemotion',
        // Fix #8: inline <style> elements
        'style',
    ];

    /**
     * Document properties that elements must not shadow via id or name
     * (DOM clobbering defense).
     */
    private const CLOBBER_TARGETS = [
        'cookie', 'domain', 'forms', 'images', 'links', 'scripts',
        'body', 'head', 'documentElement', 'defaultView',
        'location', 'title', 'URL', 'referrer', 'anchors',
        'characterSet', 'charset', 'contentType', 'doctype',
        'embeds', 'plugins', 'styleSheets', 'implementation',
        'getElementById', 'getElementsByClassName', 'getElementsByName',
        'getElementsByTagName', 'querySelector', 'querySelectorAll',
        'write', 'writeln', 'open', 'close', 'createElement',
        'createTextNode', 'createDocumentFragment', 'createEvent',
        'all', 'children', 'firstElementChild', 'lastElementChild',
    ];

    /**
     * Attributes that carry URLs and need URL policy checks.
     */
    private const URL_ATTRS = [
        'src', 'href', 'action', 'formaction', 'poster', 'srcset', 'data', 'cite',
    ];

    /**
     * Default tag allowlist — safe subset of HTML.
     */
    private const DEFAULT_TAGS = [
        'div', 'span', 'p', 'a', 'b', 'i', 'u', 'em', 'strong', 'small', 'sub', 'sup',
        'br', 'hr', 'wbr',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'ul', 'ol', 'li', 'dl', 'dt', 'dd',
        'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td', 'caption', 'colgroup', 'col',
        'img', 'picture', 'source', 'video', 'audio', 'track',
        'figure', 'figcaption', 'blockquote', 'cite', 'q',
        'pre', 'code', 'kbd', 'samp', 'var', 'mark', 'abbr', 'time', 'dfn',
        'details', 'summary',
        'section', 'article', 'aside', 'nav', 'header', 'footer', 'main', 'address',
        'form', 'input', 'textarea', 'select', 'option', 'optgroup',
        'button', 'label', 'fieldset', 'legend', 'output', 'progress', 'meter',
        'canvas', 'svg', 'g', 'path', 'circle', 'rect', 'line', 'polyline', 'polygon',
        'ellipse', 'text', 'tspan', 'defs', 'use', 'clippath', 'mask', 'pattern',
        'lineargradient', 'radialgradient', 'stop',
    ];

    /**
     * Default attribute allowlist — per-tag and global.
     */
    private const DEFAULT_ATTRIBUTES = [
        '*' => [
            'id', 'class', 'style', 'title', 'lang', 'dir', 'tabindex', 'role',
            'aria-*', 'data-*', 'hidden', 'draggable',
        ],
        'a'      => ['href', 'target', 'rel', 'download'],
        'img'    => ['src', 'srcset', 'alt', 'width', 'height', 'loading', 'decoding', 'sizes'],
        'video'  => ['src', 'poster', 'controls', 'autoplay', 'muted', 'loop', 'preload', 'width', 'height'],
        'audio'  => ['src', 'controls', 'autoplay', 'muted', 'loop', 'preload'],
        'source' => ['src', 'srcset', 'type', 'media', 'sizes'],
        'track'  => ['src', 'kind', 'srclang', 'label', 'default'],
        'input'  => ['type', 'name', 'value', 'placeholder', 'required', 'disabled', 'checked',
                     'readonly', 'min', 'max', 'step', 'pattern', 'maxlength', 'minlength',
                     'autocomplete', 'autofocus', 'multiple', 'accept', 'size', 'list', 'form'],
        'textarea' => ['name', 'rows', 'cols', 'placeholder', 'required', 'disabled', 'readonly',
                       'maxlength', 'minlength', 'wrap', 'autocomplete', 'autofocus', 'form'],
        'select'   => ['name', 'required', 'disabled', 'multiple', 'size', 'form', 'autofocus'],
        'option'   => ['value', 'selected', 'disabled', 'label'],
        'optgroup' => ['label', 'disabled'],
        'button'   => ['type', 'name', 'value', 'disabled', 'form', 'autofocus'],
        'form'     => ['action', 'method', 'enctype', 'novalidate', 'target', 'autocomplete'],
        'label'    => ['for'],
        'td'       => ['colspan', 'rowspan', 'headers'],
        'th'       => ['colspan', 'rowspan', 'headers', 'scope', 'abbr'],
        'col'      => ['span'],
        'colgroup' => ['span'],
        'time'     => ['datetime'],
        'progress' => ['value', 'max'],
        'meter'    => ['value', 'min', 'max', 'low', 'high', 'optimum'],
        'output'   => ['for', 'name', 'form'],
        'details'  => ['open'],
        'canvas'   => ['width', 'height'],
        'svg'      => ['viewbox', 'width', 'height', 'xmlns', 'fill', 'stroke', 'stroke-width'],
        'path'     => ['d', 'fill', 'stroke', 'stroke-width', 'transform'],
        'circle'   => ['cx', 'cy', 'r', 'fill', 'stroke', 'stroke-width'],
        'rect'     => ['x', 'y', 'width', 'height', 'rx', 'ry', 'fill', 'stroke', 'stroke-width'],
        'line'     => ['x1', 'y1', 'x2', 'y2', 'stroke', 'stroke-width'],
        'polyline' => ['points', 'fill', 'stroke', 'stroke-width'],
        'polygon'  => ['points', 'fill', 'stroke', 'stroke-width'],
        'ellipse'  => ['cx', 'cy', 'rx', 'ry', 'fill', 'stroke', 'stroke-width'],
        'text'     => ['x', 'y', 'dx', 'dy', 'text-anchor', 'fill', 'font-size', 'font-family', 'transform'],
        'tspan'    => ['x', 'y', 'dx', 'dy'],
        'g'        => ['transform', 'fill', 'stroke', 'stroke-width', 'opacity'],
        'use'      => ['href', 'x', 'y', 'width', 'height'],
        'clippath' => ['id'],
        'lineargradient' => ['id', 'x1', 'y1', 'x2', 'y2', 'gradientunits', 'gradienttransform'],
        'radialgradient' => ['id', 'cx', 'cy', 'r', 'fx', 'fy', 'gradientunits', 'gradienttransform'],
        'stop'     => ['offset', 'stop-color', 'stop-opacity'],
    ];

    // ── Instance state ──────────────────────────────────────────────

    /** @var array<string, true> Allowed tags lookup */
    private array $allowedTags;

    /** @var array<string, array> Allowed attributes per tag */
    private array $allowedAttrs;

    /** @var UrlPolicy URL policy checker */
    private UrlPolicy $urlPolicy;

    /** @var int Maximum HTML input size in bytes */
    private int $maxHTMLBytes;

    /** @var int Maximum element count after sanitization */
    private int $maxNodes;

    /** @var array<string, true> Clobber targets lookup */
    private array $clobberTargets;

    /** @var array<string, true> Layer 1 deny tags lookup */
    private array $denyTags;

    /** @var array<string, true> URL attributes lookup */
    private array $urlAttrs;

    /**
     * Create a sanitizer with the given policy.
     *
     * @param array $policy {
     *   @type string[]      $tags       Allowed tag names (defaults to DEFAULT_TAGS)
     *   @type array         $attributes Allowed attrs per tag (defaults to DEFAULT_ATTRIBUTES)
     *   @type callable|null $url        Host URL policy hook
     *   @type array         $limits     { maxHTMLBytes: int, maxNodes: int }
     * }
     */
    public function __construct(array $policy = [])
    {
        // Build tag lookup
        $tags = $policy['tags'] ?? self::DEFAULT_TAGS;
        $this->allowedTags = [];
        foreach ($tags as $tag) {
            $this->allowedTags[strtolower($tag)] = true;
        }

        // Build attribute lookup
        $attrs = $policy['attributes'] ?? self::DEFAULT_ATTRIBUTES;
        $this->allowedAttrs = [];
        foreach ($attrs as $tag => $attrList) {
            $lower = strtolower($tag);
            $this->allowedAttrs[$lower] = [];
            foreach ($attrList as $attr) {
                $this->allowedAttrs[$lower][] = strtolower($attr);
            }
        }

        // URL policy
        $urlHook = $policy['url'] ?? null;
        $docOrigin = $policy['documentOrigin'] ?? null;
        $this->urlPolicy = new UrlPolicy($urlHook, $docOrigin);

        // Limits
        $limits = $policy['limits'] ?? [];
        $this->maxHTMLBytes = $limits['maxHTMLBytes'] ?? 262144;
        $this->maxNodes     = $limits['maxNodes']     ?? 5000;

        // Build lookups
        $this->clobberTargets = array_flip(self::CLOBBER_TARGETS);
        $this->denyTags = array_flip(self::LAYER1_DENY_TAGS);
        $this->urlAttrs = array_flip(self::URL_ATTRS);
    }

    /**
     * Sanitize an HTML string and return the cleaned HTML.
     *
     * @param string $html Untrusted HTML input
     * @return string Sanitized HTML
     * @throws \RangeException If input exceeds size or node limits
     * @throws \InvalidArgumentException If input is not a string
     */
    public function sanitize(string $html): string
    {
        // Size check
        $byteLen = strlen($html);
        if ($byteLen > $this->maxHTMLBytes) {
            throw new \RangeException(
                "SafeScript: HTML input exceeds limit ({$byteLen} > {$this->maxHTMLBytes} bytes)"
            );
        }

        // Empty string — nothing to do
        if (trim($html) === '') {
            return '';
        }

        // Parse into DOM
        $doc = new \DOMDocument('1.0', 'UTF-8');

        // Suppress libxml warnings for malformed HTML
        $prev = libxml_use_internal_errors(true);

        // Wrap in a container to handle fragments properly
        $wrapped = '<div id="__safescript_root__">' . $html . '</div>';
        $doc->loadHTML(
            '<?xml encoding="UTF-8">' . $wrapped,
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );

        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        // Find our wrapper
        $root = null;
        $xpath = new \DOMXPath($doc);
        $nodes = $xpath->query('//*[@id="__safescript_root__"]');
        if ($nodes && $nodes->length > 0) {
            $root = $nodes->item(0);
        }

        if (!$root) {
            // Fallback: use body or documentElement
            $root = $doc->getElementsByTagName('body')->item(0)
                ?? $doc->documentElement
                ?? $doc;
        }

        // Walk and sanitize
        $this->walkAndSanitize($root);

        // Count nodes
        $nodeCount = $this->countElements($root);
        if ($nodeCount > $this->maxNodes) {
            throw new \RangeException(
                "SafeScript: sanitized HTML exceeds node limit ({$nodeCount} > {$this->maxNodes})"
            );
        }

        // Serialize children of root (not root itself)
        $output = '';
        foreach ($root->childNodes as $child) {
            $output .= $doc->saveHTML($child);
        }

        return $output;
    }

    /**
     * Walk and sanitize a DOM tree in place.
     * Removes disallowed elements, strips disallowed attributes.
     */
    private function walkAndSanitize(\DOMNode $node): void
    {
        // Collect children first (modifying during iteration is unsafe)
        $children = [];
        if ($node->childNodes) {
            foreach ($node->childNodes as $child) {
                $children[] = $child;
            }
        }

        foreach ($children as $child) {
            if ($child->nodeType !== XML_ELEMENT_NODE) {
                continue;
            }

            $tagName = strtolower($child->nodeName);

            // Layer 1: always-deny elements — remove entirely (children too)
            if (isset($this->denyTags[$tagName])) {
                $child->parentNode->removeChild($child);
                continue;
            }

            // Layer 1: meta http-equiv=refresh
            if ($tagName === 'meta') {
                $httpEquiv = $child->getAttribute('http-equiv');
                if ($httpEquiv && strtolower($httpEquiv) === 'refresh') {
                    $child->parentNode->removeChild($child);
                    continue;
                }
            }

            // Layer 2: tag allowlist — promote children, remove element
            if (!isset($this->allowedTags[$tagName])) {
                // Move children up before removing
                while ($child->firstChild) {
                    $child->parentNode->insertBefore($child->firstChild, $child);
                }
                $child->parentNode->removeChild($child);
                continue;
            }

            // Sanitize attributes on this allowed element
            $this->sanitizeAttributes($child, $tagName);

            // Recurse into children
            $this->walkAndSanitize($child);
        }
    }

    /**
     * Sanitize attributes on a single element.
     */
    private function sanitizeAttributes(\DOMElement $el, string $tagName): void
    {
        // Collect attribute names first (modifying during iteration is unsafe)
        $toRemove = [];
        $attrs = $el->attributes;

        for ($i = 0; $i < $attrs->length; $i++) {
            $attr = $attrs->item($i);
            $name = strtolower($attr->name);
            $value = $attr->value;

            // Layer 1: strip all on* event handler attributes
            if (strlen($name) > 2 && substr($name, 0, 2) === 'on') {
                $toRemove[] = $attr->name;
                continue;
            }

            // Layer 1: deny srcdoc
            if ($name === 'srcdoc') {
                $toRemove[] = $attr->name;
                continue;
            }

            // Layer 1: DOM clobbering check
            if (($name === 'id' || $name === 'name') && isset($this->clobberTargets[$value])) {
                $toRemove[] = $attr->name;
                continue;
            }

            // Layer 2: attribute allowlist check
            if (!$this->isAttrAllowed($tagName, $name)) {
                $toRemove[] = $attr->name;
                continue;
            }

            // URL attribute checks
            if (isset($this->urlAttrs[$name])) {
                if ($name === 'srcset') {
                    $cleanSrcset = $this->sanitizeSrcset($tagName, $value);
                    if ($cleanSrcset === null) {
                        $toRemove[] = $attr->name;
                    } else {
                        $el->setAttribute($attr->name, $cleanSrcset);
                    }
                } else {
                    // Layer 1: javascript: URLs are always denied
                    if (UrlPolicy::isJavascriptURL($value)) {
                        $toRemove[] = $attr->name;
                        continue;
                    }

                    // Run URL policy
                    $purpose = UrlPolicy::classifyPurpose($tagName, $name);
                    $result = $this->urlPolicy->check([
                        'element'   => $tagName,
                        'attribute' => $name,
                        'url'       => $value,
                        'purpose'   => $purpose,
                    ]);

                    if (!$result['allow']) {
                        $toRemove[] = $attr->name;
                    } elseif (isset($result['url']) && $result['url'] !== $value) {
                        $el->setAttribute($attr->name, $result['url']);
                    }
                }
                continue;
            }

            // Sanitize inline style values
            if ($name === 'style') {
                $cleanStyle = $this->sanitizeStyleValue($value);
                if ($cleanStyle !== $value) {
                    $el->setAttribute('style', $cleanStyle);
                }
                continue;
            }
        }

        // Remove disallowed attributes
        foreach ($toRemove as $attrName) {
            $el->removeAttribute($attrName);
        }

        // target="_blank" without proper rel → add rel="noopener noreferrer"
        if (in_array($tagName, ['a', 'area'], true)
            && $el->getAttribute('target') === '_blank'
        ) {
            $rel = $el->getAttribute('rel') ?: '';
            $hasNoopener = strpos($rel, 'noopener') !== false;
            $hasNoreferrer = strpos($rel, 'noreferrer') !== false;
            if (!$hasNoopener || !$hasNoreferrer) {
                $parts = $rel ? preg_split('/\s+/', $rel) : [];
                if (!$hasNoopener) {
                    $parts[] = 'noopener';
                }
                if (!$hasNoreferrer) {
                    $parts[] = 'noreferrer';
                }
                $el->setAttribute('rel', implode(' ', $parts));
            }
        }
    }

    /**
     * Check whether an attribute is allowed for a given tag.
     */
    private function isAttrAllowed(string $tagName, string $attrName): bool
    {
        // Check global attributes (from '*' key)
        if (isset($this->allowedAttrs['*'])) {
            if ($this->matchesAttrList($attrName, $this->allowedAttrs['*'])) {
                return true;
            }
        }

        // Check tag-specific attributes
        if (isset($this->allowedAttrs[$tagName])) {
            if ($this->matchesAttrList($attrName, $this->allowedAttrs[$tagName])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if an attribute matches any pattern in a list (supports aria-* and data-* globs).
     */
    private function matchesAttrList(string $attr, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if ($pattern === $attr) {
                return true;
            }
            // Glob pattern: 'aria-*', 'data-*'
            if (substr($pattern, -1) === '*') {
                $prefix = substr($pattern, 0, -1);
                if (strpos($attr, $prefix) === 0) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Sanitize an inline style value — strip url(), expression(), -moz-binding.
     */
    private function sanitizeStyleValue(string $value): string
    {
        $clean = $value;
        $clean = preg_replace('/url\s*\(/i', '/* blocked */ (', $clean);
        $clean = preg_replace('/expression\s*\(/i', '/* blocked */ (', $clean);
        $clean = preg_replace('/-moz-binding\s*:/i', '/* blocked */:', $clean);
        // Fix #6: additional CSS functions that can load external resources
        $clean = preg_replace('/image-set\s*\(/i', '/* blocked */ (', $clean);
        $clean = preg_replace('/cross-fade\s*\(/i', '/* blocked */ (', $clean);
        $clean = preg_replace('/paint\s*\(/i', '/* blocked */ (', $clean);
        $clean = preg_replace('/-webkit-image-set\s*[:(]/i', '/* blocked */(', $clean);
        return $clean;
    }

    /**
     * Parse srcset and check each URL through the URL policy.
     *
     * @return string|null Cleaned srcset, or null if all entries denied
     */
    private function sanitizeSrcset(string $tagName, string $srcset): ?string
    {
        $entries = $this->parseSrcset($srcset);
        $allowed = [];

        foreach ($entries as $entry) {
            $purpose = UrlPolicy::classifyPurpose($tagName, 'srcset');
            $result = $this->urlPolicy->check([
                'element'   => $tagName,
                'attribute' => 'srcset',
                'url'       => $entry['url'],
                'purpose'   => $purpose,
            ]);

            if ($result['allow']) {
                $entry['url'] = $result['url'] ?? $entry['url'];
                $allowed[] = $entry;
            }
        }

        if (empty($allowed)) {
            return null;
        }

        return $this->buildSrcset($allowed);
    }

    /**
     * Parse srcset into individual entries.
     *
     * @return array<array{url: string, descriptor: string}>
     */
    private function parseSrcset(string $srcset): array
    {
        $entries = [];
        $parts = explode(',', $srcset);
        foreach ($parts as $part) {
            $trimmed = trim($part);
            if ($trimmed === '') {
                continue;
            }
            if (preg_match('/^(\S+)\s+(.*)$/', $trimmed, $m)) {
                $entries[] = ['url' => $m[1], 'descriptor' => trim($m[2])];
            } else {
                $entries[] = ['url' => $trimmed, 'descriptor' => ''];
            }
        }
        return $entries;
    }

    /**
     * Rebuild srcset from array of entries.
     */
    private function buildSrcset(array $entries): string
    {
        $parts = [];
        foreach ($entries as $e) {
            $parts[] = $e['descriptor'] ? $e['url'] . ' ' . $e['descriptor'] : $e['url'];
        }
        return implode(', ', $parts);
    }

    /**
     * Count element nodes in a subtree.
     */
    private function countElements(\DOMNode $root): int
    {
        $count = 0;
        if ($root->nodeType === XML_ELEMENT_NODE) {
            $count++;
        }
        if ($root->childNodes) {
            foreach ($root->childNodes as $child) {
                $count += $this->countElements($child);
            }
        }
        return $count;
    }
}
