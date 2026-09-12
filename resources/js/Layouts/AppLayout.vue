<script setup>
import { computed, ref, onMounted, onUnmounted } from 'vue'
import { Link, usePage, router } from '@inertiajs/vue3'
import { useTheme } from '@/composables/useTheme'
import Dialog from '@/Components/Dialog.vue'
import NavLink from '@/Components/NavLink.vue'
import {
    Sun,
    Moon,
    SunMoon,
    Globe,
    Globe2,
    LogIn,
    Settings,
    LogOut,
    Users,
    Zap,
    MapPin,
    ArrowRightLeft,
    Route,
    Wifi,
    Menu,
    X,
} from '@lucide/vue'

const page = usePage()
const auth = computed(() => page.props.auth)
const flash = computed(() => page.props.flash)

const { theme, cycleTheme } = useTheme()

// The drawer keeps its own, larger styling; it just needed to say which page
// it is on -- both to a screen reader and, through NavLink, visibly.
const isCurrent = (name) => {
    try {
        return route().current(name)
    } catch {
        return false
    }
}

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

const menuOpen = ref(false)

let removeNavListener
onMounted(() => {
    removeNavListener = router.on('navigate', () => {
        menuOpen.value = false
    })
})
onUnmounted(() => {
    removeNavListener?.()
})
</script>

