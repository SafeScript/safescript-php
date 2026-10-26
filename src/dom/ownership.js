/**
 * Ownership — tracks which DOM nodes a SafeScript execution context owns.
 *
 * Owned nodes: full read/write/insert/remove access.
 * Readable nodes: visible but not modifiable (host can grant read on extra subtrees).
 * Inaccessible: everything else — querySelector stops, parentElement returns null.
 */
'use strict';

/**
 * @constructor
 * @param {Element} root - The root element this code owns (the tool's container)
 */
function Ownership(root) {
  this._root = root;
  this._owned = new WeakSet();
  this._readable = new WeakSet();
  this._detached = new WeakSet();

  // Root and its existing children are owned at invocation time
  if (root) {
    this._markSubtreeOwned(root);
  }
}

/**
 * Recursively mark a node and all its descendants as owned.
 * @param {Node} node
 */
Ownership.prototype._markSubtreeOwned = function(node) {
  this._owned.add(node);
  var child = node.firstChild;
  while (child) {
    this._markSubtreeOwned(child);
    child = child.nextSibling;
  }
};

/**
 * Returns the root element.
 * @returns {Element}
 */
Ownership.prototype.getRoot = function() {
  return this._root;
};

/**
 * Check whether this code owns a node (full access).
 * @param {Node} node
 * @returns {boolean}
 */
Ownership.prototype.owns = function(node) {
  if (node == null) return false;
  if (this._detached.has(node)) return false;
  return this._owned.has(node);
};

/**
 * Mark a newly created or explicitly granted node as owned.
 * @param {Node} node
 */
Ownership.prototype.markOwned = function(node) {
  if (node == null) return;
  this._detached.delete(node);
  this._owned.add(node);
};

/**
 * Grant read-only access to a node (and optionally its subtree).
 * @param {Node} node
 * @param {boolean} [deep] - If true, grant read to the full subtree
 */
Ownership.prototype.grantRead = function(node, deep) {
  if (node == null) return;
  this._readable.add(node);
  if (deep) {
    var child = node.firstChild;
    while (child) {
      this.grantRead(child, true);
      child = child.nextSibling;
    }
  }
};

/**
 * Mark a node as detached — it was moved outside the owned subtree.
 * The node loses owned status; subsequent mutation attempts will throw.
 * @param {Node} node
 */
Ownership.prototype.markDetached = function(node) {
  if (node == null) return;
  this._detached.add(node);
};

/**
 * Check whether a node is within the root's subtree (DOM containment).
 * @param {Node} node
 * @returns {boolean}
 */
Ownership.prototype.isInSubtree = function(node) {
  if (node == null) return false;
  if (this._root == null) return false;
  if (node === this._root) return true;
  return this._root.contains(node);
};

/**
 * Check whether a node is readable (owned or explicitly granted read access).
 * @param {Node} node
 * @returns {boolean}
 */
Ownership.prototype.checkRead = function(node) {
  if (node == null) return false;
  if (this.owns(node)) return true;
  if (this._readable.has(node)) return true;
  return false;
};

/**
 * Assert that a node is owned. Throws TypeError if not.
 * Called before mutate, insert, and remove operations.
 * @param {Node} node
 * @param {string} [operation] - Description for the error message
 */
Ownership.prototype.checkMutation = function(node, operation) {
  if (!this.owns(node)) {
    var op = operation || 'mutate';
    throw new TypeError('SafeScript DOM: cannot ' + op + ' a node that is not owned by this execution context');
  }
};

/**
 * Check whether a node is at or beyond the ownership boundary.
 * Used to decide whether parentElement should return null.
 * @param {Node} node
 * @returns {boolean}
 */
Ownership.prototype.isAtBoundary = function(node) {
  return node === this._root;
};

/**
 * Filter a NodeList to only nodes within the owned subtree.
 * Used by scoped querySelector/querySelectorAll.
 * @param {NodeList|Array} nodes
 * @returns {Array}
 */
Ownership.prototype.filterOwned = function(nodes) {
  var result = [];
  for (var i = 0; i < nodes.length; i++) {
    if (this.owns(nodes[i]) || this._readable.has(nodes[i])) {
      result.push(nodes[i]);
    }
  }
  return result;
};

/**
 * Cleanup — release all references. Called on tool removal / teardown.
 */
Ownership.prototype.clear = function() {
  this._root = null;
  this._owned = new WeakSet();
  this._readable = new WeakSet();
  this._detached = new WeakSet();
};

module.exports = Ownership;
