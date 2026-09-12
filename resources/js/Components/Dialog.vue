<script setup>
import { ref, onMounted, onBeforeUnmount, nextTick } from 'vue'

/**
 * Dialog semantics + behaviour for anything that covers the page: the
 * registration modal and the mobile drawer. Mount it with `v-if` (so the
 * caller keeps its own <Transition>, backdrop and layout classes) and it adds
 * what a plain <div> cannot: `role="dialog"`, `aria-modal`, an accessible
 * name, Escape-to-close, a Tab focus trap, a body scroll lock and focus
 * return to whatever opened it.
 *
 * Every class you pass lands on the panel through attribute fallthrough, so
 * the visual design stays entirely with the caller.
 */

const props = defineProps({
    /** id of the heading that names the dialog — preferred over `label`. */
    labelledby: { type: String, default: undefined },
    /** Accessible name for dialogs without a visible heading (the drawer). */
    label: { type: String, default: undefined },
})

const emit = defineEmits(['close'])

const panel = ref(null)
let lastFocused = null
let previousOverflow = ''

const FOCUSABLE = [
    'a[href]',
    'button:not([disabled])',
    'input:not([disabled])',
    'select:not([disabled])',
    'textarea:not([disabled])',
    '[tabindex]:not([tabindex="-1"])',
].join(',')

function focusables() {
    return Array.from(panel.value?.querySelectorAll(FOCUSABLE) ?? [])
        .filter(el => el.offsetParent !== null)
}

function onKeydown(e) {
    if (e.key === 'Escape') {
        e.preventDefault()
        emit('close')
        return
    }
    if (e.key !== 'Tab') return

    const items = focusables()
    if (!items.length) {
        e.preventDefault()
        panel.value?.focus()
        return
    }

    const first = items[0]
    const last = items[items.length - 1]

    // Without this, Tab walks straight out of the dialog into the page behind
    // it — measured at 24 still-tabbable background controls.
    if (!panel.value?.contains(document.activeElement)) {
        e.preventDefault()
        first.focus()
    } else if (e.shiftKey && document.activeElement === first) {
        e.preventDefault()
        last.focus()
    } else if (!e.shiftKey && document.activeElement === last) {
        e.preventDefault()
        first.focus()
    }
}

onMounted(async () => {
    lastFocused = document.activeElement
    previousOverflow = document.body.style.overflow
    document.body.style.overflow = 'hidden'
    document.addEventListener('keydown', onKeydown)
    await nextTick()
    ;(focusables()[0] ?? panel.value)?.focus()
})

onBeforeUnmount(() => {
    document.body.style.overflow = previousOverflow
    document.removeEventListener('keydown', onKeydown)
    lastFocused?.focus?.()
})
</script>

<template>
    <div
        ref="panel"
        role="dialog"
        aria-modal="true"
        :aria-labelledby="labelledby"
        :aria-label="label"
        tabindex="-1"
    >
        <slot />
    </div>
</template>
