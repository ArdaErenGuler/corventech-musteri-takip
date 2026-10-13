/* Corventech Panel — arayüz davranışları (bağımlılık yok) */
(function () {
    'use strict';

    var $ = function (sel, root) { return (root || document).querySelector(sel); };
    var $$ = function (sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); };

    /* ---------- Çekmece menü ---------- */
    var drawer = $('#drawer');
    var scrim = $('.scrim');
    var openBtn = $('[data-drawer-open]');

    function setDrawer(open) {
        if (!drawer) return;
        drawer.classList.toggle('is-open', open);
        document.body.classList.toggle('drawer-locked', open);
        if (openBtn) openBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (scrim) {
            if (open) {
                scrim.hidden = false;
                requestAnimationFrame(function () { scrim.classList.add('is-visible'); });
            } else {
                scrim.classList.remove('is-visible');
                setTimeout(function () { if (!drawer.classList.contains('is-open')) scrim.hidden = true; }, 200);
            }
        }
        if (open) {
            var first = $('.nav-link', drawer);
            if (first) first.focus({ preventScroll: true });
        } else if (openBtn) {
            openBtn.focus({ preventScroll: true });
        }
    }

    $$('[data-drawer-open]').forEach(function (b) { b.addEventListener('click', function () { setDrawer(true); }); });
    $$('[data-drawer-close]').forEach(function (b) { b.addEventListener('click', function () { setDrawer(false); }); });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && drawer && drawer.classList.contains('is-open')) setDrawer(false);
    });

    // Soldan sağa kaydırınca kapat (mobil)
    if (drawer) {
        var startX = null;
        drawer.addEventListener('touchstart', function (e) { startX = e.touches[0].clientX; }, { passive: true });
        drawer.addEventListener('touchend', function (e) {
            if (startX !== null && startX - e.changedTouches[0].clientX > 60) setDrawer(false);
            startX = null;
        });
    }

    /* ---------- Tema ---------- */
    function effectiveTheme() {
        var t = document.documentElement.getAttribute('data-theme');
        if (t === 'light' || t === 'dark') return t;
        return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    }
    function applyTheme(t) {
        if (t === 'system') document.documentElement.removeAttribute('data-theme');
        else document.documentElement.setAttribute('data-theme', t);
        try { localStorage.setItem('theme', t); } catch (e) {}
        $$('[data-theme-choice]').forEach(function (r) { r.checked = r.value === t; });
    }
    $$('[data-theme-toggle]').forEach(function (b) {
        b.addEventListener('click', function () { applyTheme(effectiveTheme() === 'dark' ? 'light' : 'dark'); });
    });
    $$('[data-theme-choice]').forEach(function (r) {
        var saved = 'system';
        try { saved = localStorage.getItem('theme') || 'system'; } catch (e) {}
        r.checked = r.value === saved;
        r.addEventListener('change', function () { if (r.checked) applyTheme(r.value); });
    });

    /* ---------- Onay ve bildirimler ---------- */
    document.addEventListener('submit', function (e) {
        var form = e.target;
        var msg = form.getAttribute('data-confirm') || (e.submitter && e.submitter.getAttribute('data-confirm'));
        if (msg && !window.confirm(msg)) e.preventDefault();
    });

    $$('.flash').forEach(function (f) {
        if (f.classList.contains('flash-error')) return;
        setTimeout(function () {
            f.classList.add('is-hiding');
            setTimeout(function () { f.remove(); }, 350);
        }, 4500);
    });

    $$('select[data-autosubmit]').forEach(function (s) {
        s.addEventListener('change', function () { s.form.submit(); });
    });

    /* ---------- Sekmeler ---------- */
    $$('[data-tabs]').forEach(function (tabs) {
        var buttons = $$('[data-tab]', tabs);
        function show(id, push) {
            buttons.forEach(function (b) {
                var on = b.getAttribute('data-tab') === id;
                b.classList.toggle('is-active', on);
                b.setAttribute('aria-selected', on ? 'true' : 'false');
                var panel = document.getElementById(b.getAttribute('data-tab'));
                if (panel) panel.classList.toggle('is-active', on);
            });
            if (push) history.replaceState(null, '', '#' + id);
        }
        buttons.forEach(function (b) {
            b.addEventListener('click', function () { show(b.getAttribute('data-tab'), true); });
        });
        var hash = location.hash.slice(1);
        if (hash && buttons.some(function (b) { return b.getAttribute('data-tab') === hash; })) show(hash, false);
    });

    /* ---------- Sipariş formu ---------- */
    var orderForm = $('[data-order-form]');
    if (orderForm) {
        var service = $('[name="service_id"]', orderForm);
        var price = $('[name="price"]', orderForm);
        var setup = $('[name="setup_fee"]', orderForm);
        var start = $('[name="start_date"]', orderForm);
        var firstDue = $('[name="first_due_date"]', orderForm);
        var cycles = $$('[name="billing_cycle"], [name="default_cycle"]', orderForm);
        var firstDueTouched = firstDue && firstDue.value !== '' && firstDue.value !== start.value;

        function cycleValue() {
            var c = cycles.filter(function (r) { return r.checked; })[0];
            return c ? c.value : 'monthly';
        }
        function syncCycle() {
            var recurring = cycleValue() !== 'one_time';
            $$('[data-recurring-only]', orderForm).forEach(function (el) { el.hidden = !recurring; });
            var label = $('[data-price-label]', orderForm);
            if (label) label.textContent = { one_time: 'Tutar', monthly: 'Aylık tutar', yearly: 'Yıllık tutar' }[cycleValue()];
        }
        cycles.forEach(function (r) { r.addEventListener('change', syncCycle); });
        syncCycle();

        if (service) {
            service.addEventListener('change', function () {
                var opt = service.options[service.selectedIndex];
                if (!opt || !opt.dataset.price) return;
                price.value = opt.dataset.price;
                if (setup) setup.value = opt.dataset.setup || '0';
                cycles.forEach(function (r) { r.checked = r.value === opt.dataset.cycle; });
                syncCycle();
            });
        }
        if (firstDue && start) {
            firstDue.addEventListener('input', function () { firstDueTouched = true; });
            start.addEventListener('change', function () { if (!firstDueTouched) firstDue.value = start.value; });
        }
    }

    /* ---------- Ödeme formu: müşteri seçilince açık dönemleri getir ---------- */
    var payForm = $('[data-payment-form]');
    if (payForm) {
        var customer = $('[name="customer_id"]', payForm);
        var due = $('[name="due_id"]', payForm);
        var amount = $('[name="amount"]', payForm);

        function fillAmount() {
            var opt = due.options[due.selectedIndex];
            if (opt && opt.dataset.remaining) amount.value = opt.dataset.remaining;
        }
        function loadDues(keepSelected) {
            var id = customer.value;
            var selected = keepSelected ? due.value : '';
            due.innerHTML = '<option value="">Döneme bağlama (genel ödeme)</option>';
            if (!id) return;
            fetch('api.php?action=open_dues&customer_id=' + encodeURIComponent(id), {
                headers: { 'X-Requested-With': 'fetch' },
                credentials: 'same-origin'
            })
                .then(function (r) { return r.json(); })
                .then(function (rows) {
                    rows.forEach(function (d) {
                        var o = document.createElement('option');
                        o.value = d.id;
                        o.textContent = d.label;
                        o.dataset.remaining = d.remaining;
                        due.appendChild(o);
                    });
                    if (selected) due.value = selected;
                    else if (rows.length) { due.value = rows[0].id; }
                    if (!keepSelected) fillAmount();
                })
                .catch(function () {});
        }
        if (customer && due) {
            customer.addEventListener('change', function () { loadDues(false); });
            due.addEventListener('change', fillAmount);
        }
    }

    /* ---------- Grafik ipuçları ---------- */
    $$('.chart').forEach(function (chart) {
        var tip = document.createElement('div');
        tip.className = 'chart-tip';
        tip.hidden = true;
        chart.appendChild(tip);
        $$('[data-tip]', chart).forEach(function (col) {
            function show() {
                tip.innerHTML = col.getAttribute('data-tip');
                var cr = chart.getBoundingClientRect();
                var br = (col.querySelector('.bar') || col).getBoundingClientRect();
                var x = Math.min(Math.max(br.left + br.width / 2 - cr.left, 60), cr.width - 60);
                tip.style.left = x + 'px';
                tip.style.top = (br.top - cr.top) + 'px';
                tip.hidden = false;
            }
            function hide() { tip.hidden = true; }
            col.addEventListener('mouseenter', show);
            col.addEventListener('focus', show);
            col.addEventListener('mouseleave', hide);
            col.addEventListener('blur', hide);
            col.addEventListener('touchstart', show, { passive: true });
        });
    });

    /* ---------- PWA ---------- */
    if ('serviceWorker' in navigator && (location.protocol === 'https:' || location.hostname === 'localhost' || location.hostname === '127.0.0.1')) {
        window.addEventListener('load', function () {
            navigator.serviceWorker.register('sw.js').catch(function () {});
        });
    }

    var deferredPrompt = null;
    window.addEventListener('beforeinstallprompt', function (e) {
        e.preventDefault();
        deferredPrompt = e;
        $$('[data-install]').forEach(function (b) { b.hidden = false; });
    });
    $$('[data-install]').forEach(function (b) {
        b.addEventListener('click', function () {
            if (!deferredPrompt) return;
            deferredPrompt.prompt();
            deferredPrompt.userChoice.finally(function () {
                deferredPrompt = null;
                b.hidden = true;
            });
        });
    });
})();
