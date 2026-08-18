<script setup>
import { ref, computed, onMounted } from 'vue'
import { Head } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

const props = defineProps({
    initialUrl:       { type: String, default: '' },
    userAgentOptions: { type: Array,  default: () => [] },
})

// ── State ────────────────────────────────────────────────────────────────

const urlInput      = ref(props.initialUrl || '')
const selectedAgent = ref('default')
const hops          = ref([])
const loading       = ref(false)
const error         = ref('')
const expandedHops  = ref(new Set())

// ── Grouped user-agent options ───────────────────────────────────────────

const groupedAgents = computed(() => {
    const groups = {}
    for (const opt of props.userAgentOptions) {
        if (!groups[opt.group]) groups[opt.group] = []
        groups[opt.group].push(opt)
    }
    return groups
})

// ── Check ────────────────────────────────────────────────────────────────

async function runCheck() {
    const url = urlInput.value.trim()
    if (!url || loading.value) return

    loading.value  = true
    error.value    = ''
    hops.value     = []
    expandedHops.value = new Set()

    try {
        const params = new URL(window.location.href)
        params.searchParams.set('url', url)
        window.history.replaceState({}, '', params)
    } catch { /* ignore */ }

    try {
        const token = document.querySelector('meta[name="csrf-token"]')?.content
        const res = await fetch('/redirect/check', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                ...(token ? { 'X-CSRF-TOKEN': token } : {}),
            },
            body: JSON.stringify({ url, user_agent: selectedAgent.value }),
        })

        if (res.status === 429) {
            error.value = 'Too many requests — please wait a moment before checking again.'
            return
        }

        const body = await res.json().catch(() => ({}))

        if (!res.ok) {
            error.value = body.error || body.message || `Request failed (HTTP ${res.status}).`
            return
        }

        if (body.error) {
            error.value = body.error
        }

        hops.value = body.hops ?? []
    } catch (e) {
        error.value = e.message || 'Network error.'
    } finally {
        loading.value = false
    }
}

function toggleHop(index) {
    const next = new Set(expandedHops.value)
    if (next.has(index)) {
        next.delete(index)
    } else {
        next.add(index)
    }
    expandedHops.value = next
}

// ── Status helpers ───────────────────────────────────────────────────────

function statusBadgeClass(status) {
    if (status >= 200 && status < 300) return 'bg-green-100 text-green-800 dark:bg-green-950 dark:text-green-300 border-green-200 dark:border-green-800'
    if (status >= 300 && status < 400) return 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300 border-amber-200 dark:border-amber-800'
    if (status >= 400 && status < 500) return 'bg-rose-100 text-rose-800 dark:bg-rose-950 dark:text-rose-300 border-rose-200 dark:border-rose-800'
    if (status >= 500)                 return 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-300 border-red-200 dark:border-red-800'
    return 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300 border-gray-200 dark:border-gray-700'
}

function isRedirect(status) {
    return status >= 300 && status < 400
}

const verdictConfig = computed(() => {
    if (!hops.value.length) return null
    const last = hops.value[hops.value.length - 1]
    const redirectCount = hops.value.filter(h => isRedirect(h.status)).length

    if (last.status >= 200 && last.status < 300) {
        if (redirectCount === 0) {
            return { bg: 'bg-green-50 dark:bg-green-950/40 border-green-200 dark:border-green-800', icon: '✓', iconBg: 'bg-green-500', title: 'No redirects — direct response', titleCl: 'text-green-700 dark:text-green-300' }
        }
        return { bg: 'bg-green-50 dark:bg-green-950/40 border-green-200 dark:border-green-800', icon: '✓', iconBg: 'bg-green-500', title: `${redirectCount} redirect${redirectCount > 1 ? 's' : ''} — ends OK`, titleCl: 'text-green-700 dark:text-green-300' }
    }
    if (last.status >= 300 && last.status < 400) {
        return { bg: 'bg-amber-50 dark:bg-amber-950/40 border-amber-200 dark:border-amber-800', icon: '→', iconBg: 'bg-amber-500', title: 'Redirect chain not resolved', titleCl: 'text-amber-700 dark:text-amber-300' }
    }
    return { bg: 'bg-rose-50 dark:bg-rose-950/40 border-rose-200 dark:border-rose-800', icon: '✗', iconBg: 'bg-rose-500', title: `Ends with HTTP ${last.status}`, titleCl: 'text-rose-700 dark:text-rose-300' }
})

