<script setup>
import { ref, computed, onMounted } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import {
    MapPin, Search, Loader2, Globe, Building2, Network, Wifi,
    Clock, Cloud, Shield, AlertTriangle, Server, History, ArrowRight,
} from 'lucide-vue-next'

const props = defineProps({
    initialInput: { type: String, default: '' },
})

const HISTORY_KEY = 'iplookup:history'
const HISTORY_MAX_AGE_MS = 7 * 24 * 60 * 60 * 1000 // 7 days
const HISTORY_LIMIT = 5

const input = ref(props.initialInput || '')
const loading = ref(false)
const error = ref('')
const result = ref(null)
const recent = ref([])

function loadHistory() {
    try {
        const raw = localStorage.getItem(HISTORY_KEY)
        if (!raw) return []
        const parsed = JSON.parse(raw)
        if (!Array.isArray(parsed)) return []
        const cutoff = Date.now() - HISTORY_MAX_AGE_MS
        return parsed.filter(e => e && typeof e.looked_up_at === 'number' && e.looked_up_at >= cutoff)
    } catch {
        return []
    }
}

function saveHistory(entries) {
    try {
        localStorage.setItem(HISTORY_KEY, JSON.stringify(entries))
    } catch {
        /* ignore quota / privacy errors */
    }
}

function addToHistory(data) {
    if (!data?.ip || data.private) return
    const entry = {
        ip: data.ip,
        looked_up_at: Date.now(),
        data: {
            country: data.country,
            country_code: data.country_code,
            city: data.city,
            isp: data.isp,
        },
    }
    const filtered = recent.value.filter(e => e.ip !== entry.ip)
    filtered.unshift(entry)
    recent.value = filtered.slice(0, HISTORY_LIMIT)
    saveHistory(recent.value)
}

function clearHistory() {
    recent.value = []
    try { localStorage.removeItem(HISTORY_KEY) } catch { /* ignore */ }
}

async function lookup() {
    const q = input.value.trim()
    if (!q || loading.value) return

    loading.value = true
    error.value = ''
    result.value = null

    try {
        const token = document.querySelector('meta[name="csrf-token"]')?.content
        const res = await fetch('/ip/lookup', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                ...(token ? { 'X-CSRF-TOKEN': token } : {}),
            },
            body: JSON.stringify({ q }),
        })

        const body = await res.json().catch(() => ({}))

        if (!res.ok) {
            error.value = body.error || `Lookup failed (HTTP ${res.status}).`
            return
        }

        result.value = body.result
        addToHistory(body.result)
    } catch (e) {
        error.value = e.message || 'Network error.'
    } finally {
        loading.value = false
    }
}

