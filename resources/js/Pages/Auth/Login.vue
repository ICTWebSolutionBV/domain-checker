<script setup>
import { ref, onMounted } from 'vue'
import { Head, useForm, Link } from '@inertiajs/vue3'
import AuthLayout from '@/Layouts/AuthLayout.vue'
import { Fingerprint, Mail, Lock, Loader2 } from 'lucide-vue-next'

const form = useForm({
    email: '',
    password: '',
    remember: false,
})

const passkeyLoading = ref(false)
const passkeyError = ref('')
const supportsPasskeys = typeof window !== 'undefined' && window.browserSupportsWebAuthn?.()

const login = () => {
    form.post(route('login'), {
        onFinish: () => form.reset('password'),
    })
}

const loginWithPasskey = async () => {
    passkeyLoading.value = true
    passkeyError.value = ''
    try {
        const optionsRes = await fetch(route('passkeys.authentication_options'))
        const options = await optionsRes.json()
        const assertion = await window.startAuthentication({ optionsJSON: options })
        const passkeyForm = useForm({ start_authentication_response: JSON.stringify(assertion) })
        passkeyForm.post(route('passkeys.login'), {
            onError: () => {
                passkeyError.value = 'Passkey authentication failed. Please try again.'
            },
        })
    } catch (e) {
        if (e.name === 'NotAllowedError') {
            passkeyError.value = 'Passkey authentication was cancelled.'
        } else if (e.name !== 'AbortError') {
            passkeyError.value = e.message || 'Passkey authentication failed.'
        }
    } finally {
        passkeyLoading.value = false
    }
}

onMounted(() => {
    if (supportsPasskeys) {
        loginWithPasskey()
    }
})
</script>

<template>
    <AuthLayout title="Welcome back" subtitle="Sign in to access your account">
        <Head title="Login" />

        <!-- Passkey -->
        <div v-if="supportsPasskeys" class="mb-5">
            <button
                @click="loginWithPasskey"
                :disabled="passkeyLoading"
                class="w-full flex items-center justify-center gap-2.5 px-4 py-3 bg-indigo-600 hover:bg-indigo-700 disabled:opacity-60 text-white font-semibold rounded-xl transition-colors text-sm shadow-sm shadow-indigo-600/20"
            >
                <Loader2 v-if="passkeyLoading" class="w-4 h-4 animate-spin" />
                <Fingerprint v-else class="w-4 h-4" />
                {{ passkeyLoading ? 'Authenticating…' : 'Sign in with Passkey' }}
            </button>
            <p v-if="passkeyError" class="text-red-500 dark:text-red-400 text-xs mt-2 text-center">{{ passkeyError }}</p>

            <div class="relative mt-5 mb-5">
                <div class="absolute inset-0 flex items-center">
                    <div class="w-full border-t border-gray-200 dark:border-gray-700" />
                </div>
                <div class="relative flex justify-center text-xs">
                    <span class="bg-white dark:bg-gray-900 px-3 text-gray-400">or continue with password</span>
                </div>
            </div>
        </div>

        <!-- Password form -->
        <form @submit.prevent="login" class="space-y-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Email</label>
                <div class="relative">
                    <Mail class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" />
                    <input
                        v-model="form.email"
                        type="email"
                        required
                        autofocus
                        autocomplete="username"
                        class="w-full pl-9 pr-3 py-2.5 bg-gray-50 dark:bg-gray-800 border rounded-xl text-sm outline-none transition focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                        :class="form.errors.email ? 'border-red-400' : 'border-gray-300 dark:border-gray-700'"
                    />
                </div>
                <p v-if="form.errors.email" class="text-red-500 text-xs mt-1">{{ form.errors.email }}</p>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Password</label>
                <div class="relative">
                    <Lock class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" />
                    <input
                        v-model="form.password"
                        type="password"
                        required
                        autocomplete="current-password"
                        class="w-full pl-9 pr-3 py-2.5 bg-gray-50 dark:bg-gray-800 border rounded-xl text-sm outline-none transition focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                        :class="form.errors.password ? 'border-red-400' : 'border-gray-300 dark:border-gray-700'"
                    />
                </div>
                <p v-if="form.errors.password" class="text-red-500 text-xs mt-1">{{ form.errors.password }}</p>
            </div>

            <div class="flex items-center justify-between">
                <label class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-400 cursor-pointer">
                    <input v-model="form.remember" type="checkbox" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" />
                    Remember me
                </label>
            </div>

            <button
                type="submit"
                :disabled="form.processing"
                class="w-full py-2.5 bg-gray-900 dark:bg-white hover:bg-gray-800 dark:hover:bg-gray-100 disabled:opacity-50 text-white dark:text-gray-900 font-semibold rounded-xl transition-colors text-sm"
            >
                {{ form.processing ? 'Signing in…' : 'Sign in' }}
            </button>
        </form>
    </AuthLayout>
</template>
