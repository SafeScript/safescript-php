<?php
/**
 * SafeScript URL Policy — purpose-based URL authorization.
 *
 * Same interface as the JS url-policy.js module.
 * Classifies URLs by purpose (image, navigation, media, style, form, other)
 * and applies default or host-supplied authorization.
 */

namespace SafeScript;

class UrlPolicy
{
    /** @var callable|null Host-supplied URL authorization hook */
    private $hook;

    /** @var string Document origin for same-origin checks */
    private $origin;

    /**
     * @param callable|null $hook  function(array $ctx) → array { 'allow' => bool, 'url' => string? }
     * @param string        $origin  Document origin (scheme + host + port)
     */
    public function __construct($hook = null, $origin = '')
    {
        $this->hook = $hook;
        $this->origin = $origin;
    }

    /**
     * Classify a tag+attribute pair into a URL purpose.
     *
     * @param string $tag   Lowercase tag name
     * @param string $attr  Lowercase attribute name
     * @return string       Purpose: image|media|navigation|style|form|other
     */
    public static function classifyPurpose($tag, $attr)
    {
        // Image
        if ($tag === 'img' && ($attr === 'src' || $attr === 'srcset')) return 'image';
        if ($tag === 'input' && $attr === 'src') return 'image';  // type=image
        if ($tag === 'video' && $attr === 'poster') return 'image';

        // Media
        if (($tag === 'video' || $tag === 'audio') && $attr === 'src') return 'media';
        if ($tag === 'source' && $attr === 'src') return 'media';
        if ($tag === 'track' && $attr === 'src') return 'media';

        // Navigation
        if (($tag === 'a' || $tag === 'area') && $attr === 'href') return 'navigation';

        // Form
        if ($tag === 'form' && ($attr === 'action' || $attr === 'formaction')) return 'form';
        if ($attr === 'formaction') return 'form';

        // Style
        if ($tag === 'link' && $attr === 'href') return 'style';

        return 'other';
    }

    /**
     * Check a URL against the policy.
     *
     * @param array $ctx  { 'element' => tag, 'attribute' => attr, 'url' => absolute URL, 'purpose' => string }
     * @return array      { 'allow' => bool, 'url' => string? }
     */
    public function check($ctx)
    {
        $url = isset($ctx['url']) ? $ctx['url'] : '';

        // Layer 1: always deny javascript: and vbscript:
        $lower = strtolower(trim($url));
        if (strpos($lower, 'javascript:') === 0) return array('allow' => false);
        if (strpos($lower, 'vbscript:') === 0) return array('allow' => false);

        // Layer 1: deny data: except data:image/* when purpose is image
        if (strpos($lower, 'data:') === 0) {
            $purpose = isset($ctx['purpose']) ? $ctx['purpose'] : 'other';
            if ($purpose === 'image' && preg_match('/^data:image\//', $lower)) {
                // Allow data:image/* for images — but still ask host hook
            } else {
                return array('allow' => false);
            }
        }

        // Host hook overrides defaults
        if ($this->hook) {
            $result = call_user_func($this->hook, $ctx);
            if (is_array($result)) return $result;
            return array('allow' => false);
        }

        // Default policy
        $purpose = isset($ctx['purpose']) ? $ctx['purpose'] : 'other';
        return $this->applyDefault($url, $purpose);
    }

    /**
     * Default policy when no host hook is provided.
     */
    private function applyDefault($url, $purpose)
    {
        $sameOrigin = $this->isSameOrigin($url);

        switch ($purpose) {
            case 'image':
            case 'media':
                return array('allow' => $sameOrigin);
            case 'navigation':
                return array('allow' => $sameOrigin);
            case 'style':
            case 'form':
            case 'other':
            default:
                return array('allow' => false);
        }
    }

    /**
     * Check if a URL is same-origin with the document.
     */
    private function isSameOrigin($url)
    {
        if (!$this->origin) return false;
        $parsed = parse_url($url);
        if (!$parsed || !isset($parsed['scheme']) || !isset($parsed['host'])) return false;
        $port = isset($parsed['port']) ? $parsed['port'] : ($parsed['scheme'] === 'https' ? 443 : 80);
        $urlOrigin = $parsed['scheme'] . '://' . $parsed['host'] . ':' . $port;

        $docParsed = parse_url($this->origin);
        if (!$docParsed) return false;
        $docPort = isset($docParsed['port']) ? $docParsed['port'] :
            (isset($docParsed['scheme']) && $docParsed['scheme'] === 'https' ? 443 : 80);
        $docOrigin = (isset($docParsed['scheme']) ? $docParsed['scheme'] : 'https') .
            '://' . (isset($docParsed['host']) ? $docParsed['host'] : '') . ':' . $docPort;

        return $urlOrigin === $docOrigin;
    }

    /**
     * Parse srcset attribute and check each URL.
     *
     * @param string $srcset  The srcset attribute value
     * @param string $tag     Element tag name
     * @return string         Sanitized srcset (entries with denied URLs removed)
     */
    public function sanitizeSrcset($srcset, $tag)
    {
        $entries = preg_split('/\s*,\s*/', trim($srcset));
        $kept = array();
        foreach ($entries as $entry) {
            $entry = trim($entry);
            if (!$entry) continue;
            $parts = preg_split('/\s+/', $entry, 2);
            $url = $parts[0];
            $descriptor = isset($parts[1]) ? $parts[1] : '';
            $result = $this->check(array(
                'element' => $tag,
                'attribute' => 'srcset',
                'url' => $url,
                'purpose' => 'image'
            ));
            if (!empty($result['allow'])) {
                $finalUrl = isset($result['url']) ? $result['url'] : $url;
                $kept[] = $descriptor ? ($finalUrl . ' ' . $descriptor) : $finalUrl;
            }
        }
        return implode(', ', $kept);
    }

    /**
     * Check if a URL is a javascript: URL.
     *
     * @param string $url
     * @return bool
     */
    public static function isJavascriptURL(string $url): bool
    {
        $lower = strtolower(trim(preg_replace('/\s/', '', $url)));
        return strpos($lower, 'javascript:') === 0;
    }
}
