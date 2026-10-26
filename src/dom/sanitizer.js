/**
 * SafeScript v1.2 — HTML Sanitizer
 *
 * Tree-walking sanitizer operating on DocumentFragment.
 * Enforces Layer 1 invariants (non-negotiable) and Layer 2 host policy.
 *
 * Exports:
 *   createSanitizer(policy, domImpl?) → { sanitize(html) → string, sanitizeFragment(html) → DocumentFragment }
 */
'use strict';

var urlPolicy = require('./url-policy');

// ── Layer 1 invariants — always stripped, host cannot relax ─────────

/**
 * Elements that are ALWAYS removed regardless of the host tag allowlist.
 * These are security-critical and can never be legitimate in sandboxed code.
 */
var LAYER1_DENY_TAGS = Object.create(null);
var _denyTags = [
  'script', 'iframe', 'object', 'embed', 'applet',
  'base', 'link', 'noscript',
  // SVG dangerous elements
  'foreignobject',
  // Fix #5: SVG animation tags (can modify attributes to bypass policy)
  'animate', 'set', 'animatetransform', 'animatemotion',
  // Fix #8: inline <style> elements (can restyle host page)
  'style'
];
for (var i = 0; i < _denyTags.length; i++) {
  LAYER1_DENY_TAGS[_denyTags[i]] = true;
}

/**
 * Document properties that elements must not shadow via id or name
 * (DOM clobbering defense).
 */
var DOCUMENT_PROPS = Object.create(null);
var _docProps = [
  'cookie', 'domain', 'forms', 'images', 'links', 'scripts',
  'body', 'head', 'documentElement', 'defaultView',
  'location', 'title', 'URL', 'referrer', 'anchors',
  'characterSet', 'charset', 'contentType', 'doctype',
  'embeds', 'plugins', 'styleSheets', 'implementation',
  'getElementById', 'getElementsByClassName', 'getElementsByName',
  'getElementsByTagName', 'querySelector', 'querySelectorAll',
  'write', 'writeln', 'open', 'close', 'createElement',
  'createTextNode', 'createDocumentFragment', 'createEvent',
  'all', 'children', 'firstElementChild', 'lastElementChild'
];
for (var j = 0; j < _docProps.length; j++) {
  DOCUMENT_PROPS[_docProps[j]] = true;
}

/**
 * Attributes that carry URLs and need URL policy checks.
 */
var URL_ATTRS = Object.create(null);
var _urlAttrs = ['src', 'href', 'action', 'formaction', 'poster', 'srcset', 'data', 'cite'];
for (var k = 0; k < _urlAttrs.length; k++) {
  URL_ATTRS[_urlAttrs[k]] = true;
}

/**
 * Regex patterns for CSS value sanitization.
 */
