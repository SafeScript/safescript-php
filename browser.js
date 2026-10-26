/**
 * SafeScript browser bundle builder.
 *
 * Run: node browser.js > safescript.js
 */
'use strict';

var fs = require('fs');
var path = require('path');

function read(f) {
  return fs.readFileSync(path.join(__dirname, f), 'utf8');
}

// Map from require path to bundle variable name
var MODULE_MAP = {
  './defs': 'defs',
  './parse': 'parser',
  './validate': 'validator',
  './transform': 'transformer',
  './emit': 'emitter',
};

function wrapModule(name, src) {
  var out = src;

  // Replace `var xxx = require('./yyy');` with `var xxx = <bundle_var>;`
  // If the local var name matches the bundle var name, just remove the line
  // (the outer-scope variable is already visible).
  out = out.replace(/var\s+(\w+)\s*=\s*require\(['"]([^'"]+)['"]\);?/g,
    function(match, varName, reqPath) {
      if (reqPath === 'crypto') return 'var ' + varName + ' = null;';
      if (MODULE_MAP[reqPath]) {
        var bundleVar = MODULE_MAP[reqPath];
        if (varName === bundleVar) return '// (uses outer ' + bundleVar + ')';
        return 'var ' + varName + ' = ' + bundleVar + ';';
      }
      return '// ' + match + ' (stripped)';
    });

  // Replace bare `try { crypto = require('crypto'); } catch(e) {...}` pattern
  out = out.replace(/try\s*\{\s*crypto\s*=\s*require\('crypto'\);\s*\}\s*catch\s*\(\w+\)\s*\{[^}]*\}/g, '');

  // Remove module.exports
  out = out.replace(/^module\.exports\s*=.*/gm, '');

  // Remove 'use strict' (we have it at the top level)
  out = out.replace(/^'use strict';\s*$/gm, '');

  return '// ── ' + name + ' ' + '─'.repeat(Math.max(1, 60 - name.length)) + '\nvar ' + name + ' = (function() {\n  var exports = {};\n' + out + '\n  return exports;\n})();\n';
}

var bundle = `(function(root) {
"use strict";

${wrapModule('defs', read('src/defs.js'))}
${wrapModule('parser', read('src/parse.js'))}
${wrapModule('validator', read('src/validate.js'))}
${wrapModule('transformer', read('src/transform.js'))}
${wrapModule('emitter', read('src/emit.js'))}
${wrapModule('runtime', read('src/runtime.js'))}
${wrapModule('compiler', read('src/compile.js'))}
${wrapModule('headerParser', read('src/header.js'))}

// ── Public API ──────────────────────────────────────────────────────
var SafeScript = {
  compile: function(source) {
    return compiler.compile(source);
  },
  run: function(source, scope, opts) {
    if (typeof source === 'object' && source !== null && typeof source.source === 'string') {
      opts = scope;
      scope = source.scope;
      source = source.source;
    }
    var compiled = compiler.compile(source);
    var fn = runtime.buildFunction(compiled.code, scope, opts || {});
    return fn();
  },
  validate: function(source) {
    try {
      var ast = parser.parse(source);
      return validator.validate(ast);
    } catch(e) {
      return { ok: false, errors: [{ message: e.message, line: e.line, col: e.col }] };
    }
  },
  parse: function(source) {
    return parser.parse(source);
  },
  sanitize: function(obj) {
    return runtime.sanitize(obj);
  },
  capabilities: function(source) {
    return headerParser.parseHeader(source);
  },
  checkScope: function(source, scope) {
    var caps = headerParser.parseHeader(source);
    return headerParser.checkScope(caps, scope);
  },
  load: function(source, resolver) {
    var caps = headerParser.parseHeader(source);
    var resolved = resolver ? resolver(caps) : {};
    return Promise.resolve(resolved).then(function(scope) {
      var check = headerParser.checkScope(caps, scope);
      if (!check.ok) {
        throw new Error('Missing capabilities: ' + check.missing.join(', '));
      }
      return { source: source, scope: scope };
    });
  },
  /**
   * Async AST hash using SubtleCrypto (browser).
   * In Node.js, compile().hash is synchronous via crypto module.
   * In browsers, compile().hash is null — use this for async SHA-256.
   */
  hashASTAsync: function(ast) {
    var normalized = JSON.stringify(ast, function(key, value) {
      if (key === 'loc' || key === 'line' || key === 'col') return undefined;
      return value;
    });
    var data = new TextEncoder().encode(normalized);
    return crypto.subtle.digest('SHA-256', data).then(function(buf) {
      var bytes = new Uint8Array(buf);
      var hex = '';
      for (var i = 0; i < bytes.length; i++) {
        hex += ('0' + bytes[i].toString(16)).slice(-2);
      }
      return hex;
    });
  }
};

if (typeof module !== 'undefined' && module.exports) {
  module.exports = SafeScript;
} else {
  root.SafeScript = SafeScript;
}

})(typeof globalThis !== 'undefined' ? globalThis : typeof self !== 'undefined' ? self : this);
`;

process.stdout.write(bundle);
