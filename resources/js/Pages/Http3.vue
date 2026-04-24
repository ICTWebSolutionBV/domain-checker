<script setup>
import { ref, computed, onMounted } from 'vue'
import { Head } from '@inertiajs/vue3'
import AppLayout from '@/Layouts/AppLayout.vue'

const props = defineProps({
    initialHost: { type: String, default: '' },
})

// ── State ────────────────────────────────────────────────────────────────

const hostInput  = ref(props.initialHost || '')
const displayUrl = ref('')
const checks     = ref([])   // { key, status, label, detail }
const verdict    = ref(null) // { result, h3, summary }
const isChecking = ref(false)
const error      = ref('')

let abortController = null

// ── Check keys in display order ──────────────────────────────────────────

const CHECK_ORDER = ['dns', 'ipv6', 'https', 'tls13', 'http2', 'altsvc', 'http3']

const orderedChecks = computed(() =>
    CHECK_ORDER
        .map(key => checks.value.find(c => c.key === key))
        .filter(Boolean)
)

// ── Run check ────────────────────────────────────────────────────────────

async function runCheck() {
    const host = hostInput.value.trim()
    if (!host || isChecking.value) return

    if (abortController) abortController.abort()
    abortController = new AbortController()

    checks.value  = []
    verdict.value = null
    displayUrl.value = ''
    error.value   = ''
    isChecking.value = true

    try {
        const response = await fetch(
            route('http3.check') + '?host=' + encodeURIComponent(host),
            {
                signal: abortController.signal,
                headers: { Accept: 'text/event-stream' },
            }
        )

        if (response.status === 429) {
            error.value = 'Too many requests — please wait a moment before checking again.'
            isChecking.value = false
            return
        }
        if (!response.ok) {
            error.value = 'Something went wrong. Please try again.'
            isChecking.value = false
            return
        }

        const reader  = response.body.getReader()
        const decoder = new TextDecoder()
        let   buffer  = ''

        while (true) {
            const { done, value } = await reader.read()
            if (done) break

            buffer += decoder.decode(value, { stream: true })
            const parts = buffer.split('\n\n')
            buffer = parts.pop()

            for (const part of parts) {
                const dataLine = part.split('\n').find(l => l.startsWith('data: '))
                if (!dataLine) continue
                try {
                    const ev = JSON.parse(dataLine.slice(6))
                    handleEvent(ev)
                } catch {
                    // ignore malformed
                }
            }
        }
    } catch (err) {
        if (err.name === 'AbortError') return
        error.value = 'Connection error. Please try again.'
    } finally {
        isChecking.value = false
    }
}

function handleEvent(ev) {
    if (ev.type === 'host') {
        displayUrl.value = ev.url
        return
    }
    if (ev.type === 'check') {
        const idx = checks.value.findIndex(c => c.key === ev.key)
        if (idx >= 0) {
            checks.value[idx] = ev
        } else {
            checks.value.push(ev)
        }
        return
    }
    if (ev.type === 'done') {
        verdict.value    = ev
        isChecking.value = false
        return
    }
    if (ev.error) {
        error.value      = ev.error
        isChecking.value = false
    }
}

function submitForm() {
    runCheck()
}

// Run immediately if a host was pre-filled via query param
onMounted(() => {
    if (props.initialHost) runCheck()
})

// ── UI helpers ───────────────────────────────────────────────────────────

