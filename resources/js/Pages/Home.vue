<script setup>
import { ref, computed, watch, onUnmounted } from 'vue'
import { Head } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import { useDomainCheck } from '@/composables/useDomainCheck'
import {
    Search, Globe, CheckCircle, XCircle, HelpCircle, Loader2,
    Copy, Check, ClipboardList, X, UserCircle, Building2
} from 'lucide-vue-next'

const props = defineProps({
    popularTlds: Array,
})

const { results, isDone, isChecking, checkedCount, totalCount, error, check, reset } = useDomainCheck()

const domainInput = ref('')
const searchedDomain = ref('')
const pinnedTld = ref('') // TLD the user explicitly typed (e.g. "nl" from "example.nl")
const allTldsData = ref([])
const loadingAllTlds = ref(false)
const selectedGroup = ref('popular')
const selected = ref(new Set())
const copied = ref(false)
const filterMode = ref('all') // 'all' | 'available' | 'taken'

// Registration modal
const showModal = ref(false)
const reg = ref({
    existingAccount: '',
    companyName: '',
    firstName: '',
    lastName: '',
    street: '',
    houseNumber: '',
    postalCode: '',
    city: '',
    phone: '',
    email: '',
    kvk: '',
    vatId: '',
})

const currentTlds = computed(() =>
    selectedGroup.value === 'popular' ? props.popularTlds : allTldsData.value
)

// Auto-select the pinned TLD as soon as it comes back "available"
watch(results, (val) => {
    if (pinnedTld.value && val[pinnedTld.value] === 'available') {
        const domain = `${searchedDomain.value}.${pinnedTld.value}`
        if (!selected.value.has(domain)) {
            const next = new Set(selected.value)
            next.add(domain)
            selected.value = next
        }
    }
}, { deep: true })

// Clear error when user starts typing again
watch(domainInput, () => { if (error.value) error.value = null })

