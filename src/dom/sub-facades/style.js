/**
 * SafeScript DOM Membrane — SafeStyle sub-facade
 *
 * Wraps a native CSSStyleDeclaration with value validation.
 * Denies url(), expression(), -moz-binding in CSS values.
 * Provides property-level get/set and method-level access.
 */
'use strict';

/**
 * Pattern matching dangerous CSS values.
 * Matches: url(...), expression(...), -moz-binding:...
 * Case-insensitive.
 */
var DANGEROUS_CSS_RE = /\b(url|expression|image-set|cross-fade|paint)\s*\(|(-moz-binding|-webkit-image-set)\s*[:(]/i;

/**
 * Commonly used CSS property names that SafeStyle exposes.
 * This list covers most layout, typography, color, and animation properties.
 */
var CSS_PROPERTIES = [
  // Box model
  'width', 'height', 'minWidth', 'minHeight', 'maxWidth', 'maxHeight',
  'margin', 'marginTop', 'marginRight', 'marginBottom', 'marginLeft',
  'padding', 'paddingTop', 'paddingRight', 'paddingBottom', 'paddingLeft',
  'border', 'borderTop', 'borderRight', 'borderBottom', 'borderLeft',
  'borderWidth', 'borderStyle', 'borderColor', 'borderRadius',
  'borderTopLeftRadius', 'borderTopRightRadius',
  'borderBottomLeftRadius', 'borderBottomRightRadius',
  // Layout
  'display', 'position', 'top', 'right', 'bottom', 'left',
  'float', 'clear', 'overflow', 'overflowX', 'overflowY',
  'zIndex', 'visibility', 'opacity',
  // Flexbox
  'flex', 'flexDirection', 'flexWrap', 'flexFlow', 'flexGrow', 'flexShrink', 'flexBasis',
  'justifyContent', 'alignItems', 'alignSelf', 'alignContent',
  'order', 'gap', 'rowGap', 'columnGap',
  // Grid
  'gridTemplateColumns', 'gridTemplateRows', 'gridColumn', 'gridRow',
  'gridArea', 'gridGap', 'gridAutoFlow',
  // Typography
  'color', 'font', 'fontFamily', 'fontSize', 'fontWeight', 'fontStyle',
  'lineHeight', 'letterSpacing', 'textAlign', 'textDecoration',
  'textTransform', 'textIndent', 'textOverflow', 'whiteSpace', 'wordBreak',
  'wordWrap', 'overflowWrap',
  // Background (values validated — url() stripped)
  'background', 'backgroundColor', 'backgroundPosition', 'backgroundSize',
  'backgroundRepeat', 'backgroundClip',
  // Transform and animation
  'transform', 'transformOrigin', 'transition', 'transitionProperty',
  'transitionDuration', 'transitionTimingFunction', 'transitionDelay',
  'animation', 'animationName', 'animationDuration', 'animationTimingFunction',
  'animationDelay', 'animationIterationCount', 'animationDirection',
  // Other
  'boxShadow', 'textShadow', 'outline', 'outlineColor', 'outlineStyle', 'outlineWidth',
  'cursor', 'pointerEvents', 'userSelect', 'boxSizing',
  'verticalAlign', 'tableLayout', 'borderCollapse', 'borderSpacing',
  'listStyle', 'listStyleType', 'listStylePosition',
  'objectFit', 'objectPosition', 'aspectRatio',
  'filter', 'backdropFilter', 'mixBlendMode',
  'resize', 'scrollBehavior', 'touchAction', 'willChange',
  'contain', 'isolation', 'appearance'
];

/**
 * Validate a CSS property value. Throws if it contains dangerous patterns.
 *
 * @param {string} prop - The CSS property name (for error messages)
 * @param {string} value - The CSS value to validate
 * @throws {Error} If the value contains url(), expression(), or -moz-binding
 */
function validateCSSValue(prop, value) {
  if (typeof value !== 'string') return;
  if (DANGEROUS_CSS_RE.test(value)) {
    throw new Error(
      'SafeScript: CSS value for "' + prop + '" contains forbidden pattern ' +
      '(url(), expression(), or -moz-binding are not allowed)'
    );
  }
}

/**
 * Validate an entire cssText string.
 *
 * @param {string} cssText - The full CSS text
 * @throws {Error} If it contains dangerous patterns
 */
function validateCSSText(cssText) {
  if (typeof cssText !== 'string') return;
  if (DANGEROUS_CSS_RE.test(cssText)) {
    throw new Error(
      'SafeScript: cssText contains forbidden pattern ' +
      '(url(), expression(), or -moz-binding are not allowed)'
    );
  }
}

/**
 * Create a safe style wrapper.
 *
 * @param {CSSStyleDeclaration} nativeStyle - The real style object
 * @param {Object} policy - Host policy (for potential style property allowlist)
 * @returns {Object} Frozen safe style facade
 */
function createSafeStyle(nativeStyle, policy) {
  var facade = Object.create(null);

  // Allowlist of style properties (null = allow all safe CSS properties)
  var allowedProps = null;
  if (policy && policy.styles) {
    allowedProps = Object.create(null);
    for (var s = 0; s < policy.styles.length; s++) {
      allowedProps[policy.styles[s]] = true;
    }
  }

  function checkPropAllowed(prop) {
    if (allowedProps && !allowedProps[prop]) {
      throw new Error('SafeScript: CSS property "' + prop + '" is not allowed by policy');
    }
  }

  // Define get/set for each known CSS property
  for (var i = 0; i < CSS_PROPERTIES.length; i++) {
    (function(prop) {
      Object.defineProperty(facade, prop, {
        get: function() {
          return nativeStyle[prop];
        },
        set: function(value) {
          checkPropAllowed(prop);
          validateCSSValue(prop, value);
          nativeStyle[prop] = value;
        },
        enumerable: true,
        configurable: false
      });
    })(CSS_PROPERTIES[i]);
  }

  // cssText — full style text access
  Object.defineProperty(facade, 'cssText', {
    get: function() {
      return nativeStyle.cssText;
    },
    set: function(value) {
      validateCSSText(value);
      nativeStyle.cssText = value;
    },
    enumerable: true,
    configurable: false
  });

  // length — number of explicitly set properties
  Object.defineProperty(facade, 'length', {
    get: function() { return nativeStyle.length; },
    enumerable: true
  });

  // Method-level access
  facade.getPropertyValue = function(prop) {
    // Fix #12: check allowlist on read too
    checkPropAllowed(prop);
    return nativeStyle.getPropertyValue(prop);
  };

  facade.setProperty = function(prop, value, priority) {
    checkPropAllowed(prop);
    validateCSSValue(prop, value);
    nativeStyle.setProperty(prop, value, priority || '');
  };

  facade.removeProperty = function(prop) {
    checkPropAllowed(prop);
    return nativeStyle.removeProperty(prop);
  };

  facade.getPropertyPriority = function(prop) {
    return nativeStyle.getPropertyPriority(prop);
  };

  facade.item = function(index) {
    return nativeStyle.item(index);
  };

  // Fix #10: preventExtensions blocks adding arbitrary properties
  // (we don't freeze because setters need to work)
  Object.preventExtensions(facade);
  return facade;
}

exports.createSafeStyle = createSafeStyle;
exports.validateCSSValue = validateCSSValue;
exports.DANGEROUS_CSS_RE = DANGEROUS_CSS_RE;

/**
 * Adapter for SafeHandle lazy sub-facade creation.
 */
exports.create = function(node, context) {
  return createSafeStyle(node.style, context.policy);
};