var CSS_URL_RE = /url\s*\(/gi;
var CSS_EXPRESSION_RE = /expression\s*\(/gi;
var CSS_MOZ_BINDING_RE = /-moz-binding\s*:/gi;
// Fix #6: additional CSS functions that can load external resources
var CSS_IMAGE_SET_RE = /image-set\s*\(/gi;
var CSS_CROSS_FADE_RE = /cross-fade\s*\(/gi;
var CSS_PAINT_RE = /paint\s*\(/gi;
var CSS_WEBKIT_IMAGE_SET_RE = /-webkit-image-set\s*[:(]/gi;

// ── Helpers ─────────────────────────────────────────────────────────

/**
 * Test whether an attribute name is an event handler (on*).
 */
function isEventAttr(name) {
  return name.length > 2 && name.charCodeAt(0) === 111 /* o */ &&
         name.charCodeAt(1) === 110 /* n */;
}

/**
 * Sanitize an inline style value — strip url(), expression(), -moz-binding.
 * Returns the cleaned value or empty string if everything was stripped.
 */
function sanitizeStyleValue(value) {
  if (!value) return '';
  var clean = value;
  clean = clean.replace(CSS_URL_RE, '/* blocked */ (');
  clean = clean.replace(CSS_EXPRESSION_RE, '/* blocked */ (');
  clean = clean.replace(CSS_MOZ_BINDING_RE, '/* blocked */:');
  // Fix #6: block additional CSS functions that can load external resources
  clean = clean.replace(CSS_IMAGE_SET_RE, '/* blocked */ (');
  clean = clean.replace(CSS_CROSS_FADE_RE, '/* blocked */ (');
  clean = clean.replace(CSS_PAINT_RE, '/* blocked */ (');
  clean = clean.replace(CSS_WEBKIT_IMAGE_SET_RE, '/* blocked */(');
  return clean;
}

/**
 * Parse srcset into individual entries, returning array of { url, descriptor }.
 */
function parseSrcset(srcset) {
  var entries = [];
  var parts = srcset.split(',');
  for (var i = 0; i < parts.length; i++) {
    var trimmed = parts[i].trim();
    if (!trimmed) continue;
    var spaceIdx = trimmed.search(/\s/);
    if (spaceIdx === -1) {
      entries.push({ url: trimmed, descriptor: '' });
    } else {
      entries.push({
        url: trimmed.slice(0, spaceIdx),
        descriptor: trimmed.slice(spaceIdx).trim()
      });
    }
  }
  return entries;
}

/**
 * Rebuild srcset from array of { url, descriptor }.
 */
function buildSrcset(entries) {
  var parts = [];
  for (var i = 0; i < entries.length; i++) {
    var e = entries[i];
    parts.push(e.descriptor ? e.url + ' ' + e.descriptor : e.url);
  }
  return parts.join(', ');
}

/**
 * Check if a meta element has http-equiv="refresh".
 */
function isMetaRefresh(el) {
  var httpEquiv = el.getAttribute('http-equiv');
  return httpEquiv && httpEquiv.toLowerCase() === 'refresh';
}

/**
 * Check if a link element has rel="import" or rel="stylesheet".
 */
function isDangerousLink(el) {
  var rel = el.getAttribute('rel');
  if (!rel) return false;
  var lower = rel.toLowerCase();
  return lower === 'import' || lower === 'stylesheet';
}

// ── Main sanitizer ──────────────────────────────────────────────────

/**
 * Create a sanitizer with the given policy.
 *
 * @param {Object} policy
 *   - tags:       {string[]}  Allowed tag names (lowercase)
 *   - attributes: {Object}    Allowed attrs per tag. '*' for globals.
 *                              Values can include 'aria-*' and 'data-*' globs.
 *   - url:        {Function|null}  Host URL policy hook: function(ctx) → { allow, url? }
 *   - limits:     {Object}    { maxHTMLBytes, maxNodes }
 * @param {Object} [domImpl]   Optional document implementation for parsing.
 *                              Defaults to globalThis.document.
 * @returns {{ sanitize: function(html): string, sanitizeFragment: function(html): DocumentFragment }}
 */
function createSanitizer(policy, domImpl) {
  policy = policy || {};

  // Build tag lookup
  var allowedTags = Object.create(null);
  var policyTags = policy.tags || [];
  for (var i = 0; i < policyTags.length; i++) {
    allowedTags[policyTags[i].toLowerCase()] = true;
  }

  // Always allow text-level safe tags even if host doesn't list them:
  // (actually no — the host controls the allowlist fully; Layer 1 only REMOVES)

  // Build attribute lookup
  var allowedAttrs = Object.create(null);
  var policyAttrs = policy.attributes || {};
  var attrKeys = Object.keys(policyAttrs);
  for (var j = 0; j < attrKeys.length; j++) {
    var tag = attrKeys[j].toLowerCase();
    var attrList = policyAttrs[attrKeys[j]];
    var attrSet = Object.create(null);
    var hasAriaGlob = false;
    var hasDataGlob = false;
    for (var k = 0; k < attrList.length; k++) {
      var attrName = attrList[k].toLowerCase();
      if (attrName === 'aria-*') {
        hasAriaGlob = true;
      } else if (attrName === 'data-*') {
        hasDataGlob = true;
      } else {
        attrSet[attrName] = true;
      }
    }
    attrSet['__ariaGlob'] = hasAriaGlob;
    attrSet['__dataGlob'] = hasDataGlob;
    allowedAttrs[tag] = attrSet;
  }

  // Limits
  var maxHTMLBytes = (policy.limits && policy.limits.maxHTMLBytes) || 262144;
  var maxNodes = (policy.limits && policy.limits.maxNodes) || 5000;

  // URL policy
  var docOrigin = null;
  if (domImpl && domImpl.location) {
    docOrigin = domImpl.location.origin;
  } else if (typeof location !== 'undefined' && location.origin) {
    docOrigin = location.origin;
  }
  var urlChecker = urlPolicy.createURLPolicy(policy.url || null, docOrigin);

  // Document implementation for inert parsing
  var doc = domImpl || (typeof document !== 'undefined' ? document : null);

  /**
   * Create an inert document for HTML parsing (avoids triggering resource loads).
   */
  function createInertDoc() {
    if (doc && doc.implementation && doc.implementation.createHTMLDocument) {
      return doc.implementation.createHTMLDocument('');
    }
    return null;
  }

  /**
   * Parse HTML into a DocumentFragment using an inert document.
   */
  function parseHTML(html) {
    var inertDoc = createInertDoc();
    if (!inertDoc) {
      throw new Error('SafeScript: no DOM implementation available for HTML parsing');
    }
    // Use the inert document's body to parse — this avoids resource loading
    var container = inertDoc.createElement('div');
    container.innerHTML = html;

    // Move children into a fragment
    var frag = inertDoc.createDocumentFragment();
    while (container.firstChild) {
      frag.appendChild(container.firstChild);
    }
    return frag;
  }

  /**
   * Check whether an attribute is allowed for a given tag.
   */
  function isAttrAllowed(tagName, attrName) {
    var lower = attrName.toLowerCase();

    // Global attributes (from '*' key)
    var globalSet = allowedAttrs['*'];
    if (globalSet) {
      if (globalSet[lower]) return true;
      if (globalSet['__ariaGlob'] && lower.indexOf('aria-') === 0) return true;
      if (globalSet['__dataGlob'] && lower.indexOf('data-') === 0) return true;
    }

    // Tag-specific attributes
    var tagSet = allowedAttrs[tagName];
    if (tagSet) {
      if (tagSet[lower]) return true;
      if (tagSet['__ariaGlob'] && lower.indexOf('aria-') === 0) return true;
      if (tagSet['__dataGlob'] && lower.indexOf('data-') === 0) return true;
    }

    return false;
  }

  /**
   * Run URL policy on a URL attribute value. Returns the allowed URL or null.
   */
  function checkURL(tagName, attrName, value) {
    var purpose = urlPolicy.classifyPurpose(tagName, attrName);
    var result = urlChecker.check({
      element: tagName,
      attribute: attrName,
      url: value,
      purpose: purpose
    });
    if (!result.allow) return null;
    return result.url || value;
  }

  /**
   * Process srcset: check each URL entry through the URL policy.
   */
  function sanitizeSrcset(tagName, srcset) {
    var entries = parseSrcset(srcset);
    var allowed = [];
    for (var i = 0; i < entries.length; i++) {
      var checked = checkURL(tagName, 'srcset', entries[i].url);
      if (checked !== null) {
        entries[i].url = checked;
        allowed.push(entries[i]);
      }
    }
    if (allowed.length === 0) return null;
    return buildSrcset(allowed);
  }

  /**
   * Count total element nodes in a tree.
   */
  function countNodes(root) {
    var count = 0;
    var walker = function(node) {
      if (node.nodeType === 1) count++; // ELEMENT_NODE
      var child = node.firstChild;
      while (child) {
        walker(child);
        child = child.nextSibling;
      }
    };
    walker(root);
    return count;
  }

  /**
   * Walk and sanitize a DOM tree in place.
   * Removes disallowed elements, strips disallowed attributes,
   * enforces Layer 1 invariants.
   */
  function walkAndSanitize(node) {
    // Process children first (bottom-up so removals don't skip siblings)
    var child = node.firstChild;
    while (child) {
      var next = child.nextSibling;

      if (child.nodeType === 1) { // ELEMENT_NODE
        var tagName = child.tagName.toLowerCase();

        // Layer 1: always-deny elements
        if (LAYER1_DENY_TAGS[tagName]) {
          node.removeChild(child);
          child = next;
          continue;
        }

        // Layer 1: meta http-equiv=refresh
        if (tagName === 'meta' && isMetaRefresh(child)) {
          node.removeChild(child);
          child = next;
          continue;
        }

        // Layer 1: dangerous link elements
        if (tagName === 'link' && isDangerousLink(child)) {
          node.removeChild(child);
          child = next;
          continue;
        }

        // Layer 1: SVG script (may appear as 'script' inside SVG namespace)
        if (tagName === 'script') {
          node.removeChild(child);
          child = next;
          continue;
        }

        // Layer 2: tag allowlist — if not allowed, promote children and remove
        if (!allowedTags[tagName]) {
          // Move children up before removing (don't lose text content)
          while (child.firstChild) {
            node.insertBefore(child.firstChild, child);
          }
          node.removeChild(child);
          child = next;
          continue;
        }

        // Process attributes on this allowed element
        sanitizeAttributes(child, tagName);

        // Recurse into children
        walkAndSanitize(child);
      }

      child = next;
    }
  }

  /**
   * Sanitize attributes on a single element.
   */
  function sanitizeAttributes(el, tagName) {
    // Collect attribute names first (modifying during iteration is unsafe)
    var attrs = el.attributes;
    var toRemove = [];
    var attrCount = attrs.length;

    for (var i = 0; i < attrCount; i++) {
      var attr = attrs[i];
      var name = attr.name.toLowerCase();
      var value = attr.value;

      // Layer 1: strip all on* event attributes
      if (isEventAttr(name)) {
        toRemove.push(attr.name);
        continue;
      }

      // Layer 1: deny srcdoc
      if (name === 'srcdoc') {
        toRemove.push(attr.name);
        continue;
      }

      // Layer 1: DOM clobbering check
      if ((name === 'id' || name === 'name') && DOCUMENT_PROPS[value]) {
        toRemove.push(attr.name);
        continue;
      }

      // Layer 2: attribute allowlist check
      if (!isAttrAllowed(tagName, name)) {
        toRemove.push(attr.name);
        continue;
      }

      // URL attribute checks
      if (URL_ATTRS[name]) {
        if (name === 'srcset') {
          var cleanSrcset = sanitizeSrcset(tagName, value);
          if (cleanSrcset === null) {
            toRemove.push(attr.name);
          } else {
            el.setAttribute(attr.name, cleanSrcset);
          }
        } else {
          // Layer 1: javascript: URLs are always denied
          if (urlPolicy.isJavascriptURL(value)) {
            toRemove.push(attr.name);
            continue;
          }

          // Run URL policy
          var checked = checkURL(tagName, name, value);
          if (checked === null) {
            toRemove.push(attr.name);
          } else if (checked !== value) {
            el.setAttribute(attr.name, checked);
          }
        }
        continue;
      }

      // Sanitize inline style values
      if (name === 'style') {
        var cleanStyle = sanitizeStyleValue(value);
        if (cleanStyle !== value) {
          el.setAttribute('style', cleanStyle);
        }
        continue;
      }
    }

    // Remove disallowed attributes
    for (var r = 0; r < toRemove.length; r++) {
      el.removeAttribute(toRemove[r]);
    }

    // target="_blank" without rel → add rel="noopener noreferrer"
    if ((tagName === 'a' || tagName === 'area') &&
        el.getAttribute('target') === '_blank') {
      var rel = el.getAttribute('rel') || '';
      var hasNoopener = rel.indexOf('noopener') !== -1;
      var hasNoreferrer = rel.indexOf('noreferrer') !== -1;
      if (!hasNoopener || !hasNoreferrer) {
        var parts = rel ? rel.split(/\s+/) : [];
        if (!hasNoopener) parts.push('noopener');
        if (!hasNoreferrer) parts.push('noreferrer');
        el.setAttribute('rel', parts.join(' '));
      }
    }
  }

  // ── Public methods ──────────────────────────────────────────────

  return {
    /**
     * Sanitize an HTML string and return the cleaned HTML as a string.
     *
     * @param {string} html — untrusted HTML
     * @returns {string} sanitized HTML
     * @throws {RangeError} if input exceeds maxHTMLBytes or result exceeds maxNodes
     */
    sanitize: function(html) {
      if (typeof html !== 'string') {
        throw new TypeError('SafeScript: sanitize expects a string');
      }

      // Size check
      var byteLen = typeof TextEncoder !== 'undefined'
        ? new TextEncoder().encode(html).length
        : html.length; // fallback: character count (sufficient for ASCII-heavy HTML)
      if (byteLen > maxHTMLBytes) {
        throw new RangeError(
          'SafeScript: HTML input exceeds limit (' + byteLen + ' > ' + maxHTMLBytes + ' bytes)'
        );
      }

      // Parse into inert fragment
      var frag = parseHTML(html);

      // Walk and sanitize
      walkAndSanitize(frag);

      // Node count check
      var nodeCount = countNodes(frag);
      if (nodeCount > maxNodes) {
        throw new RangeError(
          'SafeScript: sanitized HTML exceeds node limit (' + nodeCount + ' > ' + maxNodes + ')'
        );
      }

      // Serialize back to HTML
      var inertDoc = createInertDoc();
      var container = inertDoc.createElement('div');
      container.appendChild(frag);
      return container.innerHTML;
    },

    /**
     * Sanitize an HTML string and return a DocumentFragment.
     * Used internally by SafeHandle for innerHTML/insertAdjacentHTML.
     *
     * @param {string} html — untrusted HTML
     * @returns {DocumentFragment} sanitized fragment (in the inert document)
     * @throws {RangeError} if input exceeds limits
     */
    sanitizeFragment: function(html) {
      if (typeof html !== 'string') {
        throw new TypeError('SafeScript: sanitizeFragment expects a string');
      }

      var byteLen = typeof TextEncoder !== 'undefined'
        ? new TextEncoder().encode(html).length
        : html.length;
      if (byteLen > maxHTMLBytes) {
        throw new RangeError(
          'SafeScript: HTML input exceeds limit (' + byteLen + ' > ' + maxHTMLBytes + ' bytes)'
        );
      }

      var frag = parseHTML(html);
      walkAndSanitize(frag);

      var nodeCount = countNodes(frag);
      if (nodeCount > maxNodes) {
        throw new RangeError(
          'SafeScript: sanitized HTML exceeds node limit (' + nodeCount + ' > ' + maxNodes + ')'
        );
      }

      return frag;
    }
  };
}

exports.createSanitizer = createSanitizer;
