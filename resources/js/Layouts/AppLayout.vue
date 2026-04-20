<script setup>
import { computed } from 'vue'
import { Link, usePage } from '@inertiajs/vue3'
import { useTheme } from '@/composables/useTheme'
import { Sun, Moon, SunMoon, Globe, LogIn, Settings, LogOut } from 'lucide-vue-next'

const page = usePage()
const auth = computed(() => page.props.auth)
const flash = computed(() => page.props.flash)

const { theme, cycleTheme } = useTheme()

const themeIcon = computed(() => {
    if (theme.value === 'dark') return Moon
    if (theme.value === 'light') return Sun
    return SunMoon
})

const themeLabel = computed(() => {
    if (theme.value === 'dark') return 'Dark'
    if (theme.value === 'light') return 'Light'
    return 'Auto'
})
</script>

<template>
    <div class="min-h-screen text-gray-900 dark:text-gray-100 transition-colors duration-200">
        <!-- Dot grid background -->
        <div class="fixed inset-0 -z-10 pointer-events-none overflow-hidden bg-white dark:bg-gray-950" aria-hidden="true">
            <div class="absolute inset-0 dot-grid" />
            <div class="absolute inset-x-0 bottom-0 h-96 bg-gradient-to-t from-white dark:from-gray-950 to-transparent" />
            <div class="absolute inset-x-0 top-0 h-32 bg-gradient-to-b from-white dark:from-gray-950 to-transparent" />
        </div>
        <!-- Navbar -->
        <nav class="border-b border-gray-200 dark:border-gray-800 bg-white/80 dark:bg-gray-950/80 backdrop-blur-sm sticky top-0 z-50">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-14 flex items-center justify-between">
                <!-- Logo -->
                <Link :href="route('home')" class="flex items-center gap-2.5 font-semibold text-gray-900 dark:text-white hover:opacity-80 transition-opacity">
                    <div class="w-7 h-7 bg-indigo-600 rounded-lg flex items-center justify-center">
                        <Globe class="w-4 h-4 text-white" />
                    </div>
                    <span class="text-sm">Domain Checker</span>
                </Link>

                <!-- Right side -->
                <div class="flex items-center gap-2">
                    <!-- Theme toggle -->
                    <button
                        @click="cycleTheme"
                        class="flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg text-xs font-medium text-gray-500 dark:text-gray-400 hover:text-gray-900 dark:hover:text-white hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors"
                        :title="`Theme: ${themeLabel}`"
                    >
                        <component :is="themeIcon" class="w-4 h-4" />
                        <span class="hidden sm:inline">{{ themeLabel }}</span>
                    </button>

                    <template v-if="auth.user">
                        <Link
                            :href="route('settings')"
                            class="flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg text-xs font-medium text-gray-500 dark:text-gray-400 hover:text-gray-900 dark:hover:text-white hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors"
                        >
                            <Settings class="w-4 h-4" />
                            <span class="hidden sm:inline">Settings</span>
                        </Link>
                        <Link
                            :href="route('logout')"
                            method="post"
                            as="button"
                            class="flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg text-xs font-medium text-gray-500 dark:text-gray-400 hover:text-gray-900 dark:hover:text-white hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors"
                        >
                            <LogOut class="w-4 h-4" />
                            <span class="hidden sm:inline">Logout</span>
                        </Link>
                    </template>
                    <template v-else>
                        <Link
                            :href="route('login')"
                            class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium bg-indigo-600 hover:bg-indigo-700 text-white transition-colors"
                        >
                            <LogIn class="w-3.5 h-3.5" />
                            Login
                        </Link>
                    </template>
                </div>
            </div>
        </nav>

        <!-- Flash messages -->
        <div v-if="flash.status" class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pt-4">
            <div class="bg-green-50 dark:bg-green-950 border border-green-200 dark:border-green-800 text-green-800 dark:text-green-200 rounded-xl px-4 py-3 text-sm">
                {{ flash.status }}
            </div>
        </div>
        <div v-if="flash.error" class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pt-4">
            <div class="bg-red-50 dark:bg-red-950 border border-red-200 dark:border-red-800 text-red-800 dark:text-red-200 rounded-xl px-4 py-3 text-sm">
                {{ flash.error }}
            </div>
        </div>

        <!-- Page content -->
        <main>
            <slot />
        </main>

        <!-- Footer -->
        <footer class="border-t border-gray-200 dark:border-gray-800 mt-16">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
                <p class="text-center text-xs text-gray-400 dark:text-gray-600">
                    &copy; {{ new Date().getFullYear() }} <a href="https://ictwebsolution.nl" target="_blank" rel="noopener noreferrer" class="hover:text-gray-600 dark:hover:text-gray-400 transition-colors">ICTWebSolution B.V.</a> — All Rights Reserved
                </p>
            </div>
        </footer>
    </div>
</template>