function pickRecent(ip) {
    input.value = ip
    lookup()
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

const badges = computed(() => {
    if (!result.value) return []
    const b = []
    if (result.value.mobile)  b.push({ label: 'Mobile network', tone: 'amber' })
    if (result.value.proxy)   b.push({ label: 'Proxy / VPN / Tor', tone: 'rose' })
    if (result.value.hosting) b.push({ label: 'Hosting / Datacenter', tone: 'indigo' })
    return b
})

const toneClasses = {
    amber:  'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-200 border-amber-200 dark:border-amber-800',
    rose:   'bg-rose-100 text-rose-800 dark:bg-rose-950 dark:text-rose-200 border-rose-200 dark:border-rose-800',
    indigo: 'bg-indigo-100 text-indigo-800 dark:bg-indigo-950 dark:text-indigo-200 border-indigo-200 dark:border-indigo-800',
}

const mapSrc = computed(() => {
    const r = result.value
    if (!r || typeof r.lat !== 'number' || typeof r.lon !== 'number') return null
    const delta = 0.8
    const bbox = [r.lon - delta, r.lat - delta, r.lon + delta, r.lat + delta].join(',')
    return `https://www.openstreetmap.org/export/embed.html?bbox=${bbox}&layer=mapnik&marker=${r.lat},${r.lon}`
})

function formatRelative(value) {
    if (!value) return ''
    const t = typeof value === 'number' ? value : new Date(value).getTime()
    if (!Number.isFinite(t)) return ''
    const diff = Math.max(0, (Date.now() - t) / 1000)
    if (diff < 60) return 'just now'
    if (diff < 3600) return `${Math.floor(diff / 60)}m ago`
    if (diff < 86400) return `${Math.floor(diff / 3600)}h ago`
    return `${Math.floor(diff / 86400)}d ago`
}

onMounted(() => {
    recent.value = loadHistory()
    if (props.initialInput) lookup()
})
</script>

<template>
    <Head title="IP Lookup" />

    <AppLayout>
        <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-10">
            <!-- Hero -->
            <div class="text-center mb-8">
                <div class="inline-flex items-center justify-center w-14 h-14 rounded-2xl bg-indigo-600 mb-4 shadow-lg shadow-indigo-600/20">
                    <MapPin class="w-7 h-7 text-white" />
                </div>
                <h1 class="text-3xl sm:text-4xl font-bold tracking-tight text-gray-900 dark:text-white">
                    IP Address Lookup
                </h1>
                <p class="mt-3 text-sm text-gray-500 dark:text-gray-400 max-w-xl mx-auto">
                    Geolocation, ISP, ASN, reverse DNS and proxy / hosting signals for any public IPv4 or IPv6 address.
                </p>
            </div>

            <!-- Search bar -->
            <form @submit.prevent="lookup" class="relative">
                <div class="flex gap-2">
                    <div class="relative flex-1">
                        <Search class="absolute left-4 top-1/2 -translate-y-1/2 w-5 h-5 text-gray-400" />
                        <input
                            v-model="input"
                            type="text"
                            placeholder="8.8.8.8 or example.com"
                            autocomplete="off"
                            spellcheck="false"
                            class="w-full pl-12 pr-4 py-4 rounded-2xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 text-gray-900 dark:text-white placeholder-gray-400 focus:border-indigo-500 focus:outline-none focus:ring-4 focus:ring-indigo-500/10 transition"
                        />
                    </div>
                    <button
                        type="submit"
                        :disabled="loading || !input.trim()"
                        class="px-6 rounded-2xl bg-indigo-600 hover:bg-indigo-700 text-white font-medium text-sm shadow-lg shadow-indigo-600/20 disabled:opacity-50 disabled:cursor-not-allowed transition"
                    >
                        <Loader2 v-if="loading" class="w-5 h-5 animate-spin" />
                        <span v-else>Lookup</span>
                    </button>
                </div>
            </form>

            <!-- Error -->
            <div v-if="error" class="mt-6 rounded-xl border border-rose-200 dark:border-rose-900 bg-rose-50 dark:bg-rose-950 text-rose-800 dark:text-rose-200 px-4 py-3 text-sm flex items-center gap-2">
                <AlertTriangle class="w-4 h-4 shrink-0" />
                {{ error }}
            </div>

            <!-- Private range notice -->
            <div v-if="result?.private" class="mt-8 rounded-2xl border border-amber-200 dark:border-amber-900 bg-amber-50 dark:bg-amber-950/40 p-6">
                <div class="flex items-start gap-3">
                    <AlertTriangle class="w-5 h-5 text-amber-600 dark:text-amber-400 shrink-0 mt-0.5" />
                    <div>
                        <p class="font-semibold text-amber-900 dark:text-amber-100">{{ result.ip }}</p>
                        <p class="text-sm text-amber-800 dark:text-amber-300 mt-1">{{ result.message }}</p>
                    </div>
                </div>
            </div>

            <!-- Result -->
            <div v-else-if="result" class="mt-8 space-y-6">
                <!-- Header card -->
                <div class="rounded-2xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 overflow-hidden">
                    <div class="p-6 flex flex-col sm:flex-row sm:items-center gap-4 sm:gap-6">
                        <div class="shrink-0">
                            <div class="text-5xl leading-none">
                                {{ countryFlag(result.country_code) || '🌐' }}
                            </div>
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="font-mono text-lg font-semibold text-gray-900 dark:text-white break-all">
                                {{ result.ip }}
                            </div>
                            <div v-if="result.hostname" class="text-sm text-gray-500 dark:text-gray-400 break-all mt-0.5">
                                {{ result.hostname }}
                            </div>
                            <div class="text-sm text-gray-600 dark:text-gray-300 mt-2">
                                <span v-if="result.city">{{ result.city }}, </span>
                                <span v-if="result.region_name">{{ result.region_name }}, </span>
                                <span v-if="result.country">{{ result.country }}</span>
                            </div>
                        </div>
                        <div v-if="badges.length" class="flex flex-wrap gap-2">
                            <span
                                v-for="b in badges" :key="b.label"
                                class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-medium border"
                                :class="toneClasses[b.tone]"
                            >
                                <Shield class="w-3 h-3" />
                                {{ b.label }}
                            </span>
                        </div>
                    </div>

                    <!-- Map -->
                    <div v-if="mapSrc" class="border-t border-gray-200 dark:border-gray-800 bg-gray-50 dark:bg-gray-950">
                        <iframe
                            :src="mapSrc"
                            class="w-full h-72 border-0"
                            loading="lazy"
                            referrerpolicy="no-referrer"
                        />
                    </div>
                </div>

                <!-- Detail grid -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div class="rounded-2xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 p-5">
                        <div class="flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-3">
                            <MapPin class="w-4 h-4" /> Location
                        </div>
                        <dl class="space-y-2 text-sm">
                            <div class="flex justify-between gap-4">
                                <dt class="text-gray-500 dark:text-gray-400">Continent</dt>
                                <dd class="text-gray-900 dark:text-white text-right">{{ result.continent || '—' }}</dd>
                            </div>
                            <div class="flex justify-between gap-4">
                                <dt class="text-gray-500 dark:text-gray-400">Country</dt>
                                <dd class="text-gray-900 dark:text-white text-right">
                                    {{ result.country || '—' }}
                                    <span v-if="result.country_code" class="text-gray-500 dark:text-gray-400">({{ result.country_code }})</span>
                                </dd>
                            </div>
                            <div class="flex justify-between gap-4">
                                <dt class="text-gray-500 dark:text-gray-400">Region</dt>
                                <dd class="text-gray-900 dark:text-white text-right">{{ result.region_name || '—' }}</dd>
                            </div>
                            <div class="flex justify-between gap-4">
                                <dt class="text-gray-500 dark:text-gray-400">City</dt>
                                <dd class="text-gray-900 dark:text-white text-right">{{ result.city || '—' }}</dd>
                            </div>
                            <div v-if="result.district" class="flex justify-between gap-4">
                                <dt class="text-gray-500 dark:text-gray-400">District</dt>
                                <dd class="text-gray-900 dark:text-white text-right">{{ result.district }}</dd>
                            </div>
                            <div v-if="result.zip" class="flex justify-between gap-4">
                                <dt class="text-gray-500 dark:text-gray-400">Postal code</dt>
                                <dd class="text-gray-900 dark:text-white text-right font-mono">{{ result.zip }}</dd>
                            </div>
                            <div v-if="result.lat !== null && result.lon !== null" class="flex justify-between gap-4">
                                <dt class="text-gray-500 dark:text-gray-400">Coordinates</dt>
                                <dd class="text-gray-900 dark:text-white text-right font-mono">{{ result.lat }}, {{ result.lon }}</dd>
                            </div>
                        </dl>
                    </div>

                    <div class="rounded-2xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 p-5">
                        <div class="flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-3">
                            <Network class="w-4 h-4" /> Network
                        </div>
                        <dl class="space-y-2 text-sm">
                            <div class="flex justify-between gap-4">
                                <dt class="text-gray-500 dark:text-gray-400">ISP</dt>
                                <dd class="text-gray-900 dark:text-white text-right">{{ result.isp || '—' }}</dd>
                            </div>
                            <div class="flex justify-between gap-4">
                                <dt class="text-gray-500 dark:text-gray-400">Organization</dt>
                                <dd class="text-gray-900 dark:text-white text-right">{{ result.org || '—' }}</dd>
                            </div>
                            <div class="flex justify-between gap-4">
                                <dt class="text-gray-500 dark:text-gray-400">ASN</dt>
                                <dd class="text-gray-900 dark:text-white text-right font-mono break-all">{{ result.as || '—' }}</dd>
                            </div>
                            <div v-if="result.as_name" class="flex justify-between gap-4">
                                <dt class="text-gray-500 dark:text-gray-400">AS name</dt>
                                <dd class="text-gray-900 dark:text-white text-right">{{ result.as_name }}</dd>
                            </div>
                            <div class="flex justify-between gap-4">
                                <dt class="text-gray-500 dark:text-gray-400">Reverse DNS</dt>
                                <dd class="text-gray-900 dark:text-white text-right break-all">{{ result.reverse_dns || '—' }}</dd>
                            </div>
                        </dl>
                    </div>

                    <div class="rounded-2xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 p-5">
                        <div class="flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-3">
                            <Clock class="w-4 h-4" /> Time &amp; Currency
                        </div>
                        <dl class="space-y-2 text-sm">
                            <div class="flex justify-between gap-4">
                                <dt class="text-gray-500 dark:text-gray-400">Timezone</dt>
                                <dd class="text-gray-900 dark:text-white text-right">{{ result.timezone || '—' }}</dd>
                            </div>
                            <div v-if="result.utc_offset !== null" class="flex justify-between gap-4">
                                <dt class="text-gray-500 dark:text-gray-400">UTC offset</dt>
                                <dd class="text-gray-900 dark:text-white text-right font-mono">
                                    {{ result.utc_offset >= 0 ? '+' : '' }}{{ (result.utc_offset / 3600).toFixed(1) }}h
                                </dd>
                            </div>
                            <div v-if="result.currency" class="flex justify-between gap-4">
                                <dt class="text-gray-500 dark:text-gray-400">Currency</dt>
                                <dd class="text-gray-900 dark:text-white text-right font-mono">{{ result.currency }}</dd>
                            </div>
                        </dl>
                    </div>

                    <div class="rounded-2xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 p-5">
                        <div class="flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-3">
                            <Shield class="w-4 h-4" /> Flags
                        </div>
                        <dl class="space-y-2 text-sm">
                            <div class="flex justify-between gap-4">
                                <dt class="text-gray-500 dark:text-gray-400">Mobile network</dt>
                                <dd :class="result.mobile ? 'text-amber-600 dark:text-amber-400 font-medium' : 'text-gray-900 dark:text-white'">
                                    {{ result.mobile ? 'Yes' : 'No' }}
                                </dd>
                            </div>
                            <div class="flex justify-between gap-4">
                                <dt class="text-gray-500 dark:text-gray-400">Proxy / VPN / Tor</dt>
                                <dd :class="result.proxy ? 'text-rose-600 dark:text-rose-400 font-medium' : 'text-gray-900 dark:text-white'">
                                    {{ result.proxy ? 'Yes' : 'No' }}
                                </dd>
                            </div>
                            <div class="flex justify-between gap-4">
                                <dt class="text-gray-500 dark:text-gray-400">Hosting / Datacenter</dt>
                                <dd :class="result.hosting ? 'text-indigo-600 dark:text-indigo-400 font-medium' : 'text-gray-900 dark:text-white'">
                                    {{ result.hosting ? 'Yes' : 'No' }}
                                </dd>
                            </div>
                        </dl>
                    </div>
                </div>
            </div>

            <!-- Recent history (stored locally in your browser, expires after 7 days) -->
            <div v-if="recent.length" class="mt-12">
                <div class="flex items-center justify-between mb-4">
                    <div class="flex items-center gap-2">
                        <History class="w-4 h-4 text-gray-500 dark:text-gray-400" />
                        <h2 class="text-sm font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                            Your recent lookups
                        </h2>
                    </div>
                    <button
                        type="button"
                        @click="clearHistory"
                        class="text-xs text-gray-500 dark:text-gray-400 hover:text-rose-600 dark:hover:text-rose-400 transition"
                    >
                        Clear
                    </button>
                </div>
                <div class="rounded-2xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 divide-y divide-gray-100 dark:divide-gray-800">
                    <button
                        v-for="entry in recent" :key="entry.ip + entry.looked_up_at"
                        type="button"
                        @click="pickRecent(entry.ip)"
                        class="w-full flex items-center gap-4 px-4 py-3 text-left hover:bg-gray-50 dark:hover:bg-gray-800/50 transition"
                    >
                        <div class="text-2xl leading-none shrink-0">
                            {{ countryFlag(entry.data?.country_code) || '🌐' }}
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="font-mono text-sm font-medium text-gray-900 dark:text-white truncate">{{ entry.ip }}</div>
                            <div class="text-xs text-gray-500 dark:text-gray-400 truncate">
                                <template v-if="entry.data?.city || entry.data?.country">
                                    <span v-if="entry.data?.city">{{ entry.data.city }}, </span>
                                    <span>{{ entry.data?.country || '—' }}</span>
                                    <span v-if="entry.data?.isp"> · {{ entry.data.isp }}</span>
                                </template>
                                <span v-else>—</span>
                            </div>
                        </div>
                        <div class="hidden sm:block text-xs text-gray-400 dark:text-gray-500 shrink-0">
                            {{ formatRelative(entry.looked_up_at) }}
                        </div>
                        <ArrowRight class="w-4 h-4 text-gray-400 shrink-0" />
                    </button>
                </div>
            </div>

            <!-- Info -->
            <div class="mt-12 rounded-2xl border border-gray-200 dark:border-gray-800 bg-gray-50 dark:bg-gray-900/50 p-6 text-sm text-gray-600 dark:text-gray-400">
                <p class="font-medium text-gray-900 dark:text-white mb-2">How this works</p>
                <p>
                    Geolocation data comes from <a href="https://ip-api.com" target="_blank" rel="noopener" class="text-indigo-600 dark:text-indigo-400 hover:underline">ip-api.com</a>, a free public IP information database. Hostnames are resolved via DNS. Results are cached server-side for one hour per IP. Private and reserved ranges are not geolocated. Your recent lookups are kept privately in your own browser's localStorage and expire after 7 days — nothing is stored on the server.
                </p>
            </div>
        </div>
    </AppLayout>
</template>
