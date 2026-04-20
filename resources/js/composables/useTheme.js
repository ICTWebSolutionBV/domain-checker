import { ref } from 'vue'

export function applyTheme(preference) {
    if (preference === 'dark') {
        document.documentElement.classList.add('dark')
    } else if (preference === 'light') {
        document.documentElement.classList.remove('dark')
    } else {
        if (window.matchMedia('(prefers-color-scheme: dark)').matches) {
            document.documentElement.classList.add('dark')
        } else {
            document.documentElement.classList.remove('dark')
        }
    }
}

export function useTheme() {
    const theme = ref(localStorage.getItem('theme') || 'auto')

    function setTheme(value) {
        theme.value = value
        localStorage.setItem('theme', value)
        applyTheme(value)
    }

    function cycleTheme() {
        const order = ['light', 'dark', 'auto']
        const idx = order.indexOf(theme.value)
        setTheme(order[(idx + 1) % order.length])
    }

    return { theme, setTheme, cycleTheme }
}
