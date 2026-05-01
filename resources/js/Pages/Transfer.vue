<script setup>
import { ref, computed } from 'vue'
import { Head } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import {
    ArrowRightLeft, Plus, Trash2, ChevronDown, ChevronUp,
    Copy, Check, X, UserCircle, Building2, Globe, Info, Pencil,
} from 'lucide-vue-next'

// ── Helpers ──────────────────────────────────────────────────────────────
function blankBlock(label) {
    return {
        label,
        open: true,
        domainInput: '',
        domains: [],
        existingAccount: '',
        companyName: '',
        firstName: '',
        lastName: '',
        street: '',
        houseNumber: '',
        postalCode: '',
        city: '',
        country: '',
        phone: '',
        email: '',
        kvk: '',
        vatId: '',
        authCode: '',
        notes: '',
    }
}

const DOMAIN_RE = /^(?:[a-z0-9](?:[a-z0-9\-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/i

function normalizeDomain(raw) {
    let v = String(raw || '').trim().toLowerCase()
    v = v.replace(/^https?:\/\//, '').replace(/^www\./, '').replace(/\/.*$/, '')
    return v
}

// ── State ────────────────────────────────────────────────────────────────
const blocks = ref([blankBlock('')])
const requesterName  = ref('')
const requesterEmail = ref('')
const requesterNote  = ref('')
const copied = ref(false)
const copyError = ref('')

const totalDomains = computed(() => blocks.value.reduce((n, b) => n + b.domains.length, 0))

// ── Block / domain management ────────────────────────────────────────────
function addBlock() {
    blocks.value.forEach(b => (b.open = false))
    blocks.value.push(blankBlock(''))
}

function removeBlock(i) {
    if (blocks.value.length === 1) {
        blocks.value[0] = blankBlock('')
        return
    }
    blocks.value.splice(i, 1)
}

function toggleBlock(i) {
    blocks.value[i].open = !blocks.value[i].open
}

function commitDomains(block) {
    const raw = (block.domainInput || '').split(/[\s,;]+/)
    const seen = new Set(block.domains)
    let added = 0
    for (const r of raw) {
        const d = normalizeDomain(r)
        if (!d) continue
        if (!DOMAIN_RE.test(d)) continue
        if (seen.has(d)) continue
        block.domains.push(d)
        seen.add(d)
        added++
    }
    block.domainInput = ''
    return added
}

function onDomainKeydown(block, e) {
    if (e.key === 'Enter' || e.key === ',' || e.key === ';' || e.key === ' ') {
        if (block.domainInput.trim()) {
            e.preventDefault()
            commitDomains(block)
        }
    } else if (e.key === 'Backspace' && !block.domainInput && block.domains.length) {
        block.domains.pop()
    }
}

function onDomainPaste(block) {
    setTimeout(() => commitDomains(block), 0)
}

function removeDomain(block, idx) {
    block.domains.splice(idx, 1)
}

// ── Clipboard summary ────────────────────────────────────────────────────
function blockSummary(block, idx) {
    const lines = []
    const title = (block.label && block.label.trim()) || `Group ${idx + 1}`
    lines.push(`── ${title} — ${block.domains.length} domain(s) ──`)

    if (block.domains.length) {
        lines.push('Domains:')
        block.domains.forEach(d => lines.push(`  • ${d}`))
    } else {
        lines.push('Domains: (none)')
    }

    if (block.existingAccount && block.existingAccount.trim()) {
        lines.push('')
        lines.push(`Existing account: ${block.existingAccount.trim()}`)
    }

    const reg = [
        ['Company',     block.companyName],
        ['Name',        [block.firstName, block.lastName].filter(Boolean).join(' ')],
        ['Address',     [block.street,    block.houseNumber].filter(Boolean).join(' ')],
        ['Postal code', block.postalCode],
        ['City',        block.city],
        ['Country',     block.country],
        ['Phone',       block.phone],
        ['Email',       block.email],
        ['KVK',         block.kvk],
        ['VAT ID',      block.vatId],
    ].filter(([, v]) => v && String(v).trim())

    if (reg.length) {
        lines.push('')
        lines.push('Registrant:')
        const pad = Math.max(...reg.map(([k]) => k.length))
        reg.forEach(([k, v]) => lines.push(`  ${k.padEnd(pad)} : ${String(v).trim()}`))
    }

    if (block.authCode && block.authCode.trim()) {
        lines.push('')
        lines.push(`Auth / EPP code: ${block.authCode.trim()}`)
    }

    if (block.notes && block.notes.trim()) {
        lines.push('')
        lines.push('Notes:')
        block.notes.trim().split(/\n/).forEach(l => lines.push(`  ${l}`))
    }

    return lines.join('\n')
}

function buildClipboardText() {
    const lines = []
    lines.push('Domain transfer request')
    lines.push('═══════════════════════')
    if (requesterName.value.trim() || requesterEmail.value.trim()) {
        lines.push('')
        if (requesterName.value.trim())  lines.push(`Requester:  ${requesterName.value.trim()}`)
        if (requesterEmail.value.trim()) lines.push(`Reply to:   ${requesterEmail.value.trim()}`)
    }
    if (requesterNote.value.trim()) {
        lines.push('')
        lines.push('Message:')
        requesterNote.value.trim().split(/\n/).forEach(l => lines.push(`  ${l}`))
    }
    lines.push('')
    lines.push(`Total: ${totalDomains.value} domain(s) across ${blocks.value.length} group(s)`)
    blocks.value.forEach((b, i) => {
        lines.push('')
        lines.push(blockSummary(b, i))
    })
    return lines.join('\n')
}

async function copyAll() {
    copyError.value = ''
    // Auto-commit anything still typed but not chipped
    blocks.value.forEach(commitDomains)

    if (totalDomains.value === 0) {
        copyError.value = 'Add at least one domain before copying.'
        return
    }

    try {
        await navigator.clipboard.writeText(buildClipboardText())
        copied.value = true
        setTimeout(() => { copied.value = false }, 2200)
    } catch (e) {
        copyError.value = 'Could not access the clipboard. Select the preview text below and copy manually.'
    }
}

const previewText = computed(() => buildClipboardText())
const showPreview = ref(false)
</script>

<template>
    <AppLayout>
        <Head title="Transfer domains" />

        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 pt-12 pb-16">

            <!-- Hero -->
            <div class="text-center mb-10">
                <div class="inline-flex items-center justify-center w-12 h-12 bg-indigo-100 dark:bg-indigo-900/40 rounded-2xl mb-4">
                    <ArrowRightLeft class="w-6 h-6 text-indigo-600 dark:text-indigo-400" />
                </div>
                <h1 class="text-3xl sm:text-4xl font-bold text-gray-900 dark:text-white tracking-tight mb-3">
                    Transfer domains to us
                </h1>
                <p class="text-sm text-gray-500 dark:text-gray-400 max-w-2xl mx-auto leading-relaxed">
                    Add the domains you want to transfer, fill in the owner details once per group, and copy everything to your clipboard in one click. Paste the result into an email or chat to send it our way. Need different owners for different domains? Add another group below.
                </p>
            </div>

            <!-- Requester card -->
            <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-2xl p-5 mb-6 shadow-sm">
                <div class="flex items-center gap-2 mb-4">
                    <UserCircle class="w-4 h-4 text-indigo-500" />
                    <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Your details <span class="font-normal text-gray-400">(optional — included at the top of the copied request)</span></h2>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Your name</label>
                        <input v-model="requesterName" type="text" placeholder="John Doe" class="w-full px-3 py-2 text-sm bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl text-gray-900 dark:text-white placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent" />
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Reply-to email</label>
                        <input v-model="requesterEmail" type="email" placeholder="john@example.com" class="w-full px-3 py-2 text-sm bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl text-gray-900 dark:text-white placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent" />
                    </div>
                </div>
                <div class="mt-3">
                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Message <span class="text-gray-400">(optional)</span></label>
                    <textarea v-model="requesterNote" rows="2" placeholder="Anything we should know about these transfers — e.g. preferred go-live date." class="w-full px-3 py-2 text-sm bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl text-gray-900 dark:text-white placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent resize-y"></textarea>
                </div>
            </div>

            <!-- Repeater of blocks -->
            <div class="space-y-3">
                <div
                    v-for="(block, i) in blocks"
                    :key="i"
                    class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-2xl shadow-sm overflow-hidden"
                >
                    <!-- Header bar -->
                    <div class="flex items-center justify-between gap-3 px-5 py-3 border-b border-gray-100 dark:border-gray-800">
                        <div class="flex items-center gap-2 flex-1 min-w-0">
                            <span class="inline-flex items-center justify-center w-6 h-6 rounded-md bg-indigo-50 dark:bg-indigo-950/40 text-indigo-600 dark:text-indigo-400 text-xs font-semibold shrink-0">
                                {{ i + 1 }}
                            </span>

                            <!-- Editable label — looks like a real input so it's obvious you can rename it -->
                            <label
                                class="group flex items-center gap-1.5 flex-1 min-w-0 px-2 py-1 -mx-2 -my-1 rounded-md border border-dashed border-gray-300 dark:border-gray-700 hover:border-indigo-400 dark:hover:border-indigo-500 hover:bg-indigo-50/40 dark:hover:bg-indigo-950/20 focus-within:border-indigo-500 focus-within:bg-white dark:focus-within:bg-gray-900 focus-within:border-solid transition-colors cursor-text"
                                :title="'Click to rename this group'"
                            >
                                <Pencil class="w-3 h-3 text-gray-400 group-hover:text-indigo-500 group-focus-within:text-indigo-500 shrink-0" />
                                <input
                                    v-model="block.label"
                                    type="text"
                                    class="bg-transparent text-sm font-medium text-gray-900 dark:text-white placeholder-gray-400 dark:placeholder-gray-500 focus:outline-none focus:ring-0 border-0 p-0 truncate min-w-0 flex-1"
                                    :placeholder="`Name this group (e.g. “${i === 0 ? 'My company domains' : 'Client X domains'}”)`"
                                    maxlength="60"
                                />
                            </label>

                            <span class="text-xs text-gray-400 dark:text-gray-500 shrink-0 hidden sm:inline">
                                {{ block.domains.length }} domain{{ block.domains.length === 1 ? '' : 's' }}
                            </span>
                        </div>

                        <div class="flex items-center gap-1 shrink-0">
                            <button
                                @click="removeBlock(i)"
                                type="button"
                                class="p-1.5 text-red-500 hover:text-red-600 hover:bg-red-50 dark:hover:bg-red-950/30 rounded-lg transition-colors"
                                :title="blocks.length === 1 ? 'Clear this group' : 'Remove this group'"
                            >
                                <Trash2 class="w-4 h-4" />
                            </button>
                            <button
                                @click="toggleBlock(i)"
                                type="button"
                                class="p-1.5 text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-800 rounded-lg transition-colors"
                                :title="block.open ? 'Collapse' : 'Expand'"
                            >
                                <component :is="block.open ? ChevronUp : ChevronDown" class="w-4 h-4" />
                            </button>
                        </div>
                    </div>

                    <!-- Body -->
                    <div v-show="block.open" class="px-5 py-4 space-y-5">

                        <!-- Domains chip input -->
                        <div>
                            <p class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-2 flex items-center gap-1.5">
                                <Globe class="w-3.5 h-3.5" />
                                Domains in this group
                            </p>
                            <div class="flex flex-wrap items-center gap-2 px-2.5 py-2 bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl focus-within:ring-2 focus-within:ring-indigo-500 focus-within:border-transparent">
                                <span
                                    v-for="(d, di) in block.domains"
                                    :key="di"
                                    class="inline-flex items-center gap-1 px-2 py-1 bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded-lg text-xs text-gray-800 dark:text-gray-100"
                                >
                                    {{ d }}
                                    <button
                                        type="button"
                                        @click="removeDomain(block, di)"
                                        class="ml-0.5 -mr-0.5 p-0.5 text-gray-400 hover:text-red-500 rounded"
                                        :title="`Remove ${d}`"
                                    >
                                        <X class="w-3 h-3" />
                                    </button>
                                </span>
                                <input
                                    v-model="block.domainInput"
                                    @keydown="onDomainKeydown(block, $event)"
                                    @paste="onDomainPaste(block)"
                                    @blur="commitDomains(block)"
                                    type="text"
                                    :placeholder="block.domains.length ? 'Add another…' : 'example.com, another.nl …'"
                                    class="flex-1 min-w-[180px] bg-transparent border-0 text-sm text-gray-900 dark:text-white placeholder-gray-400 focus:outline-none focus:ring-0 px-1 py-0.5"
                                />
                            </div>
                            <p class="mt-1.5 text-xs text-gray-400 flex items-center gap-1">
                                <Info class="w-3 h-3 shrink-0" />
                                Press Enter, comma, or space to add. Paste a list to add many at once.
                            </p>
                        </div>

                        <!-- Existing account -->
                        <div class="bg-indigo-50 dark:bg-indigo-950/30 border border-indigo-200 dark:border-indigo-800 rounded-xl p-3">
                            <label class="block text-xs font-semibold text-indigo-700 dark:text-indigo-400 mb-1">Existing account <span class="font-normal text-indigo-500 dark:text-indigo-500">(optional)</span></label>
                            <p class="text-xs text-indigo-500 dark:text-indigo-500 mb-2">Already a customer? Enter the contact name or company so we know which account the domains in this group should land under.</p>
                            <div class="relative">
                                <UserCircle class="absolute left-3 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-indigo-400" />
                                <input v-model="block.existingAccount" type="text" placeholder="e.g. John Doe or Example Company" class="w-full pl-8 pr-3 py-2 text-sm bg-white dark:bg-gray-900 border border-indigo-200 dark:border-indigo-700 rounded-xl text-gray-900 dark:text-white placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent" />
                            </div>
                        </div>

                        <div class="flex items-center gap-2 pt-1">
                            <div class="h-px flex-1 bg-gray-100 dark:bg-gray-800" />
                            <span class="text-xs text-gray-400 shrink-0">— or — new registrant</span>
                            <div class="h-px flex-1 bg-gray-100 dark:bg-gray-800" />
                        </div>

                        <!-- New registrant fields -->
                        <div class="space-y-3">
                            <div>
                                <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Company name <span class="text-gray-400">(optional)</span></label>
                                <div class="relative">
                                    <Building2 class="absolute left-3 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-gray-400" />
                                    <input v-model="block.companyName" type="text" placeholder="Example Company" class="w-full pl-8 pr-3 py-2 text-sm bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl text-gray-900 dark:text-white placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent" />
                                </div>
                            </div>

                            <div class="grid grid-cols-2 gap-2">
                                <div>
                                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">First name</label>
                                    <input v-model="block.firstName" type="text" placeholder="John" class="w-full px-3 py-2 text-sm bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl text-gray-900 dark:text-white placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent" />
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Last name</label>
                                    <input v-model="block.lastName" type="text" placeholder="Doe" class="w-full px-3 py-2 text-sm bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl text-gray-900 dark:text-white placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent" />
                                </div>
                            </div>

                            <div class="grid grid-cols-3 gap-2">
                                <div class="col-span-2">
                                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Street</label>
                                    <input v-model="block.street" type="text" placeholder="Kerkstraat" class="w-full px-3 py-2 text-sm bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl text-gray-900 dark:text-white placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent" />
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">House no.</label>
                                    <input v-model="block.houseNumber" type="text" placeholder="42A" class="w-full px-3 py-2 text-sm bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl text-gray-900 dark:text-white placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent" />
                                </div>
                            </div>

                            <div class="grid grid-cols-3 gap-2">
                                <div>
                                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Postal code</label>
                                    <input v-model="block.postalCode" type="text" placeholder="1234 AB" class="w-full px-3 py-2 text-sm bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl text-gray-900 dark:text-white placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent" />
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">City</label>
                                    <input v-model="block.city" type="text" placeholder="Amsterdam" class="w-full px-3 py-2 text-sm bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl text-gray-900 dark:text-white placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent" />
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Country</label>
                                    <input v-model="block.country" type="text" placeholder="Netherlands" class="w-full px-3 py-2 text-sm bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl text-gray-900 dark:text-white placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent" />
                                </div>
                            </div>

                            <div class="grid grid-cols-2 gap-2">
                                <div>
                                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Phone</label>
                                    <input v-model="block.phone" type="tel" placeholder="+31 6 12345678" class="w-full px-3 py-2 text-sm bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl text-gray-900 dark:text-white placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent" />
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Email</label>
                                    <input v-model="block.email" type="email" placeholder="john@example.com" class="w-full px-3 py-2 text-sm bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl text-gray-900 dark:text-white placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent" />
                                </div>
                            </div>

                            <div class="grid grid-cols-2 gap-2">
                                <div>
                                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">KVK <span class="text-gray-400">(optional)</span></label>
                                    <input v-model="block.kvk" type="text" placeholder="12345678" class="w-full px-3 py-2 text-sm bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl text-gray-900 dark:text-white placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent" />
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">VAT ID <span class="text-gray-400">(optional)</span></label>
                                    <input v-model="block.vatId" type="text" placeholder="NL123456789B01" class="w-full px-3 py-2 text-sm bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl text-gray-900 dark:text-white placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent" />
                                </div>
                            </div>

                            <div>
                                <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Auth / EPP code <span class="text-gray-400">(optional — same code applies to every domain in this group)</span></label>
                                <input v-model="block.authCode" type="text" placeholder="EPP code from current registrar" class="w-full px-3 py-2 text-sm bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl text-gray-900 dark:text-white placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent font-mono" />
                            </div>

                            <div>
                                <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Notes <span class="text-gray-400">(optional)</span></label>
                                <textarea v-model="block.notes" rows="2" placeholder="Anything specific to the domains in this group." class="w-full px-3 py-2 text-sm bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl text-gray-900 dark:text-white placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent resize-y"></textarea>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Add block -->
            <div class="mt-4 flex justify-center">
                <button
                    @click="addBlock"
                    type="button"
                    class="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium text-indigo-600 dark:text-indigo-400 bg-indigo-50 dark:bg-indigo-950/30 hover:bg-indigo-100 dark:hover:bg-indigo-950/50 border border-indigo-200 dark:border-indigo-800 rounded-xl transition-colors"
                    title="Add another group of domains with different owner details"
                >
                    <Plus class="w-4 h-4" />
                    Add domains for another owner
                </button>
            </div>

            <!-- Submit bar -->
            <div class="mt-8 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 bg-gradient-to-r from-indigo-50 to-purple-50 dark:from-indigo-950/30 dark:to-purple-950/30 border border-indigo-200 dark:border-indigo-900 rounded-2xl px-5 py-4">
                <div class="text-sm text-gray-700 dark:text-gray-300">
                    <span class="font-semibold">{{ totalDomains }}</span>
                    domain{{ totalDomains === 1 ? '' : 's' }} across
                    <span class="font-semibold">{{ blocks.length }}</span>
                    group{{ blocks.length === 1 ? '' : 's' }} ready to copy.
                </div>
                <div class="flex items-center gap-2">
                    <button
                        @click="showPreview = !showPreview"
                        type="button"
                        class="px-3 py-2 text-sm font-medium text-gray-600 dark:text-gray-300 hover:text-gray-900 dark:hover:text-white rounded-xl transition-colors"
                    >
                        {{ showPreview ? 'Hide preview' : 'Show preview' }}
                    </button>
                    <button
                        @click="copyAll"
                        type="button"
                        class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl text-sm font-semibold transition-all"
                        :class="copied ? 'bg-emerald-600 text-white' : 'bg-indigo-600 hover:bg-indigo-500 text-white'"
                    >
                        <Check v-if="copied" class="w-4 h-4" />
                        <Copy v-else class="w-4 h-4" />
                        {{ copied ? 'Copied!' : 'Copy to clipboard' }}
                    </button>
                </div>
            </div>

            <p v-if="copyError" class="mt-3 text-xs text-red-500">{{ copyError }}</p>

            <!-- Preview -->
            <div v-if="showPreview" class="mt-4 bg-gray-50 dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-2xl p-4">
                <p class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-2">Preview</p>
                <pre class="text-xs text-gray-700 dark:text-gray-300 whitespace-pre-wrap font-mono leading-relaxed">{{ previewText }}</pre>
            </div>

        </div>
    </AppLayout>
</template>
