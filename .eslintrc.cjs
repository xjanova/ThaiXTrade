/**
 * TPIX TRADE - ESLint Configuration
 * Developed by Xman Studio
 */

module.exports = {
    root: true,
    env: {
        browser: true,
        es2022: true,
        node: true,
    },
    extends: [
        'eslint:recommended',
        'plugin:vue/vue3-recommended',
    ],
    parserOptions: {
        ecmaVersion: 'latest',
        sourceType: 'module',
    },
    plugins: ['vue'],
    rules: {
        // Vue specific
        'vue/multi-word-component-names': 'off',
        'vue/no-v-html': 'warn',
        'vue/require-default-prop': 'off',
        'vue/html-indent': ['error', 4],
        'vue/script-indent': ['error', 4, { baseIndent: 0 }],
        'vue/max-attributes-per-line': ['error', {
            singleline: 3,
            multiline: 1,
        }],

        // General
        'indent': ['error', 4],
        'semi': ['error', 'always'],
        'quotes': ['error', 'single', { avoidEscape: true }],
        'comma-dangle': ['error', 'always-multiline'],
        'no-unused-vars': ['warn', { argsIgnorePattern: '^_' }],
        'no-console': process.env.NODE_ENV === 'production' ? 'warn' : 'off',
        'no-debugger': process.env.NODE_ENV === 'production' ? 'error' : 'off',

        // Best practices
        'eqeqeq': ['error', 'always'],
        'no-var': 'error',
        'prefer-const': 'error',
        'prefer-arrow-callback': 'error',
        'arrow-spacing': 'error',
        'object-shorthand': 'error',
    },
    overrides: [
        {
            files: ['*.vue'],
            rules: {
                'indent': 'off', // Use vue/script-indent instead
            },
        },
    ],
    ignorePatterns: [
        'node_modules/',
        'vendor/',
        'public_html/build/',
        'storage/',
        '*.min.js',
    ],
};
