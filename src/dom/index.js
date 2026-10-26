/**
 * SafeScript DOM Membrane — public API.
 *
 * createDOMContext(root, policy) → context object injected into scope.
 * destroyDOMContext(ctx) → cleanup (timers, listeners, cache).
 *
 * This is the single integration point. The runtime calls createDOMContext
 * when opts.dom is present and injects `document`, `setTimeout`, etc.
 */
'use strict';

var IdentityCache      = require('./identity-cache');
var Ownership          = require('./ownership');
var createBudgets      = require('./budgets').createBudgets;
var createSafeDocument = require('./safe-document').createSafeDocument;
var createSafeTimers   = require('./safe-timers').createSafeTimers;
var createSanitizer    = require('./sanitizer').createSanitizer;
var createURLPolicy    = require('./url-policy').createURLPolicy;
var createEventBridge  = require('./event-bridge').createEventBridge;

/**
 * Default tag allowlist — safe subset of HTML.
 */
var DEFAULT_TAGS = [
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
  'lineargradient', 'radialgradient', 'stop'
];

/**
 * Default attribute allowlist — per-tag and global.
 */
var DEFAULT_ATTRIBUTES = {
  '*': [
    'id', 'class', 'style', 'title', 'lang', 'dir', 'tabindex', 'role',
    'aria-*', 'data-*', 'hidden', 'draggable'
  ],
  'a':      ['href', 'target', 'rel', 'download'],
  'img':    ['src', 'srcset', 'alt', 'width', 'height', 'loading', 'decoding', 'sizes'],
  'video':  ['src', 'poster', 'controls', 'autoplay', 'muted', 'loop', 'preload', 'width', 'height'],
  'audio':  ['src', 'controls', 'autoplay', 'muted', 'loop', 'preload'],
  'source': ['src', 'srcset', 'type', 'media', 'sizes'],
  'track':  ['src', 'kind', 'srclang', 'label', 'default'],
  'input':  ['type', 'name', 'value', 'placeholder', 'required', 'disabled', 'checked',
             'readonly', 'min', 'max', 'step', 'pattern', 'maxlength', 'minlength',
             'autocomplete', 'autofocus', 'multiple', 'accept', 'size', 'list', 'form'],
  'textarea': ['name', 'rows', 'cols', 'placeholder', 'required', 'disabled', 'readonly',
               'maxlength', 'minlength', 'wrap', 'autocomplete', 'autofocus', 'form'],
  'select':   ['name', 'required', 'disabled', 'multiple', 'size', 'form', 'autofocus'],
  'option':   ['value', 'selected', 'disabled', 'label'],
  'optgroup': ['label', 'disabled'],
  'button':   ['type', 'name', 'value', 'disabled', 'form', 'autofocus'],
  'form':     ['action', 'method', 'enctype', 'novalidate', 'target', 'autocomplete'],
  'label':    ['for'],
  'td':       ['colspan', 'rowspan', 'headers'],
  'th':       ['colspan', 'rowspan', 'headers', 'scope', 'abbr'],
  'col':      ['span'],
  'colgroup': ['span'],
  'time':     ['datetime'],
  'progress': ['value', 'max'],
  'meter':    ['value', 'min', 'max', 'low', 'high', 'optimum'],
  'output':   ['for', 'name', 'form'],
  'details':  ['open'],
  'canvas':   ['width', 'height'],
  'svg':      ['viewBox', 'width', 'height', 'xmlns', 'fill', 'stroke', 'stroke-width'],
  'path':     ['d', 'fill', 'stroke', 'stroke-width', 'transform'],
  'circle':   ['cx', 'cy', 'r', 'fill', 'stroke', 'stroke-width'],
  'rect':     ['x', 'y', 'width', 'height', 'rx', 'ry', 'fill', 'stroke', 'stroke-width'],
  'line':     ['x1', 'y1', 'x2', 'y2', 'stroke', 'stroke-width'],
  'polyline': ['points', 'fill', 'stroke', 'stroke-width'],
  'polygon':  ['points', 'fill', 'stroke', 'stroke-width'],
  'ellipse':  ['cx', 'cy', 'rx', 'ry', 'fill', 'stroke', 'stroke-width'],
  'text':     ['x', 'y', 'dx', 'dy', 'text-anchor', 'fill', 'font-size', 'font-family', 'transform'],
  'tspan':    ['x', 'y', 'dx', 'dy'],
  'g':        ['transform', 'fill', 'stroke', 'stroke-width', 'opacity'],
  'use':      ['href', 'x', 'y', 'width', 'height'],
  'clippath': ['id'],
  'lineargradient': ['id', 'x1', 'y1', 'x2', 'y2', 'gradientunits', 'gradienttransform'],
  'radialgradient': ['id', 'cx', 'cy', 'r', 'fx', 'fy', 'gradientunits', 'gradienttransform'],
  'stop':     ['offset', 'stop-color', 'stop-opacity']
};