const verdictConfig = computed(() => {
    if (!verdict.value) return null
    const r = verdict.value.result
    if (r === 'supported') return {
        bg:      'bg-green-50 dark:bg-green-950/40 border-green-200 dark:border-green-800',
        icon:    '✓',
        iconBg:  'bg-green-500',
        title:   'HTTP/3 Supported',
        titleCl: 'text-green-700 dark:text-green-300',
    }
    if (r === 'not_supported') return {
        bg:      'bg-red-50 dark:bg-red-950/40 border-red-200 dark:border-red-800',
        icon:    '✗',
        iconBg:  'bg-red-500',
        title:   'HTTP/3 Not Supported',
        titleCl: 'text-red-700 dark:text-red-300',
    }
    return {
        bg:      'bg-gray-50 dark:bg-gray-900/60 border-gray-200 dark:border-gray-700',
        icon:    '!',
        iconBg:  'bg-gray-400',
        title:   'Check Failed',
        titleCl: 'text-gray-700 dark:text-gray-300',
    }
})

function statusIcon(status) {
    return {
        pass: '✓',
        fail: '✗',
        warn: '⚠',
        info: 'ℹ',
    }[status] ?? '·'
}

function statusClass(status) {
    return {
        pass: 'bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-400',
        fail: 'bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-400',
        warn: 'bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-400',
        info: 'bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-400',
    }[status] ?? 'bg-gray-100 dark:bg-gray-800 text-gray-500 dark:text-gray-400'
}

// Pending check placeholders shown while checking is in progress
const pendingKeys = computed(() => {
    if (!isChecking.value) return []
    const done = new Set(checks.value.map(c => c.key))
    return CHECK_ORDER.filter(k => !done.has(k))
})
</script>

