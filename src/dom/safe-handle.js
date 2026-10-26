/**
 * SafeHandle — frozen facade wrapping a native DOM node.
 *
 * SafeScript code never holds a reference to a raw native DOM node.
 * Every property access and method call goes through policy checks.
 * Every DOM node returned is wrapped in another SafeHandle.
 *
 * Private state is held via closure — no class private fields,
 * no prototype pollution surface.
 */
'use strict';

// ── Private WeakMaps for native node/context (Fix #1: sandbox escape prevention) ──
var _handleToNode = new WeakMap();
var _handleToContext = new WeakMap();

// ── Helpers ──────────────────────────────────────────────────────────

/**
 * Properties that are always blocked, regardless of policy.
 */
var BLOCKED_PROPS = {
  ownerDocument: 1, getRootNode: 1,
  constructor: 1, __proto__: 1, prototype: 1,
  attachShadow: 1, importNode: 1, adoptNode: 1
};

/**
 * Read-only scalar properties forwarded verbatim (no wrapping needed).
 */
var SCALAR_READ_PROPS = [
  'tagName', 'nodeName', 'nodeType',
  'childElementCount',
  'offsetWidth', 'offsetHeight', 'offsetTop', 'offsetLeft',
  'scrollWidth', 'scrollHeight',
  'clientWidth', 'clientHeight'
];

/**
 * Read-only scalar properties for form elements.
 */
var FORM_READ_PROPS = ['value', 'checked', 'disabled', 'selected'];

/**
 * Read/write scalar properties.
 */
var SCALAR_WRITE_PROPS = {
  textContent: 1, className: 1, id: 1,
  value: 1, checked: 1, disabled: 1, selected: 1,
  scrollTop: 1, scrollLeft: 1
};

/**
 * Properties whose read value is a single DOM node (needs wrapping).
 */
var NODE_PROPS = [
  'parentElement', 'firstChild', 'lastChild',
  'nextSibling', 'previousSibling'
];

/**
 * Properties whose read value is a node list (needs wrapping each).
 */
var NODELIST_PROPS = ['children', 'childNodes'];

// ── createSafeHandle ─────────────────────────────────────────────────

/**
 * Create a SafeHandle wrapping a native DOM node.
 *
 * @param {Node} node - The native DOM node
 * @param {Object} context - Execution context:
 *   context.policy     — tag/attr allowlists, URL policy hook, event allowlist, limits
 *   context.ownership  — Ownership instance
 *   context.cache      — IdentityCache instance
 *   context.budgets    — resource budget counters
 *   context.sanitizer  — HTML sanitizer (wired after construction)
 *   context.eventBridge — event bridge (wired after construction)
 * @returns {Object} Frozen SafeHandle facade
 */
