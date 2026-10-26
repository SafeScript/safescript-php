/**
 * SafeScript v1.2 — URL Policy
 *
 * Purpose-based URL authorization. Every attribute or property assignment
 * that can trigger a network request passes through this policy.
 *
 * Exports:
 *   createURLPolicy(hostHook, documentOrigin) → { check(ctx) }
 *   classifyPurpose(tagName, attrName)        → purpose string
 */
'use strict';

// ── Purpose classification ──────────────────────────────────────────

var PURPOSE_MAP = Object.create(null);

// image
PURPOSE_MAP['img.src'] = 'image';
PURPOSE_MAP['img.srcset'] = 'image';
PURPOSE_MAP['input.src'] = 'image';   // input[type=image]
PURPOSE_MAP['video.poster'] = 'image';

// media
PURPOSE_MAP['video.src'] = 'media';
PURPOSE_MAP['audio.src'] = 'media';
PURPOSE_MAP['source.src'] = 'media';
PURPOSE_MAP['track.src'] = 'media';

// navigation
PURPOSE_MAP['a.href'] = 'navigation';
PURPOSE_MAP['area.href'] = 'navigation';

// form
PURPOSE_MAP['form.action'] = 'form';
PURPOSE_MAP['form.formaction'] = 'form';
PURPOSE_MAP['button.formaction'] = 'form';
PURPOSE_MAP['input.formaction'] = 'form';

// style
PURPOSE_MAP['link.href'] = 'style';

/**
 * Classify the purpose of a URL based on the element tag and attribute name.
 *
 * @param {string} tagName   — lowercase tag name (e.g. 'img')
 * @param {string} attrName  — lowercase attribute name (e.g. 'src')
 * @returns {string} One of 'image', 'media', 'navigation', 'form', 'style', 'other'
 */
function classifyPurpose(tagName, attrName) {
  var key = tagName + '.' + attrName;
  return PURPOSE_MAP[key] || 'other';
}

// ── Scheme checks ───────────────────────────────────────────────────

var DANGEROUS_SCHEME_RE = /^\s*javascript\s*:/i;
var DATA_SCHEME_RE = /^\s*data\s*:/i;
var DATA_IMAGE_RE = /^\s*data\s*:\s*image\//i;

/**
 * Check whether a URL uses the javascript: scheme (including whitespace tricks).
 */
function isJavascriptURL(url) {
  return DANGEROUS_SCHEME_RE.test(url);
}

/**
 * Check whether a URL uses the data: scheme.
 */
function isDataURL(url) {
  return DATA_SCHEME_RE.test(url);
}

/**
 * Check whether a data: URL is specifically an image.
 */
function isDataImageURL(url) {
  return DATA_IMAGE_RE.test(url);
}

// ── Origin extraction ───────────────────────────────────────────────

/**
 * Extract the origin from a URL string.
 * Returns null for non-http(s) URLs, data: URLs, etc.
 */
function extractOrigin(url) {
  var m = url.match(/^(https?:\/\/[^\/\?#]+)/i);
  return m ? m[1].toLowerCase() : null;
}

// ── Default policy (no host hook) ───────────────────────────────────

/**
 * Apply the default URL policy:
 *   image:      same-origin allowed
 *   media:      same-origin allowed
 *   navigation: same-origin allowed
 *   style:      deny
 *   form:       deny
 *   other:      deny
 */
function defaultCheck(ctx, docOrigin) {
  var purpose = ctx.purpose;

  if (purpose === 'image' || purpose === 'media' || purpose === 'navigation') {
    var urlOrigin = extractOrigin(ctx.url);
    if (urlOrigin && docOrigin && urlOrigin === docOrigin) {
      return { allow: true };
    }
    return { allow: false };
  }

  // style, form, other — deny by default
  return { allow: false };
}

// ── Public API ──────────────────────────────────────────────────────

/**
 * Create a URL policy checker.
 *
 * @param {Function|null} hostHook — optional host-provided function(ctx) → { allow, url? }
 * @param {string|null} documentOrigin — the document's origin for same-origin checks
 *                                       (e.g. 'https://example.com')
 * @returns {{ check: function(ctx): { allow: boolean, url?: string } }}
 */
function createURLPolicy(hostHook, documentOrigin) {
  var docOrigin = documentOrigin ? documentOrigin.toLowerCase() : null;

  return {
    /**
     * Check whether a URL is allowed.
     *
     * @param {Object} ctx
     *   - element:   {string} tag name ('img', 'a', etc.)
     *   - attribute: {string} attribute name ('src', 'href', etc.)
     *   - url:       {string} the proposed URL (should be resolved to absolute)
     *   - purpose:   {string} 'image'|'media'|'navigation'|'style'|'form'|'other'
     * @returns {{ allow: boolean, url?: string }}
     */
    check: function(ctx) {
      var url = ctx.url;

      // Layer 1 invariants — non-negotiable, host cannot relax
      if (isJavascriptURL(url)) {
        return { allow: false };
      }

      if (isDataURL(url)) {
        // data:image/* MAY be allowed if the host hook allows it
        if (isDataImageURL(url) && hostHook) {
          var hookResult = hostHook(ctx);
          if (hookResult && hookResult.allow) {
            return hookResult;
          }
        }
        // All other data: URLs — always deny
        return { allow: false };
      }

      // If host provided a hook, delegate to it
      if (hostHook) {
        var result = hostHook(ctx);
        // Normalize: must have { allow: boolean }
        if (!result || typeof result !== 'object') {
          return { allow: false };
        }
        return {
          allow: !!result.allow,
          url: result.url || undefined
        };
      }

      // No host hook — apply default policy
      return defaultCheck(ctx, docOrigin);
    }
  };
}

exports.createURLPolicy = createURLPolicy;
exports.classifyPurpose = classifyPurpose;
exports.isJavascriptURL = isJavascriptURL;
exports.isDataURL = isDataURL;
exports.isDataImageURL = isDataImageURL;
exports.extractOrigin = extractOrigin;
