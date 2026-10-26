/**
 * SafeScript DOM Membrane — SafeCollection
 *
 * Read-only array-like views for children, childNodes,
 * querySelectorAll results. Each node is wrapped via the
 * identity cache. Supports forEach and item access.
 */
'use strict';

/**
 * Create a safe collection from a NodeList or HTMLCollection.
 * Returns a frozen array-like object with SafeHandles.
 *
 * @param {NodeList|HTMLCollection|Array} nodeList - The native node collection
 * @param {Object} context - DOM context with .cache (IdentityCache) and .ownership
 * @returns {Object} Frozen array-like with length, item(), forEach(), and indexed access
 */
function createSafeCollection(nodeList, context) {
  var cache = context.cache;
  var ownership = context.ownership;

  // Build an array of SafeHandles, filtering to accessible nodes only
  var items = [];
  var len = nodeList.length;
  for (var i = 0; i < len; i++) {
    var node = nodeList[i];
    if (ownership.checkRead(node)) {
      items.push(cache.getOrCreate(node, context));
    }
  }

  var collection = Object.create(null);

  // Indexed access via defineProperty
  for (var j = 0; j < items.length; j++) {
    (function(idx) {
      Object.defineProperty(collection, idx, {
        value: items[idx],
        writable: false,
        enumerable: true,
        configurable: false
      });
    })(j);
  }

  // length property
  Object.defineProperty(collection, 'length', {
    value: items.length,
    writable: false,
    enumerable: false,
    configurable: false
  });

  collection.item = function(index) {
    var idx = index | 0;
    if (idx < 0 || idx >= items.length) return null;
    return items[idx];
  };

  collection.forEach = function(callback, thisArg) {
    if (typeof callback !== 'function') {
      throw new TypeError('SafeScript: forEach callback must be a function');
    }
    for (var k = 0; k < items.length; k++) {
      callback.call(thisArg, items[k], k, collection);
    }
  };

  collection.toArray = function() {
    return items.slice();
  };

  return Object.freeze(collection);
}

exports.createSafeCollection = createSafeCollection;