function createSafeHandle(node, context) {
  var handle = Object.create(null);

  // ── identity ────────────────────────────────────────────────────
  // Tag so runtime can recognise SafeHandles
  Object.defineProperty(handle, '__isSafeHandle', {
    value: true, enumerable: false, configurable: false, writable: false
  });

  // ── helper: wrap a node through the identity cache ──────────────
  function wrap(n) {
    if (n == null) return null;
    return context.cache.getOrCreate(n, context);
  }

  function wrapList(nl) {
    var out = [];
    for (var i = 0; i < nl.length; i++) {
      var n = nl[i];
      if (context.ownership.checkRead(n)) {
        out.push(wrap(n));
      }
    }
    return Object.freeze(out);
  }

  // ── scalar read properties ──────────────────────────────────────
  var i, prop;
  for (i = 0; i < SCALAR_READ_PROPS.length; i++) {
    prop = SCALAR_READ_PROPS[i];
    (function(p) {
      Object.defineProperty(handle, p, {
        get: function() { return node[p]; },
        enumerable: true, configurable: false
      });
    })(prop);
  }

  // Form element read props — return undefined if the property doesn't exist on the node
  for (i = 0; i < FORM_READ_PROPS.length; i++) {
    prop = FORM_READ_PROPS[i];
    (function(p) {
      Object.defineProperty(handle, p, {
        get: function() { return node[p]; },
        set: function(v) {
          context.ownership.checkMutation(node, 'set ' + p);
          node[p] = v;
        },
        enumerable: true, configurable: false
      });
    })(prop);
  }

  // ── textContent (read/write with size check) ────────────────────
  Object.defineProperty(handle, 'textContent', {
    get: function() { return node.textContent; },
    set: function(v) {
      context.ownership.checkMutation(node, 'set textContent');
      var s = String(v);
      var limits = context.policy && context.policy.limits;
      var maxBytes = (limits && limits.maxTextBytes) || 262144;
      if (s.length > maxBytes) {
        throw new RangeError('SafeScript DOM: textContent exceeds limit (' + maxBytes + ' bytes)');
      }
      node.textContent = s;
    },
    enumerable: true, configurable: false
  });

  // ── innerHTML (read returns text, write sanitizes) ──────────────
  Object.defineProperty(handle, 'innerHTML', {
    get: function() {
      // Return raw innerHTML — the content was sanitized on write
      return node.innerHTML;
    },
    set: function(v) {
      context.ownership.checkMutation(node, 'set innerHTML');
      var s = String(v);
      var limits = context.policy && context.policy.limits;
      var maxBytes = (limits && limits.maxHTMLBytes) || 262144;
      if (s.length > maxBytes) {
        throw new RangeError('SafeScript DOM: innerHTML exceeds limit (' + maxBytes + ' bytes)');
      }
      if (context.sanitizer) {
        s = context.sanitizer.sanitize(s, context.policy);
      }
      node.innerHTML = s;
      // Fix #15: count new children toward node budget
      var newCount = _countChildren(node);
      if (context.budgets) context.budgets.allocNodes(newCount);
      // Mark new children as owned
      _markChildrenOwned(node, context);
    },
    enumerable: true, configurable: false
  });

  // ── outerHTML (write — denied on root) ──────────────────────────
  Object.defineProperty(handle, 'outerHTML', {
    get: function() { return node.outerHTML; },
    set: function(v) {
      if (context.ownership.isAtBoundary(node)) {
        throw new TypeError('SafeScript DOM: cannot set outerHTML on the root element');
      }
      context.ownership.checkMutation(node, 'set outerHTML');
      var s = String(v);
      var limits = context.policy && context.policy.limits;
      var maxBytes = (limits && limits.maxHTMLBytes) || 262144;
      if (s.length > maxBytes) {
        throw new RangeError('SafeScript DOM: outerHTML exceeds limit (' + maxBytes + ' bytes)');
      }
      if (context.sanitizer) {
        s = context.sanitizer.sanitize(s, context.policy);
      }
      // Fix #16: capture siblings before replacement for budget/ownership
      var parent = node.parentNode;
      var prevSib = node.previousSibling;
      var nextSib = node.nextSibling;
      node.outerHTML = s;
      // Walk the replacement nodes and mark owned + count toward budget
      if (parent) {
        var cur = prevSib ? prevSib.nextSibling : parent.firstChild;
        var newCount = 0;
        while (cur && cur !== nextSib) {
          newCount++;
          newCount += _countChildren(cur);
          context.ownership.markOwned(cur);
          _markChildrenOwned(cur, context);
          cur = cur.nextSibling;
        }
        if (newCount > 0 && context.budgets) {
          context.budgets.allocNodes(newCount);
        }
      }
    },
    enumerable: true, configurable: false
  });

  // ── className, id (read/write) ──────────────────────────────────
  // className already has a read getter above; add setter
  Object.defineProperty(handle, 'className', {
    get: function() { return node.className; },
    set: function(v) {
      context.ownership.checkMutation(node, 'set className');
      node.className = String(v);
    },
    enumerable: true, configurable: false
  });

  Object.defineProperty(handle, 'id', {
    get: function() { return node.id; },
    set: function(v) {
      context.ownership.checkMutation(node, 'set id');
      var idVal = String(v);
      // Fix #3: DOM clobbering check
      var isClobberingName = require('./safe-document').isClobberingName;
      if (isClobberingName(idVal)) {
        throw new TypeError('SafeScript DOM: id "' + idVal + '" would shadow a document property');
      }
      node.id = idVal;
    },
    enumerable: true, configurable: false
  });

  // ── scrollTop, scrollLeft (read/write) ──────────────────────────
  Object.defineProperty(handle, 'scrollTop', {
    get: function() { return node.scrollTop; },
    set: function(v) { node.scrollTop = v; },
    enumerable: true, configurable: false
  });
  Object.defineProperty(handle, 'scrollLeft', {
    get: function() { return node.scrollLeft; },
    set: function(v) { node.scrollLeft = v; },
    enumerable: true, configurable: false
  });

  // ── node-returning properties (wrapped) ─────────────────────────
  for (i = 0; i < NODE_PROPS.length; i++) {
    prop = NODE_PROPS[i];
    (function(p) {
      Object.defineProperty(handle, p, {
        get: function() {
          var target = node[p];
          if (target == null) return null;
          // parentElement stops at boundary
          if (p === 'parentElement') {
            if (context.ownership.isAtBoundary(node)) return null;
            if (!context.ownership.checkRead(target)) return null;
          }
          // siblings: only return if readable
          if (p === 'nextSibling' || p === 'previousSibling') {
            if (!context.ownership.checkRead(target)) return null;
          }
          return wrap(target);
        },
        enumerable: true, configurable: false
      });
    })(prop);
  }

  // ── node-list properties (wrapped, filtered) ───────────────────
  for (i = 0; i < NODELIST_PROPS.length; i++) {
    prop = NODELIST_PROPS[i];
    (function(p) {
      Object.defineProperty(handle, p, {
        get: function() { return wrapList(node[p]); },
        enumerable: true, configurable: false
      });
    })(prop);
  }

  // ── attributes (plain objects, not live NamedNodeMap) ────────────
  Object.defineProperty(handle, 'attributes', {
    get: function() {
      var attrs = node.attributes;
      var out = [];
      for (var a = 0; a < attrs.length; a++) {
        out.push({ name: attrs[a].name, value: attrs[a].value });
      }
      return Object.freeze(out);
    },
    enumerable: true, configurable: false
  });

  // ── sub-facades (classList, style, dataset) ─────────────────────
  // These are lazy — created on first access. The actual sub-facade
  // modules are required at the bottom to avoid circular deps.
  var _classList = null, _style = null, _dataset = null;

  Object.defineProperty(handle, 'classList', {
    get: function() {
      if (!_classList) {
        var SafeClassList = require('./sub-facades/classList');
        _classList = SafeClassList.create(node, context, handle);
      }
      return _classList;
    },
    enumerable: true, configurable: false
  });

  Object.defineProperty(handle, 'style', {
    get: function() {
      if (!_style) {
        var SafeStyle = require('./sub-facades/style');
        _style = SafeStyle.create(node, context, handle);
      }
      return _style;
    },
    enumerable: true, configurable: false
  });

  Object.defineProperty(handle, 'dataset', {
    get: function() {
      if (!_dataset) {
        var SafeDataset = require('./sub-facades/dataset');
        _dataset = SafeDataset.create(node, context, handle);
      }
      return _dataset;
    },
    enumerable: true, configurable: false
  });

  // ── methods ─────────────────────────────────────────────────────

  // getAttribute / setAttribute / removeAttribute / hasAttribute
  handle.getAttribute = function(name) {
    return node.getAttribute(String(name));
  };

  handle.setAttribute = function(name, value) {
    context.ownership.checkMutation(node, 'setAttribute');
    var n = String(name).toLowerCase();
    var v = String(value);
    // Block on* event attributes (Layer 1 invariant)
    if (n.indexOf('on') === 0 && n.length > 2) {
      throw new TypeError('SafeScript DOM: cannot set event handler attribute "' + n + '" — use addEventListener instead');
    }
    // Check attribute allowlist
    _checkAttribute(node.tagName.toLowerCase(), n, context.policy);
    // URL policy for src/href/action/formaction/poster
    if (_isURLAttribute(n)) {
      _checkURL(v, node.tagName.toLowerCase(), n, context);
    }
    node.setAttribute(n, v);
    // Fix #7: auto-add rel=noopener noreferrer when setting target="_blank"
    if (n === 'target' && v === '_blank') {
      var tagLower = node.tagName.toLowerCase();
      if (tagLower === 'a' || tagLower === 'area') {
        var rel = node.getAttribute('rel') || '';
        var hasNoopener = rel.indexOf('noopener') !== -1;
        var hasNoreferrer = rel.indexOf('noreferrer') !== -1;
        if (!hasNoopener || !hasNoreferrer) {
          var parts = rel ? rel.split(/\s+/) : [];
          if (!hasNoopener) parts.push('noopener');
          if (!hasNoreferrer) parts.push('noreferrer');
          node.setAttribute('rel', parts.join(' '));
        }
      }
    }
  };

  handle.removeAttribute = function(name) {
    context.ownership.checkMutation(node, 'removeAttribute');
    node.removeAttribute(String(name));
  };

  handle.hasAttribute = function(name) {
    return node.hasAttribute(String(name));
  };

  // ── tree mutation methods ───────────────────────────────────────

  handle.appendChild = function(child) {
    context.ownership.checkMutation(node, 'appendChild');
    var childNode = _unwrap(child);
    _checkNodeBudget(context);
    node.appendChild(childNode);
    context.ownership.markOwned(childNode);
    return wrap(childNode);
  };

  handle.insertBefore = function(newChild, refChild) {
    context.ownership.checkMutation(node, 'insertBefore');
    var newNode = _unwrap(newChild);
    var refNode = refChild ? _unwrap(refChild) : null;
    _checkNodeBudget(context);
    node.insertBefore(newNode, refNode);
    context.ownership.markOwned(newNode);
    return wrap(newNode);
  };

  handle.removeChild = function(child) {
    context.ownership.checkMutation(node, 'removeChild');
    var childNode = _unwrap(child);
    node.removeChild(childNode);
    return wrap(childNode);
  };

  handle.replaceChild = function(newChild, oldChild) {
    context.ownership.checkMutation(node, 'replaceChild');
    var newNode = _unwrap(newChild);
    var oldNode = _unwrap(oldChild);
    node.replaceChild(newNode, oldNode);
    context.ownership.markOwned(newNode);
    return wrap(oldNode);
  };

  handle.replaceChildren = function() {
    context.ownership.checkMutation(node, 'replaceChildren');
    var nativeArgs = [];
    for (var a = 0; a < arguments.length; a++) {
      var arg = arguments[a];
      if (typeof arg === 'string') {
        nativeArgs.push(arg);
      } else {
        nativeArgs.push(_unwrap(arg));
      }
    }
    node.replaceChildren.apply(node, nativeArgs);
    // Fix #15: count new children toward node budget
    var newCount = _countChildren(node);
    if (context.budgets) context.budgets.allocNodes(newCount);
    // Mark all new children as owned
    _markChildrenOwned(node, context);
  };

  // cloneNode — shallow only, re-validates attributes (Fix #2)
  handle.cloneNode = function(deep) {
    if (deep) {
      throw new TypeError('SafeScript DOM: cloneNode(true) is denied — use shallow clone and rebuild');
    }
    var clone = node.cloneNode(false);
    // Re-validate all attributes on the clone (Fix #2)
    if (clone.attributes) {
      var attrs = clone.attributes;
      var toRemove = [];
      for (var ci = 0; ci < attrs.length; ci++) {
        var an = attrs[ci].name.toLowerCase();
        var av = attrs[ci].value;
        // Strip on* event handler attributes
        if (an.indexOf('on') === 0 && an.length > 2) {
          toRemove.push(attrs[ci].name);
          continue;
        }
        // Check attribute allowlist
        try {
          _checkAttribute(clone.tagName.toLowerCase(), an, context.policy);
        } catch (e) {
          toRemove.push(attrs[ci].name);
          continue;
        }
        // Check URL policy
        if (_isURLAttribute(an)) {
          try {
            _checkURL(av, clone.tagName.toLowerCase(), an, context);
          } catch (e) {
            toRemove.push(attrs[ci].name);
          }
        }
      }
      for (var cr = 0; cr < toRemove.length; cr++) {
        clone.removeAttribute(toRemove[cr]);
      }
    }
    _checkNodeBudget(context);
    context.ownership.markOwned(clone);
    return wrap(clone);
  };

  // ── query methods (scoped to owned subtree) ─────────────────────

  handle.querySelector = function(selector) {
    var sel = String(selector);
    // Fix #9: selector complexity limit
    if (sel.length > 512) {
      throw new Error('SafeScript DOM: selector exceeds 512-character limit');
    }
    var result = node.querySelector(sel);
    if (result == null) return null;
    if (!context.ownership.checkRead(result)) return null;
    return wrap(result);
  };

  handle.querySelectorAll = function(selector) {
    var sel = String(selector);
    // Fix #9: selector complexity limit
    if (sel.length > 512) {
      throw new Error('SafeScript DOM: selector exceeds 512-character limit');
    }
    var results = node.querySelectorAll(sel);
    return wrapList(results);
  };

  handle.closest = function(selector) {
    var sel = String(selector);
    // Fix #14: selector complexity limit (parity with querySelector)
    if (sel.length > 512) {
      throw new Error('SafeScript DOM: selector exceeds 512-character limit');
    }
    var current = node;
    while (current) {
      if (context.ownership.isAtBoundary(current)) {
        // Check root itself, then stop
        if (current.matches && current.matches(sel)) {
          return wrap(current);
        }
        return null;
      }
      if (current.matches && current.matches(sel)) {
        if (context.ownership.checkRead(current)) {
          return wrap(current);
        }
        return null;
      }
      current = current.parentElement;
    }
    return null;
  };

  handle.matches = function(selector) {
    var sel = String(selector);
    // Fix #14: selector complexity limit (parity with querySelector)
    if (sel.length > 512) {
      throw new Error('SafeScript DOM: selector exceeds 512-character limit');
    }
    return node.matches(sel);
  };

  // ── event methods ───────────────────────────────────────────────

  handle.addEventListener = function(type, callback, options) {
    if (context.eventBridge) {
      context.eventBridge.addEventListener(node, type, callback, options, context);
    } else {
      throw new TypeError('SafeScript DOM: event handling not available');
    }
  };

  handle.removeEventListener = function(type, callback, options) {
    if (context.eventBridge) {
      context.eventBridge.removeEventListener(node, type, callback, options);
    } else {
      throw new TypeError('SafeScript DOM: event handling not available');
    }
  };

  // ── focus / blur / click (synthetic, owned only) ────────────────

  handle.focus = function() {
    context.ownership.checkMutation(node, 'focus');
    node.focus();
  };

  handle.blur = function() {
    context.ownership.checkMutation(node, 'blur');
    node.blur();
  };

  handle.click = function() {
    context.ownership.checkMutation(node, 'click');
    node.click();
  };

  // ── getBoundingClientRect (returns plain object copy) ────────────

  handle.getBoundingClientRect = function() {
    var rect = node.getBoundingClientRect();
    return {
      top: rect.top, right: rect.right,
      bottom: rect.bottom, left: rect.left,
      width: rect.width, height: rect.height,
      x: rect.x, y: rect.y
    };
  };

  // ── remove (owned only, denied on root) ─────────────────────────

  handle.remove = function() {
    if (context.ownership.isAtBoundary(node)) {
      throw new TypeError('SafeScript DOM: cannot remove the root element');
    }
    context.ownership.checkMutation(node, 'remove');
    node.remove();
  };

  // ── insertAdjacentHTML (sanitized) ──────────────────────────────

  handle.insertAdjacentHTML = function(position, html) {
    var pos = String(position);
    if (context.ownership.isAtBoundary(node)) {
      if (pos === 'beforebegin' || pos === 'afterend') {
        throw new TypeError('SafeScript DOM: cannot insertAdjacentHTML "' + pos + '" on the root element — would escape subtree');
      }
    }
    context.ownership.checkMutation(node, 'insertAdjacentHTML');
    var s = String(html);
    var limits = context.policy && context.policy.limits;
    var maxBytes = (limits && limits.maxHTMLBytes) || 262144;
    if (s.length > maxBytes) {
      throw new RangeError('SafeScript DOM: insertAdjacentHTML exceeds limit (' + maxBytes + ' bytes)');
    }
    if (context.sanitizer) {
      s = context.sanitizer.sanitize(s, context.policy);
    }
    node.insertAdjacentHTML(pos, s);
    // Fix #15: count new children toward node budget
    var newCount = _countChildren(node);
    if (context.budgets) context.budgets.allocNodes(newCount);
    _markChildrenOwned(node, context);
  };

  // ── insertAdjacentElement (boundary check) ──────────────────────

  handle.insertAdjacentElement = function(position, element) {
    var pos = String(position);
    if (context.ownership.isAtBoundary(node)) {
      if (pos === 'beforebegin' || pos === 'afterend') {
        throw new TypeError('SafeScript DOM: cannot insertAdjacentElement "' + pos + '" on the root element — would escape subtree');
      }
    }
    context.ownership.checkMutation(node, 'insertAdjacentElement');
    var el = _unwrap(element);
    _checkNodeBudget(context);
    var result = node.insertAdjacentElement(pos, el);
    if (result) {
      context.ownership.markOwned(el);
    }
    return result ? wrap(result) : null;
  };

  // ── Store in WeakMaps before freezing (Fix #1) ──────────────────
  _handleToNode.set(handle, node);
  _handleToContext.set(handle, context);

  // ── Freeze the handle — no new properties, no modification ──────
  Object.freeze(handle);
  return handle;
}

