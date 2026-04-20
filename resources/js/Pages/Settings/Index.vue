<script setup>
import { ref, computed } from 'vue'
import { Head, useForm, usePage, Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import {
    User, Lock, ShieldCheck, ShieldOff, Fingerprint, Plus, Trash2,
    Loader2, QrCode, Copy, CheckCheck, Key, RefreshCw, Plug, Eye, EyeOff, X
} from 'lucide-vue-next'

const props = defineProps({
    twoFactorEnabled: Boolean,
    qrCodeUrl: String,
    setupSecret: String,
    recoveryCodes: Array,
    passkeys: Array,
    rtrConfigured: Boolean,
    rtrBaseUrl: String,
})

const page = usePage()
const auth = computed(() => page.props.auth)

// Profile form
const profileForm = useForm({
    name: auth.value.user?.name || '',
    email: auth.value.user?.email || '',
})

// Password form
const passwordForm = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
})

// 2FA confirm form
const totpForm = useForm({ code: '' })

// Disable 2FA form
const disableForm = useForm({ password: '' })

// Passkey registration
const passkeyName = ref('')
const passkeyLoading = ref(false)
const passkeyError = ref('')
const supportsPasskeys = typeof window !== 'undefined' && window.browserSupportsWebAuthn?.()

const copiedCode = ref(null)

function copyCode(code) {
    navigator.clipboard.writeText(code)
    copiedCode.value = code
    setTimeout(() => copiedCode.value = null, 2000)
}

async function registerPasskey() {
    if (!passkeyName.value.trim()) return
    passkeyLoading.value = true
    passkeyError.value = ''
    try {
        const optionsRes = await fetch(route('passkeys.register-options'))
        const optionsJson = await optionsRes.text()
        const options = JSON.parse(optionsJson)
        const regResponse = await window.startRegistration({ optionsJSON: options })
        const form = useForm({
            name: passkeyName.value.trim(),
            passkey_response: JSON.stringify(regResponse),
        })
        form.post(route('passkeys.store'), {
            onSuccess: () => { passkeyName.value = '' },
            onError: (errors) => { passkeyError.value = Object.values(errors)[0] || 'Failed to register passkey.' },
        })
    } catch (e) {
        passkeyError.value = e.name === 'NotAllowedError' ? 'Registration cancelled.' : (e.message || 'Failed to register passkey.')
    } finally {
        passkeyLoading.value = false
    }
}

function deletePasskey(id) {
    if (!confirm('Remove this passkey?')) return
    useForm({}).delete(route('passkeys.destroy', id))
}

// API integrations
const apiForm = useForm({
    api_key: '',
    base_url: props.rtrBaseUrl || 'https://api.yoursrs.com',
    clear: false,
})
const showApiKey = ref(false)

function saveApiSettings() {
    apiForm.put(route('settings.api'), {
        onSuccess: () => { apiForm.api_key = '' },
    })
}

function clearApiKey() {
    if (!confirm('Remove the Realtime Register API key?')) return
    apiForm.clear = true
    apiForm.put(route('settings.api'), {
        onSuccess: () => { apiForm.clear = false },
    })
}
</script>

