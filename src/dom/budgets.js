/**
 * SafeScript DOM — resource budget tracking.
 *
 * Enforces configurable limits on DOM resource usage.
 * Exceeding a budget throws RangeError. Operations do not partially apply.
 */
'use strict';

var DEFAULTS = {
  maxNodes: 5000,
  maxTextBytes: 262144,       // 256 KB
  maxHTMLBytes: 262144,       // 256 KB
  maxListeners: 100,
  maxActiveTimers: 20,
  maxTimerFrequency: 50       // minimum ms between timer fires
};

/**
 * Create a budget tracker for one SafeScript execution context.
 *
 * @param {Object} [limits] - Host-supplied limits (merged over defaults)
 * @returns {Object} Budget tracker
 */
function createBudgets(limits) {
  var cfg = {};
  var k;
  for (k in DEFAULTS) {
    if (DEFAULTS.hasOwnProperty(k)) {
      cfg[k] = (limits && limits.hasOwnProperty(k)) ? limits[k] : DEFAULTS[k];
    }
  }

  var nodeCount = 0;
  var listenerCount = 0;
  var activeTimers = 0;

  return {
    /** Check whether creating n more nodes is within budget. Throws if not. */
    allocNodes: function(n) {
      if (nodeCount + n > cfg.maxNodes) {
        throw new RangeError(
          'SafeScript DOM: node budget exceeded (' +
          (nodeCount + n) + ' > ' + cfg.maxNodes + ')'
        );
      }
      nodeCount += n;
    },

    /** Release n nodes from the budget (node removed / detached). */
    releaseNodes: function(n) {
      nodeCount -= n;
      if (nodeCount < 0) nodeCount = 0;
    },

    /** Current node count. */
    getNodeCount: function() { return nodeCount; },

    /** Set node count directly (e.g. after counting root's existing children). */
    setNodeCount: function(n) { nodeCount = n; },

    /** Check text assignment size. Throws if over limit. */
    checkTextSize: function(text) {
      var bytes = typeof text === 'string' ? text.length * 2 : 0; // rough UTF-16
      if (bytes > cfg.maxTextBytes) {
        throw new RangeError(
          'SafeScript DOM: text size exceeded (' +
          bytes + ' bytes > ' + cfg.maxTextBytes + ')'
        );
      }
    },

    /** Check HTML assignment size. Throws if over limit. */
    checkHTMLSize: function(html) {
      var bytes = typeof html === 'string' ? html.length * 2 : 0;
      if (bytes > cfg.maxHTMLBytes) {
        throw new RangeError(
          'SafeScript DOM: HTML size exceeded (' +
          bytes + ' bytes > ' + cfg.maxHTMLBytes + ')'
        );
      }
    },

    /** Allocate a listener slot. Throws if over budget. */
    allocListener: function() {
      if (listenerCount + 1 > cfg.maxListeners) {
        throw new RangeError(
          'SafeScript DOM: listener budget exceeded (' +
          (listenerCount + 1) + ' > ' + cfg.maxListeners + ')'
        );
      }
      listenerCount++;
    },

    /** Release a listener slot. */
    releaseListener: function() {
      listenerCount--;
      if (listenerCount < 0) listenerCount = 0;
    },

    /** Allocate a timer slot. Throws if over budget. */
    allocTimer: function() {
      if (activeTimers + 1 > cfg.maxActiveTimers) {
        throw new RangeError(
          'SafeScript DOM: timer budget exceeded (' +
          (activeTimers + 1) + ' > ' + cfg.maxActiveTimers + ')'
        );
      }
      activeTimers++;
    },

    /** Release a timer slot. */
    releaseTimer: function() {
      activeTimers--;
      if (activeTimers < 0) activeTimers = 0;
    },

    /** Get minimum timer interval. */
    getMinTimerInterval: function() { return cfg.maxTimerFrequency; },

    /** Get the raw config. */
    getConfig: function() { return cfg; },

    /** Count nodes in a subtree (for initial budget accounting). */
    countSubtreeNodes: function(root) {
      var count = 0;
      var walker = function(node) {
        count++;
        var child = node.firstChild;
        while (child) {
          walker(child);
          child = child.nextSibling;
        }
      };
      walker(root);
      return count;
    }
  };
}

exports.createBudgets = createBudgets;
exports.DEFAULTS = DEFAULTS;