// ── Private helpers ──────────────────────────────────────────────────

/**
 * Unwrap a SafeHandle to its native node. Throws if not a SafeHandle.
 */
function _unwrap(handleOrNode) {
  if (handleOrNode == null) return null;
  if (handleOrNode.__isSafeHandle) {
    var n = _handleToNode.get(handleOrNode);
    if (n) return n;
  }
  // Allow strings for text content in replaceChildren
  if (typeof handleOrNode === 'string') return handleOrNode;
  throw new TypeError('SafeScript DOM: expected a SafeHandle, got ' + typeof handleOrNode);
}

/**
 * Count all descendant nodes (Fix #15: for budget accounting after HTML parsing).
 */
function _countChildren(parentNode) {
  var count = 0;
  var child = parentNode.firstChild;
  while (child) {
    count++;
    count += _countChildren(child);
    child = child.nextSibling;
  }
  return count;
}

/**
 * Mark all children of a node as owned (after innerHTML or insertAdjacentHTML).
 */
function _markChildrenOwned(parentNode, context) {
  var child = parentNode.firstChild;
  while (child) {
    context.ownership.markOwned(child);
    _markChildrenOwned(child, context);
    child = child.nextSibling;
  }
}

/**
 * Check node budget before creating/inserting.
 */
function _checkNodeBudget(context) {
  if (context.budgets) {
    context.budgets.allocNodes(1);
  }
}

