<script setup>
import { ref } from 'vue'
import { Head, useForm, Link } from '@inertiajs/vue3'
import AuthLayout from '@/Layouts/AuthLayout.vue'
import { ShieldCheck, Loader2 } from 'lucide-vue-next'

const form = useForm({ code: '' })
const codeInput = ref(null)

const verify = () => {
    form.post(route('two-factor.verify'), {
        onError: () => {
            form.reset('code')
            codeInput.value?.focus()
        },
    })
}
</script>

<template>
    <AuthLayout title="Two-factor authentication" subtitle="Enter the code from your authenticator app">
        <Head title="Two-factor challenge" />

        <div class="flex justify-center mb-5">
            <div class="w-12 h-12 bg-indigo-100 dark:bg-indigo-900/40 rounded-2xl flex items-center justify-center">
                <ShieldCheck class="w-6 h-6 text-indigo-600 dark:text-indigo-400" />
            </div>
        </div>

        <form @submit.prevent="verify" class="space-y-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Authentication code</label>
                <input
                    ref="codeInput"
                    v-model="form.code"
                    type="text"
                    inputmode="numeric"
                    autocomplete="one-time-code"
                    maxlength="6"
                    placeholder="000000"
                    autofocus
                    class="w-full px-3 py-3 bg-gray-50 dark:bg-gray-800 border border-gray-300 dark:border-gray-700 rounded-xl text-sm text-center tracking-[0.5em] font-mono outline-none transition focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                    :class="form.errors.code ? 'border-red-400' : ''"
                />
                <p v-if="form.errors.code" class="text-red-500 text-xs mt-1 text-center">{{ form.errors.code }}</p>
            </div>

            <button
                type="submit"
                :disabled="form.processing || form.code.length < 6"
                class="w-full py-2.5 bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 text-white font-semibold rounded-xl transition-colors text-sm"
            >
                <Loader2 v-if="form.processing" class="w-4 h-4 animate-spin inline mr-2" />
                {{ form.processing ? 'Verifying…' : 'Verify' }}
            </button>

            <div class="text-center">
                <Link
                    :href="route('two-factor.cancel')"
                    method="post"
                    as="button"
                    class="text-xs text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 transition-colors"
                >
                    Cancel and go back to login
                </Link>
            </div>
        </form>
    </AuthLayout>
</template>
