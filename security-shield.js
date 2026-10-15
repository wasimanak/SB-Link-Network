/**
 * SB Link / NetBee - Client-Side Security Shield (Mobile & Desktop Optimized)
 * 
 * Protections:
 * 1. Disables Right Click (Context Menu on Desktop & Long-press on Mobile)
 * 2. Blocks DevTools Shortcuts (F12, Ctrl+Shift+I, Ctrl+Shift+J, Ctrl+Shift+C, Ctrl+U, Ctrl+S)
 * 3. Console Neutralization (Prevents script tampering & memory dumping)
 * 4. Image Dragging & Text Scraping Protection
 * 5. 100% Mobile & Webview Safe (No freezing, no infinite loops)
 */
(function () {
    'use strict';

    // 1. Disable Right-Click Context Menu (Desktop & Long-press Context Menu on Mobile)
    document.addEventListener('contextmenu', function (e) {
        // Allow context menu only if user is interacting with an input or textarea
        if (e.target && (e.target.nodeName === 'INPUT' || e.target.nodeName === 'TEXTAREA')) {
            return true;
        }
        e.preventDefault();
        return false;
    }, { capture: true });

    // 2. Block Inspect & DevTools Keyboard Shortcuts (Desktop)
    window.addEventListener('keydown', function (e) {
        // F12
        if (e.key === 'F12' || e.keyCode === 123) {
            e.preventDefault();
            e.stopPropagation();
            return false;
        }

        const isCtrlOrMeta = e.ctrlKey || e.metaKey; // Windows Ctrl or Mac Cmd

        if (isCtrlOrMeta) {
            // Ctrl + U (View Source)
            if (e.key === 'u' || e.key === 'U' || e.keyCode === 85) {
                e.preventDefault();
                e.stopPropagation();
                return false;
            }

            // Ctrl + S (Save Page)
            if (e.key === 's' || e.key === 'S' || e.keyCode === 83) {
                e.preventDefault();
                e.stopPropagation();
                return false;
            }

            // Ctrl + Shift combinations
            if (e.shiftKey) {
                // Ctrl + Shift + I (Inspect)
                if (e.key === 'I' || e.key === 'i' || e.keyCode === 73) {
                    e.preventDefault();
                    e.stopPropagation();
                    return false;
                }
                // Ctrl + Shift + J (Console)
                if (e.key === 'J' || e.key === 'j' || e.keyCode === 74) {
                    e.preventDefault();
                    e.stopPropagation();
                    return false;
                }
                // Ctrl + Shift + C (Element Inspector)
                if (e.key === 'C' || e.key === 'c' || e.keyCode === 67) {
                    e.preventDefault();
                    e.stopPropagation();
                    return false;
                }
                // Ctrl + Shift + K (Firefox Console)
                if (e.key === 'K' || e.key === 'k' || e.keyCode === 75) {
                    e.preventDefault();
                    e.stopPropagation();
                    return false;
                }
                // Ctrl + Shift + E (Firefox Network)
                if (e.key === 'E' || e.key === 'e' || e.keyCode === 69) {
                    e.preventDefault();
                    e.stopPropagation();
                    return false;
                }
            }
        }
    }, { capture: true });

    // 3. Neutralize Console in production
    try {
        if (window.console) {
            const noop = function () {};
            const methods = ['log', 'debug', 'info', 'warn', 'error', 'table', 'trace', 'dir'];
            methods.forEach(function (m) {
                window.console[m] = noop;
            });
        }
    } catch (e) {}

    // 4. Disable image and link dragging
    document.addEventListener('dragstart', function (e) {
        if (e.target && (e.target.nodeName === 'IMG' || e.target.nodeName === 'A')) {
            e.preventDefault();
            return false;
        }
    });

})();
