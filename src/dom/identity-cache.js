/**
 * IdentityCache — WeakMap<NativeNode, SafeHandle> per execution context.
 *
 * Ensures the same native DOM node always returns the same SafeHandle,
 * so === comparisons work as developers expect.
 */
'use strict';

var createSafeHandle = require('./safe-handle').createSafeHandle;

/**
 * @constructor
 */
function IdentityCache() {
  this._map = new WeakMap();
  this._stale = new WeakSet();
}

/**
 * Return the existing SafeHandle for a node, or create and cache a new one.
 * Returns null if the node is null/undefined.
 *
 * @param {Node|null} node - Native DOM node
 * @param {Object} context - Execution context (policy, ownership, cache, budgets, etc.)
 * @returns {Object|null} SafeHandle or null
 */
IdentityCache.prototype.getOrCreate = function(node, context) {
  if (node == null) return null;

  // Stale handles must not be reused — the node left the owned subtree
  if (this._stale.has(node)) {
    this._stale.delete(node);
    this._map.delete(node);
  }

  var existing = this._map.get(node);
  if (existing) return existing;

  var handle = createSafeHandle(node, context);
  this._map.set(node, handle);
  return handle;
};

/**
 * Mark a handle as stale. The cached handle will be discarded on next
 * access and a fresh one created (which will then fail ownership checks
 * on mutation).
 *
 * @param {Node} node - Native DOM node whose handle is stale
 */
IdentityCache.prototype.invalidate = function(node) {
  if (node == null) return;
  this._stale.add(node);
};

/**
 * Check whether a handle exists for a node (without creating one).
 *
 * @param {Node} node
 * @returns {boolean}
 */
IdentityCache.prototype.has = function(node) {
  if (node == null) return false;
  if (this._stale.has(node)) return false;
  return this._map.has(node);
};

/**
 * Cleanup — release all references. Called on tool removal / teardown.
 */
IdentityCache.prototype.clear = function() {
  // WeakMap/WeakSet cannot be iterated, so we replace them.
  this._map = new WeakMap();
  this._stale = new WeakSet();
};

module.exports = IdentityCache;
