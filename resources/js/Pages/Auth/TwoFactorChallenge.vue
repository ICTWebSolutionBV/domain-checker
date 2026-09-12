<script setup>
import { computed, nextTick, ref } from 'vue'
import { Head, useForm, Link } from '@inertiajs/vue3'
import AuthLayout from '@/Layouts/AuthLayout.vue'
import { ShieldCheck, Loader2 } from 'lucide-vue-next'

const form = useForm({ code: '' })
const codeInput = ref(null)
// Recovery codes look like A1B2C3D4E5-F6G7H8I9J0, so the six-digit field
// cannot hold one. Switching mode swaps the constraints rather than loosening
// the TOTP field, which keeps the common path a numeric keypad.
const usingRecoveryCode = ref(false)

const canSubmit = computed(() =>
    usingRecoveryCode.value ? form.code.trim().length >= 8 : form.code.length === 6,
)

const useRecoveryCode = () => {
    usingRecoveryCode.value = !usingRecoveryCode.value
    form.reset('code')
    form.clearErrors()
    nextTick(() => codeInput.value?.focus())
}

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
    <AuthLayout
        title="Two-factor authentication"
        :subtitle="usingRecoveryCode ? 'Enter one of the recovery codes you saved' : 'Enter the code from your authenticator app'"
    >
        <Head title="Two-factor challenge" />

        <div class="flex justify-center mb-5">
            <div class="w-12 h-12 bg-indigo-100 dark:bg-indigo-900/40 rounded-2xl flex items-center justify-center">
                <ShieldCheck class="w-6 h-6 text-indigo-600 dark:text-indigo-400" />
            </div>
        </div>

        <form @submit.prevent="verify" class="space-y-4">
            <div>
                <label for="two-factor-code" class="ui-label text-sm">
                    {{ usingRecoveryCode ? 'Recovery code' : 'Authentication code' }}
                </label>
                <input
                    id="two-factor-code"
                    ref="codeInput"
                    v-model="form.code"
                    name="code"
                    type="text"
                    :inputmode="usingRecoveryCode ? 'text' : 'numeric'"
                    :autocomplete="usingRecoveryCode ? 'off' : 'one-time-code'"
                    :maxlength="usingRecoveryCode ? 32 : 6"
                    :placeholder="usingRecoveryCode ? 'XXXXXXXXXX-XXXXXXXXXX' : '000000'"
                    :spellcheck="false"
                    autofocus
                    class="ui-input py-3 text-center font-mono"
                    :class="[
                        usingRecoveryCode ? 'tracking-normal text-sm' : 'tracking-[0.5em]',
                        form.errors.code ? 'border-red-400' : '',
                    ]"
                />
                <p v-if="form.errors.code" role="alert" class="text-red-600 dark:text-red-400 text-xs mt-1 text-center">{{ form.errors.code }}</p>
            </div>

            <button
                type="submit"
                :disabled="form.processing || ! canSubmit"
                class="ui-btn ui-btn-primary w-full"
            >
                <Loader2 v-if="form.processing" class="w-4 h-4 animate-spin inline mr-2" />
                {{ form.processing ? 'Verifying…' : 'Verify' }}
            </button>

            <div class="text-center">
                <button type="button" class="text-xs text-indigo-600 dark:text-indigo-400 hover:underline" @click="useRecoveryCode">
                    {{ usingRecoveryCode ? 'Use your authenticator app instead' : 'Use a recovery code instead' }}
                </button>
            </div>

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
