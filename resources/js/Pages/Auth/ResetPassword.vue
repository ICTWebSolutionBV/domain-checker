<script setup>
import { Head, useForm } from '@inertiajs/vue3'
import FormField from '@/Components/FormField.vue'

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
                <p v-if="form.errors.token" role="alert" class="text-red-600 dark:text-red-400 text-sm mb-4">
                    {{ form.errors.token }}
                </p>
                <form @submit.prevent="submit" class="space-y-4">
                    <FormField label="Email" label-class="text-sm mb-1" :error="form.errors.email" v-slot="field">
                        <input v-bind="field" :value="form.email" type="email" name="email" readonly
                            autocomplete="username" class="ui-input" />
                    </FormField>
                    <FormField label="New password" label-class="text-sm mb-1" :error="form.errors.password" v-slot="field">
                        <input v-bind="field" v-model="form.password" type="password" name="password" required autocomplete="new-password"
                            class="ui-input" />
                    </FormField>
                    <FormField label="Confirm new password" label-class="text-sm mb-1" :error="form.errors.password_confirmation" v-slot="field">
                        <input v-bind="field" v-model="form.password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password"
                            class="ui-input" />
                    </FormField>
                    <button type="submit" :disabled="form.processing"
                        class="ui-btn ui-btn-primary w-full">
                        {{ form.processing ? 'Updating…' : 'Reset password' }}
                    </button>
                </form>
            </div>
        </div>
    </div>
</template>
