<script setup>
import { ref, computed, watch } from 'vue'
import { Head } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import { Search, Loader2, Copy, Check, X, AlertTriangle, Globe2, Eye, EyeOff } from 'lucide-vue-next'

const DNS_TYPES = ['MX', 'NS', 'TXT', 'A', 'AAAA', 'CNAME']

const textarea  = ref('')
const selectedType = ref('MX')
const loading   = ref(false)
const error     = ref('')
const results   = ref([])
const copied    = ref(false)
const showGeo   = ref(true)

const hasResults = computed(() => results.value.length > 0)

const parsedDomains = computed(() => {
    const lines = textarea.value
        .split(/[\n,]+/)
        .map(s => s.trim().toLowerCase())
        .map(s => s.replace(/^https?:\/\//i, '').replace(/^www\./i, '').replace(/\/.*$/, ''))
        .filter(Boolean)
    return [...new Set(lines)]
})

watch(selectedType, () => { if (hasResults.value) runLookup() })

async function runLookup() {
    const domains = parsedDomains.value
    if (!domains.length || loading.value) return

    loading.value = true
    error.value   = ''
    results.value = []

    try {
        const token = document.querySelector('meta[name="csrf-token"]')?.content
        const res = await fetch('/dns/lookup', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                ...(token ? { 'X-CSRF-TOKEN': token } : {}),
            },
            body: JSON.stringify({ domains, type: selectedType.value }),
        })

        const body = await res.json().catch(() => ({}))

        if (!res.ok) {
            error.value = res.status === 429
                ? 'Too many requests — please wait a moment and try again.'
                : (body.message || 'Lookup failed. Please try again.')
            return
        }

        results.value = body.results ?? []
    } catch (e) {
        error.value = e.message || 'Network error.'
    } finally {
        loading.value = false
    }
}

function reset() {
    results.value = []
    error.value   = ''
}

function countryFlag(code) {
    if (!code || code.length !== 2) return ''
    const base = 0x1f1e6
    const A = 'A'.charCodeAt(0)
    return String.fromCodePoint(
        base + code.toUpperCase().charCodeAt(0) - A,
        base + code.toUpperCase().charCodeAt(1) - A,
    )
}

function ipLookupUrl(ip) {
    return `/ip?q=${encodeURIComponent(ip)}`
}

async function copyTable() {
    const geoHeaders = showGeo.value ? ['Country', 'Region', 'City', 'ISP', 'ASN'] : []
    const header = ['Domain', 'IP', ...geoHeaders, selectedType.value].join('\t')
    const rows = results.value.map(row => {
        const geo = showGeo.value ? [
            row.geo?.country ?? '—',
            row.geo?.region  ?? '—',
            row.geo?.city    ?? '—',
            row.geo?.isp     ?? '—',
            row.geo?.asn     ?? '—',
        ] : []
        const recs = (row.records ?? []).map(r =>
            r.priority !== undefined ? `${r.value} (pri: ${r.priority})` : r.value
        ).join(', ') || '—'
        return [row.domain, row.ip ?? '—', ...geo, recs].join('\t')
    })
    await navigator.clipboard.writeText([header, ...rows].join('\n'))
    copied.value = true
    setTimeout(() => { copied.value = false }, 2000)
}
</script>

