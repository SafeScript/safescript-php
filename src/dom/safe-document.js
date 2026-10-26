/**
 * SafeScript DOM — SafeDocument facade.
 *
 * Restricted document facade that SafeScript code receives as `document`.
 * All queries scoped to the root element's subtree.
 * document.body remapped to root element.
 */
'use strict';

/**
 * Properties on the real document that must NEVER be exposed.
 * Any access attempt returns undefined or throws.
 */
var BLOCKED_DOCUMENT_PROPS = {
  cookie: 1, domain: 1, referrer: 1, URL: 1, documentURI: 1,
  write: 1, writeln: 1, open: 1, close: 1,
  defaultView: 1, head: 1, documentElement: 1,
  scripts: 1, stylesheets: 1, forms: 1, images: 1, links: 1,
  execCommand: 1, queryCommandEnabled: 1, queryCommandState: 1,
  createEvent: 1, createRange: 1, createNodeIterator: 1, createTreeWalker: 1,
  importNode: 1, adoptNode: 1,
  createAttribute: 1, createComment: 1, createProcessingInstruction: 1,
  createCDATASection: 1,
  caretPositionFromPoint: 1, caretRangeFromPoint: 1, elementFromPoint: 1, elementsFromPoint: 1,
  getSelection: 1, hasFocus: 1,
  fullscreenElement: 1, fullscreenEnabled: 1, exitFullscreen: 1,
  pointerLockElement: 1, exitPointerLock: 1,
  hidden: 1, visibilityState: 1,
  activeElement: 1,
  designMode: 1, contentType: 1,
  characterSet: 1, charset: 1, inputEncoding: 1,
  doctype: 1, implementation: 1,
  lastModified: 1, readyState: 1, title: 1,
  embeds: 1, plugins: 1, anchors: 1, applets: 1,
  all: 1
};

/**
 * DOM clobbering targets — id/name values that shadow document properties.
 */
var CLOBBER_TARGETS = {
  cookie: 1, domain: 1, forms: 1, body: 1, head: 1, images: 1,
  links: 1, scripts: 1, title: 1, URL: 1, referrer: 1, location: 1,
  documentElement: 1, defaultView: 1, designMode: 1, all: 1,
  anchors: 1, applets: 1, embeds: 1, plugins: 1, stylesheets: 1
};

/**
 * Check if an id or name value would clobber a document property.
 * @param {string} value
 * @returns {boolean}
 */
function isClobberingName(value) {
  return CLOBBER_TARGETS.hasOwnProperty(value);
}

/**
 * Create a SafeDocument facade.
 *
 * @param {Object} context - Execution context with:
 *   - rootHandle: SafeHandle wrapping the root element
 *   - rootNode: the native root element
 *   - policy: tag/attr/url policy
 *   - ownership: Ownership instance
 *   - cache: IdentityCache instance
 *   - budgets: Budget tracker
 *   - sanitizer: HTML sanitizer instance
 *   - handleFactory: function(node) → SafeHandle
 * @returns {Object} Frozen SafeDocument facade
 */
function createSafeDocument(context) {
  var rootHandle = context.rootHandle;
  var rootNode = context.rootNode;
  var policy = context.policy;
  var ownership = context.ownership;
  var cache = context.cache;
  var budgets = context.budgets;
  var handleFactory = context.handleFactory;

  var tagAllowlist = {};
  if (policy.tags) {
    for (var i = 0; i < policy.tags.length; i++) {
      tagAllowlist[policy.tags[i].toLowerCase()] = true;
    }
  }

  var doc = Object.create(null);

  /**
   * createElement — checks tag allowlist, returns SafeHandle.
   */
  doc.createElement = function(tag) {
    if (typeof tag !== 'string') {
      throw new TypeError('SafeScript DOM: createElement requires a string tag name');
    }
    var lower = tag.toLowerCase();
    if (!tagAllowlist[lower]) {
      throw new Error('SafeScript DOM: element <' + lower + '> is not allowed');
    }
    budgets.allocNodes(1);
    var node = rootNode.ownerDocument.createElement(lower);
    ownership.markOwned(node);
    return handleFactory(node);
  };

  /**
   * createTextNode — checks text size, returns SafeHandle.
   */
  doc.createTextNode = function(text) {
    var t = String(text);
    budgets.checkTextSize(t);
    budgets.allocNodes(1);
    var node = rootNode.ownerDocument.createTextNode(t);
    ownership.markOwned(node);
    return handleFactory(node);
  };

  /**
   * createDocumentFragment — returns SafeHandle wrapping a real fragment.
   */
  doc.createDocumentFragment = function() {
    var frag = rootNode.ownerDocument.createDocumentFragment();
    ownership.markOwned(frag);
    return handleFactory(frag);
  };

  /**
   * querySelector — scoped to root element's subtree.
   */
  doc.querySelector = function(selector) {
    if (typeof selector !== 'string') {
      throw new TypeError('SafeScript DOM: querySelector requires a string selector');
    }
    // Fix #9: selector complexity limit
    if (selector.length > 512) {
      throw new Error('SafeScript DOM: selector exceeds 512-character limit');
    }
    var result = rootNode.querySelector(selector);
    if (!result) return null;
    if (!ownership.isInSubtree(result)) return null;
    return handleFactory(result);
  };

  /**
   * querySelectorAll — scoped to root element's subtree.
   */
  doc.querySelectorAll = function(selector) {
    if (typeof selector !== 'string') {
      throw new TypeError('SafeScript DOM: querySelectorAll requires a string selector');
    }
    // Fix #9: selector complexity limit
    if (selector.length > 512) {
      throw new Error('SafeScript DOM: selector exceeds 512-character limit');
    }
    var results = rootNode.querySelectorAll(selector);
    var arr = [];
    for (var j = 0; j < results.length; j++) {
      if (ownership.isInSubtree(results[j])) {
        arr.push(handleFactory(results[j]));
      }
    }
    return Object.freeze(arr);
  };

  /**
   * getElementById — scoped to root element's subtree.
   */
  doc.getElementById = function(id) {
    if (typeof id !== 'string') return null;
    // Escape special chars in id for querySelector
    var escapedId = id.replace(/([^\w-])/g, '\\$1');
    var result = rootNode.querySelector('#' + escapedId);
    if (!result) return null;
    if (!ownership.isInSubtree(result)) return null;
    return handleFactory(result);
  };

  /**
   * document.body — remapped to root element.
   */
  Object.defineProperty(doc, 'body', {
    get: function() { return rootHandle; },
    enumerable: true,
    configurable: false
  });

  return Object.freeze(doc);
}

exports.createSafeDocument = createSafeDocument;
exports.isClobberingName = isClobberingName;
exports.BLOCKED_DOCUMENT_PROPS = BLOCKED_DOCUMENT_PROPS;
