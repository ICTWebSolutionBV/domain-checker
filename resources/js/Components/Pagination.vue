<script setup>
import { Link } from '@inertiajs/vue3'

// Renders a Laravel paginator's page links. Nothing is shown for a single page,
// so the admin lists look exactly as they did until someone actually has more
// than one page of users or invites.
defineProps({
    paginator: { type: Object, required: true },
})

// Laravel's paginator labels arrive as markup: "&laquo; Previous", "Next
// &raquo;", plain page numbers. They came from the framework, not from a
// user, but rendering them with v-html on a component is still the wrong
// tool -- it can clobber the component's own children, and it means this
// template would happily render markup the day the labels stop being ours.
const labelText = (label) =>
    String(label)
        .replace(/&laquo;/g, '\u2039')
        .replace(/&raquo;/g, '\u203a')
        .replace(/&nbsp;/g, ' ')
        .replace(/<[^>]*>/g, '')
        .trim()
</script>

<template>
    <nav
        v-if="paginator.last_page > 1"
        class="flex items-center justify-between gap-2 px-4 py-3 border-t border-gray-200 dark:border-gray-800"
    >
        <p class="text-xs text-gray-500 dark:text-gray-400">
            {{ paginator.from }}&ndash;{{ paginator.to }} of {{ paginator.total }}
        </p>
        <div class="flex items-center gap-1">
            <template v-for="(link, i) in paginator.links" :key="i">
                <Link
                    v-if="link.url"
                    :href="link.url"
                    preserve-scroll
                    class="px-2.5 py-1 rounded-lg text-xs font-medium"
                    :class="
                        link.active
                            ? 'bg-gray-900 dark:bg-gray-100 text-white dark:text-gray-900'
                            : 'text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800'
                    "
                >
                    {{ labelText(link.label) }}
                </Link>
                <span v-else class="px-2.5 py-1 text-xs font-medium text-gray-300 dark:text-gray-600">
                    {{ labelText(link.label) }}
                </span>
            </template>
        </div>
    </nav>
</template>
