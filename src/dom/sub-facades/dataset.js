/**
 * SafeScript DOM Membrane — SafeDataset sub-facade
 *
 * Wraps a native DOMStringMap (element.dataset) with ownership
 * checks and attribute policy validation.
 */
'use strict';

/**
 * Convert a camelCase dataset key to its data-* attribute name.
 * e.g. 'userId' → 'data-user-id'
 *
 * @param {string} key - The camelCase dataset key
 * @returns {string} The data-* attribute name
 */
function keyToAttr(key) {
  return 'data-' + key.replace(/[A-Z]/g, function(ch) {
    return '-' + ch.toLowerCase();
  });
}

/**
 * Check whether a data-* attribute is allowed by policy.
 *
 * @param {string} attrName - The data-* attribute name
 * @param {string} tagName - The element's tag name (lowercase)
 * @param {Object} policy - The host policy with .attributes
 * @returns {boolean} Whether the attribute is allowed
 */
function isDataAttrAllowed(attrName, tagName, policy) {
  if (!policy || !policy.attributes) return true;

  // Check global wildcard 'data-*' pattern
  var globalAttrs = policy.attributes['*'];
  if (globalAttrs) {
    for (var i = 0; i < globalAttrs.length; i++) {
      if (globalAttrs[i] === 'data-*') return true;
      if (globalAttrs[i] === attrName) return true;
    }
  }

  // Check tag-specific attributes
  var tagAttrs = policy.attributes[tagName];
  if (tagAttrs) {
    for (var j = 0; j < tagAttrs.length; j++) {
      if (tagAttrs[j] === 'data-*') return true;
      if (tagAttrs[j] === attrName) return true;
    }
  }

  return false;
}

/**
 * Create a safe dataset wrapper.
 *
 * @param {DOMStringMap} nativeDataset - The real dataset
 * @param {Object} ownership - Ownership tracker
 * @param {Node} node - The native DOM node this dataset belongs to
 * @param {Object} policy - Host policy with .attributes
 * @returns {Object} Safe dataset facade with get/set/delete
 */
function createSafeDataset(nativeDataset, ownership, node, policy) {
  var tagName = (node.tagName || '').toLowerCase();

  var facade = Object.create(null);

  /**
   * Get a dataset value.
   */
  facade.get = function(key) {
    if (typeof key !== 'string') return undefined;
    return nativeDataset[key];
  };

  /**
   * Set a dataset value.
   */
  facade.set = function(key, value) {
    if (typeof key !== 'string' || key === '') {
      throw new TypeError('SafeScript: dataset key must be a non-empty string');
    }
    if (!ownership.owns(node)) {
      throw new Error('SafeScript: cannot modify dataset — node is not owned');
    }
    var attrName = keyToAttr(key);
    if (!isDataAttrAllowed(attrName, tagName, policy)) {
      throw new Error('SafeScript: data attribute "' + attrName + '" is not allowed by policy');
    }
    nativeDataset[key] = String(value);
  };

  /**
   * Delete a dataset value.
   */
  facade.remove = function(key) {
    if (typeof key !== 'string') return false;
    if (!ownership.owns(node)) {
      throw new Error('SafeScript: cannot modify dataset — node is not owned');
    }
    if (key in nativeDataset) {
      delete nativeDataset[key];
      return true;
    }
    return false;
  };

  /**
   * Check whether a dataset key exists.
   */
  facade.has = function(key) {
    if (typeof key !== 'string') return false;
    return key in nativeDataset;
  };

  /**
   * Get all dataset keys.
   */
  facade.keys = function() {
    return Object.keys(nativeDataset);
  };

  return Object.freeze(facade);
}

exports.createSafeDataset = createSafeDataset;

/**
 * Adapter for SafeHandle lazy sub-facade creation.
 */
exports.create = function(node, context) {
  return createSafeDataset(node.dataset, context.ownership, node, context.policy);
};
