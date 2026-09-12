<script setup>
import { Head, useForm } from '@inertiajs/vue3'
import FormField from '@/Components/FormField.vue'

const props = defineProps({
    token: String,
    email: String,
    first_name: { type: String, default: '' },
    last_name: { type: String, default: '' },
    expires_at: String,
})

const form = useForm({
    first_name: props.first_name || '',
    last_name: props.last_name || '',
    password: '',
    password_confirmation: '',
})

const submit = () => {
    form.post(route('invite.accept', props.token))
}
</script>

<template>
    <Head title="Accept Invitation" />
    <div class="min-h-screen flex items-center justify-center bg-gradient-to-br from-gray-900 via-gray-800 to-indigo-900 px-4 py-8">
        <div class="w-full max-w-sm">
            <div class="text-center mb-6">
                <div class="w-16 h-16 bg-indigo-600 rounded-2xl flex items-center justify-center mx-auto mb-4 shadow-lg shadow-indigo-600/30">
                    <svg class="w-9 h-9 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <circle cx="12" cy="12" r="10" stroke-width="2"/>
                        <line x1="2" y1="12" x2="22" y2="12" stroke-width="2"/>
                        <path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z" stroke-width="2"/>
                    </svg>
                </div>
                <h1 class="text-2xl font-bold text-white">You're invited!</h1>
                <p class="text-gray-400 mt-1 text-sm">Create your account for {{ email }}</p>
            </div>

            <div class="ui-card shadow-overlay p-6">
                <form @submit.prevent="submit" class="space-y-4">
                    <div class="grid grid-cols-2 gap-3">
                        <FormField label="First Name" label-class="text-sm mb-1" :error="form.errors.first_name" v-slot="field">
                            <input v-bind="field" v-model="form.first_name" type="text" name="first_name" required autofocus
                                autocomplete="given-name" class="ui-input" />
                        </FormField>
                        <FormField label="Last Name" hint="(optional)" label-class="text-sm mb-1" :error="form.errors.last_name" v-slot="field">
                            <input v-bind="field" v-model="form.last_name" type="text" name="last_name"
                                autocomplete="family-name" class="ui-input" />
                        </FormField>
                    </div>

                    <FormField label="Password" label-class="text-sm mb-1" :error="form.errors.password" v-slot="field">
                        <input v-bind="field" v-model="form.password" type="password" name="password" required autocomplete="new-password"
                            class="ui-input" />
                    </FormField>

                    <FormField label="Confirm Password" label-class="text-sm mb-1" :error="form.errors.password_confirmation" v-slot="field">
                        <input v-bind="field" v-model="form.password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password"
                            class="ui-input" />
                    </FormField>

                    <button type="submit" :disabled="form.processing"
                        class="ui-btn ui-btn-primary w-full">
                        {{ form.processing ? 'Creating account…' : 'Create account' }}
                        <svg v-if="!form.processing" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
                    </button>
                </form>
            </div>

            <p class="text-center text-xs text-gray-500 mt-4">
                Already have an account?
                <a :href="route('login')" class="text-indigo-400 hover:text-indigo-300">Sign in</a>
            </p>
        </div>
    </div>
</template>