/**
 * Check whether an attribute name is in the allowlist for a tag.
 */
function _checkAttribute(tag, attr, policy) {
  if (!policy || !policy.attributes) return; // no policy = allow all

  var allowed = policy.attributes[tag] || [];
  var globalAllowed = policy.attributes['*'] || [];

  // Check global attrs
  for (var i = 0; i < globalAllowed.length; i++) {
    var pattern = globalAllowed[i];
    if (_matchesAttrPattern(attr, pattern)) return;
  }

  // Check tag-specific attrs
  for (var j = 0; j < allowed.length; j++) {
    if (_matchesAttrPattern(attr, allowed[j])) return;
  }

  throw new TypeError('SafeScript DOM: attribute "' + attr + '" not allowed on <' + tag + '>');
}

/**
 * Match an attribute name against a pattern (supports wildcards like 'aria-*', 'data-*').
 */
function _matchesAttrPattern(attr, pattern) {
  if (pattern === attr) return true;
  if (pattern.charAt(pattern.length - 1) === '*') {
    var prefix = pattern.slice(0, -1);
    if (attr.indexOf(prefix) === 0) return true;
  }
  return false;
}

/**
 * Check whether an attribute name is a URL-bearing attribute.
 */
function _isURLAttribute(name) {
  return name === 'src' || name === 'href' || name === 'action' ||
         name === 'formaction' || name === 'poster' || name === 'srcset';
}