// ── Init ─────────────────────────────────────────────────────────────────

onMounted(() => {
    if (props.initialUrl) runCheck()
})
</script>

<template>
    <Head title="Redirect Checker" />
    <AppLayout>
        <div class="max-w-2xl mx-auto px-4 sm:px-6 py-12">

            <!-- Hero -->
            <div class="text-center mb-8">
                <h1 class="text-3xl font-bold text-gray-900 dark:text-white tracking-tight">
                    Redirect Checker
                </h1>
                <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                    Trace the full redirect chain for any URL — 301 vs 302, headers, and final destination.
                </p>
            </div>

            <!-- Form -->
            <form @submit.prevent="runCheck" class="space-y-3 mb-8">
                <div class="flex gap-2">
                    <input
                        v-model="urlInput"
                        type="text"
                        placeholder="https://example.com"
                        autocomplete="off"
                        spellcheck="false"
                        :disabled="loading"
                        class="ui-input flex-1 px-4 py-3 shadow-card dark:bg-gray-900"
                    />
                    <button
                        type="submit"
                        :disabled="loading || !urlInput.trim()"
                        class="ui-btn ui-btn-primary px-5 py-3"
                    >
                        <svg v-if="loading" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"/>
                        </svg>
                        <svg v-else class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                        </svg>
                        {{ loading ? 'Checking…' : 'Check' }}
                    </button>
                </div>

                <!-- User-agent selector -->
                <div class="flex items-center gap-2">
                    <label class="text-xs text-gray-500 dark:text-gray-400 shrink-0 font-medium">User-Agent:</label>
                    <select
                        v-model="selectedAgent"
                        :disabled="loading"
                        class="ui-input flex-1 min-w-0 px-3 py-2 rounded-lg text-xs shadow-card truncate dark:bg-gray-900"
                    >
                        <template v-for="(opts, group) in groupedAgents" :key="group">
                            <optgroup :label="group">
                                <option v-for="opt in opts" :key="opt.key" :value="opt.key">
                                    {{ opt.label }}
                                </option>
                            </optgroup>
                        </template>
                    </select>
                </div>
            </form>

            <!-- Error -->
            <div v-if="error" class="mb-6 bg-red-50 dark:bg-red-950/40 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-400 rounded-xl px-4 py-3 text-sm">
                {{ error }}
            </div>

            <!-- Results -->
            <div v-if="hops.length" class="space-y-4">

                <!-- Verdict -->
                <div v-if="verdictConfig" :class="['border rounded-2xl p-5 flex items-center gap-4', verdictConfig.bg]">
                    <div :class="['w-12 h-12 rounded-full flex items-center justify-center text-white text-xl font-bold shrink-0 shadow', verdictConfig.iconBg]">
                        {{ verdictConfig.icon }}
                    </div>
                    <div>
                        <p :class="['text-base font-bold', verdictConfig.titleCl]">{{ verdictConfig.title }}</p>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                            {{ hops.length }} hop{{ hops.length !== 1 ? 's' : '' }} traced
                        </p>
                    </div>
                </div>

                <!-- Redirect chain -->
                <div class="ui-card overflow-hidden">

                    <div v-for="(hop, index) in hops" :key="index">

                        <!-- Connector arrow between hops -->
                        <div v-if="index > 0" class="flex items-center gap-2 px-4 py-1.5 bg-gray-50 dark:bg-gray-900/60 border-y border-gray-100 dark:border-gray-800">
                            <div class="flex items-center gap-1 text-gray-400 dark:text-gray-600 text-xs">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 14l-7 7m0 0l-7-7m7 7V3"/>
                                </svg>
                                <span>redirect</span>
                            </div>
                        </div>

                        <!-- Hop card -->
                        <div>
                            <!-- Hop header (always visible) -->
                            <button
                                type="button"
                                @click="toggleHop(index)"
                                class="w-full flex items-start gap-3 px-4 py-3.5 text-left hover:bg-gray-50 dark:hover:bg-gray-800/50 transition-colors group"
                            >
                                <!-- Step number -->
                                <span class="w-6 h-6 rounded-full bg-gray-100 dark:bg-gray-800 text-gray-500 dark:text-gray-400 text-xs font-bold flex items-center justify-center shrink-0 mt-0.5">
                                    {{ index + 1 }}
                                </span>

                                <div class="flex-1 min-w-0">
                                    <!-- URL -->
                                    <p class="text-sm font-mono text-gray-900 dark:text-white break-all leading-snug">
                                        {{ hop.url }}
                                    </p>
                                    <!-- Status -->
                                    <div class="flex items-center gap-2 mt-1.5">
                                        <span :class="['inline-flex items-center px-2 py-0.5 rounded-md border text-xs font-bold font-mono', statusBadgeClass(hop.status)]">
                                            {{ hop.status }}
                                        </span>
                                        <span class="text-xs text-gray-500 dark:text-gray-400">{{ hop.status_text }}</span>
                                    </div>
                                </div>

                                <!-- Expand toggle -->
                                <svg
                                    class="w-4 h-4 text-gray-400 shrink-0 mt-1 transition-transform"
                                    :class="expandedHops.has(index) ? 'rotate-180' : ''"
                                    fill="none" stroke="currentColor" viewBox="0 0 24 24"
                                >
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                                </svg>
                            </button>

                            <!-- Expanded headers -->
                            <div v-if="expandedHops.has(index) && hop.headers.length" class="border-t border-gray-100 dark:border-gray-800">
                                <!-- Terminal-style header dump -->
                                <div class="bg-gray-950 px-4 py-3 font-mono text-xs leading-relaxed overflow-x-auto">
                                    <p class="text-emerald-400 mb-2">>>> {{ hop.url }}</p>
                                    <p class="text-gray-500 mb-1">──────────────────────────────────────────</p>
                                    <p class="text-emerald-300 font-semibold mb-2">{{ hop.status }} {{ hop.status_text }}</p>
                                    <p class="text-gray-500 mb-3">──────────────────────────────────────────</p>
                                    <div v-for="h in hop.headers" :key="h.name + h.value" class="flex gap-0">
                                        <span class="text-sky-400 shrink-0">{{ h.name }}:</span>
                                        <span class="text-gray-200 ml-2 break-all">{{ h.value }}</span>
                                    </div>
                                </div>
                            </div>
                            <div v-else-if="expandedHops.has(index)" class="border-t border-gray-100 dark:border-gray-800 px-4 py-3 text-xs text-gray-400 italic">
                                No response headers captured.
                            </div>
                        </div>

                    </div>
                </div>

                <!-- Info box -->
                <div class="ui-panel-muted dark:bg-gray-900/60 px-4 py-3.5 text-xs text-gray-600 dark:text-gray-400 space-y-1.5">
                    <p class="font-semibold text-gray-700 dark:text-gray-300">How this checker works</p>
                    <p>Each hop is fetched without auto-following redirects, so the full chain is captured individually.</p>
                    <p>Click any hop to expand its raw HTTP response headers. The user-agent sent can be changed to test bot vs. browser redirect differences.</p>
                </div>

            </div>

            <!-- Empty state -->
            <div v-else-if="!loading && !error" class="text-center py-12 text-gray-400 dark:text-gray-600">
                <svg class="w-12 h-12 mx-auto mb-3 opacity-40" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7"/>
                </svg>
                <p class="text-sm">Enter a URL to trace its redirect chain</p>
            </div>

        </div>
    </AppLayout>
</template>
