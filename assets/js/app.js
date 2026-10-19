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
        if (f.classList.contains('flash-error') || f.classList.contains('flash-warning')) return;
        setTimeout(function () {
            f.classList.add('is-hiding');
            setTimeout(function () { f.remove(); }, 350);
        }, 4500);
    });

    // "Bugün paylaşım var" şeridi: o gün için gizle
    $$('[data-banner-close]').forEach(function (b) {
        b.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            var banner = b.closest('[data-banner]');
            try { localStorage.setItem('banner-hidden', banner.getAttribute('data-banner')); } catch (err) {}
            banner.hidden = true;
        });
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
        function fromHash() {
            var hash = location.hash.slice(1);
            if (hash && buttons.some(function (b) { return b.getAttribute('data-tab') === hash; })) show(hash, false);
        }
        fromHash();
        window.addEventListener('hashchange', fromHash);
    });

    /* ---------- Sipariş formu ---------- */
    var orderForm = $('[data-order-form]');
    if (orderForm) {
        var start = $('[name="start_date"]', orderForm);
        var firstDue = $('[name="first_due_date"]', orderForm);
        var cycles = $$('[name="billing_cycle"]', orderForm);
        var firstDueTouched = firstDue && firstDue.value !== '' && firstDue.value !== start.value;

        function cycleValue() {
            var c = cycles.filter(function (r) { return r.checked; })[0];
            return c ? c.value : 'monthly';
        }
        // "1.250,50" / "10.500" / "10500" → sayı (sunucudaki parse_money ile aynı kural)
        function parseMoney(s) {
            s = String(s || '').replace(/[\s₺ ]/g, '');
            if (!s) return null;
            if (s.indexOf(',') >= 0) s = s.replace(/\./g, '').replace(',', '.');
            else if (/^\d{1,3}(\.\d{3})+$/.test(s)) s = s.replace(/\./g, '');
            var n = parseFloat(s);
            return isNaN(n) ? null : Math.round(n * 100) / 100;
        }
        function tl(n) { return n.toLocaleString('tr-TR', { minimumFractionDigits: 0, maximumFractionDigits: 2 }) + ' ₺'; }

        // Taksitli: "3 taksit × 3.500 ₺" gibi canlı özet (kuruş farkı son taksitte)
        function updateInstallmentPreview() {
            var out = $('[data-installment-preview]', orderForm);
            if (!out) return;
            var total = parseMoney($('[name="price"]', orderForm).value);
            var n = parseInt($('[name="installments"]', orderForm).value, 10);
            if (cycleValue() !== 'installment' || total === null || !n || n < 2) { out.textContent = ''; return; }
            var each = Math.round(total / n * 100) / 100;
            var last = Math.round((total - each * (n - 1)) * 100) / 100;
            out.textContent = last === each
                ? n + ' taksit × ' + tl(each) + ' = ' + tl(total)
                : (n - 1) + ' taksit × ' + tl(each) + ' + son taksit ' + tl(last) + ' = ' + tl(total);
        }

        function syncCycle() {
            var cycle = cycleValue();
            $$('[data-show-for]', orderForm).forEach(function (el) {
                el.hidden = el.getAttribute('data-show-for').split(',').indexOf(cycle) < 0;
            });
            var label = $('[data-price-label]', orderForm);
            if (label) label.textContent = { one_time: 'Tutar', monthly: 'Aylık tutar', yearly: 'Yıllık tutar', installment: 'Toplam tutar' }[cycle];
            var fdl = $('[data-first-due-label]', orderForm);
            if (fdl) fdl.textContent = cycle === 'installment' ? 'İlk taksit tarihi' : 'İlk ödeme tarihi';
            updateInstallmentPreview();
        }
        cycles.forEach(function (r) { r.addEventListener('change', syncCycle); });
        ['price', 'installments'].forEach(function (n) {
            var el = $('[name="' + n + '"]', orderForm);
            if (el) el.addEventListener('input', updateInstallmentPreview);
        });
        syncCycle();
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

    /* ---------- Gelir / gider: türe göre kategori listesi ---------- */
    $$('[data-txn-type]').forEach(function (r) {
        r.addEventListener('change', function () {
            var select = r.form.querySelector('[name="category"]');
            $$('optgroup', select).forEach(function (g) {
                var on = g.getAttribute('data-for') === r.value;
                g.disabled = !on;
                g.hidden = !on;
            });
            var first = $('optgroup[data-for="' + r.value + '"] option', select);
            if (first) select.value = first.value;
        });
    });

    /* ---------- Sosyal medya takvimi: güne dokununca o günün listesi ---------- */
    var calendar = $('[data-calendar]');
    if (calendar) {
        calendar.addEventListener('click', function (e) {
            var day = e.target.closest('[data-day]');
            if (!day) return;
            var key = day.getAttribute('data-day');
            var agenda = $('[data-agenda="' + key + '"]');
            if (!agenda || day.classList.contains('is-out')) return;   // başka ayın günü: sayfa o aya geçsin
            e.preventDefault();
            $$('.cal-day.is-selected', calendar).forEach(function (d) { d.classList.remove('is-selected'); d.removeAttribute('aria-current'); });
            day.classList.add('is-selected');
            day.setAttribute('aria-current', 'date');
            $$('[data-agenda]').forEach(function (a) { a.hidden = a !== agenda; });
            var url = new URL(location.href);
            url.searchParams.set('gun', key);
            history.replaceState(null, '', url);
            if (window.matchMedia('(max-width: 1023px)').matches) {
                agenda.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        });
    }

    /* ---------- Paylaşım bildirimi ----------
     * Uygulama açıldığında, o gün paylaşılacak gönderi varsa günde bir kez sistem bildirimi gösterir.
     * (Uygulama tamamen kapalıyken bildirim için sunucu taraflı Web Push gerekir.)
     */
    var now = new Date();
    var notifyKey = 'posts-notified-' + now.getFullYear() + '-' + (now.getMonth() + 1) + '-' + now.getDate();
    function storageGet(k) { try { return localStorage.getItem(k); } catch (e) { return null; } }
    function storageSet(k, v) { try { localStorage.setItem(k, v); } catch (e) {} }

    function notifyTodayPosts(force) {
        if (!('Notification' in window) || Notification.permission !== 'granted' || !('serviceWorker' in navigator)) return;
        if (!force && storageGet(notifyKey)) return;
        fetch('api.php?action=today_posts', { headers: { 'X-Requested-With': 'fetch' }, credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : []; })
            .then(function (rows) {
                storageSet(notifyKey, '1');
                if (!rows.length) {
                    if (force) navigator.serviceWorker.ready.then(function (reg) { reg.showNotification('Bildirimler açık', { body: 'Paylaşım günü geldiğinde haber vereceğiz.', icon: 'assets/icons/icon-192.png' }); });
                    return;
                }
                var today = rows.filter(function (r) { return !r.late; }).length;
                var late = rows.length - today;
                var title = today ? 'Bugün ' + today + ' paylaşım var' : late + ' paylaşım gecikti';
                var body = rows.slice(0, 4).map(function (r) {
                    return (r.customer || r.title) + (r.late ? ' (gecikti)' : '');
                }).join('\n');
                navigator.serviceWorker.ready.then(function (reg) {
                    reg.showNotification(title, {
                        body: body,
                        icon: 'assets/icons/icon-192.png',
                        badge: 'assets/icons/icon-192.png',
                        tag: 'posts-today',
                        data: { url: 'takvim.php' }
                    });
                });
            })
            .catch(function () {});
    }

    var notifyBtns = $$('[data-enable-notifications]');
    if ('Notification' in window && Notification.permission === 'default') {
        notifyBtns.forEach(function (b) { b.hidden = false; });
    }
    notifyBtns.forEach(function (b) {
        b.addEventListener('click', function () {
            Notification.requestPermission().then(function (perm) {
                if (perm === 'granted') {
                    notifyBtns.forEach(function (x) { x.hidden = true; });
                    notifyTodayPosts(true);
                }
            });
        });
    });
    if (drawer) notifyTodayPosts(false);   // yalnızca giriş yapılmış sayfalarda

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
