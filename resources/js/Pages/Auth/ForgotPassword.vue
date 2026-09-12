<script setup>
import { Head, useForm, Link } from '@inertiajs/vue3'
import FormField from '@/Components/FormField.vue'

defineProps({
    status: String,
})

const form = useForm({ email: '' })

const submit = () => {
    form.post(route('password.email'))
}
</script>

<template>
    <Head title="Forgot Password" />
    <div class="min-h-screen flex flex-col items-center justify-center bg-gradient-to-br from-gray-900 via-gray-800 to-indigo-900 px-4">
        <div class="w-full max-w-sm">
            <div class="mb-8 text-center">
                <h1 class="text-2xl font-bold text-white">Reset your password</h1>
                <p class="text-gray-400 text-sm mt-2">Enter your email and we'll send you a reset link.</p>
            </div>

            <div class="ui-card shadow-overlay p-6">
                <p v-if="status" role="status" class="text-emerald-700 dark:text-emerald-400 text-sm mb-4">{{ status }}</p>

                <form @submit.prevent="submit" class="space-y-4">
                    <FormField label="Email" label-class="text-sm mb-1" :error="form.errors.email" v-slot="field">
                        <input v-bind="field" v-model="form.email" type="email" name="email" required autofocus
                            autocomplete="username" class="ui-input" />
                    </FormField>
                    <button type="submit" :disabled="form.processing"
                        class="ui-btn ui-btn-primary w-full">
                        {{ form.processing ? 'Sending…' : 'Send reset link' }}
                    </button>
                </form>

                <div class="text-center mt-4">
                    <Link :href="route('login')" class="text-xs text-gray-500 hover:text-gray-700 dark:hover:text-gray-300">
                        Back to sign in
                    </Link>
                </div>
            </div>
        </div>
    </div>
</template>
