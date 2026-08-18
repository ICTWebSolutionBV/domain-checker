<script setup>
import { ref, computed, watch, onUnmounted } from 'vue'
import { Head } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import { useDomainCheck } from '@/composables/useDomainCheck'
import { useBulkDomainCheck } from '@/composables/useBulkDomainCheck'
import BulkCheckInput from '@/Components/BulkCheckInput.vue'
import {
    Search, Globe, CheckCircle, XCircle, HelpCircle, Loader2,
    Copy, Check, ClipboardList, X, UserCircle, Building2
} from 'lucide-vue-next'

const props = defineProps({
    popularTlds: Array,
})

const { results, isDone, isChecking, checkedCount, totalCount, error, check, reset } = useDomainCheck()
const {
    results: bulkResults,
    isChecking: bulkIsChecking,
    checkedCount: bulkCheckedCount,
    totalCount: bulkTotalCount,
    error: bulkError,
    check: bulkCheck,
    reset: bulkReset,
} = useBulkDomainCheck()

const mode = ref('single') // 'single' | 'bulk'

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
const showModalHelp = ref(false)
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

// The TLD the user explicitly typed must always be checked, even when it is not
// part of the selected group — otherwise the one domain they actually asked
// about is the only one missing from the results (e.g. ".nu" is not popular).
// Checked first so the headline answer resolves before the rest of the list.
const tldsToCheck = computed(() =>
    pinnedTld.value && !currentTlds.value.includes(pinnedTld.value)
        ? [pinnedTld.value, ...currentTlds.value]
        : currentTlds.value
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

// The exact domain the user typed, surfaced as a headline above the grid so the
// answer to "is bergop.nu taken?" never has to be hunted for among 46 cards.
const pinnedEntry = computed(() => {
    if (!pinnedTld.value) return null
    const status = results[pinnedTld.value]
    if (!status) return null
    return { tld: pinnedTld.value, status, domain: `${searchedDomain.value}.${pinnedTld.value}` }
})

const pinnedSummary = computed(() => {
    switch (pinnedEntry.value?.status) {
        case 'available':
            return {
                icon: CheckCircle,
                wrapper: 'bg-emerald-50 dark:bg-emerald-950/30 border-emerald-400 dark:border-emerald-700',
                text: 'text-emerald-900 dark:text-emerald-100',
                badge: 'bg-emerald-600 text-white',
                note: 'Good news — this domain is free to register.',
                label: 'Available',
            }
        case 'taken':
            return {
                icon: XCircle,
                wrapper: 'bg-red-50 dark:bg-red-950/30 border-red-400 dark:border-red-700',
                text: 'text-red-900 dark:text-red-100',
                badge: 'bg-red-600 text-white',
                note: 'This domain is already registered — see the alternatives below.',
                label: 'Taken',
            }
        case 'checking':
            return {
                icon: Loader2,
                wrapper: 'bg-gray-50 dark:bg-gray-900 border-gray-200 dark:border-gray-700',
                text: 'text-gray-900 dark:text-white',
                badge: 'bg-gray-200 dark:bg-gray-800 text-gray-500 dark:text-gray-400',
                note: 'Checking this domain…',
                label: 'Checking…',
            }
        default:
            return {
                icon: HelpCircle,
                wrapper: 'bg-gray-50 dark:bg-gray-900 border-gray-200 dark:border-gray-700',
                text: 'text-gray-900 dark:text-white',
                badge: 'bg-gray-200 dark:bg-gray-800 text-gray-500 dark:text-gray-400',
                note: 'We could not determine the status of this domain.',
                label: 'Unknown',
            }
    }
})

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
    filterMode.value = 'all'
    check(domain, tldsToCheck.value)
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

function handleBulkCheck(domains) {
    selected.value = new Set()
    bulkCheck(domains)
}

function handleBulkReset() {
    bulkReset()
    selected.value = new Set()
}

function switchMode(newMode) {
    if (newMode === mode.value) return
    mode.value = newMode
    if (newMode === 'single') handleBulkReset()
    else handleReset()
}

const bulkResultEntries = computed(() =>
    Object.entries(bulkResults).map(([domain, status]) => ({ domain, status }))
)

const bulkAvailableEntries = computed(() => bulkResultEntries.value.filter(e => e.status === 'available'))

const bulkProgressPercent = computed(() => {
    if (bulkTotalCount.value === 0) return 0
    return Math.round((bulkCheckedCount.value / bulkTotalCount.value) * 100)
})

const allBulkAvailableSelected = computed(() =>
    bulkAvailableEntries.value.length > 0 &&
    bulkAvailableEntries.value.every(e => selected.value.has(e.domain))
)

function toggleSelectAllBulkAvailable() {
    if (allBulkAvailableSelected.value) {
        const next = new Set(selected.value)
        bulkAvailableEntries.value.forEach(e => next.delete(e.domain))
        selected.value = next
    } else {
        const next = new Set(selected.value)
        bulkAvailableEntries.value.forEach(e => next.add(e.domain))
        selected.value = next
    }
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
            <p class="text-gray-600 dark:text-gray-400 text-lg mb-6">
                Check availability across {{ popularTlds.length }}+ extensions instantly
            </p>

            <!-- Mode toggle -->
            <div class="ui-surface-light inline-flex items-center justify-center gap-1 mb-6 p-1 rounded-2xl">
                <button
                    @click="switchMode('single')"
                    class="px-4 py-2 rounded-xl text-sm font-medium transition-colors"
                    :class="mode === 'single'
                        ? 'bg-indigo-600 text-white shadow-sm shadow-indigo-600/20'
                        : 'text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-white hover:bg-gray-100 dark:hover:bg-gray-800'"
                >
                    Single domain
                </button>
                <button
                    @click="switchMode('bulk')"
                    class="px-4 py-2 rounded-xl text-sm font-medium transition-colors"
                    :class="mode === 'bulk'
                        ? 'bg-indigo-600 text-white shadow-sm shadow-indigo-600/20'
                        : 'text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-white hover:bg-gray-100 dark:hover:bg-gray-800'"
                >
                    Bulk check
                </button>
            </div>

            <!-- Rate limit / error banner (single mode) -->
            <Transition
                enter-active-class="transition-all duration-200 ease-out"
                enter-from-class="opacity-0 -translate-y-2"
                enter-to-class="opacity-100 translate-y-0"
                leave-active-class="transition-all duration-150 ease-in"
                leave-from-class="opacity-100 translate-y-0"
                leave-to-class="opacity-0 -translate-y-2"
            >
                <div v-if="mode === 'single' && error" class="max-w-2xl mx-auto mb-4">
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

            <!-- Rate limit / error banner (bulk mode) -->
            <Transition
                enter-active-class="transition-all duration-200 ease-out"
                enter-from-class="opacity-0 -translate-y-2"
                enter-to-class="opacity-100 translate-y-0"
                leave-active-class="transition-all duration-150 ease-in"
                leave-from-class="opacity-100 translate-y-0"
                leave-to-class="opacity-0 -translate-y-2"
            >
                <div v-if="mode === 'bulk' && bulkError" class="max-w-2xl mx-auto mb-4">
                    <div
                        class="flex items-center gap-3 px-4 py-3 rounded-2xl text-sm font-medium border"
                        :class="bulkError === 'rate_limited'
                            ? 'bg-amber-50 dark:bg-amber-950/40 border-amber-200 dark:border-amber-800 text-amber-800 dark:text-amber-300'
                            : 'bg-red-50 dark:bg-red-950/40 border-red-200 dark:border-red-800 text-red-700 dark:text-red-400'"
                    >
                        <component :is="bulkError === 'rate_limited' ? HelpCircle : XCircle" class="w-4 h-4 shrink-0" />
                        <span v-if="bulkError === 'rate_limited'">Too many requests — please wait a moment before checking again.</span>
                        <span v-else>Something went wrong. Please try again.</span>
                    </div>
                </div>
            </Transition>

            <!-- Single mode: search bar -->
            <template v-if="mode === 'single'">
                <div class="flex gap-2 max-w-2xl mx-auto">
                    <div class="flex-1 relative">
                        <Search class="absolute left-4 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" />
                        <input
                            v-model="domainInput"
                            @keydown="handleKeydown"
                            type="text"
                            placeholder="YourDomainName"
                            autocomplete="off"
                            spellcheck="false"
                            class="ui-input pl-10 pr-4 py-3.5 rounded-2xl text-base shadow-card dark:bg-gray-900"
                        />
                    </div>
                    <button
                        @click="handleCheck"
                        :disabled="isChecking || !domainInput.trim()"
                        class="ui-btn ui-btn-primary px-6 py-3.5 rounded-2xl"
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
                            ? 'bg-indigo-600 text-white shadow-sm shadow-indigo-600/20'
                            : 'text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-white hover:bg-gray-100 dark:hover:bg-gray-800'"
                    >
                        Popular ({{ popularTlds.length }})
                    </button>
                    <button
                        @click="loadAllTlds"
                        :disabled="loadingAllTlds"
                        class="px-3 py-1.5 rounded-lg text-xs font-medium transition-colors flex items-center gap-1.5"
                        :class="selectedGroup === 'all'
                            ? 'bg-indigo-600 text-white shadow-sm shadow-indigo-600/20'
                            : 'text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-white hover:bg-gray-100 dark:hover:bg-gray-800'"
                    >
                        <Loader2 v-if="loadingAllTlds" class="w-3 h-3 animate-spin" />
                        All extensions {{ allTldsData.length > 0 ? `(${allTldsData.length})` : '' }}
                    </button>
                    <button
                        v-if="hasResults"
                        @click="handleReset"
                        class="px-3 py-1.5 rounded-lg text-xs font-medium text-gray-500 hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors"
                    >
                        Clear
                    </button>
                </div>
            </template>

            <!-- Bulk mode: textarea input -->
            <BulkCheckInput
                v-else
                :is-checking="bulkIsChecking"
                @check="handleBulkCheck"
                @reset="handleBulkReset"
            />
        </div>

        <!-- Single-domain results -->
        <div v-if="hasResults && mode === 'single'" class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pb-32">

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

            <!-- Headline result for the exact domain the user typed -->
            <div
                v-if="pinnedEntry"
                class="flex flex-col sm:flex-row sm:items-center gap-4 px-5 py-4 mb-6 rounded-2xl border-2 shadow-sm"
                :class="pinnedSummary.wrapper"
            >
                <component
                    :is="pinnedSummary.icon"
                    class="w-8 h-8 shrink-0"
                    :class="[pinnedSummary.text, pinnedEntry.status === 'checking' ? 'animate-spin' : '']"
                />
                <div class="flex-1 min-w-0">
                    <div class="text-xl sm:text-2xl font-bold tracking-tight truncate" :class="pinnedSummary.text">
                        {{ pinnedEntry.domain }}
                    </div>
                    <p class="text-sm mt-0.5" :class="pinnedSummary.text">
                        {{ pinnedSummary.note }}
                    </p>
                </div>
                <div class="flex items-center gap-3 shrink-0">
                    <span
                        class="px-3 py-1.5 rounded-full text-sm font-semibold whitespace-nowrap"
                        :class="pinnedSummary.badge"
                    >
                        {{ pinnedSummary.label }}
                    </span>
                    <button
                        v-if="pinnedEntry.status === 'available'"
                        @click="toggleSelect(pinnedEntry.domain)"
                        class="px-3 py-1.5 rounded-xl text-sm font-medium transition-colors whitespace-nowrap"
                        :class="selected.has(pinnedEntry.domain)
                            ? 'bg-emerald-600 text-white hover:bg-emerald-700'
                            : 'border border-emerald-500 text-emerald-700 dark:text-emerald-300 hover:bg-emerald-100 dark:hover:bg-emerald-900/40'"
                    >
                        {{ selected.has(pinnedEntry.domain) ? 'Selected' : 'Select' }}
                    </button>
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
            <div class="ui-surface-light rounded-2xl p-2 sm:p-3 grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-x-4 gap-y-0">
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

        <!-- Bulk results -->
        <div v-if="mode === 'bulk' && bulkResultEntries.length > 0" class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pb-32">

            <!-- Progress bar -->
            <div v-if="bulkIsChecking" class="mb-5">
                <div class="flex justify-between text-xs text-gray-500 dark:text-gray-400 mb-1.5">
                    <span>Checking domains…</span>
                    <span>{{ bulkCheckedCount }} / {{ bulkTotalCount }}</span>
                </div>
                <div class="h-1 bg-gray-200 dark:bg-gray-800 rounded-full overflow-hidden">
                    <div class="h-full bg-indigo-500 rounded-full transition-all duration-300" :style="{ width: bulkProgressPercent + '%' }" />
                </div>
            </div>

            <!-- Toolbar -->
            <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
                <div class="text-xs text-gray-500 dark:text-gray-400">
                    {{ bulkResultEntries.length }} {{ bulkResultEntries.length === 1 ? 'domain' : 'domains' }} checked
                    <span v-if="bulkAvailableEntries.length" class="text-emerald-600 dark:text-emerald-400 font-medium">
                        · {{ bulkAvailableEntries.length }} available
                    </span>
                </div>
                <button
                    v-if="bulkAvailableEntries.length > 0"
                    @click="toggleSelectAllBulkAvailable"
                    class="text-xs font-medium text-indigo-600 dark:text-indigo-400 hover:text-indigo-700 dark:hover:text-indigo-300 transition-colors"
                >
                    {{ allBulkAvailableSelected ? 'Deselect all' : 'Select all available' }}
                </button>
            </div>

            <!-- 3-column list grid -->
            <div class="ui-surface-light rounded-2xl p-2 sm:p-3 grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-x-4 gap-y-0">
                <div
                    v-for="entry in bulkResultEntries"
                    :key="entry.domain"
                    class="flex items-center gap-3 py-2.5 px-3 rounded-xl border-b border-gray-100 dark:border-gray-800/60 transition-colors"
                    :class="[
                        statusConfig(entry.status).rowClass,
                        selected.has(entry.domain) ? 'bg-indigo-50 dark:bg-indigo-950/30 border-transparent' : '',
                        entry.status === 'available' ? 'cursor-pointer' : 'cursor-default',
                    ]"
                    @click="entry.status === 'available' ? toggleSelect(entry.domain) : null"
                >
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

                    <div class="flex-1 min-w-0">
                        <span class="text-sm font-medium text-gray-900 dark:text-gray-100 truncate block">
                            {{ entry.domain }}
                        </span>
                    </div>

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
        <div v-if="mode === 'single' && !hasResults" class="max-w-4xl mx-auto px-4 pb-16 text-center">
            <div class="grid grid-cols-3 sm:grid-cols-6 gap-2 opacity-30 select-none pointer-events-none mb-6">
                <div v-for="tld in popularTlds.slice(0, 12)" :key="tld" class="border border-gray-200 dark:border-gray-800 rounded-xl p-3 text-center">
                    <Globe class="w-4 h-4 mx-auto mb-1.5 text-gray-400" />
                    <div class="font-mono text-xs text-gray-500">.{{ tld }}</div>
                </div>
            </div>
            <p class="text-sm text-gray-500 dark:text-gray-500">Enter a domain name above to check availability</p>
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
                <!-- Hint line -->
                <p class="text-center text-xs font-medium text-gray-600 dark:text-gray-500 mb-2 hidden sm:block">
                    Fill in your registration details so we can process your order
                </p>
                <div class="max-w-2xl mx-auto bg-gray-900 dark:bg-gray-800 border border-gray-700 dark:border-gray-600 rounded-2xl shadow-overlay px-4 py-3 flex items-center gap-3">
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
                        class="ui-btn ui-btn-primary px-4 py-2 focus-visible:ring-offset-gray-900"
                        title="Fill in registration details and copy to clipboard"
                    >
                        <Copy class="w-4 h-4" />
                        Fill in details and request
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
                <div class="relative w-full max-w-2xl bg-surface rounded-2xl shadow-overlay border border-hairline dark:border-gray-700 overflow-hidden max-h-[90vh] flex flex-col">

                    <!-- Header -->
                    <div class="flex items-center justify-between gap-4 px-6 sm:px-8 py-4 border-b border-hairline shrink-0">
                        <div class="flex items-center gap-2.5">
                            <div class="w-8 h-8 bg-indigo-100 dark:bg-indigo-900/40 rounded-lg flex items-center justify-center">
                                <ClipboardList class="w-4 h-4 text-indigo-600 dark:text-indigo-400" />
                            </div>
                            <div>
                                <h2 class="text-sm font-semibold text-gray-900 dark:text-white">Fill in details and request</h2>
                                <p class="ui-help">Add your details so we can process your order</p>
                            </div>
                        </div>
                        <div class="flex items-center gap-1">
                            <button
                                @click="showModalHelp = !showModalHelp"
                                class="ui-btn ui-btn-sm px-3 py-1.5 font-semibold"
                                :class="showModalHelp ? 'ui-btn-help-on' : 'ui-btn-help'"
                                :aria-expanded="showModalHelp"
                            >
                                <HelpCircle class="w-4 h-4 shrink-0" />
                                <span class="hidden sm:inline">How does this work?</span>
                                <span class="sm:hidden">Help</span>
                                <span v-if="!showModalHelp" class="ui-attention-dot ring-surface" aria-hidden="true"></span>
                            </button>
                            <button @click="closeModal" class="ui-icon-btn" aria-label="Close">
                                <X class="w-4 h-4" />
                            </button>
                        </div>
                    </div>

                    <!-- Collapsible help panel -->
                    <Transition
                        enter-active-class="transition-all duration-200 ease-out overflow-hidden"
                        enter-from-class="max-h-0 opacity-0"
                        enter-to-class="max-h-96 opacity-100"
                        leave-active-class="transition-all duration-150 ease-in overflow-hidden"
                        leave-from-class="max-h-96 opacity-100"
                        leave-to-class="max-h-0 opacity-0"
                    >
                        <div v-if="showModalHelp" class="px-6 sm:px-8 py-4 bg-indigo-50 dark:bg-indigo-950/30 border-b border-indigo-100 dark:border-indigo-900">
                            <p class="ui-section-title text-indigo-700 dark:text-indigo-400 mb-3">How to order domains</p>
                            <ol class="space-y-2">
                                <li class="flex items-start gap-2.5 text-xs text-indigo-900/90 dark:text-indigo-300 leading-relaxed">
                                    <span class="inline-flex items-center justify-center w-4 h-4 rounded-full bg-indigo-200 dark:bg-indigo-800 text-indigo-800 dark:text-indigo-300 font-semibold shrink-0 mt-0.5">1</span>
                                    <span>Search for a domain name and check availability across extensions.</span>
                                </li>
                                <li class="flex items-start gap-2.5 text-xs text-indigo-900/90 dark:text-indigo-300 leading-relaxed">
                                    <span class="inline-flex items-center justify-center w-4 h-4 rounded-full bg-indigo-200 dark:bg-indigo-800 text-indigo-800 dark:text-indigo-300 font-semibold shrink-0 mt-0.5">2</span>
                                    <span>Tick the domains you want to order — they'll appear in the bar at the bottom.</span>
                                </li>
                                <li class="flex items-start gap-2.5 text-xs text-indigo-900/90 dark:text-indigo-300 leading-relaxed">
                                    <span class="inline-flex items-center justify-center w-4 h-4 rounded-full bg-indigo-200 dark:bg-indigo-800 text-indigo-800 dark:text-indigo-300 font-semibold shrink-0 mt-0.5">3</span>
                                    <span>Click <strong class="font-semibold">Fill in details and request</strong> to open this panel and fill in your registration details.</span>
                                </li>
                                <li class="flex items-start gap-2.5 text-xs text-indigo-900/90 dark:text-indigo-300 leading-relaxed">
                                    <span class="inline-flex items-center justify-center w-4 h-4 rounded-full bg-indigo-200 dark:bg-indigo-800 text-indigo-800 dark:text-indigo-300 font-semibold shrink-0 mt-0.5">4</span>
                                    <span>Click <strong class="font-semibold">Copy to clipboard</strong> at the bottom — your domain list and details are now on your clipboard.</span>
                                </li>
                                <li class="flex items-start gap-2.5 text-xs text-indigo-900/90 dark:text-indigo-300 leading-relaxed">
                                    <span class="inline-flex items-center justify-center w-4 h-4 rounded-full bg-indigo-200 dark:bg-indigo-800 text-indigo-800 dark:text-indigo-300 font-semibold shrink-0 mt-0.5">5</span>
                                    <span>Paste it into an email, WhatsApp, or chat message and send it to your provider to place the order.</span>
                                </li>
                            </ol>
                        </div>
                    </Transition>

                    <div class="overflow-y-auto flex-1">
                        <!-- Selected domains -->
                        <div class="px-6 sm:px-8 py-4 border-b border-hairline">
                            <p class="ui-section-title mb-2.5">Selected domains</p>
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
                        <div class="px-6 sm:px-8 py-6 space-y-6">
                            <p class="ui-section-title">Registration details <span class="normal-case font-normal">(required — added to clipboard)</span></p>

                            <!-- Existing account -->
                            <div class="ui-accent-panel p-4">
                                <label class="block ui-accent-title mb-0.5">Existing account <span class="font-normal text-indigo-600/70 dark:text-indigo-500">(optional)</span></label>
                                <p class="ui-accent-help mb-3">Already a customer? Enter your name or company so we link this to the right account.</p>
                                <div class="relative">
                                    <UserCircle class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-indigo-500 dark:text-indigo-400 pointer-events-none" />
                                    <input v-model="reg.existingAccount" type="text" placeholder="e.g. John Doe or Example Company" class="ui-input ui-input-accent pl-9" />
                                </div>
                            </div>

                            <!-- Personal & company -->
                            <div class="space-y-3">
                                <p class="ui-section-title">Contact details</p>
                                <div>
                                    <label class="ui-label text-sm">Company name <span class="ui-label-hint">(optional)</span></label>
                                    <div class="relative">
                                        <Building2 class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400 pointer-events-none" />
                                        <input v-model="reg.companyName" type="text" placeholder="Example Company" class="ui-input pl-9" />
                                    </div>
                                </div>
                                <div class="grid grid-cols-2 gap-3">
                                    <div>
                                        <label class="ui-label text-sm">First name</label>
                                        <input v-model="reg.firstName" type="text" placeholder="John" class="ui-input" />
                                    </div>
                                    <div>
                                        <label class="ui-label text-sm">Last name</label>
                                        <input v-model="reg.lastName" type="text" placeholder="Doe" class="ui-input" />
                                    </div>
                                </div>
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                    <div>
                                        <label class="ui-label text-sm">Phone number</label>
                                        <input v-model="reg.phone" type="tel" placeholder="+31 6 12345678" class="ui-input" />
                                    </div>
                                    <div>
                                        <label class="ui-label text-sm">Email</label>
                                        <input v-model="reg.email" type="email" placeholder="john@example.com" class="ui-input" />
                                    </div>
                                </div>
                            </div>

                            <!-- Address -->
                            <div class="space-y-3">
                                <p class="ui-section-title">Address</p>
                                <div class="grid grid-cols-3 gap-3">
                                    <div class="col-span-2">
                                        <label class="ui-label text-sm">Street</label>
                                        <input v-model="reg.street" type="text" placeholder="Kerkstraat" class="ui-input" />
                                    </div>
                                    <div>
                                        <label class="ui-label text-sm">House no.</label>
                                        <input v-model="reg.houseNumber" type="text" placeholder="42A" class="ui-input" />
                                    </div>
                                </div>
                                <div class="grid grid-cols-2 gap-3">
                                    <div>
                                        <label class="ui-label text-sm">Postal code</label>
                                        <input v-model="reg.postalCode" type="text" placeholder="1234 AB" class="ui-input" />
                                    </div>
                                    <div>
                                        <label class="ui-label text-sm">City</label>
                                        <input v-model="reg.city" type="text" placeholder="Amsterdam" class="ui-input" />
                                    </div>
                                </div>
                            </div>

                            <!-- Business IDs -->
                            <div class="space-y-3">
                                <p class="ui-section-title">Business <span class="normal-case font-normal">(optional)</span></p>
                                <div class="grid grid-cols-2 gap-3">
                                    <div>
                                        <label class="ui-label text-sm">KVK</label>
                                        <input v-model="reg.kvk" type="text" placeholder="12345678" class="ui-input" />
                                    </div>
                                    <div>
                                        <label class="ui-label text-sm">VAT ID</label>
                                        <input v-model="reg.vatId" type="text" placeholder="NL123456789B01" class="ui-input" />
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Footer -->
                    <div class="px-6 sm:px-8 py-4 border-t border-hairline bg-gray-50/80 dark:bg-gray-900 shrink-0 flex items-center justify-between gap-3">
                        <p class="ui-help">Fields left empty are omitted from the clipboard.</p>
                        <button
                            @click="copyToClipboard"
                            class="ui-btn shrink-0"
                            :class="copied ? 'bg-emerald-600 text-white hover:bg-emerald-700' : 'ui-btn-primary'"
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