// Auto-check when user types a full domain like "example.nl"
let autoCheckTimer = null
watch(domainInput, (val) => {
    clearTimeout(autoCheckTimer)
    const trimmed = val.trim().toLowerCase()
        .replace(/^https?:\/\//i, '')
        .replace(/^www\./i, '')
    // Only auto-trigger if input looks like name.tld (dot with ≥2 chars on each side)
    if (/^[a-z0-9-]+\.[a-z]{2,}$/.test(trimmed)) {
        autoCheckTimer = setTimeout(() => handleCheck(), 400)
    }
})
onUnmounted(() => clearTimeout(autoCheckTimer))

const hasResults = computed(() => Object.keys(results).length > 0)

const resultEntries = computed(() =>
    Object.entries(results).map(([tld, status]) => ({
        tld,
        status,
        domain: `${searchedDomain.value}.${tld}`,
    }))
)

// Sort so the pinned TLD (explicitly typed) always appears first
const sortedEntries = computed(() => {
    if (!pinnedTld.value) return resultEntries.value
    const pinned = resultEntries.value.find(e => e.tld === pinnedTld.value)
    const rest = resultEntries.value.filter(e => e.tld !== pinnedTld.value)
    return pinned ? [pinned, ...rest] : resultEntries.value
})

const filteredEntries = computed(() => {
    if (filterMode.value === 'available') return sortedEntries.value.filter(e => e.status === 'available')
    if (filterMode.value === 'taken') return sortedEntries.value.filter(e => e.status === 'taken')
    return sortedEntries.value
})

const availableEntries = computed(() => resultEntries.value.filter(e => e.status === 'available'))

const statusCounts = computed(() => {
    const counts = { available: 0, taken: 0, unknown: 0, checking: 0 }
    Object.values(results).forEach(s => { counts[s] = (counts[s] || 0) + 1 })
    return counts
})

const progressPercent = computed(() => {
    if (totalCount.value === 0) return 0
    return Math.round((checkedCount.value / totalCount.value) * 100)
})

const selectedList = computed(() => Array.from(selected.value))

const allAvailableSelected = computed(() =>
    availableEntries.value.length > 0 &&
    availableEntries.value.every(e => selected.value.has(e.domain))
)

async function loadAllTlds() {
    if (allTldsData.value.length > 0) {
        selectedGroup.value = 'all'
        return
    }
    loadingAllTlds.value = true
    try {
        const res = await fetch(route('tlds.index'))
        const data = await res.json()
        allTldsData.value = data.all
        selectedGroup.value = 'all'
    } finally {
        loadingAllTlds.value = false
    }
}

function handleCheck() {
    let domain = domainInput.value.trim().toLowerCase().replace(/\s+/g, '')
    if (!domain) return
    // Strip protocol/www prefix (e.g. https://www.example.com → example.com)
    domain = domain.replace(/^https?:\/\//i, '').replace(/^www\./i, '')
    // If the user typed a full domain like "example.nl", extract & pin the TLD then strip it
    if (domain.includes('.')) {
        const parts = domain.split('.')
        pinnedTld.value = parts[parts.length - 1]
        domain = parts.slice(0, -1).join('.')
    } else {
        pinnedTld.value = ''
    }
    searchedDomain.value = domain
    selected.value = new Set()
    filterMode.value = 'all'
    check(domain, currentTlds.value)
}

function handleKeydown(e) {
    if (e.key === 'Enter') handleCheck()
}

function handleReset() {
    reset()
    selected.value = new Set()
    searchedDomain.value = ''
    pinnedTld.value = ''
    filterMode.value = 'all'
}

function toggleSelect(domain) {
    const next = new Set(selected.value)
    if (next.has(domain)) next.delete(domain)
    else next.add(domain)
    selected.value = next
}

function toggleSelectAllAvailable() {
    if (allAvailableSelected.value) {
        const next = new Set(selected.value)
        availableEntries.value.forEach(e => next.delete(e.domain))
        selected.value = next
    } else {
        const next = new Set(selected.value)
        availableEntries.value.forEach(e => next.add(e.domain))
        selected.value = next
    }
}

function clearSelection() {
    selected.value = new Set()
}

function openModal() {
    showModal.value = true
}

function closeModal() {
    showModal.value = false
    copied.value = false
}

async function copyToClipboard() {
    const r = reg.value
    const lines = []

    lines.push('── Selected domains ──')
    selectedList.value.forEach(d => lines.push(d))

    const hasDetails = Object.values(r).some(v => v.trim())
    if (hasDetails) {
        lines.push('')
        lines.push('── Registration details ──')
        if (r.existingAccount) lines.push(`Account:     ${r.existingAccount}`)
        if (r.companyName)  lines.push(`Company:     ${r.companyName}`)
        if (r.firstName || r.lastName) lines.push(`Name:        ${[r.firstName, r.lastName].filter(Boolean).join(' ')}`)
        if (r.street || r.houseNumber) lines.push(`Address:     ${[r.street, r.houseNumber].filter(Boolean).join(' ')}`)
        if (r.postalCode)   lines.push(`Postal code: ${r.postalCode}`)
        if (r.city)         lines.push(`City:        ${r.city}`)
        if (r.phone)        lines.push(`Phone:       ${r.phone}`)
        if (r.email)        lines.push(`Email:       ${r.email}`)
        if (r.kvk)          lines.push(`KVK:         ${r.kvk}`)
        if (r.vatId)        lines.push(`VAT ID:      ${r.vatId}`)
    }

    await navigator.clipboard.writeText(lines.join('\n'))
    copied.value = true
    setTimeout(() => { copied.value = false }, 2000)
}

function statusConfig(status) {
    switch (status) {
        case 'available':
            return {
                icon: CheckCircle,
                badgeClass: 'bg-emerald-100 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-400 border border-emerald-200 dark:border-emerald-800',
                rowClass: 'hover:bg-emerald-50 dark:hover:bg-emerald-950/20',
                label: 'Available',
            }
        case 'taken':
            return {
                icon: XCircle,
                badgeClass: 'bg-red-100 dark:bg-red-900/40 text-red-600 dark:text-red-400 border border-red-200 dark:border-red-800',
                rowClass: 'hover:bg-red-50/50 dark:hover:bg-red-950/10',
                label: 'Taken',
            }
        case 'checking':
            return {
                icon: Loader2,
                badgeClass: 'bg-gray-100 dark:bg-gray-800 text-gray-400 border border-gray-200 dark:border-gray-700',
                rowClass: '',
                label: 'Checking…',
            }
        default:
            return {
                icon: HelpCircle,
                badgeClass: 'bg-gray-100 dark:bg-gray-800 text-gray-400 border border-gray-200 dark:border-gray-700',
                rowClass: '',
                label: 'Unknown',
            }
    }
}
</script>

<template>
    <AppLayout>
        <Head title="Domain Checker" />

        <!-- Hero -->
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 pt-16 pb-10 text-center">
            <h1 class="text-4xl sm:text-5xl font-bold text-gray-900 dark:text-white mb-4 tracking-tight">
                Find your perfect<br>
                <span class="text-indigo-600 dark:text-indigo-400">domain name</span>
            </h1>
            <p class="text-gray-500 dark:text-gray-400 text-lg mb-10">
                Check availability across {{ popularTlds.length }}+ extensions instantly
            </p>

            <!-- Rate limit / error banner -->
            <Transition
                enter-active-class="transition-all duration-200 ease-out"
                enter-from-class="opacity-0 -translate-y-2"
                enter-to-class="opacity-100 translate-y-0"
                leave-active-class="transition-all duration-150 ease-in"
                leave-from-class="opacity-100 translate-y-0"
                leave-to-class="opacity-0 -translate-y-2"
            >
                <div v-if="error" class="max-w-2xl mx-auto mb-4">
                    <div
                        class="flex items-center gap-3 px-4 py-3 rounded-2xl text-sm font-medium border"
                        :class="error === 'rate_limited'
                            ? 'bg-amber-50 dark:bg-amber-950/40 border-amber-200 dark:border-amber-800 text-amber-800 dark:text-amber-300'
                            : 'bg-red-50 dark:bg-red-950/40 border-red-200 dark:border-red-800 text-red-700 dark:text-red-400'"
                    >
                        <component :is="error === 'rate_limited' ? HelpCircle : XCircle" class="w-4 h-4 shrink-0" />
                        <span v-if="error === 'rate_limited'">Too many requests — please wait a moment before checking again.</span>
                        <span v-else>Something went wrong. Please try again.</span>
                    </div>
                </div>
            </Transition>

            <!-- Search bar -->
            <div class="flex gap-2 max-w-2xl mx-auto">
                <div class="flex-1 relative">
                    <Search class="absolute left-4 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" />
                    <input
                        v-model="domainInput"
                        @keydown="handleKeydown"
                        type="text"
                        placeholder="yourname"
                        autocomplete="off"
                        spellcheck="false"
                        class="w-full pl-10 pr-4 py-3.5 bg-white dark:bg-gray-900 border border-gray-300 dark:border-gray-700 rounded-2xl text-base text-gray-900 dark:text-white placeholder-gray-400 outline-none transition focus:ring-2 focus:ring-indigo-500 focus:border-transparent shadow-sm"
                    />
                </div>
                <button
                    @click="handleCheck"
                    :disabled="isChecking || !domainInput.trim()"
                    class="px-6 py-3.5 bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 disabled:cursor-not-allowed text-white font-semibold rounded-2xl transition-colors text-sm shadow-sm shadow-indigo-600/20 flex items-center gap-2 whitespace-nowrap"
                >
                    <Loader2 v-if="isChecking" class="w-4 h-4 animate-spin" />
                    <Search v-else class="w-4 h-4" />
                    {{ isChecking ? 'Checking…' : 'Check' }}
                </button>
            </div>

            <!-- TLD group selector -->
            <div class="flex items-center justify-center gap-3 mt-4">
                <button
                    @click="selectedGroup = 'popular'"
                    class="px-3 py-1.5 rounded-lg text-xs font-medium transition-colors"
                    :class="selectedGroup === 'popular'
                        ? 'bg-indigo-600 text-white'
                        : 'text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800'"
                >
                    Popular ({{ popularTlds.length }})
                </button>
                <button
                    @click="loadAllTlds"
                    :disabled="loadingAllTlds"
                    class="px-3 py-1.5 rounded-lg text-xs font-medium transition-colors flex items-center gap-1.5"
                    :class="selectedGroup === 'all'
                        ? 'bg-indigo-600 text-white'
                        : 'text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800'"
                >
                    <Loader2 v-if="loadingAllTlds" class="w-3 h-3 animate-spin" />
                    All extensions {{ allTldsData.length > 0 ? `(${allTldsData.length})` : '' }}
                </button>
                <button
                    v-if="hasResults"
                    @click="handleReset"
                    class="px-3 py-1.5 rounded-lg text-xs font-medium text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors"
                >
                    Clear
                </button>
            </div>
        </div>

        <!-- Results -->
        <div v-if="hasResults" class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pb-32">

            <!-- Progress bar -->
            <div v-if="isChecking" class="mb-5">
                <div class="flex justify-between text-xs text-gray-500 dark:text-gray-400 mb-1.5">
                    <span>Checking <span class="font-medium text-gray-700 dark:text-gray-300">{{ searchedDomain }}</span>…</span>
                    <span>{{ checkedCount }} / {{ totalCount }}</span>
                </div>
                <div class="h-1 bg-gray-200 dark:bg-gray-800 rounded-full overflow-hidden">
                    <div class="h-full bg-indigo-500 rounded-full transition-all duration-300" :style="{ width: progressPercent + '%' }" />
                </div>
            </div>

            <!-- Toolbar -->
            <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
                <!-- Stats -->
                <div class="flex flex-wrap items-center gap-3 text-xs">
                    <button
                        @click="filterMode = 'all'"
                        class="flex items-center gap-1.5 px-2.5 py-1 rounded-lg transition-colors font-medium"
                        :class="filterMode === 'all' ? 'bg-gray-900 dark:bg-white text-white dark:text-gray-900' : 'text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800'"
                    >
                        All ({{ Object.keys(results).length }})
                    </button>
                    <button
                        v-if="statusCounts.available"
                        @click="filterMode = 'available'"
                        class="flex items-center gap-1.5 px-2.5 py-1 rounded-lg transition-colors font-medium"
                        :class="filterMode === 'available' ? 'bg-emerald-600 text-white' : 'text-emerald-600 dark:text-emerald-400 hover:bg-emerald-50 dark:hover:bg-emerald-950/30'"
                    >
                        <CheckCircle class="w-3.5 h-3.5" />
                        {{ statusCounts.available }} available
                    </button>
                    <button
                        v-if="statusCounts.taken"
                        @click="filterMode = 'taken'"
                        class="flex items-center gap-1.5 px-2.5 py-1 rounded-lg transition-colors font-medium"
                        :class="filterMode === 'taken' ? 'bg-red-500 text-white' : 'text-red-500 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-950/30'"
                    >
                        <XCircle class="w-3.5 h-3.5" />
                        {{ statusCounts.taken }} taken
                    </button>
                    <span v-if="statusCounts.unknown" class="flex items-center gap-1.5 text-gray-400 px-2.5 py-1">
                        <HelpCircle class="w-3.5 h-3.5" />
                        {{ statusCounts.unknown }} unknown
                    </span>
                </div>

                <!-- Select all available -->
                <button
                    v-if="availableEntries.length > 0"
                    @click="toggleSelectAllAvailable"
                    class="text-xs font-medium text-indigo-600 dark:text-indigo-400 hover:text-indigo-700 dark:hover:text-indigo-300 transition-colors"
                >
                    {{ allAvailableSelected ? 'Deselect all' : 'Select all available' }}
                </button>
            </div>

            <!-- 3-column list grid -->
            <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-x-4 gap-y-0">
                <div
                    v-for="(entry, index) in filteredEntries"
                    :key="entry.tld"
                    class="flex items-center gap-3 py-2.5 px-3 rounded-xl border-b border-gray-100 dark:border-gray-800/60 transition-colors"
                    :class="[
                        statusConfig(entry.status).rowClass,
                        selected.has(entry.domain) ? 'bg-indigo-50 dark:bg-indigo-950/30 border-transparent' : '',
                        entry.status === 'available' ? 'cursor-pointer' : 'cursor-default',
                        entry.tld === pinnedTld && pinnedTld ? 'ring-1 ring-indigo-300 dark:ring-indigo-700 bg-indigo-50/60 dark:bg-indigo-950/20 rounded-xl mb-2' : '',
                    ]"
                    @click="entry.status === 'available' ? toggleSelect(entry.domain) : null"
                >
                    <!-- Checkbox (only for available) -->
                    <div class="shrink-0 w-5 h-5">
                        <div
                            v-if="entry.status === 'available'"
                            class="w-5 h-5 rounded border-2 flex items-center justify-center transition-all"
                            :class="selected.has(entry.domain)
                                ? 'bg-indigo-600 border-indigo-600'
                                : 'border-gray-300 dark:border-gray-600 hover:border-indigo-400'"
                        >
                            <Check v-if="selected.has(entry.domain)" class="w-3 h-3 text-white" />
                        </div>
                        <div v-else class="w-5 h-5" />
                    </div>

                    <!-- Domain name -->
                    <div class="flex-1 min-w-0">
                        <span class="text-sm font-medium text-gray-900 dark:text-gray-100 truncate block">
                            {{ searchedDomain }}<span class="text-indigo-600 dark:text-indigo-400 font-semibold">.{{ entry.tld }}</span>
                        </span>
                    </div>

                    <!-- Status badge -->
                    <div class="shrink-0">
                        <span
                            v-if="entry.status !== 'checking'"
                            class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium"
                            :class="statusConfig(entry.status).badgeClass"
                        >
                            <component :is="statusConfig(entry.status).icon" class="w-3 h-3" />
                            {{ statusConfig(entry.status).label }}
                        </span>
                        <Loader2 v-else class="w-4 h-4 animate-spin text-gray-400" />
                    </div>
                </div>
            </div>
        </div>

        <!-- Empty state -->
        <div v-else class="max-w-4xl mx-auto px-4 pb-16 text-center">
            <div class="grid grid-cols-3 sm:grid-cols-6 gap-2 opacity-30 select-none pointer-events-none mb-6">
                <div v-for="tld in popularTlds.slice(0, 12)" :key="tld" class="border border-gray-200 dark:border-gray-800 rounded-xl p-3 text-center">
                    <Globe class="w-4 h-4 mx-auto mb-1.5 text-gray-400" />
                    <div class="font-mono text-xs text-gray-500">.{{ tld }}</div>
                </div>
            </div>
            <p class="text-sm text-gray-400 dark:text-gray-500">Enter a domain name above to check availability</p>
        </div>

        <!-- Sticky clipboard bar -->
        <Transition
            enter-active-class="transition-all duration-300 ease-out"
            enter-from-class="translate-y-full opacity-0"
            enter-to-class="translate-y-0 opacity-100"
            leave-active-class="transition-all duration-200 ease-in"
            leave-from-class="translate-y-0 opacity-100"
            leave-to-class="translate-y-full opacity-0"
        >
            <div v-if="selectedList.length > 0" class="fixed bottom-0 inset-x-0 z-50 p-4">
                <div class="max-w-2xl mx-auto bg-gray-900 dark:bg-gray-800 border border-gray-700 dark:border-gray-600 rounded-2xl shadow-2xl shadow-black/30 px-4 py-3 flex items-center gap-3">
                    <div class="flex items-center gap-2 flex-1 min-w-0">
                        <ClipboardList class="w-4 h-4 text-indigo-400 shrink-0" />
                        <span class="text-sm font-medium text-white">
                            {{ selectedList.length }} {{ selectedList.length === 1 ? 'domain' : 'domains' }} selected
                        </span>
                        <span class="text-xs text-gray-400 truncate hidden sm:block">
                            — {{ selectedList.slice(0, 3).join(', ') }}{{ selectedList.length > 3 ? ` +${selectedList.length - 3} more` : '' }}
                        </span>
                    </div>
                    <button @click="clearSelection" class="p-1.5 text-gray-400 hover:text-white hover:bg-gray-700 rounded-lg transition-colors" title="Clear selection">
                        <X class="w-4 h-4" />
                    </button>
                    <button
                        @click="openModal"
                        class="flex items-center gap-2 px-4 py-2 rounded-xl text-sm font-semibold bg-indigo-600 hover:bg-indigo-500 text-white transition-colors"
                    >
                        <Copy class="w-4 h-4" />
                        Copy to clipboard
                    </button>
                </div>
            </div>
        </Transition>

        <!-- Registration details modal -->
        <Transition
            enter-active-class="transition-all duration-200 ease-out"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="transition-all duration-150 ease-in"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div v-if="showModal" class="fixed inset-0 z-50 flex items-end sm:items-center justify-center p-4" @click.self="closeModal">
                <!-- Backdrop -->
                <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" @click="closeModal" />

                <!-- Modal panel -->
                <div class="relative w-full max-w-lg bg-white dark:bg-gray-900 rounded-2xl shadow-2xl border border-gray-200 dark:border-gray-700 overflow-hidden max-h-[90vh] flex flex-col">

                    <!-- Header -->
                    <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100 dark:border-gray-800 shrink-0">
                        <div class="flex items-center gap-2.5">
                            <div class="w-8 h-8 bg-indigo-100 dark:bg-indigo-900/40 rounded-lg flex items-center justify-center">
                                <ClipboardList class="w-4 h-4 text-indigo-600 dark:text-indigo-400" />
                            </div>
                            <div>
                                <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Copy to clipboard</h2>
                                <p class="text-xs text-gray-500 dark:text-gray-400">Optionally add registration details</p>
                            </div>
                        </div>
                        <button @click="closeModal" class="p-1.5 text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-800 rounded-lg transition-colors">
                            <X class="w-4 h-4" />
                        </button>
                    </div>

                    <div class="overflow-y-auto flex-1">
                        <!-- Selected domains -->
                        <div class="px-6 py-4 border-b border-gray-100 dark:border-gray-800">
                            <p class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-2">Selected domains</p>
                            <div class="flex flex-wrap gap-2">
                                <span
                                    v-for="domain in selectedList"
                                    :key="domain"
                                    class="inline-flex items-center gap-1.5 px-2.5 py-1 bg-emerald-50 dark:bg-emerald-950/40 text-emerald-700 dark:text-emerald-400 border border-emerald-200 dark:border-emerald-800 rounded-lg text-xs font-medium"
                                >
                                    <CheckCircle class="w-3 h-3" />
                                    {{ domain }}
                                </span>
                            </div>
                        </div>

                        <!-- Registration details form -->
                        <div class="px-6 py-4">
                            <p class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-4">Registration details <span class="normal-case font-normal text-gray-400">(optional — added to clipboard)</span></p>

                            <div class="space-y-3">
                                <!-- Existing account -->
                                <div class="bg-indigo-50 dark:bg-indigo-950/30 border border-indigo-200 dark:border-indigo-800 rounded-xl p-3">
                                    <label class="block text-xs font-semibold text-indigo-700 dark:text-indigo-400 mb-1">Existing account <span class="font-normal text-indigo-500 dark:text-indigo-500">(optional)</span></label>
                                    <p class="text-xs text-indigo-500 dark:text-indigo-500 mb-2">Already a customer? Enter the contact name or company so we know which account to register under.</p>
                                    <div class="relative">
                                        <UserCircle class="absolute left-3 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-indigo-400" />
                                        <input v-model="reg.existingAccount" type="text" placeholder="e.g. John Doe or Example Company" class="w-full pl-8 pr-3 py-2 text-sm bg-white dark:bg-gray-900 border border-indigo-200 dark:border-indigo-700 rounded-xl text-gray-900 dark:text-white placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent" />
                                    </div>
                                </div>

                                <!-- Divider -->
                                <div class="flex items-center gap-2 pt-1">
                                    <div class="h-px flex-1 bg-gray-100 dark:bg-gray-800" />
                                    <span class="text-xs text-gray-400 shrink-0">New registrant details</span>
                                    <div class="h-px flex-1 bg-gray-100 dark:bg-gray-800" />
                                </div>

                                <!-- Company -->
                                <div>
                                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Company name <span class="text-gray-400">(optional)</span></label>
                                    <div class="relative">
                                        <Building2 class="absolute left-3 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-gray-400" />
                                        <input v-model="reg.companyName" type="text" placeholder="Example Company" class="w-full pl-8 pr-3 py-2 text-sm bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl text-gray-900 dark:text-white placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent" />
                                    </div>
                                </div>

                                <!-- Name -->
                                <div class="grid grid-cols-2 gap-2">
                                    <div>
                                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">First name</label>
                                        <input v-model="reg.firstName" type="text" placeholder="John" class="w-full px-3 py-2 text-sm bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl text-gray-900 dark:text-white placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent" />
                                    </div>
                                    <div>
                                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Last name</label>
                                        <input v-model="reg.lastName" type="text" placeholder="Doe" class="w-full px-3 py-2 text-sm bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl text-gray-900 dark:text-white placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent" />
                                    </div>
                                </div>

                                <!-- Address -->
                                <div class="grid grid-cols-3 gap-2">
                                    <div class="col-span-2">
                                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Street</label>
                                        <input v-model="reg.street" type="text" placeholder="Kerkstraat" class="w-full px-3 py-2 text-sm bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl text-gray-900 dark:text-white placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent" />
                                    </div>
                                    <div>
                                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">House no.</label>
                                        <input v-model="reg.houseNumber" type="text" placeholder="42A" class="w-full px-3 py-2 text-sm bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl text-gray-900 dark:text-white placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent" />
                                    </div>
                                </div>
                                <div class="grid grid-cols-2 gap-2">
                                    <div>
                                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Postal code</label>
                                        <input v-model="reg.postalCode" type="text" placeholder="1234 AB" class="w-full px-3 py-2 text-sm bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl text-gray-900 dark:text-white placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent" />
                                    </div>
                                    <div>
                                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">City</label>
                                        <input v-model="reg.city" type="text" placeholder="Amsterdam" class="w-full px-3 py-2 text-sm bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl text-gray-900 dark:text-white placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent" />
                                    </div>
                                </div>

                                <!-- Contact -->
                                <div>
                                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Phone number</label>
                                    <input v-model="reg.phone" type="tel" placeholder="+31 6 12345678" class="w-full px-3 py-2 text-sm bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl text-gray-900 dark:text-white placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent" />
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">Email</label>
                                    <input v-model="reg.email" type="email" placeholder="john@example.com" class="w-full px-3 py-2 text-sm bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl text-gray-900 dark:text-white placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent" />
                                </div>

                                <!-- Optional business -->
                                <div class="grid grid-cols-2 gap-2">
                                    <div>
                                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">KVK <span class="text-gray-400">(optional)</span></label>
                                        <input v-model="reg.kvk" type="text" placeholder="12345678" class="w-full px-3 py-2 text-sm bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl text-gray-900 dark:text-white placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent" />
                                    </div>
                                    <div>
                                        <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">VAT ID <span class="text-gray-400">(optional)</span></label>
                                        <input v-model="reg.vatId" type="text" placeholder="NL123456789B01" class="w-full px-3 py-2 text-sm bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl text-gray-900 dark:text-white placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent" />
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Footer -->
                    <div class="px-6 py-4 border-t border-gray-100 dark:border-gray-800 shrink-0 flex items-center justify-between gap-3">
                        <p class="text-xs text-gray-400">Fields left empty are omitted from the clipboard.</p>
                        <button
                            @click="copyToClipboard"
                            class="flex items-center gap-2 px-5 py-2.5 rounded-xl text-sm font-semibold transition-all shrink-0"
                            :class="copied ? 'bg-emerald-600 text-white' : 'bg-indigo-600 hover:bg-indigo-500 text-white'"
                        >
                            <Check v-if="copied" class="w-4 h-4" />
                            <Copy v-else class="w-4 h-4" />
                            {{ copied ? 'Copied!' : 'Copy to clipboard' }}
                        </button>
                    </div>
                </div>
            </div>
        </Transition>
    </AppLayout>
</template>
