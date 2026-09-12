import { ref, onScopeDispose } from 'vue'

/**
 * navigator.clipboard is undefined on any non-secure origin — this app is
 * served over plain HTTP in dev and on the LAN — and writeText rejects when
 * the document is not focused or permission is denied. Four call sites each
 * handled that differently: two had no catch at all (an unhandled rejection
 * and a button that never changed), one swallowed the error silently, and one
 * flipped to the success checkmark regardless of whether anything was copied.
 *
 * This is Transfer.vue's behaviour — try/catch, a real error message, a
 * preview to copy by hand — for everyone.
 */
export function useClipboard({ resetAfter = 2000 } = {}) {
    const copied = ref(null)
    const error = ref('')
    let timer = null

    onScopeDispose(() => clearTimeout(timer))

    async function copy(text, key = true) {
        clearTimeout(timer)
        error.value = ''
        try {
            if (!navigator.clipboard?.writeText) throw new Error('unavailable')
            await navigator.clipboard.writeText(text)
            copied.value = key
            timer = setTimeout(() => {
                copied.value = null
            }, resetAfter)
            return true
        } catch {
            copied.value = null
            error.value = 'Copying failed — select the text and copy it manually.'
            return false
        }
    }

    return { copy, copied, error }
}