<template>
    <AppLayout>
        <Head title="Settings" />

        <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-10">
            <h1 class="text-2xl font-bold text-gray-900 dark:text-white mb-8">Settings</h1>

            <div class="space-y-6">
                <!-- Profile -->
                <section class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-800 overflow-hidden">
                    <div class="px-6 py-4 border-b border-gray-100 dark:border-gray-800 flex items-center gap-3">
                        <User class="w-4 h-4 text-gray-400" />
                        <h2 class="font-semibold text-gray-900 dark:text-white text-sm">Profile</h2>
                    </div>
                    <form @submit.prevent="profileForm.put(route('settings.profile'))" class="p-6 space-y-4">
                        <div class="grid sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1.5">Name</label>
                                <input v-model="profileForm.name" type="text" required class="w-full px-3 py-2.5 bg-gray-50 dark:bg-gray-800 border border-gray-300 dark:border-gray-700 rounded-xl text-sm outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition" />
                                <p v-if="profileForm.errors.name" class="text-red-500 text-xs mt-1">{{ profileForm.errors.name }}</p>
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1.5">Email</label>
                                <input v-model="profileForm.email" type="email" required class="w-full px-3 py-2.5 bg-gray-50 dark:bg-gray-800 border border-gray-300 dark:border-gray-700 rounded-xl text-sm outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition" />
                                <p v-if="profileForm.errors.email" class="text-red-500 text-xs mt-1">{{ profileForm.errors.email }}</p>
                            </div>
                        </div>
                        <div class="flex justify-end">
                            <button type="submit" :disabled="profileForm.processing" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 text-white text-sm font-medium rounded-xl transition-colors">
                                Save changes
                            </button>
                        </div>
                    </form>
                </section>

                <!-- Password -->
                <section class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-800 overflow-hidden">
                    <div class="px-6 py-4 border-b border-gray-100 dark:border-gray-800 flex items-center gap-3">
                        <Lock class="w-4 h-4 text-gray-400" />
                        <h2 class="font-semibold text-gray-900 dark:text-white text-sm">Password</h2>
                    </div>
                    <form @submit.prevent="passwordForm.put(route('settings.password'), { onSuccess: () => passwordForm.reset() })" class="p-6 space-y-4">
                        <div>
                            <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1.5">Current password</label>
                            <input v-model="passwordForm.current_password" type="password" autocomplete="current-password" class="w-full px-3 py-2.5 bg-gray-50 dark:bg-gray-800 border border-gray-300 dark:border-gray-700 rounded-xl text-sm outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition" />
                            <p v-if="passwordForm.errors.current_password" class="text-red-500 text-xs mt-1">{{ passwordForm.errors.current_password }}</p>
                        </div>
                        <div class="grid sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1.5">New password</label>
                                <input v-model="passwordForm.password" type="password" autocomplete="new-password" class="w-full px-3 py-2.5 bg-gray-50 dark:bg-gray-800 border border-gray-300 dark:border-gray-700 rounded-xl text-sm outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition" />
                                <p v-if="passwordForm.errors.password" class="text-red-500 text-xs mt-1">{{ passwordForm.errors.password }}</p>
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1.5">Confirm password</label>
                                <input v-model="passwordForm.password_confirmation" type="password" autocomplete="new-password" class="w-full px-3 py-2.5 bg-gray-50 dark:bg-gray-800 border border-gray-300 dark:border-gray-700 rounded-xl text-sm outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition" />
                            </div>
                        </div>
                        <div class="flex justify-end">
                            <button type="submit" :disabled="passwordForm.processing" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 text-white text-sm font-medium rounded-xl transition-colors">
                                Update password
                            </button>
                        </div>
                    </form>
                </section>

                <!-- Two-Factor Authentication -->
                <section class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-800 overflow-hidden">
                    <div class="px-6 py-4 border-b border-gray-100 dark:border-gray-800 flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <ShieldCheck class="w-4 h-4 text-gray-400" />
                            <h2 class="font-semibold text-gray-900 dark:text-white text-sm">Two-factor authentication</h2>
                        </div>
                        <span class="text-xs px-2 py-0.5 rounded-full font-medium"
                            :class="twoFactorEnabled
                                ? 'bg-emerald-100 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-400'
                                : 'bg-gray-100 dark:bg-gray-800 text-gray-500'"
                        >
                            {{ twoFactorEnabled ? 'Enabled' : 'Disabled' }}
                        </span>
                    </div>

                    <div class="p-6">
                        <!-- QR code setup -->
                        <div v-if="qrCodeUrl && !twoFactorEnabled" class="space-y-4">
                            <p class="text-sm text-gray-600 dark:text-gray-400">Scan this QR code with your authenticator app (Google Authenticator, Authy, etc.), then enter the 6-digit code below.</p>
                            <div class="flex justify-center">
                                <div class="p-4 bg-white rounded-2xl border border-gray-200 dark:border-gray-700 inline-block">
                                    <img :src="'https://api.qrserver.com/v1/create-qr-code/?size=180x180&data=' + encodeURIComponent(qrCodeUrl)" alt="QR Code" class="w-44 h-44" />
                                </div>
                            </div>
                            <div class="bg-gray-50 dark:bg-gray-800 rounded-xl p-3 text-center">
                                <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">Manual entry key</p>
                                <code class="text-sm font-mono text-gray-900 dark:text-gray-100 tracking-wider">{{ setupSecret }}</code>
                            </div>
                            <form @submit.prevent="totpForm.post(route('settings.two-factor.confirm'))" class="space-y-3">
                                <input
                                    v-model="totpForm.code"
                                    type="text"
                                    inputmode="numeric"
                                    maxlength="6"
                                    placeholder="000000"
                                    class="w-full px-3 py-3 bg-gray-50 dark:bg-gray-800 border border-gray-300 dark:border-gray-700 rounded-xl text-sm text-center tracking-[0.5em] font-mono outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition"
                                    :class="totpForm.errors.code ? 'border-red-400' : ''"
                                />
                                <p v-if="totpForm.errors.code" class="text-red-500 text-xs text-center">{{ totpForm.errors.code }}</p>
                                <button type="submit" :disabled="totpForm.processing || totpForm.code.length < 6" class="w-full py-2.5 bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 text-white text-sm font-medium rounded-xl transition-colors">
                                    Activate two-factor authentication
                                </button>
                            </form>
                        </div>

                        <!-- Recovery codes (shown once after enable) -->
                        <div v-else-if="recoveryCodes && recoveryCodes.length" class="space-y-4">
                            <div class="bg-amber-50 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-800 rounded-xl p-4">
                                <p class="text-sm font-medium text-amber-800 dark:text-amber-300 mb-1">Save these recovery codes</p>
                                <p class="text-xs text-amber-700 dark:text-amber-400">Store these codes somewhere safe. Each can be used once if you lose access to your authenticator.</p>
                            </div>
                            <div class="grid grid-cols-2 gap-2">
                                <div v-for="code in recoveryCodes" :key="code" class="flex items-center justify-between bg-gray-50 dark:bg-gray-800 rounded-lg px-3 py-2">
                                    <code class="text-xs font-mono text-gray-700 dark:text-gray-300">{{ code }}</code>
                                    <button @click="copyCode(code)" class="ml-2 text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 transition-colors">
                                        <CheckCheck v-if="copiedCode === code" class="w-3.5 h-3.5 text-emerald-500" />
                                        <Copy v-else class="w-3.5 h-3.5" />
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- 2FA enabled state -->
                        <div v-else-if="twoFactorEnabled" class="space-y-4">
                            <p class="text-sm text-gray-600 dark:text-gray-400">Two-factor authentication is active. Your account is protected with TOTP authentication.</p>
                            <form @submit.prevent="disableForm.post(route('settings.two-factor.disable'))">
                                <div class="flex gap-3">
                                    <input v-model="disableForm.password" type="password" placeholder="Confirm with your password" class="flex-1 px-3 py-2.5 bg-gray-50 dark:bg-gray-800 border border-gray-300 dark:border-gray-700 rounded-xl text-sm outline-none focus:ring-2 focus:ring-red-500 focus:border-transparent transition" />
                                    <button type="submit" :disabled="disableForm.processing" class="px-4 py-2.5 bg-red-600 hover:bg-red-700 disabled:opacity-50 text-white text-sm font-medium rounded-xl transition-colors flex items-center gap-2">
                                        <ShieldOff class="w-4 h-4" />
                                        Disable
                                    </button>
                                </div>
                                <p v-if="disableForm.errors.password" class="text-red-500 text-xs mt-1">{{ disableForm.errors.password }}</p>
                            </form>
                        </div>

                        <!-- 2FA disabled state -->
                        <div v-else>
                            <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">Add an extra layer of security to your account. You'll need an authenticator app like Google Authenticator or Authy.</p>
                            <Link :href="route('settings.two-factor.init')" method="post" as="button" class="flex items-center gap-2 px-4 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium rounded-xl transition-colors">
                                <ShieldCheck class="w-4 h-4" />
                                Enable two-factor authentication
                            </Link>
                        </div>
                    </div>
                </section>

                <!-- Passkeys -->
                <section class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-800 overflow-hidden">
                    <div class="px-6 py-4 border-b border-gray-100 dark:border-gray-800 flex items-center gap-3">
                        <Fingerprint class="w-4 h-4 text-gray-400" />
                        <h2 class="font-semibold text-gray-900 dark:text-white text-sm">Passkeys</h2>
                        <span class="ml-auto text-xs text-gray-400">{{ passkeys.length }} registered</span>
                    </div>
                    <div class="p-6 space-y-4">
                        <!-- Existing passkeys -->
                        <div v-if="passkeys.length" class="space-y-2">
                            <div v-for="pk in passkeys" :key="pk.id" class="flex items-center justify-between p-3 bg-gray-50 dark:bg-gray-800 rounded-xl">
                                <div class="flex items-center gap-3">
                                    <Key class="w-4 h-4 text-gray-400" />
                                    <div>
                                        <p class="text-sm font-medium text-gray-900 dark:text-white">{{ pk.name }}</p>
                                        <p class="text-xs text-gray-400">Added {{ pk.created_at }} <span v-if="pk.last_used_at">· Last used {{ pk.last_used_at }}</span></p>
                                    </div>
                                </div>
                                <button @click="deletePasskey(pk.id)" class="p-1.5 text-gray-400 hover:text-red-500 dark:hover:text-red-400 hover:bg-red-50 dark:hover:bg-red-950/30 rounded-lg transition-colors">
                                    <Trash2 class="w-4 h-4" />
                                </button>
                            </div>
                        </div>

                        <!-- Add passkey -->
                        <div v-if="supportsPasskeys" class="space-y-2">
                            <p class="text-sm text-gray-600 dark:text-gray-400">Add a passkey to sign in without a password using Face ID, Touch ID, or a hardware security key.</p>
                            <div class="flex gap-2">
                                <input
                                    v-model="passkeyName"
                                    @keydown.enter="registerPasskey"
                                    type="text"
                                    placeholder="Name this passkey (e.g. MacBook)"
                                    class="flex-1 px-3 py-2.5 bg-gray-50 dark:bg-gray-800 border border-gray-300 dark:border-gray-700 rounded-xl text-sm outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition"
                                />
                                <button
                                    @click="registerPasskey"
                                    :disabled="passkeyLoading || !passkeyName.trim()"
                                    class="px-4 py-2.5 bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 text-white text-sm font-medium rounded-xl transition-colors flex items-center gap-2"
                                >
                                    <Loader2 v-if="passkeyLoading" class="w-4 h-4 animate-spin" />
                                    <Plus v-else class="w-4 h-4" />
                                    Add
                                </button>
                            </div>
                            <p v-if="passkeyError" class="text-red-500 text-xs">{{ passkeyError }}</p>
                        </div>
                        <p v-else class="text-sm text-gray-400">Passkeys are not supported in this browser.</p>
                    </div>
                </section>

                <!-- API Integrations -->
                <section class="bg-white dark:bg-gray-900 rounded-2xl border border-gray-200 dark:border-gray-800 overflow-hidden">
                    <div class="px-6 py-4 border-b border-gray-100 dark:border-gray-800 flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <Plug class="w-4 h-4 text-gray-400" />
                            <h2 class="font-semibold text-gray-900 dark:text-white text-sm">API Integrations</h2>
                        </div>
                    </div>

                    <div class="p-6 space-y-6">
                        <!-- Realtime Register -->
                        <div>
                            <div class="flex items-center gap-3 mb-4">
                                <div class="flex-1">
                                    <div class="flex items-center gap-2">
                                        <h3 class="text-sm font-medium text-gray-900 dark:text-white">Realtime Register</h3>
                                        <span
                                            v-if="rtrConfigured"
                                            class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-emerald-100 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-400 border border-emerald-200 dark:border-emerald-800"
                                        >
                                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 inline-block" />
                                            Connected
                                        </span>
                                        <span
                                            v-else
                                            class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 dark:bg-gray-800 text-gray-500 dark:text-gray-400 border border-gray-200 dark:border-gray-700"
                                        >
                                            Not configured
                                        </span>
                                    </div>
                                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                                        When configured, domain checks use the Realtime Register API first (racing with RDAP), giving faster and more authoritative results.
                                    </p>
                                </div>
                                <button
                                    v-if="rtrConfigured"
                                    @click="clearApiKey"
                                    class="shrink-0 flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium text-red-500 hover:text-red-600 hover:bg-red-50 dark:hover:bg-red-950/30 border border-red-200 dark:border-red-800 transition-colors"
                                >
                                    <X class="w-3.5 h-3.5" />
                                    Remove key
                                </button>
                            </div>

                            <form @submit.prevent="saveApiSettings" class="space-y-3">
                                <!-- API Key -->
                                <div>
                                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1.5">
                                        {{ rtrConfigured ? 'Replace API key' : 'API key' }}
                                    </label>
                                    <div class="relative">
                                        <Key class="absolute left-3 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-gray-400" />
                                        <input
                                            v-model="apiForm.api_key"
                                            :type="showApiKey ? 'text' : 'password'"
                                            :placeholder="rtrConfigured ? 'Enter new key to replace…' : 'ApiKey …'"
                                            autocomplete="off"
                                            class="w-full pl-8 pr-10 py-2.5 bg-gray-50 dark:bg-gray-800 border border-gray-300 dark:border-gray-700 rounded-xl text-sm font-mono outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition"
                                        />
                                        <button
                                            type="button"
                                            @click="showApiKey = !showApiKey"
                                            class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300"
                                        >
                                            <EyeOff v-if="showApiKey" class="w-4 h-4" />
                                            <Eye v-else class="w-4 h-4" />
                                        </button>
                                    </div>
                                    <p v-if="apiForm.errors.api_key" class="text-red-500 text-xs mt-1">{{ apiForm.errors.api_key }}</p>
                                </div>

                                <!-- Base URL -->
                                <div>
                                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1.5">
                                        API base URL <span class="text-gray-400 font-normal">(production: api.yoursrs.com · test: api.yoursrs-ote.com)</span>
                                    </label>
                                    <input
                                        v-model="apiForm.base_url"
                                        type="url"
                                        class="w-full px-3 py-2.5 bg-gray-50 dark:bg-gray-800 border border-gray-300 dark:border-gray-700 rounded-xl text-sm font-mono outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition"
                                    />
                                    <p v-if="apiForm.errors.base_url" class="text-red-500 text-xs mt-1">{{ apiForm.errors.base_url }}</p>
                                </div>

                                <div class="flex justify-end pt-1">
                                    <button
                                        type="submit"
                                        :disabled="apiForm.processing || (!apiForm.api_key.trim() && apiForm.base_url === (rtrBaseUrl || 'https://api.yoursrs.com'))"
                                        class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 disabled:cursor-not-allowed text-white text-sm font-medium rounded-xl transition-colors"
                                    >
                                        Save
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </section>
            </div>
        </div>
    </AppLayout>
</template>
