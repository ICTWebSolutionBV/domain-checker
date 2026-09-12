<script setup>
import { ref, computed } from 'vue'
import { Search, Loader2 } from '@lucide/vue'

const props = defineProps({
    isChecking: Boolean,
})

const emit = defineEmits(['check', 'reset'])

const textarea = ref('')

const LIMIT = 50

const enteredDomains = computed(() => {
    const lines = textarea.value
        .split('\n')
        .map((l) =>
            l
                .trim()
                .toLowerCase()
                .replace(/^https?:\/\//i, '')
                .replace(/^www\./i, ''),
        )
        .filter((l) => l.length > 0 && l.includes('.'))
    return [...new Set(lines)]
})

const parsedDomains = computed(() => enteredDomains.value.slice(0, LIMIT))

// Pasting 80 domains used to drop 30 of them with nothing but a count to
// hint at it.
const discardedCount = computed(() => Math.max(0, enteredDomains.value.length - LIMIT))

function handleCheck() {
    if (!parsedDomains.value.length || props.isChecking) return
    emit('check', parsedDomains.value)
}

function handleReset() {
    textarea.value = ''
    emit('reset')
}

function handleKeydown(e) {
    if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') handleCheck()
}
</script>

<template>
    <div class="max-w-2xl mx-auto w-full">
        <label for="bulk-domains" class="sr-only">Domains to check, one per line</label>
        <textarea
            id="bulk-domains"
            v-model="textarea"
            @keydown="handleKeydown"
            rows="5"
            placeholder="example.com&#10;mysite.nl&#10;coolbrand.io"
            autocomplete="off"
            spellcheck="false"
            class="ui-input px-4 py-3.5 rounded-2xl font-mono shadow-card resize-none dark:bg-gray-900"
        />
        <div class="flex items-center justify-between mt-2">
            <p class="text-xs text-gray-500 dark:text-gray-400">
                One domain per line · up to 50 · Ctrl+Enter to check
                <span v-if="parsedDomains.length" class="text-gray-600 dark:text-gray-400 font-medium">
                    · {{ parsedDomains.length }} {{ parsedDomains.length === 1 ? 'domain' : 'domains' }} entered
                </span>
            </p>
            <button
                v-if="textarea.trim()"
                type="button"
                @click="handleReset"
                class="text-xs text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 transition-colors"
            >
                Clear
            </button>
        </div>
        <p v-if="discardedCount" role="alert" class="mt-2 text-xs font-medium text-amber-800 dark:text-amber-300">
            {{ discardedCount }} of {{ enteredDomains.length }} domains will not be checked — the limit is
            {{ LIMIT }} per run.
        </p>
        <button
            type="button"
            @click="handleCheck"
            :disabled="isChecking || !parsedDomains.length"
            class="ui-btn ui-btn-primary mt-3 w-full px-6 py-3.5 rounded-2xl"
        >
            <Loader2 v-if="isChecking" class="w-4 h-4 animate-spin" />
            <Search v-else class="w-4 h-4" />
            {{ isChecking ? 'Checking…' : 'Check domains' }}
        </button>
    </div>
</template>
