<script setup>
import { ref } from 'vue'
import { Head, Link, useForm, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import FormField from '@/Components/FormField.vue'
import Pagination from '@/Components/Pagination.vue'

// Both lists are paginated now, so each prop is a Laravel paginator: rows in
// .data, page links in .links.
defineProps({
    users: Object,
    invites: Object,
    assignableRoles: { type: Array, default: () => ['user', 'admin'] },
})

const roleLabel = (r) => (r === 'super_admin' ? 'Super Admin' : r === 'admin' ? 'Admin' : 'User')
const rolePillClass = (r) =>
    r === 'super_admin'
        ? 'bg-rose-50 dark:bg-rose-900/20 text-rose-700 dark:text-rose-400'
        : r === 'admin'
          ? 'bg-purple-50 dark:bg-purple-900/20 text-purple-700 dark:text-purple-400'
          : 'bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-400'

const showInviteForm = ref(false)

// Every router.delete/post here fired with no onFinish and no pending flag,
// and none of the buttons was ever disabled — a double-click sent two deletes
// or two password-reset emails.
const busy = ref(null)

function mutate(key, run) {
    if (busy.value) return
    busy.value = key
    run({
        preserveScroll: true,
        onFinish: () => {
            busy.value = null
        },
    })
}

const inviteForm = useForm({
    email: '',
    first_name: '',
    last_name: '',
    role: 'user',
    expires_hours: 72,
})

const createInvite = () => {
    inviteForm.post(route('admin.invites.store'), {
        onSuccess: () => {
            inviteForm.reset()
            showInviteForm.value = false
        },
    })
}

const deleteUser = (id) => {
    if (!confirm('Are you sure you want to delete this user?')) return
    mutate(`delete-${id}`, (opts) => router.delete(route('admin.users.destroy', id), opts))
}

// Revoking used to fire straight into router.delete with no confirmation at
// all, while resending an invite and sending a password reset — neither of
// which destroys anything — both asked first.
const revokeInvite = (invite) => {
    if (!confirm(`Revoke the invite for ${invite.email}? The link in their email stops working immediately.`)) return
    mutate(`revoke-${invite.id}`, (opts) => router.delete(route('admin.invites.destroy', invite.id), opts))
}

const resendInvite = (invite) => {
    if (!confirm(`Resend invite to ${invite.email}? A fresh link with a new 72-hour expiry will be emailed.`)) return
    mutate(`resend-${invite.id}`, (opts) => router.post(route('admin.invites.resend', invite.id), {}, opts))
}

const sendPasswordReset = (user) => {
    if (!confirm(`Send a password reset email to ${user.email}?`)) return
    mutate(`reset-${user.id}`, (opts) => router.post(route('admin.users.password-reset', user.id), {}, opts))
}

const resetTwoFactor = (user) => {
    if (!confirm(`Reset all 2FA settings for ${user.email}?\n\nThis will remove their authenticator and passkeys.`))
        return
    mutate(`2fa-${user.id}`, (opts) => router.post(route('admin.users.reset-2fa', user.id), {}, opts))
}

const twoFactorSummary = (user) => {
    const m = []
    if (user.two_factor?.totp_enabled) m.push('App')
    if (user.two_factor?.passkeys_enabled) m.push('Passkey')
    return m.length ? m.join(', ') : 'None'
}
</script>

<template>
    <Head title="Users" />
    <AppLayout>
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10">
            <div class="flex items-center justify-between mb-6">
                <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Users</h1>
                <div class="flex gap-2">
                    <button
                        @click="showInviteForm = !showInviteForm"
                        :aria-expanded="showInviteForm"
                        class="ui-btn ui-btn-primary"
                    >
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"
                            />
                        </svg>
                        Invite
                    </button>
                    <Link
                        :href="route('admin.users.create')"
                        class="inline-flex items-center gap-2 px-4 py-2.5 bg-gray-900 dark:bg-gray-100 hover:bg-gray-800 dark:hover:bg-white text-white dark:text-gray-900 font-medium rounded-xl transition-colors text-sm"
                    >
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                        </svg>
                        Create User
                    </Link>
                </div>
            </div>

            <!-- Invite form -->
            <div v-if="showInviteForm" class="ui-card rounded-xl p-6 mb-6">
                <h2 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Send Invite</h2>
                <form @submit.prevent="createInvite" class="flex flex-wrap gap-3 items-end">
                    <FormField
                        label="First Name"
                        hint="(optional)"
                        label-class="text-sm mb-1"
                        class="flex-1 min-w-[140px]"
                        :error="inviteForm.errors.first_name"
                        v-slot="field"
                    >
                        <input
                            v-bind="field"
                            v-model="inviteForm.first_name"
                            type="text"
                            placeholder="Jane"
                            autocomplete="off"
                            class="ui-input"
                        />
                    </FormField>
                    <FormField
                        label="Last Name"
                        hint="(optional)"
                        label-class="text-sm mb-1"
                        class="flex-1 min-w-[140px]"
                        :error="inviteForm.errors.last_name"
                        v-slot="field"
                    >
                        <input
                            v-bind="field"
                            v-model="inviteForm.last_name"
                            type="text"
                            placeholder="Doe"
                            autocomplete="off"
                            class="ui-input"
                        />
                    </FormField>
                    <FormField
                        label="Email"
                        label-class="text-sm mb-1"
                        class="flex-1 min-w-[180px]"
                        :error="inviteForm.errors.email"
                        v-slot="field"
                    >
                        <input
                            v-bind="field"
                            v-model="inviteForm.email"
                            type="email"
                            required
                            placeholder="jane@example.com"
                            autocomplete="off"
                            class="ui-input"
                        />
                    </FormField>
                    <FormField
                        label="Role"
                        label-class="text-sm mb-1"
                        class="w-28"
                        :error="inviteForm.errors.role"
                        v-slot="field"
                    >
                        <select v-bind="field" v-model="inviteForm.role" class="ui-input">
                            <option v-for="r in assignableRoles" :key="r" :value="r">{{ roleLabel(r) }}</option>
                        </select>
                    </FormField>
                    <FormField
                        label="Expires (hours)"
                        label-class="text-sm mb-1"
                        class="w-36"
                        :error="inviteForm.errors.expires_hours"
                        v-slot="field"
                    >
                        <input
                            v-bind="field"
                            v-model.number="inviteForm.expires_hours"
                            type="number"
                            min="1"
                            max="720"
                            class="ui-input"
                        />
                    </FormField>
                    <button type="submit" :disabled="inviteForm.processing" class="ui-btn ui-btn-primary self-end">
                        {{ inviteForm.processing ? 'Sending…' : 'Send Invite' }}
                    </button>
                </form>
            </div>

            <!-- Users table -->
            <div class="ui-card rounded-xl mb-6">
                <div class="overflow-x-auto rounded-xl">
                    <table class="w-full text-sm">
                        <caption class="sr-only">
                            User accounts
                        </caption>
                        <thead class="bg-gray-50 dark:bg-gray-800/50">
                            <tr>
                                <th
                                    scope="col"
                                    class="text-left px-4 py-3 font-medium text-gray-500 dark:text-gray-400"
                                >
                                    Name
                                </th>
                                <th
                                    scope="col"
                                    class="text-left px-4 py-3 font-medium text-gray-500 dark:text-gray-400"
                                >
                                    Email
                                </th>
                                <th
                                    scope="col"
                                    class="text-left px-4 py-3 font-medium text-gray-500 dark:text-gray-400"
                                >
                                    Role
                                </th>
                                <th
                                    scope="col"
                                    class="text-left px-4 py-3 font-medium text-gray-500 dark:text-gray-400"
                                >
                                    2FA
                                </th>
                                <th
                                    scope="col"
                                    class="text-right px-4 py-3 font-medium text-gray-500 dark:text-gray-400"
                                >
                                    Actions
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                            <tr v-for="user in users.data" :key="user.id">
                                <td class="px-4 py-3 text-gray-900 dark:text-white font-medium">{{ user.name }}</td>
                                <td class="px-4 py-3 text-gray-500 dark:text-gray-400">{{ user.email }}</td>
                                <td class="px-4 py-3">
                                    <span
                                        :class="rolePillClass(user.role)"
                                        class="text-xs px-2 py-1 rounded-full font-medium"
                                        >{{ roleLabel(user.role) }}</span
                                    >
                                </td>
                                <td class="px-4 py-3 text-xs text-gray-500 dark:text-gray-400">
                                    {{ twoFactorSummary(user) }}
                                </td>
                                <td class="px-4 py-3 text-right whitespace-nowrap">
                                    <div class="flex items-center justify-end gap-4">
                                        <Link
                                            :href="route('admin.users.edit', user.id)"
                                            class="text-indigo-700 hover:text-indigo-800 dark:text-indigo-400 dark:hover:text-indigo-300 text-xs font-medium py-1.5"
                                            >Edit</Link
                                        >
                                        <button
                                            @click="sendPasswordReset(user)"
                                            :disabled="!!busy"
                                            class="text-gray-600 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white text-xs font-medium py-1.5 disabled:opacity-50"
                                        >
                                            {{ busy === `reset-${user.id}` ? 'Sending…' : 'Send password reset' }}
                                        </button>
                                        <button
                                            @click="resetTwoFactor(user)"
                                            :disabled="!!busy"
                                            class="text-amber-700 hover:text-amber-800 dark:text-amber-500 dark:hover:text-amber-400 text-xs font-medium py-1.5 disabled:opacity-50"
                                        >
                                            Reset 2FA
                                        </button>
                                        <button
                                            @click="deleteUser(user.id)"
                                            :disabled="!!busy"
                                            class="text-red-600 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300 text-xs font-medium py-1.5 disabled:opacity-50"
                                        >
                                            Delete
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <Pagination :paginator="users" />
            </div>

            <!-- Pending invites -->
            <div v-if="invites.data.length" class="ui-card rounded-xl">
                <div class="px-4 py-3 border-b border-gray-200 dark:border-gray-800">
                    <h2 class="font-semibold text-gray-900 dark:text-white">Pending Invites</h2>
                </div>
                <div class="overflow-x-auto rounded-b-xl">
                    <table class="w-full text-sm">
                        <caption class="sr-only">
                            Pending invitations
                        </caption>
                        <thead class="bg-gray-50 dark:bg-gray-800/50">
                            <tr>
                                <th
                                    scope="col"
                                    class="text-left px-4 py-3 font-medium text-gray-500 dark:text-gray-400"
                                >
                                    Email
                                </th>
                                <th
                                    scope="col"
                                    class="text-left px-4 py-3 font-medium text-gray-500 dark:text-gray-400"
                                >
                                    Role
                                </th>
                                <th
                                    scope="col"
                                    class="text-left px-4 py-3 font-medium text-gray-500 dark:text-gray-400"
                                >
                                    Invited by
                                </th>
                                <th
                                    scope="col"
                                    class="text-left px-4 py-3 font-medium text-gray-500 dark:text-gray-400"
                                >
                                    Status
                                </th>
                                <th
                                    scope="col"
                                    class="text-right px-4 py-3 font-medium text-gray-500 dark:text-gray-400"
                                >
                                    Actions
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                            <tr v-for="invite in invites.data" :key="invite.id">
                                <td class="px-4 py-3 text-gray-900 dark:text-white">{{ invite.email }}</td>
                                <td class="px-4 py-3 text-gray-500 dark:text-gray-400">{{ roleLabel(invite.role) }}</td>
                                <td class="px-4 py-3 text-xs text-gray-500 dark:text-gray-400">
                                    Invited by {{ invite.inviter ?? 'system' }}
                                </td>
                                <td class="px-4 py-3">
                                    <span v-if="invite.used_at" class="text-xs text-emerald-700 dark:text-emerald-400"
                                        >Used</span
                                    >
                                    <span v-else-if="!invite.is_valid" class="text-xs text-red-600 dark:text-red-400"
                                        >Expired</span
                                    >
                                    <span v-else class="text-xs text-amber-700 dark:text-amber-400">Pending</span>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <div class="flex items-center justify-end gap-4">
                                        <button
                                            v-if="!invite.used_at && !invite.is_valid"
                                            @click="resendInvite(invite)"
                                            :disabled="!!busy"
                                            class="text-indigo-700 hover:text-indigo-800 dark:text-indigo-400 dark:hover:text-indigo-300 text-xs font-medium py-1.5 disabled:opacity-50"
                                        >
                                            {{ busy === `resend-${invite.id}` ? 'Sending…' : 'Resend' }}
                                        </button>
                                        <button
                                            v-if="invite.is_valid"
                                            @click="revokeInvite(invite)"
                                            :disabled="!!busy"
                                            class="text-red-600 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300 text-xs font-medium py-1.5 disabled:opacity-50"
                                        >
                                            Revoke
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <Pagination :paginator="invites" />
            </div>
        </div>
    </AppLayout>
</template>
