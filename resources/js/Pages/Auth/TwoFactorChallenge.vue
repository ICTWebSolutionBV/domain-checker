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
                <label class="ui-label text-sm">Authentication code</label>
                <input
                    ref="codeInput"
                    v-model="form.code"
                    type="text"
                    inputmode="numeric"
                    autocomplete="one-time-code"
                    maxlength="6"
                    placeholder="000000"
                    autofocus
                    class="ui-input py-3 text-center tracking-[0.5em] font-mono"
                    :class="form.errors.code ? 'border-red-400' : ''"
                />
                <p v-if="form.errors.code" class="text-red-500 text-xs mt-1 text-center">{{ form.errors.code }}</p>
            </div>

            <button
                type="submit"
                :disabled="form.processing || form.code.length < 6"
                class="ui-btn ui-btn-primary w-full"
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
