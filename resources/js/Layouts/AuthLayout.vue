<script setup>
import { Globe } from 'lucide-vue-next'
import { Link } from '@inertiajs/vue3'

defineProps({
    title: String,
    subtitle: String,
})
</script>

<template>
    <div class="min-h-screen flex flex-col items-center justify-center bg-gradient-to-br from-slate-900 via-gray-900 to-indigo-950 px-4">
        <div class="w-full max-w-sm flex-1 flex flex-col justify-center">
            <!-- Logo -->
            <div class="mb-8 text-center">
                <Link :href="route('home')" class="inline-flex items-center gap-3 group">
                    <div class="w-12 h-12 bg-indigo-600 rounded-2xl flex items-center justify-center shadow-lg shadow-indigo-600/30 group-hover:bg-indigo-500 transition-colors">
                        <Globe class="w-6 h-6 text-white" />
                    </div>
                    <div class="text-left">
                        <h1 class="text-xl font-bold text-white leading-none">Domain Checker</h1>
                        <p class="text-xs text-indigo-300 mt-0.5">by ICTWebSolution</p>
                    </div>
                </Link>
                <p v-if="subtitle" class="text-gray-400 text-sm mt-4">{{ subtitle }}</p>
            </div>

            <!-- Card -->
            <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl p-6 border border-gray-200 dark:border-gray-800">
                <h2 v-if="title" class="text-lg font-semibold text-gray-900 dark:text-white mb-5">{{ title }}</h2>
                <slot />
            </div>
        </div>

        <footer class="py-4">
            <p class="text-center text-xs text-gray-600">
                &copy; {{ new Date().getFullYear() }} ICTWebSolution B.V.
            </p>
        </footer>
    </div>
</template>
