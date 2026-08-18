<script setup>
import { Head, useForm } from '@inertiajs/vue3'

const props = defineProps({
    email: String,
    token: String,
})

const form = useForm({
    token: props.token,
    email: props.email,
    password: '',
    password_confirmation: '',
})

const submit = () => {
    form.post(route('password.update'), {
        onFinish: () => form.reset('password', 'password_confirmation'),
    })
}
</script>

<template>
    <Head title="Reset Password" />
    <div class="min-h-screen flex flex-col items-center justify-center bg-gradient-to-br from-gray-900 via-gray-800 to-indigo-900 px-4">
        <div class="w-full max-w-sm">
            <div class="mb-8 text-center">
                <h1 class="text-2xl font-bold text-white">Choose a new password</h1>
            </div>

            <div class="ui-card shadow-overlay p-6">
                <form @submit.prevent="submit" class="space-y-4">
                    <div>
                        <label class="ui-label text-sm mb-1">Email</label>
                        <input v-model="form.email" type="email" required
                            class="ui-input" />
                        <p v-if="form.errors.email" class="text-red-600 dark:text-red-400 text-xs mt-1">{{ form.errors.email }}</p>
                    </div>
                    <div>
                        <label class="ui-label text-sm mb-1">New password</label>
                        <input v-model="form.password" type="password" required autocomplete="new-password"
                            class="ui-input" />
                        <p v-if="form.errors.password" class="text-red-600 dark:text-red-400 text-xs mt-1">{{ form.errors.password }}</p>
                    </div>
                    <div>
                        <label class="ui-label text-sm mb-1">Confirm new password</label>
                        <input v-model="form.password_confirmation" type="password" required autocomplete="new-password"
                            class="ui-input" />
                    </div>
                    <button type="submit" :disabled="form.processing"
                        class="ui-btn ui-btn-primary w-full">
                        {{ form.processing ? 'Updating…' : 'Reset password' }}
                    </button>
                </form>
            </div>
        </div>
    </div>
</template>
