<script setup>
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'

// One place for the tool links in the header and the drawer. Before this they
// were six copies of the same class string with no notion of being the current
// page: nothing marked it visually, and nothing told a screen reader either.
const props = defineProps({
    routeName: { type: String, required: true },
    label: { type: String, required: true },
    variant: { type: String, default: 'header' },
})

const active = computed(() => {
    try {
        return route().current(props.routeName)
    } catch {
        // route() is Ziggy's global; if it is unavailable the link still works.
        return false
    }
})

const base =
    'flex items-center gap-1.5 rounded-lg font-medium transition-colors min-h-9 focus-visible:outline-2 focus-visible:outline-offset-2'

const variants = {
    header: 'px-2.5 py-1.5 text-xs',
    drawer: 'px-3 py-2.5 text-sm w-full',
}

const state = computed(() =>
    active.value
        ? 'bg-gray-100 dark:bg-gray-800 text-gray-900 dark:text-white'
        : 'text-gray-500 dark:text-gray-400 hover:text-gray-900 dark:hover:text-white hover:bg-gray-100 dark:hover:bg-gray-800',
)
</script>

<template>
    <Link
        :href="route(routeName)"
        :aria-current="active ? 'page' : undefined"
        :class="[base, variants[variant], state]"
    >
        <slot />
        <span>{{ label }}</span>
    </Link>
</template>
