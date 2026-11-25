(() => {
    const intro = document.querySelector('.atlas-intro');
    const motion = matchMedia('(prefers-reduced-motion: reduce)');

    const ticker = document.querySelector(".atlas-ticker");
    if (ticker) {
        const toggle = ticker.querySelector(".atlas-ticker-toggle");
        ticker.classList.add("is-playing");
        toggle.hidden = false;
        toggle.addEventListener("click", () => {
            const paused = ticker.classList.toggle("is-paused");
            toggle.setAttribute("aria-label", paused ? "Продолжить бегущую строку" : "Приостановить бегущую строку");
            toggle.firstElementChild.textContent = paused ? "▶" : "Ⅱ";
        });
    }

    if (intro && !motion.matches && !location.hash) {
        const key = 'atlas-home-intro';
        let seen = false;
        try {
            seen = sessionStorage.getItem(key) === 'shown';
            if (!seen) sessionStorage.setItem(key, 'shown');
        } catch {
            // The intro still works when browsing storage is disabled.
        }

        if (!seen) {
            intro.hidden = false;
            const started = performance.now();
            let closing = false;
            function finish() {
                if (closing) return;
                closing = true;
                clearTimeout(timeout);
                setTimeout(() => {
                    intro.classList.add('is-finished');
                    setTimeout(() => { intro.hidden = true; }, 300);
                }, Math.max(0, 520 - (performance.now() - started)));
            }

            const timeout = setTimeout(finish, 1400);
            const hero = document.querySelector('#home .hero-image, #home > img');
            if (hero && !hero.complete) {
                hero.addEventListener('load', finish, {once: true});
                hero.addEventListener('error', finish, {once: true});
            } else {
                finish();
            }
        }
    }

    if (!motion.matches && 'IntersectionObserver' in window) {
        const observer = new IntersectionObserver(entries => {
            entries.forEach(entry => {
                if (!entry.isIntersecting) return;
                entry.target.classList.add('is-revealed');
                observer.unobserve(entry.target);
            });
        }, {threshold: 0.12});

        document.querySelectorAll('[data-atlas-reveal]').forEach(item => observer.observe(item));
    }
})();

(() => {
    const eligible = matchMedia("(hover: hover) and (pointer: fine) and (prefers-reduced-motion: no-preference)");
    const halo = document.createElement("div");
    halo.className = "atlas-cursor";
    halo.setAttribute("aria-hidden", "true");
    document.body.append(halo);
    let frame = 0;
    let pointer;

    function hide() {
        halo.classList.remove("is-visible");
        cancelAnimationFrame(frame);
        frame = 0;
    }

    document.addEventListener("pointermove", event => {
        if (!eligible.matches || event.pointerType !== "mouse" || event.target.closest("dialog[open]")) return hide();
        if (!event.target.closest("button:not(:disabled), [role=button], a.atlas-action, a.pill, a.btn")) return hide();
        pointer = event;
        if (frame) return;
        frame = requestAnimationFrame(() => {
            frame = 0;
            halo.style.transform = "translate3d(" + pointer.clientX + "px, " + pointer.clientY + "px, 0)";
            halo.classList.add("is-visible");
        });
    }, { passive: true });
    document.documentElement.addEventListener("pointerleave", hide);
    addEventListener("blur", hide);
    eligible.addEventListener("change", hide);
})();
