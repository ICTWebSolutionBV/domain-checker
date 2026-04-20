import { ref, reactive } from 'vue'

export function useDomainCheck() {
    const results = reactive({})
    const isDone = ref(false)
    const isChecking = ref(false)
    const checkedCount = ref(0)
    const totalCount = ref(0)
    let currentSource = null

    function check(domain, tlds) {
        if (currentSource) {
            currentSource.close()
        }

        Object.keys(results).forEach(key => delete results[key])
        tlds.forEach(tld => (results[tld] = 'checking'))
        isDone.value = false
        isChecking.value = true
        checkedCount.value = 0
        totalCount.value = tlds.length

        const params = new URLSearchParams({ domain, tlds: tlds.join(',') })
        const source = new EventSource(`/check?${params}`)
        currentSource = source

        source.onmessage = ({ data }) => {
            try {
                const parsed = JSON.parse(data)
                if (parsed.done) {
                    isDone.value = true
                    isChecking.value = false
                    source.close()
                    currentSource = null
                    return
                }
                if (parsed.tld && parsed.status) {
                    results[parsed.tld] = parsed.status
                    checkedCount.value++
                }
            } catch {
                // ignore parse errors
            }
        }

        source.onerror = () => {
            isDone.value = true
            isChecking.value = false
            source.close()
            currentSource = null
            tlds.forEach(tld => {
                if (results[tld] === 'checking') {
                    results[tld] = 'unknown'
                }
            })
        }
    }

    function reset() {
        if (currentSource) {
            currentSource.close()
            currentSource = null
        }
        Object.keys(results).forEach(key => delete results[key])
        isDone.value = false
        isChecking.value = false
        checkedCount.value = 0
        totalCount.value = 0
    }

    return { results, isDone, isChecking, checkedCount, totalCount, check, reset }
}