/**
 * Default event type allowlist.
 */
var DEFAULT_EVENTS = [
  'click', 'dblclick', 'mousedown', 'mouseup', 'mousemove',
  'mouseenter', 'mouseleave', 'mouseover', 'mouseout',
  'keydown', 'keyup', 'keypress',
  'focus', 'blur', 'focusin', 'focusout',
  'input', 'change', 'submit', 'reset',
  'touchstart', 'touchend', 'touchmove', 'touchcancel',
  'pointerdown', 'pointerup', 'pointermove',
  'pointerenter', 'pointerleave', 'pointerover', 'pointerout', 'pointercancel',
  'wheel', 'scroll', 'resize',
  'drag', 'dragstart', 'dragend', 'dragenter', 'dragleave', 'dragover', 'drop',
  'animationstart', 'animationend', 'animationiteration',
  'transitionend', 'transitionstart', 'transitionrun', 'transitioncancel',
  'load', 'error', 'abort',
  'contextmenu', 'select', 'copy', 'cut', 'paste',
  'play', 'pause', 'ended', 'volumechange', 'timeupdate', 'seeked', 'seeking',
  'canplay', 'canplaythrough', 'loadeddata', 'loadedmetadata',
  'toggle'
];

/**
 * Merge host policy over defaults.
 *
 * @param {Object} [hostPolicy] - Host-supplied policy overrides
 * @returns {Object} Merged policy
 */
function mergePolicy(hostPolicy) {
  var p = hostPolicy || {};
  return {
    tags:       p.tags       || DEFAULT_TAGS,
    attributes: p.attributes || DEFAULT_ATTRIBUTES,
    events:     p.events     || DEFAULT_EVENTS,
    url:        p.url        || null,   // host URL hook
    limits:     p.limits     || {},
    styles:     p.styles     || null    // optional style property allowlist
  };
}

/**
 * Create a DOM execution context for a SafeScript invocation.
 *
 * @param {Element} root   - The native root element (tool container)
 * @param {Object}  [policy] - Host-supplied policy overrides
 * @returns {Object} context with .scope (to inject into SafeScript), .cleanup()
 */
function createDOMContext(root, policy) {
  var merged = mergePolicy(policy);

  // ── Core modules ──────────────────────────────────────────────
  var ownership  = new Ownership(root);
  var cache      = new IdentityCache();
  var budgets    = createBudgets(merged.limits);
  var urlPolicy  = createURLPolicy(merged.url, '');

  // ── Build context object shared by all modules ────────────────
  var context = {
    rootNode:    root,
    rootHandle:  null,   // filled below
    policy:      merged,
    ownership:   ownership,
    cache:       cache,
    budgets:     budgets,
    sanitizer:   null,   // filled below
    eventBridge: null,   // filled below
    handleFactory: function(node) {
      return cache.getOrCreate(node, context);
    }
  };

  // ── Sanitizer (needs policy + url policy) ─────────────────────
  context.sanitizer = createSanitizer(merged);

  // ── Event bridge ──────────────────────────────────────────────
  context.eventBridge = createEventBridge(context);

  // ── Root handle ───────────────────────────────────────────────
  context.rootHandle = cache.getOrCreate(root, context);

  // ── SafeDocument facade ───────────────────────────────────────
  var safeDoc = createSafeDocument(context);

  // ── Safe timers ───────────────────────────────────────────────
  var timers = createSafeTimers(budgets);

  // ── Account existing children toward budget ───────────────────
  var initialNodes = budgets.countSubtreeNodes(root);
  budgets.setNodeCount(initialNodes);

  // ── Scope additions for SafeScript ────────────────────────────
  var scope = {
    document:      safeDoc,
    setTimeout:    timers.setTimeout,
    clearTimeout:  timers.clearTimeout,
    setInterval:   timers.setInterval,
    clearInterval: timers.clearInterval,
    console: Object.freeze({
      log:   function() {},
      warn:  function() {},
      error: function() {},
      info:  function() {},
      debug: function() {}
    })
  };

  return {
    scope:   scope,
    context: context,
    cleanup: function() {
      timers.cleanup();
      if (context.eventBridge && context.eventBridge.cleanup) {
        context.eventBridge.cleanup();
      }
      cache.clear();
      ownership.clear();
    }
  };
}

exports.createDOMContext = createDOMContext;
exports.mergePolicy = mergePolicy;
exports.DEFAULT_TAGS = DEFAULT_TAGS;
exports.DEFAULT_ATTRIBUTES = DEFAULT_ATTRIBUTES;
exports.DEFAULT_EVENTS = DEFAULT_EVENTS;
