/**
 * SafeScript DOM Membrane — Event Bridge
 *
 * Safe event subscription, wrapping, budget tracking, and cleanup.
 * Every native Event is wrapped before reaching SafeScript callbacks.
 * String event handlers are denied — only function callbacks allowed.
 */
'use strict';

/**
 * Properties forwarded from native Event to safe event object.
 */
var BASE_EVENT_PROPS = [
  'type', 'timeStamp', 'bubbles', 'cancelable',
  'defaultPrevented', 'eventPhase'
];

var MOUSE_TOUCH_PROPS = [
  'clientX', 'clientY', 'pageX', 'pageY',
  'screenX', 'screenY', 'button', 'buttons',
  'altKey', 'ctrlKey', 'metaKey', 'shiftKey'
];

var KEYBOARD_PROPS = ['key', 'code', 'repeat'];

/**
 * Create a safe event object wrapping a native Event.
 *
 * @param {Event} nativeEvent - The real DOM event
 * @param {Object} context - DOM context with .cache, .ownership
 * @returns {Object|null} Safe event object, or null if target is outside subtree
 */
function wrapEvent(nativeEvent, context) {
  var targetNode = nativeEvent.target;
  var currentTargetNode = nativeEvent.currentTarget;

  // If target is outside the authorized subtree, suppress
  if (targetNode && !context.ownership.checkRead(targetNode)) {
    return null;
  }

  var safe = Object.create(null);

  // Base event properties (read-only copies)
  var i;
  for (i = 0; i < BASE_EVENT_PROPS.length; i++) {
    safe[BASE_EVENT_PROPS[i]] = nativeEvent[BASE_EVENT_PROPS[i]];
  }

  // target and currentTarget as SafeHandles
  safe.target = targetNode ? context.cache.getOrCreate(targetNode, context) : null;
  safe.currentTarget = currentTargetNode ? context.cache.getOrCreate(currentTargetNode, context) : null;

  // Methods — delegate to native event
  safe.preventDefault = function() { nativeEvent.preventDefault(); };
  safe.stopPropagation = function() { nativeEvent.stopPropagation(); };
  safe.stopImmediatePropagation = function() { nativeEvent.stopImmediatePropagation(); };

  // Mouse/touch properties (copy if present)
  for (i = 0; i < MOUSE_TOUCH_PROPS.length; i++) {
    var mp = MOUSE_TOUCH_PROPS[i];
    if (mp in nativeEvent) {
      safe[mp] = nativeEvent[mp];
    }
  }

  // Keyboard properties (copy if present)
  for (i = 0; i < KEYBOARD_PROPS.length; i++) {
    var kp = KEYBOARD_PROPS[i];
    if (kp in nativeEvent) {
      safe[kp] = nativeEvent[kp];
    }
  }

  // Blocked: view, composedPath, srcElement, path — not present on safe object
  return Object.freeze(safe);
}

/**
 * Create an event bridge for a DOM execution context.
 *
 * @param {Object} context - DOM context with .policy, .ownership, .cache, .budgets
 * @returns {Object} Bridge with addEventListener, removeEventListener, cleanup
 */
function createEventBridge(context) {
  var policy = context.policy;
  var ownership = context.ownership;
  var budgets = context.budgets;

  // Map from SafeScript callback → { node, type, wrappedFn } for removal and cleanup
  var registrations = [];

  var allowedEvents = Object.create(null);
  var eventList = (policy && policy.events) || [];
  for (var i = 0; i < eventList.length; i++) {
    allowedEvents[eventList[i]] = true;
  }

  function findRegistration(node, type, callback) {
    for (var i = 0; i < registrations.length; i++) {
      var r = registrations[i];
      if (r && r.node === node && r.type === type && r.originalCallback === callback) {
        return i;
      }
    }
    return -1;
  }

  var bridge = {
    /**
     * Add an event listener on a native DOM node.
     *
     * @param {Node} node - The native DOM node
     * @param {string} type - Event type name
     * @param {Function} callback - SafeScript callback function
     * @param {Object} [options] - addEventListener options (capture, once, passive)
     * @param {Object} ctx - DOM execution context
     */
    addEventListener: function(node, type, callback, options, ctx) {
      // Only function callbacks — deny strings
      if (typeof callback !== 'function') {
        throw new TypeError('SafeScript: event handler must be a function, not ' + typeof callback);
      }

      // Check event type allowlist
      if (!allowedEvents[type]) {
        throw new Error('SafeScript: event type "' + type + '" is not allowed');
      }

      // Check ownership — can only listen on owned or readable nodes
      if (!ownership.checkRead(node)) {
        throw new Error('SafeScript: cannot add event listener — node is not accessible');
      }

      // Fix #13: check for duplicate registration BEFORE allocating budget
      var existingIdx = findRegistration(node, type, callback);
      if (existingIdx >= 0) return; // native would deduplicate anyway

      // Check listener budget
      budgets.allocListener();

      // Fix #11: detect once option for budget cleanup
      var isOnce = !!(options && typeof options === 'object' && options.once);

      // Wrap the callback to produce safe event objects
      var wrappedFn = function(nativeEvent) {
        var safeEvent = wrapEvent(nativeEvent, ctx || context);
        if (safeEvent === null) return; // target outside subtree — suppress
        callback(safeEvent);
        // Fix #11: release budget when once fires
        if (isOnce) {
          var idx = findRegistration(node, type, callback);
          if (idx >= 0) {
            registrations[idx] = null; // tombstone
            budgets.releaseListener();
          }
        }
      };

      // Normalize options for native addEventListener
      var nativeOpts;
      if (options && typeof options === 'object') {
        nativeOpts = {};
        if ('capture' in options) nativeOpts.capture = !!options.capture;
        if ('once' in options) nativeOpts.once = !!options.once;
        if ('passive' in options) nativeOpts.passive = !!options.passive;
      }

      // Register on the native node
      node.addEventListener(type, wrappedFn, nativeOpts);

      // Track registration
      registrations.push({
        node: node,
        type: type,
        originalCallback: callback,
        wrappedFn: wrappedFn,
        options: nativeOpts
      });
    },

    /**
     * Remove an event listener.
     *
     * @param {Node} node - The native DOM node
     * @param {string} type - Event type name
     * @param {Function} callback - The original SafeScript callback
     */
    removeEventListener: function(node, type, callback) {
      var idx = findRegistration(node, type, callback);
      if (idx < 0) return;

      var reg = registrations[idx];
      node.removeEventListener(type, reg.wrappedFn, reg.options);
      registrations[idx] = null; // tombstone
      budgets.releaseListener();
    },

    /**
     * Remove all registered listeners. Called on tool removal / cleanup.
     */
    cleanup: function() {
      for (var i = 0; i < registrations.length; i++) {
        var reg = registrations[i];
        if (!reg) continue;
        try {
          reg.node.removeEventListener(reg.type, reg.wrappedFn, reg.options);
        } catch (e) {
          // Node may have been removed from DOM — ignore
        }
      }
      registrations = [];
    },

    /**
     * Get count of active listeners.
     */
    listenerCount: function() {
      var count = 0;
      for (var i = 0; i < registrations.length; i++) {
        if (registrations[i]) count++;
      }
      return count;
    }
  };

  return bridge;
}

exports.createEventBridge = createEventBridge;
exports.wrapEvent = wrapEvent;
