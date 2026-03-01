// CHANGE LOG
// 2025-10-19 — Fix ESLint v9 flat config parse error by removing problematic block comments and using CommonJS export. // CHANGED
// - Scope: only lint files under assets/js/**/*.js.                                                                     // CHANGED
// - Safe browser globals; allow console for debug logs.                                                                 // CHANGED
// - Ignore backups, vendor, minified files.                                                                             // CHANGED

/* eslint-env node */
'use strict';

module.exports = [
  {
    // Ignore patterns (low precedence)
    ignores: [
      '**/node_modules/**',
      '**/vendor/**',
      'assets/backup/**',
      '**/*.min.js',
    ],
  },
  {
    // Apply rules only to our admin scripts
    files: ['assets/js/**/*.js'],

    languageOptions: {
      ecmaVersion: 2023,
      sourceType: 'script', // IIFE, not ESM
      globals: {
        window: 'readonly',
        document: 'readonly',
        console: 'readonly',
        fetch: 'readonly',
        setTimeout: 'readonly',
        alert: 'readonly',
      },
    },

    rules: {
      'no-undef': 'error',
      'no-unused-vars': ['warn', { args: 'after-used', ignoreRestSiblings: true }],
      'no-redeclare': 'error',
      'no-console': 'off',
      'prefer-const': 'warn',
      eqeqeq: ['warn', 'smart'],
      curly: ['warn', 'multi-line'],
    },
  },
];
