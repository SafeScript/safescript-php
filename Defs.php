<?php
/**
 * SafeScript definitions — blocked identifiers, keywords, properties.
 * PHP port of defs.js.
 */

namespace SafeScript;

class Defs
{
    /** Identifiers that cannot appear in SafeScript source */
    public const BLOCKED = [
        'constructor' => true, '__proto__' => true, 'prototype' => true,
        'arguments' => true,
        // Internal runtime identifiers (must not be shadowed by user code)
        '__safeThis' => true, '__thisCell' => true, '__safeGlobals' => true,
        'eval' => true, 'Function' => true,
        'globalThis' => true, 'window' => true, 'document' => true,
        'navigator' => true, 'self' => true, 'top' => true,
        'parent' => true, 'frames' => true, 'location' => true,
        'history' => true,
        'Proxy' => true, 'Reflect' => true, 'Symbol' => true,
        'fetch' => true, 'XMLHttpRequest' => true,
        'WebSocket' => true, 'EventSource' => true,
        'setTimeout' => true, 'setInterval' => true,
        'requestAnimationFrame' => true,
        'require' => true, 'module' => true, 'exports' => true,
        'process' => true, 'global' => true, 'Buffer' => true,
        '__filename' => true, '__dirname' => true,
    ];

    /** Keywords that are blocked (parse errors) */
    public const BLOCKED_KW = [
        'new' => true, 'delete' => true, 'class' => true,
        'extends' => true, 'super' => true, 'with' => true,
        'import' => true, 'export' => true, 'debugger' => true,
        'yield' => true,
        'async' => true, 'await' => true,
    ];

    /** Keywords that ARE allowed */
    public const ALLOWED_KW = [
        'var' => true, 'let' => true, 'const' => true,
        'if' => true, 'else' => true,
        'for' => true, 'while' => true, 'do' => true,
        'break' => true, 'continue' => true, 'return' => true,
        'function' => true,
        'try' => true, 'catch' => true, 'finally' => true,
        'throw' => true,
        'switch' => true, 'case' => true, 'default' => true,
        'typeof' => true, 'void' => true,
        'in' => true, 'of' => true,
        'true' => true, 'false' => true, 'null' => true,
        'undefined' => true,
        'instanceof' => true,
        'this' => true,
    ];

    /** Properties blocked at runtime (bracket + dot access) */
    public const BLOCKED_PROPS = [
        'constructor' => true, '__proto__' => true, 'prototype' => true,
        '__defineGetter__' => true, '__defineSetter__' => true,
        '__lookupGetter__' => true, '__lookupSetter__' => true,
        'caller' => true, 'callee' => true, 'arguments' => true,
    ];
}
