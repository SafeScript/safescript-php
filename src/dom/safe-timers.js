/**
 * SafeScript DOM — timer wrappers with budgets.
 *
 * setTimeout/setInterval wrappers that enforce:
 *   - Active timer count limit
 *   - Minimum interval frequency
 *   - Cleanup on tool removal
 */
'use strict';

/**
 * Create safe timer wrappers for a SafeScript execution context.
 *
 * @param {Object} budgets - Budget tracker
 * @returns {Object} { setTimeout, clearTimeout, setInterval, clearInterval, cleanup }
 */
function createSafeTimers(budgets) {
  var activeIds = Object.create(null);   // id → { type, nativeId }
  var nextId = 1;

  function safeSetTimeout(callback, delay) {
    if (typeof callback !== 'function') {
      throw new TypeError('SafeScript DOM: setTimeout requires a function callback');
    }
    var d = Math.max(0, Number(delay) || 0);
    budgets.allocTimer();
    var id = nextId++;
    var nativeId = setTimeout(function() {
      delete activeIds[id];
      budgets.releaseTimer();
      try { callback(); } catch(e) { /* swallow — matches browser behavior for uncaught */ }
    }, d);
    activeIds[id] = { type: 'timeout', nativeId: nativeId };
    return id;
  }

  function safeClearTimeout(id) {
    var entry = activeIds[id];
    if (!entry) return;
    if (entry.type !== 'timeout') return;
    clearTimeout(entry.nativeId);
    delete activeIds[id];
    budgets.releaseTimer();
  }

  function safeSetInterval(callback, interval) {
    if (typeof callback !== 'function') {
      throw new TypeError('SafeScript DOM: setInterval requires a function callback');
    }
    var minInterval = budgets.getMinTimerInterval();
    var iv = Math.max(minInterval, Number(interval) || minInterval);
    budgets.allocTimer();
    var id = nextId++;
    var nativeId = setInterval(function() {
      try { callback(); } catch(e) { /* swallow */ }
    }, iv);
    activeIds[id] = { type: 'interval', nativeId: nativeId };
    return id;
  }

  function safeClearInterval(id) {
    var entry = activeIds[id];
    if (!entry) return;
    if (entry.type !== 'interval') return;
    clearInterval(entry.nativeId);
    delete activeIds[id];
    budgets.releaseTimer();
  }

  function cleanup() {
    var ids = Object.keys(activeIds);
    for (var i = 0; i < ids.length; i++) {
      var entry = activeIds[ids[i]];
      if (entry.type === 'timeout') {
        clearTimeout(entry.nativeId);
      } else {
        clearInterval(entry.nativeId);
      }
    }
    activeIds = Object.create(null);
  }

  return {
    setTimeout: safeSetTimeout,
    clearTimeout: safeClearTimeout,
    setInterval: safeSetInterval,
    clearInterval: safeClearInterval,
    cleanup: cleanup,
    getActiveCount: function() { return Object.keys(activeIds).length; }
  };
}

exports.createSafeTimers = createSafeTimers;
