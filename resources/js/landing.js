document.addEventListener('DOMContentLoaded', () => {
    const root = document.documentElement;

    document.querySelectorAll('[data-theme-toggle]').forEach((button) => {
        button.setAttribute('aria-pressed', root.classList.contains('dark') ? 'true' : 'false');

        button.addEventListener('click', () => {
            const isDark = root.classList.toggle('dark');

            document.querySelectorAll('[data-theme-toggle]').forEach((other) => {
                other.setAttribute('aria-pressed', isDark ? 'true' : 'false');
            });

            try {
                localStorage.setItem('theme', isDark ? 'dark' : 'light');
            } catch (e) {}
        });
    });
});