<template>
    <div class="min-h-screen text-gray-900 dark:text-gray-100 transition-colors duration-200">
        <!-- Dot grid background -->
        <div class="fixed inset-0 -z-10 pointer-events-none overflow-hidden bg-canvas" aria-hidden="true">
            <div class="absolute inset-0 dot-grid" />
            <div class="absolute inset-x-0 bottom-0 h-96 bg-gradient-to-t from-canvas to-transparent" />
            <div class="absolute inset-x-0 top-0 h-32 bg-gradient-to-b from-canvas to-transparent" />
        </div>
        <a
            href="#main"
            class="sr-only focus:not-sr-only focus:fixed focus:top-2 focus:left-2 focus:z-[60] focus:px-3 focus:py-2 focus:rounded-xl focus:bg-indigo-600 focus:text-white focus:text-sm focus:font-semibold"
        >
            Skip to content
        </a>
        <!-- Navbar -->
        <nav
            aria-label="Main"
            class="border-b border-hairline bg-white/85 dark:bg-gray-950/80 backdrop-blur-md sticky top-0 z-50 shadow-[0_1px_2px_0_rgb(15_23_42_/_0.04)] dark:shadow-none"
        >
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-14 flex items-center justify-between">
                <!-- Logo -->
                <Link
                    :href="route('home')"
                    class="flex items-center gap-2.5 font-semibold text-gray-900 dark:text-white hover:opacity-80 transition-opacity"
                >
                    <div class="w-7 h-7 bg-indigo-600 rounded-lg flex items-center justify-center">
                        <Globe class="w-4 h-4 text-white" />
                    </div>
                    <span class="text-sm">Domain Checker</span>
                </Link>

                <!-- Desktop nav -->
                <div class="hidden sm:flex items-center gap-2">
                    <NavLink route-name="http3" label="HTTP/3">
                        <Zap class="w-4 h-4" />
                    </NavLink>
                    <NavLink route-name="my-ip" label="My IP">
                        <Wifi class="w-4 h-4" />
                    </NavLink>
                    <NavLink route-name="ip" label="IP Lookup">
                        <MapPin class="w-4 h-4" />
                    </NavLink>
                    <NavLink route-name="redirect" label="Redirects">
                        <Route class="w-4 h-4" />
                    </NavLink>
                    <NavLink route-name="dns-bulk" label="DNS">
                        <Globe2 class="w-4 h-4" />
                    </NavLink>
                    <Link
                        :href="route('transfer')"
                        class="relative flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold text-white bg-emerald-700 hover:bg-emerald-800 shadow-sm shadow-emerald-700/30 hover:shadow-emerald-700/50 transition-all"
                        title="Transfer your domains to us"
                    >
                        <ArrowRightLeft class="w-4 h-4" />
                        <span>Transfer</span>
                        <span
                            class="absolute -top-1 -right-1 w-2 h-2 bg-amber-400 rounded-full ring-2 ring-white dark:ring-gray-950 animate-pulse"
                            aria-hidden="true"
                        ></span>
                    </Link>
                    <button
                        @click="cycleTheme"
                        class="flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg text-xs font-medium text-gray-500 dark:text-gray-400 hover:text-gray-900 dark:hover:text-white hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors"
                        :title="`Theme: ${themeLabel}`"
                    >
                        <component :is="themeIcon" class="w-4 h-4" />
                        <span>{{ themeLabel }}</span>
                    </button>
                    <template v-if="auth.user">
                        <Link
                            v-if="auth.user.is_admin"
                            :href="route('admin.users.index')"
                            class="flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg text-xs font-medium text-gray-500 dark:text-gray-400 hover:text-gray-900 dark:hover:text-white hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors"
                        >
                            <Users class="w-4 h-4" />
                            <span>Users</span>
                        </Link>
                        <Link
                            :href="route('settings')"
                            class="flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg text-xs font-medium text-gray-500 dark:text-gray-400 hover:text-gray-900 dark:hover:text-white hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors"
                        >
                            <Settings class="w-4 h-4" />
                            <span>Settings</span>
                        </Link>
                        <Link
                            :href="route('logout')"
                            method="post"
                            as="button"
                            class="flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg text-xs font-medium text-gray-500 dark:text-gray-400 hover:text-gray-900 dark:hover:text-white hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors"
                        >
                            <LogOut class="w-4 h-4" />
                            <span>Logout</span>
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

                <!-- Mobile hamburger -->
                <button
                    @click="menuOpen = !menuOpen"
                    class="sm:hidden flex items-center justify-center w-10 h-10 rounded-xl text-gray-500 dark:text-gray-400 hover:text-gray-900 dark:hover:text-white hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors"
                    :aria-label="menuOpen ? 'Close menu' : 'Open menu'"
                    :aria-expanded="menuOpen"
                    aria-controls="mobile-drawer"
                >
                    <X v-if="menuOpen" class="w-5 h-5" />
                    <Menu v-else class="w-5 h-5" />
                </button>
            </div>
        </nav>

        <!-- Mobile drawer backdrop -->
        <Transition
            enter-active-class="transition-opacity duration-200"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="transition-opacity duration-200"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div
                v-if="menuOpen"
                class="fixed inset-0 bg-black/40 backdrop-blur-sm z-40 sm:hidden"
                @click="menuOpen = false"
                aria-hidden="true"
            />
        </Transition>

        <!-- Mobile drawer (slides in from right) -->
        <Transition
            enter-active-class="transition-transform duration-250 ease-out"
            enter-from-class="translate-x-full"
            enter-to-class="translate-x-0"
            leave-active-class="transition-transform duration-200 ease-in"
            leave-from-class="translate-x-0"
            leave-to-class="translate-x-full"
        >
            <Dialog
                v-if="menuOpen"
                id="mobile-drawer"
                label="Site menu"
                class="fixed top-0 right-0 h-full w-72 bg-white dark:bg-gray-900 z-50 sm:hidden shadow-2xl flex flex-col"
                @close="menuOpen = false"
            >
                <!-- Drawer header -->
                <div class="flex items-center justify-between px-5 h-14 border-b border-hairline shrink-0">
                    <Link
                        :href="route('home')"
                        class="flex items-center gap-2.5 font-semibold text-gray-900 dark:text-white"
                        @click="menuOpen = false"
                    >
                        <div class="w-7 h-7 bg-indigo-600 rounded-lg flex items-center justify-center">
                            <Globe class="w-4 h-4 text-white" />
                        </div>
                        <span class="text-sm">Domain Checker</span>
                    </Link>
                    <button
                        @click="menuOpen = false"
                        class="flex items-center justify-center w-9 h-9 rounded-xl text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors"
                        aria-label="Close menu"
                    >
                        <X class="w-5 h-5" />
                    </button>
                </div>

                <!-- Drawer nav items -->
                <nav class="flex-1 overflow-y-auto px-3 py-4 flex flex-col gap-1" aria-label="Site menu">
                    <Link
                        :href="route('http3')"
                        :aria-current="isCurrent('http3') ? 'page' : undefined"
                        class="flex items-center gap-3.5 px-4 py-3.5 rounded-xl text-sm font-medium text-gray-700 dark:text-gray-300 hover:text-gray-900 dark:hover:text-white hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors active:scale-[0.98]"
                    >
                        <Zap class="w-5 h-5 text-indigo-500 shrink-0" />
                        HTTP/3 Checker
                    </Link>
                    <Link
                        :href="route('my-ip')"
                        :aria-current="isCurrent('my-ip') ? 'page' : undefined"
                        class="flex items-center gap-3.5 px-4 py-3.5 rounded-xl text-sm font-medium text-gray-700 dark:text-gray-300 hover:text-gray-900 dark:hover:text-white hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors active:scale-[0.98]"
                    >
                        <Wifi class="w-5 h-5 text-indigo-500 shrink-0" />
                        My IP
                    </Link>
                    <Link
                        :href="route('ip')"
                        :aria-current="isCurrent('ip') ? 'page' : undefined"
                        class="flex items-center gap-3.5 px-4 py-3.5 rounded-xl text-sm font-medium text-gray-700 dark:text-gray-300 hover:text-gray-900 dark:hover:text-white hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors active:scale-[0.98]"
                    >
                        <MapPin class="w-5 h-5 text-indigo-500 shrink-0" />
                        IP Lookup
                    </Link>
                    <Link
                        :href="route('redirect')"
                        :aria-current="isCurrent('redirect') ? 'page' : undefined"
                        class="flex items-center gap-3.5 px-4 py-3.5 rounded-xl text-sm font-medium text-gray-700 dark:text-gray-300 hover:text-gray-900 dark:hover:text-white hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors active:scale-[0.98]"
                    >
                        <Route class="w-5 h-5 text-indigo-500 shrink-0" />
                        Redirect Checker
                    </Link>
                    <Link
                        :href="route('dns-bulk')"
                        :aria-current="isCurrent('dns-bulk') ? 'page' : undefined"
                        class="flex items-center gap-3.5 px-4 py-3.5 rounded-xl text-sm font-medium text-gray-700 dark:text-gray-300 hover:text-gray-900 dark:hover:text-white hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors active:scale-[0.98]"
                    >
                        <Globe2 class="w-5 h-5 text-indigo-500 shrink-0" />
                        Bulk DNS Lookup
                    </Link>

                    <!-- Transfer CTA -->
                    <Link
                        :href="route('transfer')"
                        class="relative flex items-center gap-3.5 px-4 py-3.5 rounded-xl text-sm font-semibold text-white bg-emerald-700 shadow-sm shadow-emerald-700/30 active:scale-[0.98] transition-transform mt-1"
                    >
                        <ArrowRightLeft class="w-5 h-5 shrink-0" />
                        Transfer Domains
                        <span
                            class="absolute top-2.5 right-3 w-2 h-2 bg-amber-400 rounded-full animate-pulse"
                            aria-hidden="true"
                        ></span>
                    </Link>

                    <div class="h-px bg-gray-200 dark:bg-gray-800 my-2" />

                    <!-- Theme toggle -->
                    <button
                        @click="cycleTheme"
                        class="flex items-center gap-3.5 px-4 py-3.5 rounded-xl text-sm font-medium text-gray-700 dark:text-gray-300 hover:text-gray-900 dark:hover:text-white hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors w-full text-left active:scale-[0.98]"
                    >
                        <component :is="themeIcon" class="w-5 h-5 text-gray-400 shrink-0" />
                        Theme: {{ themeLabel }}
                    </button>

                    <template v-if="auth.user">
                        <Link
                            v-if="auth.user.is_admin"
                            :href="route('admin.users.index')"
                            class="flex items-center gap-3.5 px-4 py-3.5 rounded-xl text-sm font-medium text-gray-700 dark:text-gray-300 hover:text-gray-900 dark:hover:text-white hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors active:scale-[0.98]"
                        >
                            <Users class="w-5 h-5 text-gray-400 shrink-0" />
                            Manage Users
                        </Link>
                        <Link
                            :href="route('settings')"
                            class="flex items-center gap-3.5 px-4 py-3.5 rounded-xl text-sm font-medium text-gray-700 dark:text-gray-300 hover:text-gray-900 dark:hover:text-white hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors active:scale-[0.98]"
                        >
                            <Settings class="w-5 h-5 text-gray-400 shrink-0" />
                            Settings
                        </Link>
                        <Link
                            :href="route('logout')"
                            method="post"
                            as="button"
                            class="flex items-center gap-3.5 px-4 py-3.5 rounded-xl text-sm font-medium text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-950/50 transition-colors w-full text-left active:scale-[0.98]"
                        >
                            <LogOut class="w-5 h-5 shrink-0" />
                            Logout
                        </Link>
                    </template>
                    <template v-else>
                        <Link
                            :href="route('login')"
                            class="flex items-center gap-3.5 px-4 py-3.5 rounded-xl text-sm font-semibold text-white bg-indigo-600 hover:bg-indigo-700 transition-colors active:scale-[0.98]"
                        >
                            <LogIn class="w-5 h-5 shrink-0" />
                            Login
                        </Link>
                    </template>
                </nav>
            </Dialog>
        </Transition>

        <!-- Flash messages -->
        <div v-if="flash.status || flash.success" class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pt-4">
            <div
                role="status"
                class="bg-emerald-50 dark:bg-emerald-950 border border-emerald-200 dark:border-emerald-800 text-emerald-800 dark:text-emerald-200 rounded-xl px-4 py-3 text-sm"
            >
                {{ flash.success || flash.status }}
            </div>
        </div>
        <div v-if="flash.error" class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pt-4">
            <div
                role="alert"
                class="bg-red-50 dark:bg-red-950 border border-red-200 dark:border-red-800 text-red-800 dark:text-red-200 rounded-xl px-4 py-3 text-sm"
            >
                {{ flash.error }}
            </div>
        </div>

        <!-- Page content -->
        <main id="main">
            <slot />
        </main>

        <!-- Footer -->
        <footer class="border-t border-hairline mt-16">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
                <p class="text-center text-xs text-gray-500 dark:text-gray-400">
                    &copy; {{ new Date().getFullYear() }}
                    <a
                        href="https://ictwebsolution.nl"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="font-medium hover:text-gray-900 dark:hover:text-gray-400 transition-colors"
                        >ICTWebSolution B.V.</a
                    >
                    — All Rights Reserved
                </p>
            </div>
        </footer>
    </div>
</template>
