<script setup>
import { ref, computed, onMounted } from 'vue'
import { Head, Link } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'
import {
    Wifi, MapPin, Network, Clock, Shield, Check, Copy,
    Monitor, Smartphone, Tablet, Globe, Building2, Server,
    AlertTriangle, Search,
} from 'lucide-vue-next'

const props = defineProps({
    ip:     { type: String, default: null },
    result: { type: Object, default: null },
})

const copied = ref(false)
const browserInfo = ref(null)

function copyIp() {
    if (!props.ip) return
    navigator.clipboard.writeText(props.ip).then(() => {
        copied.value = true
        setTimeout(() => { copied.value = false }, 2000)
    }).catch(() => {})
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

const isIPv6 = computed(() => props.ip && props.ip.includes(':'))

const ipVersion = computed(() => isIPv6.value ? 'IPv6' : 'IPv4')

const badges = computed(() => {
    if (!props.result) return []
    const b = []
    if (props.result.mobile)  b.push({ label: 'Mobile', tone: 'amber' })
    if (props.result.proxy)   b.push({ label: 'Proxy / VPN', tone: 'rose' })
    if (props.result.hosting) b.push({ label: 'Hosting', tone: 'indigo' })
    return b
})

const toneClasses = {
    amber:  'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-200 border-amber-200 dark:border-amber-800',
    rose:   'bg-rose-100 text-rose-800 dark:bg-rose-950 dark:text-rose-200 border-rose-200 dark:border-rose-800',
    indigo: 'bg-indigo-100 text-indigo-800 dark:bg-indigo-950 dark:text-indigo-200 border-indigo-200 dark:border-indigo-800',
}

const mapSrc = computed(() => {
    const r = props.result
    if (!r || typeof r.lat !== 'number' || typeof r.lon !== 'number') return null
    const delta = 0.8
    const bbox = [r.lon - delta, r.lat - delta, r.lon + delta, r.lat + delta].join(',')
    return `https://www.openstreetmap.org/export/embed.html?bbox=${bbox}&layer=mapnik&marker=${r.lat},${r.lon}`
})

function detectBrowser() {
    const ua = navigator.userAgent
    let browser = 'Unknown', version = '', os = 'Unknown', device = 'Desktop'

    // Browser
    if (/Edg\//.test(ua)) {
        browser = 'Edge'
        version = ua.match(/Edg\/([\d.]+)/)?.[1] || ''
    } else if (/OPR\//.test(ua)) {
        browser = 'Opera'
        version = ua.match(/OPR\/([\d.]+)/)?.[1] || ''
    } else if (/Chrome\//.test(ua) && !/Chromium/.test(ua)) {
        browser = 'Chrome'
        version = ua.match(/Chrome\/([\d.]+)/)?.[1] || ''
    } else if (/Firefox\//.test(ua)) {
        browser = 'Firefox'
        version = ua.match(/Firefox\/([\d.]+)/)?.[1] || ''
    } else if (/Safari\//.test(ua) && !/Chrome/.test(ua)) {
        browser = 'Safari'
        version = ua.match(/Version\/([\d.]+)/)?.[1] || ''
    }

    // OS
    if (/Windows NT/.test(ua)) {
        const v = ua.match(/Windows NT ([\d.]+)/)?.[1]
        const winMap = { '10.0': '10 / 11', '6.3': '8.1', '6.2': '8', '6.1': '7' }
        os = 'Windows ' + (winMap[v] || v || '')
    } else if (/Mac OS X/.test(ua)) {
        const v = ua.match(/Mac OS X ([\d_]+)/)?.[1]?.replace(/_/g, '.') || ''
        os = 'macOS ' + v
    } else if (/Android/.test(ua)) {
        os = 'Android ' + (ua.match(/Android ([\d.]+)/)?.[1] || '')
    } else if (/iPhone OS/.test(ua)) {
        os = 'iOS ' + (ua.match(/iPhone OS ([\d_]+)/)?.[1]?.replace(/_/g, '.') || '')
    } else if (/iPad/.test(ua)) {
        os = 'iPadOS ' + (ua.match(/OS ([\d_]+)/)?.[1]?.replace(/_/g, '.') || '')
    } else if (/Linux/.test(ua)) {
        os = 'Linux'
    }

    // Device
    if (/Mobi|Android|iPhone|iPod/.test(ua)) {
        device = 'Mobile'
    } else if (/Tablet|iPad/.test(ua)) {
        device = 'Tablet'
    }

    return {
        browser,
        version: version.split('.')[0],
        os: os.trim(),
        device,
        userAgent: ua,
    }
}

onMounted(() => {
    browserInfo.value = detectBrowser()
})
</script>

<template>
    <Head title="My IP Address" />

    <AppLayout>
        <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-10">

            <!-- Hero -->
            <div class="text-center mb-8">
                <div class="inline-flex items-center justify-center w-14 h-14 rounded-2xl bg-emerald-600 mb-4 shadow-lg shadow-emerald-600/20">
                    <Wifi class="w-7 h-7 text-white" />
                </div>
                <h1 class="text-3xl sm:text-4xl font-bold tracking-tight text-gray-900 dark:text-white">
                    Your IP Address
                </h1>
                <p class="mt-3 text-sm text-gray-500 dark:text-gray-400 max-w-xl mx-auto">
                    This is the public IP address your device is using to connect to the internet right now.
                </p>
            </div>

            <!-- IP hero card -->
            <div class="rounded-2xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 overflow-hidden shadow-sm">
                <div class="p-8 flex flex-col items-center text-center gap-5">
                    <!-- Flag + IP version pill -->
                    <div class="flex items-center gap-3">
                        <span class="text-5xl leading-none">{{ countryFlag(result?.country_code) || '🌐' }}</span>
                        <span
                            class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold border"
                            :class="isIPv6
                                ? 'bg-purple-100 text-purple-800 dark:bg-purple-950 dark:text-purple-200 border-purple-200 dark:border-purple-800'
                                : 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200 border-emerald-200 dark:border-emerald-800'"
                        >
                            <span class="w-1.5 h-1.5 rounded-full animate-pulse"
                                :class="isIPv6 ? 'bg-purple-500' : 'bg-emerald-500'"></span>
                            {{ ipVersion }}
                        </span>
                    </div>

                    <!-- IP address -->
                    <div class="flex items-center gap-3">
                        <span class="font-mono text-3xl sm:text-4xl font-bold tracking-tight text-gray-900 dark:text-white break-all">
                            {{ ip || '—' }}
                        </span>
                        <button
                            v-if="ip"
                            @click="copyIp"
                            type="button"
                            class="shrink-0 flex items-center justify-center w-9 h-9 rounded-xl border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 hover:bg-gray-100 dark:hover:bg-gray-700 text-gray-500 dark:text-gray-400 transition-colors"
                            title="Copy IP"
                        >
                            <Check v-if="copied" class="w-4 h-4 text-emerald-500" />
                            <Copy v-else class="w-4 h-4" />
                        </button>
                    </div>

                    <!-- Hostname -->
                    <div v-if="result?.hostname" class="text-sm text-gray-500 dark:text-gray-400 font-mono">
                        {{ result.hostname }}
                    </div>

                    <!-- Location summary -->
                    <div v-if="result && !result.private" class="text-sm text-gray-600 dark:text-gray-300 flex items-center gap-1.5">
                        <MapPin class="w-4 h-4 text-gray-400 shrink-0" />
                        <span>
                            <template v-if="result.city">{{ result.city }}, </template>
                            <template v-if="result.region_name">{{ result.region_name }}, </template>
                            <template v-if="result.country">{{ result.country }}</template>
                        </span>
                    </div>

                    <!-- Badges -->
                    <div v-if="badges.length" class="flex flex-wrap justify-center gap-2">
                        <span
                            v-for="b in badges" :key="b.label"
                            class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-medium border"
                            :class="toneClasses[b.tone]"
                        >
                            <Shield class="w-3 h-3" />
                            {{ b.label }}
                        </span>
                    </div>

                    <!-- Lookup link -->
                    <Link
                        :href="`/ip?q=${encodeURIComponent(ip || '')}`"
                        class="inline-flex items-center gap-1.5 text-xs text-indigo-600 dark:text-indigo-400 hover:underline"
                    >
                        <Search class="w-3.5 h-3.5" />
                        Full IP Lookup
                    </Link>
                </div>

                <!-- Map -->
                <div v-if="mapSrc" class="border-t border-gray-200 dark:border-gray-800">
                    <iframe
                        :src="mapSrc"
                        class="w-full h-64 border-0"
                        loading="lazy"
                        referrerpolicy="no-referrer"
                    />
                </div>
            </div>

            <!-- Private IP notice -->
            <div v-if="result?.private" class="mt-6 rounded-2xl border border-amber-200 dark:border-amber-900 bg-amber-50 dark:bg-amber-950/40 p-5 flex items-start gap-3">
                <AlertTriangle class="w-5 h-5 text-amber-600 dark:text-amber-400 shrink-0 mt-0.5" />
                <p class="text-sm text-amber-800 dark:text-amber-300">{{ result.message }}</p>
            </div>

            <!-- Detail cards -->
            <div v-if="result && !result.private" class="mt-6 grid grid-cols-1 sm:grid-cols-2 gap-4">

                <!-- Location -->
                <div class="rounded-2xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 p-5">
                    <div class="flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-4">
                        <MapPin class="w-4 h-4" /> Location
                    </div>
                    <dl class="space-y-2.5 text-sm">
                        <div class="flex justify-between gap-4">
                            <dt class="text-gray-500 dark:text-gray-400">Country</dt>
                            <dd class="text-gray-900 dark:text-white text-right font-medium">
                                {{ result.country || '—' }}
                                <span v-if="result.country_code" class="text-gray-400">({{ result.country_code }})</span>
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
                        <div v-if="result.zip" class="flex justify-between gap-4">
                            <dt class="text-gray-500 dark:text-gray-400">Postal code</dt>
                            <dd class="text-gray-900 dark:text-white text-right font-mono">{{ result.zip }}</dd>
                        </div>
                        <div v-if="result.lat !== null && result.lon !== null" class="flex justify-between gap-4">
                            <dt class="text-gray-500 dark:text-gray-400">Coordinates</dt>
                            <dd class="text-gray-900 dark:text-white text-right font-mono text-xs">{{ result.lat }}, {{ result.lon }}</dd>
                        </div>
                        <div v-if="result.timezone" class="flex justify-between gap-4">
                            <dt class="text-gray-500 dark:text-gray-400">Timezone</dt>
                            <dd class="text-gray-900 dark:text-white text-right">{{ result.timezone }}</dd>
                        </div>
                    </dl>
                </div>

                <!-- Network -->
                <div class="rounded-2xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 p-5">
                    <div class="flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-4">
                        <Network class="w-4 h-4" /> Network
                    </div>
                    <dl class="space-y-2.5 text-sm">
                        <div class="flex justify-between gap-4">
                            <dt class="text-gray-500 dark:text-gray-400">ISP</dt>
                            <dd class="text-gray-900 dark:text-white text-right font-medium">{{ result.isp || '—' }}</dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="text-gray-500 dark:text-gray-400">Organization</dt>
                            <dd class="text-gray-900 dark:text-white text-right">{{ result.org || '—' }}</dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="text-gray-500 dark:text-gray-400">ASN</dt>
                            <dd class="text-gray-900 dark:text-white text-right font-mono text-xs break-all">{{ result.as || '—' }}</dd>
                        </div>
                        <div v-if="result.reverse_dns" class="flex justify-between gap-4">
                            <dt class="text-gray-500 dark:text-gray-400">Reverse DNS</dt>
                            <dd class="text-gray-900 dark:text-white text-right break-all text-xs">{{ result.reverse_dns }}</dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="text-gray-500 dark:text-gray-400">Mobile</dt>
                            <dd :class="result.mobile ? 'text-amber-600 dark:text-amber-400 font-medium' : 'text-gray-900 dark:text-white'">
                                {{ result.mobile ? 'Yes' : 'No' }}
                            </dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="text-gray-500 dark:text-gray-400">Proxy / VPN</dt>
                            <dd :class="result.proxy ? 'text-rose-600 dark:text-rose-400 font-medium' : 'text-gray-900 dark:text-white'">
                                {{ result.proxy ? 'Yes' : 'No' }}
                            </dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="text-gray-500 dark:text-gray-400">Hosting</dt>
                            <dd :class="result.hosting ? 'text-indigo-600 dark:text-indigo-400 font-medium' : 'text-gray-900 dark:text-white'">
                                {{ result.hosting ? 'Yes' : 'No' }}
                            </dd>
                        </div>
                    </dl>
                </div>
            </div>

            <!-- Browser info (client-side) -->
            <div v-if="browserInfo" class="mt-4">
                <div class="rounded-2xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 p-5">
                    <div class="flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400 mb-4">
                        <Monitor class="w-4 h-4" /> Your Device &amp; Browser
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <div class="flex items-center gap-3 p-3 rounded-xl bg-gray-50 dark:bg-gray-800/50">
                            <div class="w-9 h-9 rounded-lg bg-blue-100 dark:bg-blue-950 flex items-center justify-center shrink-0">
                                <Globe class="w-5 h-5 text-blue-600 dark:text-blue-400" />
                            </div>
                            <div class="min-w-0">
                                <div class="text-xs text-gray-500 dark:text-gray-400">Browser</div>
                                <div class="text-sm font-semibold text-gray-900 dark:text-white truncate">
                                    {{ browserInfo.browser }}
                                    <span v-if="browserInfo.version" class="font-normal text-gray-500 dark:text-gray-400"> {{ browserInfo.version }}</span>
                                </div>
                            </div>
                        </div>
                        <div class="flex items-center gap-3 p-3 rounded-xl bg-gray-50 dark:bg-gray-800/50">
                            <div class="w-9 h-9 rounded-lg bg-violet-100 dark:bg-violet-950 flex items-center justify-center shrink-0">
                                <Server class="w-5 h-5 text-violet-600 dark:text-violet-400" />
                            </div>
                            <div class="min-w-0">
                                <div class="text-xs text-gray-500 dark:text-gray-400">Operating System</div>
                                <div class="text-sm font-semibold text-gray-900 dark:text-white truncate">{{ browserInfo.os }}</div>
                            </div>
                        </div>
                        <div class="flex items-center gap-3 p-3 rounded-xl bg-gray-50 dark:bg-gray-800/50">
                            <div class="w-9 h-9 rounded-lg bg-teal-100 dark:bg-teal-950 flex items-center justify-center shrink-0">
                                <component
                                    :is="browserInfo.device === 'Mobile' ? Smartphone : browserInfo.device === 'Tablet' ? Tablet : Monitor"
                                    class="w-5 h-5 text-teal-600 dark:text-teal-400"
                                />
                            </div>
                            <div class="min-w-0">
                                <div class="text-xs text-gray-500 dark:text-gray-400">Device</div>
                                <div class="text-sm font-semibold text-gray-900 dark:text-white">{{ browserInfo.device }}</div>
                            </div>
                        </div>
                    </div>
                    <div class="mt-3 px-3 py-2 rounded-lg bg-gray-50 dark:bg-gray-800/50 text-xs font-mono text-gray-500 dark:text-gray-400 break-all">
                        {{ browserInfo.userAgent }}
                    </div>
                </div>
            </div>

            <!-- Info note -->
            <div class="mt-8 rounded-2xl border border-gray-200 dark:border-gray-800 bg-gray-50 dark:bg-gray-900/50 p-5 text-sm text-gray-600 dark:text-gray-400">
                <p class="font-medium text-gray-900 dark:text-white mb-1.5">How this works</p>
                <p>
                    Your IP address is detected from your HTTP request and looked up using
                    <a href="https://ip-api.com" target="_blank" rel="noopener" class="text-indigo-600 dark:text-indigo-400 hover:underline">ip-api.com</a>.
                    Geolocation is approximate — it reflects the location of your ISP, not your exact address.
                    Browser and device information is detected locally in your browser and never sent to the server.
                    Want to look up a different IP?
                    <Link :href="route('ip')" class="text-indigo-600 dark:text-indigo-400 hover:underline">Use IP Lookup →</Link>
                </p>
            </div>
        </div>
    </AppLayout>
</template>
