import { ref, reactive, onScopeDispose } from 'vue'

export function useDomainCheck() {
    const results = reactive({})
    const isDone = ref(false)
    const isChecking = ref(false)
    const checkedCount = ref(0)
    const totalCount = ref(0)
    const error = ref(null) // null | 'rate_limited' | 'error' | 'incomplete'
    let abortController = null
    let runId = 0

    // Abandoning a check used to leave the reader loop alive and the HTTP
    // connection open: an Inertia navigation away from / kept writing into a
    // destroyed component's state and held a server worker. Measured once at
    // over 10 minutes of unreachable app with 46 sockets in CLOSE-WAIT.
    // onScopeDispose rather than onUnmounted, so this also works when the
    // composable is called from a non-component scope.
    onScopeDispose(() => abortController?.abort())

    function downgradePending() {
        Object.keys(results).forEach(tld => {
            if (results[tld] === 'checking') results[tld] = 'unknown'
        })
    }

    async function check(domain, tlds) {
        abortController?.abort()

        // The aborted run's finally still fires, asynchronously, after this
        // one has started — without the tag it turned the new run's spinner
        // off and re-enabled the Check button mid-stream.
        const myRun = ++runId

        Object.keys(results).forEach(key => delete results[key])
        tlds.forEach(tld => (results[tld] = 'checking'))
        isDone.value = false
        isChecking.value = true
        error.value = null
        checkedCount.value = 0
        totalCount.value = tlds.length

        abortController = new AbortController()

        try {
            // POST so TLDs go in the body — GET URLs with 1500 TLDs exceed server limits
            const body = new URLSearchParams({ domain, tlds: tlds.join(',') })
            const response = await fetch('/check', {
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
                tlds.forEach(tld => delete results[tld])
                return
            }

            if (!response.ok) {
                // Only the banner — a full grid of "Unknown" rows on top of it
                // is noise, not information.
                error.value = 'error'
                tlds.forEach(tld => delete results[tld])
                return
            }

            const reader = response.body.getReader()
            const decoder = new TextDecoder()
            let buffer = ''

            while (true) {
                const { done, value } = await reader.read()
                if (done) {
                    // The backend always terminates the stream with
                    // {"done":true}. Reaching the end of the body without it
                    // means the connection died mid-flight — an nginx
                    // proxy_read_timeout, an FPM worker recycle, a dropped
                    // mobile connection. That used to leave 43 of 46 rows
                    // spinning forever with no error and no progress bar.
                    if (!isDone.value) {
                        error.value = 'incomplete'
                        downgradePending()
                    }
                    break
                }

                buffer += decoder.decode(value, { stream: true })

                // SSE events are separated by double newline
                const parts = buffer.split('\n\n')
                buffer = parts.pop() // keep any incomplete trailing chunk

                for (const part of parts) {
                    // Comment frames (the stream opens with ": ping") carry no
                    // data line and are skipped here.
                    const dataLine = part.split('\n').find(l => l.startsWith('data: '))
                    if (!dataLine) continue
                    try {
                        const parsed = JSON.parse(dataLine.slice(6))
                        if (parsed.done) {
                            isDone.value = true
                            return
                        }
                        if (parsed.tld && parsed.status) {
                            results[parsed.tld] = parsed.status
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
        isDone.value = false
        isChecking.value = false
        error.value = null
        checkedCount.value = 0
        totalCount.value = 0
    }

    return { results, isDone, isChecking, checkedCount, totalCount, error, check, reset }
}
