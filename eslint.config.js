import js from '@eslint/js'
import vue from 'eslint-plugin-vue'
import prettier from 'eslint-config-prettier'

export default [
    {
        ignores: ['public/**', 'vendor/**', 'node_modules/**', 'bootstrap/ssr/**'],
    },
    js.configs.recommended,
    ...vue.configs['flat/recommended'],
    prettier,
    {
        languageOptions: {
            ecmaVersion: 2023,
            sourceType: 'module',
            globals: {
                // Ziggy publishes route() globally, and the SSE client and the
                // clipboard helpers talk to the browser directly.
                route: 'readonly',
                window: 'readonly',
                document: 'readonly',
                navigator: 'readonly',
                localStorage: 'readonly',
                fetch: 'readonly',
                AbortController: 'readonly',
                TextDecoder: 'readonly',
                setTimeout: 'readonly',
                clearTimeout: 'readonly',
                requestAnimationFrame: 'readonly',
                cancelAnimationFrame: 'readonly',
                console: 'readonly',
                PublicKeyCredential: 'readonly',
                URLSearchParams: 'readonly',
                matchMedia: 'readonly',
                confirm: 'readonly',
                alert: 'readonly',
                URL: 'readonly',
                Blob: 'readonly',
                FormData: 'readonly',
                EventSource: 'readonly',
            },
        },
        rules: {
            // The pages are single-word by design (Home.vue, Transfer.vue) and
            // renaming them would rename the Inertia page keys with them.
            'vue/multi-word-component-names': 'off',

            // Pure attribute cosmetics. Turning these on would reformat every
            // template in the app for no behavioural gain and bury the real
            // findings under 250 warnings, which is how a linter gets ignored.
            'vue/attributes-order': 'off',
            'vue/first-attribute-linebreak': 'off',
            'vue/max-attributes-per-line': 'off',
            'vue/singleline-html-element-content-newline': 'off',

            // These do carry signal, so they are warnings rather than noise to
            // be silenced: a prop with no default is a runtime undefined
            // waiting to happen, and v-html deserves a second look every time.
            'vue/require-default-prop': 'warn',
            'vue/no-v-html': 'warn',
        },
    },
]
