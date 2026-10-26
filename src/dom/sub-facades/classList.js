/**
 * SafeScript DOM Membrane — SafeClassList sub-facade
 *
 * Wraps a native DOMTokenList (element.classList) with ownership
 * checks and token validation. Returned to SafeScript code when
 * accessing element.classList on a SafeHandle.
 */
'use strict';

/**
 * Validate a CSS class token.
 * Rejects empty strings and tokens containing whitespace.
 *
 * @param {string} token - The class token to validate
 * @throws {Error} If the token is invalid
 */
function validateToken(token) {
  if (typeof token !== 'string' || token === '') {
    throw new TypeError('SafeScript: classList token must be a non-empty string');
  }
  if (/\s/.test(token)) {
    throw new TypeError('SafeScript: classList token must not contain whitespace: "' + token + '"');
  }
}

/**
 * Create a safe classList wrapper.
 *
 * @param {DOMTokenList} nativeClassList - The real classList
 * @param {Object} ownership - Ownership tracker
 * @param {Node} node - The native DOM node this classList belongs to
 * @returns {Object} Frozen safe classList facade
 */
function createSafeClassList(nativeClassList, ownership, node) {
  function requireOwned() {
    if (!ownership.owns(node)) {
      throw new Error('SafeScript: cannot modify classList — node is not owned');
    }
  }

  var facade = Object.create(null);

  facade.add = function() {
    requireOwned();
    for (var i = 0; i < arguments.length; i++) {
      validateToken(arguments[i]);
    }
    return nativeClassList.add.apply(nativeClassList, arguments);
  };

  facade.remove = function() {
    requireOwned();
    for (var i = 0; i < arguments.length; i++) {
      validateToken(arguments[i]);
    }
    return nativeClassList.remove.apply(nativeClassList, arguments);
  };

  facade.toggle = function(token, force) {
    requireOwned();
    validateToken(token);
    if (arguments.length > 1) {
      return nativeClassList.toggle(token, force);
    }
    return nativeClassList.toggle(token);
  };

  facade.contains = function(token) {
    // Read-only — no ownership check needed
    validateToken(token);
    return nativeClassList.contains(token);
  };

  facade.replace = function(oldToken, newToken) {
    requireOwned();
    validateToken(oldToken);
    validateToken(newToken);
    return nativeClassList.replace(oldToken, newToken);
  };

  // Read-only length and value
  Object.defineProperty(facade, 'length', {
    get: function() { return nativeClassList.length; },
    enumerable: true
  });

  Object.defineProperty(facade, 'value', {
    get: function() { return nativeClassList.value; },
    enumerable: true
  });

  facade.toString = function() {
    return nativeClassList.toString();
  };

  return Object.freeze(facade);
}

exports.createSafeClassList = createSafeClassList;

/**
 * Adapter for SafeHandle lazy sub-facade creation.
 * @param {Node} node - native DOM node
 * @param {Object} context - execution context with .ownership
 * @returns {Object} Frozen SafeClassList facade
 */
exports.create = function(node, context) {
  return createSafeClassList(node.classList, context.ownership, node);
};
