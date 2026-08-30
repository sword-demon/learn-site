import js from '@eslint/js';
import tseslint from '@typescript-eslint/eslint-plugin';
import tsparser from '@typescript-eslint/parser';
import vue from 'eslint-plugin-vue';
import vueParser from 'vue-eslint-parser';

export default [
  js.configs.recommended,
  ...vue.configs['flat/recommended'],
  {
    files: ['**/*.{ts,tsx,vue}'],
    languageOptions: {
      parser: vueParser,
      parserOptions: {
        parser: tsparser,
        ecmaVersion: 2022,
        sourceType: 'module',
        extraFileExtensions: ['.vue'],
      },
      globals: {
        __dirname: 'readonly',
        __filename: 'readonly',
      },
    },
    plugins: { '@typescript-eslint': tseslint },
    rules: {
      'no-unused-vars': 'off',
      '@typescript-eslint/no-unused-vars': ['error', { argsIgnorePattern: '^_' }],
      'vue/multi-word-component-names': 'off',
      'vue/no-undef-components': 'off',
      // Rich text passes through server-side HtmlSanitizer (apps/api/app/support/HtmlSanitizer.php)
      // which strips dangerous tags/attributes before persisting. Static rule can't verify this.
      'vue/no-v-html': 'off',
      // Formatting rules overlap with Prettier; Prettier owns layout.
      'vue/max-attributes-per-line': 'off',
      'vue/singleline-html-element-content-newline': 'off',
      'vue/multiline-html-element-content-newline': 'off',
      'vue/html-self-closing': 'off',
      'vue/html-closing-bracket-newline': 'off',
      'vue/html-indent': 'off',
    },
  },
  {
    ignores: [
      'dist/**',
      'build/**',
      'node_modules/**',
      'coverage/**',
      '**/*.min.js',
      'tests/e2e/**',
      'playwright.config.ts',
      // unplugin-auto-import / unplugin-vue-components generated types.
      'auto-imports.d.ts',
      'components.d.ts',
    ],
  },
  {
    // Tests run under vitest in Node — expose Node globals so test files
    // can use process.cwd(), etc. without triggering no-undef.
    files: ['tests/**/*.{ts,tsx}'],
    languageOptions: {
      globals: {
        process: 'readonly',
        Buffer: 'readonly',
        global: 'readonly',
      },
    },
  },
];
