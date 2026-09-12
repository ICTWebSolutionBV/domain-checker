<script setup>
import { Head, useForm, Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import FormField from '@/Components/FormField.vue'

defineProps({
    assignableRoles: { type: Array, default: () => ['user', 'admin'] },
})

const roleLabel = (r) => (r === 'super_admin' ? 'Super Admin' : r === 'admin' ? 'Admin' : 'User')

const form = useForm({
    first_name: '',
    last_name: '',
    email: '',
    password: '',
    password_confirmation: '',
    role: 'user',
})

const submit = () => {
    form.post(route('admin.users.store'))
}
</script>

<template>
    <Head title="Create User" />
    <AppLayout>
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10">
            <div class="max-w-2xl">
                <div class="flex items-center gap-3 mb-6">
                    <Link
                        :href="route('admin.users.index')"
                        class="text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300"
                        aria-label="Back to users"
                    >
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                        </svg>
                    </Link>
                    <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Create User</h1>
                </div>

                <div class="ui-card rounded-xl p-6">
                    <form @submit.prevent="submit" class="space-y-4">
                        <div class="grid grid-cols-2 gap-3">
                            <FormField
                                label="First Name"
                                label-class="text-sm mb-1"
                                :error="form.errors.first_name"
                                v-slot="field"
                            >
                                <input
                                    v-bind="field"
                                    v-model="form.first_name"
                                    type="text"
                                    required
                                    autocomplete="off"
                                    class="ui-input"
                                />
                            </FormField>
                            <FormField
                                label="Last Name"
                                hint="(optional)"
                                label-class="text-sm mb-1"
                                :error="form.errors.last_name"
                                v-slot="field"
                            >
                                <input
                                    v-bind="field"
                                    v-model="form.last_name"
                                    type="text"
                                    autocomplete="off"
                                    class="ui-input"
                                />
                            </FormField>
                        </div>
                        <FormField label="Email" label-class="text-sm mb-1" :error="form.errors.email" v-slot="field">
                            <input
                                v-bind="field"
                                v-model="form.email"
                                type="email"
                                required
                                autocomplete="off"
                                class="ui-input"
                            />
                        </FormField>
                        <FormField
                            label="Password"
                            label-class="text-sm mb-1"
                            :error="form.errors.password"
                            v-slot="field"
                        >
                            <input
                                v-bind="field"
                                v-model="form.password"
                                type="password"
                                required
                                autocomplete="new-password"
                                class="ui-input"
                            />
                        </FormField>
                        <FormField
                            label="Confirm Password"
                            label-class="text-sm mb-1"
                            :error="form.errors.password_confirmation"
                            v-slot="field"
                        >
                            <input
                                v-bind="field"
                                v-model="form.password_confirmation"
                                type="password"
                                required
                                autocomplete="new-password"
                                class="ui-input"
                            />
                        </FormField>
                        <FormField label="Role" label-class="text-sm mb-1" :error="form.errors.role" v-slot="field">
                            <select v-bind="field" v-model="form.role" class="ui-input">
                                <option v-for="r in assignableRoles" :key="r" :value="r">{{ roleLabel(r) }}</option>
                            </select>
                        </FormField>
                        <div class="flex gap-3 pt-2">
                            <button type="submit" :disabled="form.processing" class="ui-btn ui-btn-primary">
                                Create User
                            </button>
                            <Link
                                :href="route('admin.users.index')"
                                class="px-4 py-2.5 border border-gray-300 dark:border-gray-700 text-gray-700 dark:text-gray-300 rounded-xl text-sm font-medium hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors"
                            >
                                Cancel
                            </Link>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
