<script setup>
import { computed, useId } from 'vue'

/**
 * Label + control + help + error, wired together the way a form control has
 * to be: a real `for`/`id` pair, `aria-invalid` when it is wrong and
 * `aria-describedby` pointing at the help and error text.
 *
 * The control stays with the caller (icons, prefixes, selects, textareas all
 * differ) and receives the wiring through the slot props:
 *
 *   <FormField label="Email" :error="form.errors.email" v-slot="field">
 *       <input v-bind="field" v-model="form.email" type="email" class="ui-input" />
 *   </FormField>
 */

const props = defineProps({
    label: { type: String, required: true },
    /** Parenthetical after the label, e.g. "(optional)". */
    hint: { type: String, default: '' },
    /** Help text rendered under the control. */
    help: { type: String, default: '' },
    error: { type: String, default: '' },
    /** Extra classes for the label, for the few call sites that size it. */
    labelClass: { type: String, default: '' },
})

const id = useId()
const helpId = `${id}-help`
const errorId = `${id}-error`

const control = computed(() => ({
    id,
    'aria-invalid': props.error ? 'true' : undefined,
    'aria-describedby': [props.help && helpId, props.error && errorId].filter(Boolean).join(' ') || undefined,
}))
</script>

<template>
    <div>
        <label :for="id" class="ui-label" :class="labelClass">
            {{ label }}
            <span v-if="hint" class="ui-label-hint">{{ hint }}</span>
        </label>
        <slot v-bind="control" />
        <p v-if="help" :id="helpId" class="ui-help mt-1">{{ help }}</p>
        <p v-if="error" :id="errorId" role="alert" class="text-red-600 dark:text-red-400 text-xs mt-1">{{ error }}</p>
    </div>
</template>
