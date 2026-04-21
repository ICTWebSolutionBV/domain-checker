<script setup>
import { Head, Link } from '@inertiajs/vue3'

const props = defineProps({
    reason: { type: String, default: 'not_found' }, // 'not_found' | 'expired' | 'used'
})

const messages = {
    not_found: { title: 'Invite not found', body: 'This invite link is invalid or has already been removed.' },
    expired:   { title: 'Invite expired', body: 'This invite link has expired. Ask your admin to send a new one.' },
    used:      { title: 'Invite already used', body: 'This invite link has already been used to create an account.' },
}

const msg = messages[props.reason] ?? messages.not_found
</script>

<template>
    <Head title="Invalid Invitation" />
    <div class="min-h-screen flex items-center justify-center bg-gradient-to-br from-gray-900 via-gray-800 to-indigo-900 px-4">
        <div class="w-full max-w-sm text-center">
            <div class="w-16 h-16 bg-red-600 rounded-2xl flex items-center justify-center mx-auto mb-6 shadow-lg">
                <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
            </div>
            <h1 class="text-2xl font-bold text-white mb-2">{{ msg.title }}</h1>
            <p class="text-gray-400 text-sm mb-6">{{ msg.body }}</p>
            <Link :href="route('login')"
                class="inline-flex items-center gap-2 px-5 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white font-medium rounded-xl transition-colors text-sm">
                Go to sign in
            </Link>
        </div>
    </div>
</template>