<template>
    <AppLayout>
        <Head title="Bulk DNS Lookup" />

        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10">

            <!-- Hero -->
            <div class="text-center mb-10">
                <div class="inline-flex items-center justify-center w-14 h-14 rounded-2xl bg-indigo-600 mb-4 shadow-lg shadow-indigo-600/20">
                    <Globe2 class="w-7 h-7 text-white" />
                </div>
                <h1 class="text-3xl sm:text-4xl font-bold tracking-tight text-gray-900 dark:text-white">
                    Bulk DNS Lookup
                </h1>
                <p class="mt-3 text-sm text-gray-500 dark:text-gray-400 max-w-xl mx-auto">
                    Look up MX, NS, TXT, A, AAAA, or CNAME records for multiple domains at once — with IP geolocation.
                </p>
            </div>

            <!-- Input panel -->
            <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-2xl p-6 mb-6 shadow-sm">
                <div class="flex flex-col sm:flex-row gap-6">
                    <!-- Textarea -->
                    <div class="flex-1">
                        <label class="block text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-2">
                            Domains <span class="normal-case font-normal">(one per line, max 100)</span>
                        </label>
                        <textarea
                            v-model="textarea"
                            rows="10"
                            placeholder="example.com&#10;google.nl&#10;github.com"
                            spellcheck="false"
                            autocomplete="off"
                            class="w-full px-4 py-3 rounded-xl border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-sm text-gray-900 dark:text-white placeholder-gray-400 font-mono resize-y focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition"
                        />
                        <p class="mt-1.5 text-xs text-gray-400 dark:text-gray-500">
                            {{ parsedDomains.length }} domain{{ parsedDomains.length !== 1 ? 's' : '' }} detected
                        </p>
                    </div>

                    <!-- Controls -->
                    <div class="sm:w-52 flex flex-col gap-4">
                        <div>
                            <label class="block text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-2">
                                Record type
                            </label>
                            <div class="grid grid-cols-3 gap-1.5">
                                <button
                                    v-for="type in DNS_TYPES"
                                    :key="type"
                                    @click="selectedType = type"
                                    class="py-2 rounded-lg text-xs font-semibold font-mono transition-colors"
                                    :class="selectedType === type
                                        ? 'bg-indigo-600 text-white shadow-sm shadow-indigo-600/20'
                                        : 'bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-400 hover:bg-gray-200 dark:hover:bg-gray-700'"
                                >
                                    {{ type }}
                                </button>
                            </div>
                        </div>

                        <button
                            @click="runLookup"
                            :disabled="loading || !parsedDomains.length"
                            class="mt-auto flex items-center justify-center gap-2 px-6 py-3 bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 disabled:cursor-not-allowed text-white font-semibold rounded-xl transition-colors shadow-sm shadow-indigo-600/20 text-sm"
                        >
                            <Loader2 v-if="loading" class="w-4 h-4 animate-spin" />
                            <Search v-else class="w-4 h-4" />
                            {{ loading ? 'Looking up…' : 'Lookup' }}
                        </button>

                        <button
                            v-if="hasResults && !loading"
                            @click="reset"
                            class="flex items-center justify-center gap-2 px-6 py-2.5 rounded-xl text-sm font-medium text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors border border-gray-200 dark:border-gray-700"
                        >
                            <X class="w-4 h-4" />
                            Clear results
                        </button>
                    </div>
                </div>
            </div>

            <!-- Error -->
            <Transition
                enter-active-class="transition-all duration-200 ease-out"
                enter-from-class="opacity-0 -translate-y-1"
                enter-to-class="opacity-100 translate-y-0"
                leave-active-class="transition-all duration-150 ease-in"
                leave-from-class="opacity-100 translate-y-0"
                leave-to-class="opacity-0 -translate-y-1"
            >
                <div v-if="error" class="mb-6 flex items-center gap-3 px-4 py-3 rounded-xl border border-rose-200 dark:border-rose-900 bg-rose-50 dark:bg-rose-950/50 text-rose-700 dark:text-rose-300 text-sm">
                    <AlertTriangle class="w-4 h-4 shrink-0" />
                    {{ error }}
                </div>
            </Transition>

            <!-- Loading state -->
            <div v-if="loading" class="flex flex-col items-center justify-center py-16 gap-4">
                <Loader2 class="w-8 h-8 animate-spin text-indigo-500" />
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    Looking up {{ parsedDomains.length }} domain{{ parsedDomains.length !== 1 ? 's' : '' }}…
                </p>
            </div>

            <!-- Results -->
            <Transition
                enter-active-class="transition-all duration-300 ease-out"
                enter-from-class="opacity-0 translate-y-2"
                enter-to-class="opacity-100 translate-y-0"
            >
                <div v-if="hasResults && !loading">
                    <!-- Toolbar -->
                    <div class="flex items-center justify-between mb-3 gap-3 flex-wrap">
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            <span class="font-medium text-gray-900 dark:text-white">{{ results.length }}</span>
                            domain{{ results.length !== 1 ? 's' : '' }} &middot;
                            <span class="font-mono font-medium text-indigo-600 dark:text-indigo-400">{{ selectedType }}</span> records
                        </p>

                        <div class="flex items-center gap-2">
                            <!-- Geo toggle -->
                            <button
                                @click="showGeo = !showGeo"
                                class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium transition-colors border"
                                :class="showGeo
                                    ? 'bg-indigo-50 dark:bg-indigo-950/40 border-indigo-200 dark:border-indigo-800 text-indigo-600 dark:text-indigo-400'
                                    : 'bg-gray-100 dark:bg-gray-800 border-gray-200 dark:border-gray-700 text-gray-500 dark:text-gray-400 hover:bg-gray-200 dark:hover:bg-gray-700'"
                            >
                                <Eye v-if="showGeo" class="w-3.5 h-3.5" />
                                <EyeOff v-else class="w-3.5 h-3.5" />
                                {{ showGeo ? 'Hide geo' : 'Show geo' }}
                            </button>

                            <!-- Copy -->
                            <button
                                @click="copyTable"
                                class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium transition-colors"
                                :class="copied
                                    ? 'bg-emerald-100 dark:bg-emerald-950/40 text-emerald-700 dark:text-emerald-400'
                                    : 'bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-400 hover:bg-gray-200 dark:hover:bg-gray-700'"
                            >
                                <Check v-if="copied" class="w-3.5 h-3.5" />
                                <Copy v-else class="w-3.5 h-3.5" />
                                {{ copied ? 'Copied!' : 'Copy as TSV' }}
                            </button>
                        </div>
                    </div>

                    <!-- Table -->
                    <div class="rounded-2xl border border-gray-200 dark:border-gray-800 overflow-hidden bg-white dark:bg-gray-900 shadow-sm">
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="border-b border-gray-200 dark:border-gray-800 bg-gray-50 dark:bg-gray-800/60">
                                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400 whitespace-nowrap">
                                            Domain
                                        </th>
                                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400 whitespace-nowrap">
                                            IP
                                        </th>
                                        <template v-if="showGeo">
                                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400 whitespace-nowrap">
                                                Country
                                            </th>
                                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400 whitespace-nowrap">
                                                Region
                                            </th>
                                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400 whitespace-nowrap">
                                                City
                                            </th>
                                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400 whitespace-nowrap">
                                                ISP
                                            </th>
                                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400 whitespace-nowrap">
                                                ASN
                                            </th>
                                        </template>
                                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-indigo-600 dark:text-indigo-400 whitespace-nowrap font-mono">
                                            {{ selectedType }}
                                        </th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                    <tr
                                        v-for="row in results"
                                        :key="row.domain"
                                        class="hover:bg-gray-50 dark:hover:bg-gray-800/40 transition-colors"
                                    >
                                        <!-- Domain -->
                                        <td class="px-4 py-3 font-medium text-gray-900 dark:text-white whitespace-nowrap">
                                            {{ row.domain }}
                                        </td>

                                        <!-- IP (linked to IP lookup) -->
                                        <td class="px-4 py-3 whitespace-nowrap">
                                            <a
                                                v-if="row.ip"
                                                :href="ipLookupUrl(row.ip)"
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                class="font-mono text-indigo-600 dark:text-indigo-400 hover:underline"
                                            >{{ row.ip }}</a>
                                            <span v-else class="text-gray-300 dark:text-gray-600">—</span>
                                        </td>

                                        <!-- Geo columns -->
                                        <template v-if="showGeo">
                                            <!-- Country -->
                                            <td class="px-4 py-3 whitespace-nowrap">
                                                <span v-if="row.geo?.country" class="flex items-center gap-1.5 text-gray-700 dark:text-gray-300">
                                                    <span class="text-base leading-none">{{ countryFlag(row.geo.country_code) }}</span>
                                                    {{ row.geo.country }}
                                                </span>
                                                <span v-else class="text-gray-300 dark:text-gray-600">—</span>
                                            </td>
                                            <!-- Region -->
                                            <td class="px-4 py-3 text-gray-600 dark:text-gray-400 whitespace-nowrap">
                                                {{ row.geo?.region || '—' }}
                                            </td>
                                            <!-- City -->
                                            <td class="px-4 py-3 text-gray-600 dark:text-gray-400 whitespace-nowrap">
                                                {{ row.geo?.city || '—' }}
                                            </td>
                                            <!-- ISP -->
                                            <td class="px-4 py-3 text-gray-600 dark:text-gray-400">
                                                {{ row.geo?.isp || '—' }}
                                            </td>
                                            <!-- ASN -->
                                            <td class="px-4 py-3 font-mono text-gray-500 dark:text-gray-500 whitespace-nowrap">
                                                {{ row.geo?.asn || '—' }}
                                            </td>
                                        </template>

                                        <!-- DNS records -->
                                        <td class="px-4 py-3">
                                            <template v-if="row.records && row.records.length">
                                                <div class="space-y-1">
                                                    <div
                                                        v-for="(rec, i) in row.records"
                                                        :key="i"
                                                        class="font-mono text-xs leading-relaxed"
                                                    >
                                                        <template v-if="selectedType === 'MX'">
                                                            <span class="inline-flex items-center gap-1.5">
                                                                <span class="inline-block px-1.5 py-0.5 rounded text-xs font-semibold bg-indigo-100 dark:bg-indigo-950/50 text-indigo-700 dark:text-indigo-400">{{ rec.priority }}</span>
                                                                <span class="text-gray-700 dark:text-gray-300">{{ rec.value }}</span>
                                                            </span>
                                                        </template>
                                                        <template v-else-if="selectedType === 'TXT'">
                                                            <span class="text-gray-700 dark:text-gray-300 break-all">{{ rec.value }}</span>
                                                        </template>
                                                        <template v-else-if="selectedType === 'A' || selectedType === 'AAAA'">
                                                            <a :href="ipLookupUrl(rec.value)" target="_blank" rel="noopener noreferrer" class="text-indigo-600 dark:text-indigo-400 hover:underline">{{ rec.value }}</a>
                                                        </template>
                                                        <template v-else>
                                                            <span class="text-gray-700 dark:text-gray-300">{{ rec.value }}</span>
                                                        </template>
                                                    </div>
                                                </div>
                                            </template>
                                            <span v-else class="text-gray-300 dark:text-gray-600">—</span>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Footer note -->
                    <p class="mt-3 text-xs text-gray-400 dark:text-gray-600 text-center">
                        DNS cached 5 min · Geo cached 1 h · 4 concurrent geo workers · Data may lag behind live propagation
                    </p>
                </div>
            </Transition>

            <!-- Empty state -->
            <div v-if="!hasResults && !loading && !error" class="py-12 text-center">
                <div class="grid grid-cols-3 gap-2 max-w-xs mx-auto opacity-20 select-none pointer-events-none mb-6">
                    <div v-for="t in DNS_TYPES" :key="t" class="border border-gray-200 dark:border-gray-800 rounded-xl p-3 text-center">
                        <div class="font-mono text-xs font-bold text-gray-500">{{ t }}</div>
                    </div>
                </div>
                <p class="text-sm text-gray-400 dark:text-gray-500">
                    Enter domains above and choose a record type to look up
                </p>
            </div>

        </div>
    </AppLayout>
</template>
