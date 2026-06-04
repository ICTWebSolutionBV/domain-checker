import { ref, reactive } from 'vue'

export function useBulkDomainCheck() {
    const results = reactive({})
    const isDone = ref(false)
    const isChecking = ref(false)
    const checkedCount = ref(0)
    const totalCount = ref(0)
    const error = ref(null)
    let abortController = null

    async function check(domains) {
        if (abortController) {
            abortController.abort()
        }

        Object.keys(results).forEach(key => delete results[key])
        domains.forEach(d => (results[d] = 'checking'))
        isDone.value = false
        isChecking.value = true
        error.value = null
        checkedCount.value = 0
        totalCount.value = domains.length

        abortController = new AbortController()

        try {
            const body = new URLSearchParams({ domains: domains.join(',') })
            const response = await fetch('/bulk-check', {
                method: 'POST',
                signal: abortController.signal,
                headers: {
                    'Accept': 'text/event-stream',
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body,
            })

            if (response.status === 429) {
                error.value = 'rate_limited'
                domains.forEach(d => delete results[d])
                isChecking.value = false
                return
            }

            if (!response.ok) {
                error.value = 'error'
                domains.forEach(d => { if (results[d] === 'checking') results[d] = 'unknown' })
                isChecking.value = false
                return
            }

            const reader = response.body.getReader()
            const decoder = new TextDecoder()
            let buffer = ''

            while (true) {
                const { done, value } = await reader.read()
                if (done) break

                buffer += decoder.decode(value, { stream: true })

                const parts = buffer.split('\n\n')
                buffer = parts.pop()

                for (const part of parts) {
                    const dataLine = part.split('\n').find(l => l.startsWith('data: '))
                    if (!dataLine) continue
                    try {
                        const parsed = JSON.parse(dataLine.slice(6))
                        if (parsed.done) {
                            isDone.value = true
                            isChecking.value = false
                            return
                        }
                        if (parsed.domain && parsed.status) {
                            results[parsed.domain] = parsed.status
                            if (parsed.checked) checkedCount.value = parsed.checked
                            if (parsed.total)   totalCount.value  = parsed.total
                        }
                    } catch {
                        // ignore malformed events
                    }
                }
            }
        } catch (err) {
            if (err.name === 'AbortError') return
            error.value = 'error'
            domains.forEach(d => { if (results[d] === 'checking') results[d] = 'unknown' })
        } finally {
            isChecking.value = false
        }
    }

    function reset() {
        if (abortController) {
            abortController.abort()
            abortController = null
        }
        Object.keys(results).forEach(key => delete results[key])
        isDone.value = false
        isChecking.value = false
        error.value = null
        checkedCount.value = 0
        totalCount.value = 0
    }

    return { results, isDone, isChecking, checkedCount, totalCount, error, check, reset }
}