<template>
    <Head title="HTTP/3 Checker" />
    <AppLayout>
        <div class="max-w-2xl mx-auto px-4 sm:px-6 py-12">

            <!-- Hero heading -->
            <div class="text-center mb-8">
                <h1 class="text-3xl font-bold text-gray-900 dark:text-white tracking-tight">
                    HTTP/3 Checker
                </h1>
                <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                    Check whether a website supports HTTP/3 (QUIC) — the next generation of the web protocol.
                </p>
            </div>

            <!-- Search bar -->
            <form @submit.prevent="submitForm" class="flex gap-2 mb-8">
                <div class="flex-1 relative">
                    <span class="absolute left-3.5 top-1/2 -translate-y-1/2 text-gray-400 text-sm select-none pointer-events-none">https://</span>
                    <input
                        v-model="hostInput"
                        type="text"
                        placeholder="example.com"
                        autocomplete="off"
                        spellcheck="false"
                        :disabled="isChecking"
                        class="w-full pl-16 pr-4 py-3 bg-white dark:bg-gray-900 border border-gray-300 dark:border-gray-700 rounded-xl text-gray-900 dark:text-white placeholder-gray-400 text-sm shadow-sm focus:ring-2 focus:ring-indigo-500 focus:border-transparent outline-none transition disabled:opacity-60"
                    />
                </div>
                <button
                    type="submit"
                    :disabled="isChecking || !hostInput.trim()"
                    class="px-5 py-3 bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 text-white font-semibold rounded-xl transition-colors text-sm shadow-sm whitespace-nowrap flex items-center gap-2"
                >
                    <svg v-if="isChecking" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"/>
                    </svg>
                    <svg v-else class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                    </svg>
                    {{ isChecking ? 'Checking…' : 'Check' }}
                </button>
            </form>

            <!-- Error -->
            <div v-if="error" class="mb-6 bg-red-50 dark:bg-red-950/40 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-400 rounded-xl px-4 py-3 text-sm">
                {{ error }}
            </div>

            <!-- Results -->
            <div v-if="checks.length || verdict" class="space-y-4">

                <!-- Checked URL -->
                <p v-if="displayUrl" class="text-xs text-gray-400 dark:text-gray-500 text-center">
                    Checking <span class="font-mono text-gray-600 dark:text-gray-400">{{ displayUrl }}</span>
                </p>

                <!-- Verdict card -->
                <transition
                    enter-active-class="transition-all duration-500 ease-out"
                    enter-from-class="opacity-0 -translate-y-2"
                    enter-to-class="opacity-100 translate-y-0"
                >
                    <div v-if="verdict" :class="['border rounded-2xl p-5 flex items-center gap-4', verdictConfig.bg]">
                        <div :class="['w-12 h-12 rounded-full flex items-center justify-center text-white text-xl font-bold shrink-0 shadow', verdictConfig.iconBg]">
                            {{ verdictConfig.icon }}
                        </div>
                        <div>
                            <p :class="['text-lg font-bold', verdictConfig.titleCl]">{{ verdictConfig.title }}</p>
                            <p class="text-sm text-gray-600 dark:text-gray-400 mt-0.5">{{ verdict.summary }}</p>
                        </div>
                    </div>
                </transition>

                <!-- Checks list -->
                <div class="bg-white dark:bg-gray-900 border border-gray-200 dark:border-gray-800 rounded-2xl overflow-hidden">
                    <div class="divide-y divide-gray-100 dark:divide-gray-800">

                        <!-- Completed checks -->
                        <transition-group
                            enter-active-class="transition-all duration-300 ease-out"
                            enter-from-class="opacity-0 translate-x-2"
                            enter-to-class="opacity-100 translate-x-0"
                        >
                            <div v-for="check in orderedChecks" :key="check.key"
                                class="flex items-start gap-3 px-4 py-3.5">
                                <span :class="['w-6 h-6 rounded-full flex items-center justify-center text-xs font-bold shrink-0 mt-0.5', statusClass(check.status)]">
                                    {{ statusIcon(check.status) }}
                                </span>
                                <div class="min-w-0 flex-1">
                                    <p class="text-sm font-medium text-gray-900 dark:text-white">{{ check.label }}</p>
                                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5 break-all">{{ check.detail }}</p>
                                </div>
                            </div>
                        </transition-group>

                        <!-- Pending placeholders -->
                        <div v-for="key in pendingKeys" :key="'pending-' + key"
                            class="flex items-center gap-3 px-4 py-3.5 opacity-40">
                            <span class="w-6 h-6 rounded-full bg-gray-100 dark:bg-gray-800 flex items-center justify-center shrink-0">
                                <svg class="w-3 h-3 animate-spin text-gray-400" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"/>
                                </svg>
                            </span>
                            <div class="h-3 bg-gray-100 dark:bg-gray-800 rounded w-32 animate-pulse" />
                        </div>

                    </div>
                </div>

                <!-- What is HTTP/3 info box -->
                <div v-if="verdict" class="bg-gray-50 dark:bg-gray-900/60 border border-gray-200 dark:border-gray-800 rounded-xl px-4 py-3.5 text-xs text-gray-500 dark:text-gray-400 space-y-1.5">
                    <p class="font-semibold text-gray-700 dark:text-gray-300">How this checker works</p>
                    <p><span class="font-medium text-gray-600 dark:text-gray-400">DNS</span> — Resolves A (IPv4) and AAAA (IPv6) records.</p>
                    <p><span class="font-medium text-gray-600 dark:text-gray-400">TLS 1.3</span> — HTTP/3 requires TLS 1.3; older TLS versions block it.</p>
                    <p><span class="font-medium text-gray-600 dark:text-gray-400">Alt-Svc header</span> — The standard way servers advertise HTTP/3 support (e.g. <code class="font-mono bg-gray-100 dark:bg-gray-800 px-1 rounded">h3=":443"</code>).</p>
                    <p><span class="font-medium text-gray-600 dark:text-gray-400">HTTP/3 Direct</span> — Attempts an actual QUIC connection using curl (requires curl compiled with QUIC/HTTP3 support).</p>
                </div>

            </div>

            <!-- Empty state -->
            <div v-else-if="!isChecking" class="text-center py-12 text-gray-400 dark:text-gray-600">
                <svg class="w-12 h-12 mx-auto mb-3 opacity-40" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                </svg>
                <p class="text-sm">Enter a hostname to check HTTP/3 support</p>
            </div>

        </div>
    </AppLayout>
</template>
