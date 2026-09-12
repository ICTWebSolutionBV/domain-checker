import { ref, reactive, onScopeDispose } from 'vue'

export function useBulkDomainCheck() {
    const results = reactive({})
    // domain as typed → the registrable domain the backend actually checked,
    // present only when the two differ (blog.google.com → google.com).
    const checkedDomains = reactive({})
    const isDone = ref(false)
    const isChecking = ref(false)
    const checkedCount = ref(0)
    const totalCount = ref(0)
    const error = ref(null) // null | 'rate_limited' | 'error' | 'incomplete'
    let abortController = null
    let runId = 0

    // See useDomainCheck: without this an abandoned check holds the stream and
    // a server worker open, and keeps writing into destroyed state.
    onScopeDispose(() => abortController?.abort())

    function downgradePending() {
        Object.keys(results).forEach(domain => {
            if (results[domain] === 'checking') results[domain] = 'unknown'
        })
    }

    async function check(domains) {
        abortController?.abort()

        const myRun = ++runId

        Object.keys(results).forEach(key => delete results[key])
        Object.keys(checkedDomains).forEach(key => delete checkedDomains[key])
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
                return
            }

            if (!response.ok) {
                error.value = 'error'
                domains.forEach(d => delete results[d])
                return
            }

            const reader = response.body.getReader()
            const decoder = new TextDecoder()
            let buffer = ''

            while (true) {
                const { done, value } = await reader.read()
                if (done) {
                    // No {"done":true} sentinel means the stream died
                    // mid-flight; say so instead of leaving rows spinning.
                    if (!isDone.value) {
                        error.value = 'incomplete'
                        downgradePending()
                    }
                    break
                }

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
                            return
                        }
                        if (parsed.domain && parsed.status) {
                            results[parsed.domain] = parsed.status
                            if (parsed.checked_domain && parsed.checked_domain !== parsed.domain) {
                                checkedDomains[parsed.domain] = parsed.checked_domain
                            }
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
            downgradePending()
        } finally {
            if (myRun === runId) isChecking.value = false
        }
    }

    function reset() {
        abortController?.abort()
        abortController = null
        runId++
        Object.keys(results).forEach(key => delete results[key])
        Object.keys(checkedDomains).forEach(key => delete checkedDomains[key])
        isDone.value = false
        isChecking.value = false
        error.value = null
        checkedCount.value = 0
        totalCount.value = 0
    }

    return { results, checkedDomains, isDone, isChecking, checkedCount, totalCount, error, check, reset }
}
