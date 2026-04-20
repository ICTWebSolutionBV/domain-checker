import { ref, reactive } from 'vue'

export function useDomainCheck() {
    const results = reactive({})
    const isDone = ref(false)
    const isChecking = ref(false)
    const checkedCount = ref(0)
    const totalCount = ref(0)
    const error = ref(null) // null | 'rate_limited' | 'error'
    let abortController = null

    async function check(domain, tlds) {
        if (abortController) {
            abortController.abort()
        }

        Object.keys(results).forEach(key => delete results[key])
        tlds.forEach(tld => (results[tld] = 'checking'))
        isDone.value = false
        isChecking.value = true
        error.value = null
        checkedCount.value = 0
        totalCount.value = tlds.length

        abortController = new AbortController()

        try {
            const params = new URLSearchParams({ domain, tlds: tlds.join(',') })
            const response = await fetch(`/check?${params}`, {
                signal: abortController.signal,
                headers: { Accept: 'text/event-stream' },
            })

            if (response.status === 429) {
                error.value = 'rate_limited'
                tlds.forEach(tld => delete results[tld])
                isChecking.value = false
                return
            }

            if (!response.ok) {
                error.value = 'error'
                tlds.forEach(tld => { if (results[tld] === 'checking') results[tld] = 'unknown' })
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

                // SSE events are separated by double newline
                const parts = buffer.split('\n\n')
                buffer = parts.pop() // keep any incomplete trailing chunk

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
                        if (parsed.tld && parsed.status) {
                            results[parsed.tld] = parsed.status
                            checkedCount.value++
                        }
                    } catch {
                        // ignore malformed events
                    }
                }
            }
        } catch (err) {
            if (err.name === 'AbortError') return
            error.value = 'error'
            tlds.forEach(tld => { if (results[tld] === 'checking') results[tld] = 'unknown' })
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
