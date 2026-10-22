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

    const navbar = document.querySelector('[data-landing-navbar]');

    if (navbar) {
        const updateScrolled = () => {
            navbar.dataset.scrolled = window.scrollY > 16 ? 'true' : 'false';
        };

        updateScrolled();
        window.addEventListener('scroll', updateScrolled, { passive: true });
    }

    const menu = document.getElementById('mobile-menu');
    const opener = document.querySelector('[data-menu-open]');
    const backdrop = document.querySelector('[data-menu-backdrop]');

    if (menu && opener && backdrop) {
        const focusables = () => [...menu.querySelectorAll('a[href], button:not([disabled])')];

        const setOpen = (open) => {
            menu.dataset.open = open ? 'true' : 'false';
            backdrop.dataset.open = open ? 'true' : 'false';
            opener.setAttribute('aria-expanded', open ? 'true' : 'false');
            document.body.style.overflow = open ? 'hidden' : '';

            if (open) {
                focusables()[0]?.focus();
            } else {
                opener.focus();
            }
        };

        opener.addEventListener('click', () => setOpen(true));
        backdrop.addEventListener('click', () => setOpen(false));
        menu.querySelectorAll('[data-menu-close]').forEach((element) => {
            element.addEventListener('click', () => setOpen(false));
        });

        document.addEventListener('keydown', (event) => {
            if (menu.dataset.open !== 'true') {
                return;
            }

            if (event.key === 'Escape') {
                event.preventDefault();
                setOpen(false);

                return;
            }

            if (event.key !== 'Tab') {
                return;
            }

            const items = focusables();
            const first = items[0];
            const last = items[items.length - 1];

            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        });

        window.matchMedia('(min-width: 64rem)').addEventListener('change', (event) => {
            if (event.matches && menu.dataset.open === 'true') {
                setOpen(false);
            }
        });
    }
});