/**
 * Run URL policy check on a URL value.
 */
function _checkURL(url, tag, attr, context) {
  // Layer 1 invariants — ALWAYS enforced, regardless of host URL hook
  var lower = url.toLowerCase().replace(/\s/g, '');
  if (lower.indexOf('javascript:') === 0) {
    throw new TypeError('SafeScript DOM: javascript: URLs are denied');
  }
  if (lower.indexOf('data:') === 0 && lower.indexOf('data:image/') !== 0) {
    throw new TypeError('SafeScript DOM: data: URLs are denied (except data:image/*)');
  }
  // Fix #4: <use href> must be fragment-only (no external SVG loading)
  if (tag === 'use' && (attr === 'href' || attr === 'xlink:href')) {
    if (url.charAt(0) !== '#') {
      throw new TypeError('SafeScript DOM: <use> href must be a fragment reference (start with #)');
    }
  }
  // Host URL policy hook (Layer 2)
  if (!context.policy || !context.policy.url) return;
  var purpose = _urlPurpose(tag, attr);
  var result = context.policy.url({
    element: tag, attribute: attr, url: url, purpose: purpose
  });
  if (result && result.allow === false) {
    throw new TypeError('SafeScript DOM: URL denied by policy — ' + url);
  }
}

/**
 * Determine URL purpose from tag and attribute.
 */
function _urlPurpose(tag, attr) {
  if (attr === 'href' && tag === 'a') return 'navigation';
  if (attr === 'src' && (tag === 'img' || tag === 'input')) return 'image';
  if (attr === 'poster') return 'image';
  if (attr === 'src' && (tag === 'video' || tag === 'audio' || tag === 'source' || tag === 'track')) return 'media';
  if (attr === 'href' && tag === 'link') return 'style';
  if (attr === 'action' || attr === 'formaction') return 'form';
  return 'other';
}

/**
 * Resolve the native node behind a SafeHandle (for test/debug use).
 * @param {Object} handle - A SafeHandle
 * @returns {Node|undefined}
 */
function resolveNode(handle) {
  return _handleToNode.get(handle);
}

/**
 * Resolve the context behind a SafeHandle (for test/debug use).
 * @param {Object} handle - A SafeHandle
 * @returns {Object|undefined}
 */
function resolveContext(handle) {
  return _handleToContext.get(handle);
}

exports.createSafeHandle = createSafeHandle;
exports.resolveNode = resolveNode;
exports.resolveContext = resolveContext;
