<script setup>
import { ref, computed } from 'vue'
import { Search, Loader2 } from 'lucide-vue-next'

const props = defineProps({
    isChecking: Boolean,
})

const emit = defineEmits(['check', 'reset'])

const textarea = ref('')

const parsedDomains = computed(() => {
    const lines = textarea.value
        .split('\n')
        .map(l => l.trim().toLowerCase()
            .replace(/^https?:\/\//i, '')
            .replace(/^www\./i, '')
        )
        .filter(l => l.length > 0 && l.includes('.'))
    return [...new Set(lines)].slice(0, 50)
})

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
        <textarea
            v-model="textarea"
            @keydown="handleKeydown"
            rows="5"
            placeholder="example.com&#10;mysite.nl&#10;coolbrand.io"
            autocomplete="off"
            spellcheck="false"
            class="w-full px-4 py-3.5 font-mono text-sm bg-white dark:bg-gray-900 border border-gray-300 dark:border-gray-700 rounded-2xl text-gray-900 dark:text-white placeholder-gray-400 outline-none transition focus:ring-2 focus:ring-indigo-500 focus:border-transparent shadow-sm resize-none"
        />
        <div class="flex items-center justify-between mt-2">
            <p class="text-xs text-gray-400 dark:text-gray-500">
                One domain per line · up to 50
                <span v-if="parsedDomains.length" class="text-gray-600 dark:text-gray-400 font-medium">
                    · {{ parsedDomains.length }} {{ parsedDomains.length === 1 ? 'domain' : 'domains' }} entered
                </span>
            </p>
            <button
                v-if="textarea.trim()"
                @click="handleReset"
                class="text-xs text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 transition-colors"
            >
                Clear
            </button>
        </div>
        <button
            @click="handleCheck"
            :disabled="isChecking || !parsedDomains.length"
            class="mt-3 w-full px-6 py-3.5 bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 disabled:cursor-not-allowed text-white font-semibold rounded-2xl transition-colors text-sm shadow-sm shadow-indigo-600/20 flex items-center justify-center gap-2"
        >
            <Loader2 v-if="isChecking" class="w-4 h-4 animate-spin" />
            <Search v-else class="w-4 h-4" />
            {{ isChecking ? 'Checking…' : 'Check domains' }}
        </button>
    </div>
</template>
