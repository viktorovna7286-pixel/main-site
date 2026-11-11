/* Store a lead before acknowledging it; keep WhatsApp until Telegram is configured. */
(function () {
    var form = document.getElementById('lead-form');
    var hint = document.getElementById('lead-form-hint');
    if (!form) return;
    var ready = fetch('/leads-api.php', { cache: 'no-store' }).then(function (r) { return r.json(); }).then(function (data) { return data.ready === true; }).catch(function () { return false; });
    var requestId = newRequestId();
    var sending = false;
    function newRequestId() { return window.crypto && crypto.randomUUID ? crypto.randomUUID() : Date.now().toString(36) + '-' + Math.random().toString(36).slice(2) + '-8848'; }
    var params = new URLSearchParams(location.search);
    ['product_id', 'btu'].forEach(function (key) {
        var input = form.querySelector('[name="' + key + '"]');
        if (input) input.value = params.get(key) || '';
    });
    var productLabel = params.get('product_label');
    var productSummary = document.getElementById('lead-product-summary');
    if (productLabel && productSummary) { productSummary.textContent = 'Заявка на ' + productLabel.slice(0, 180); productSummary.hidden = false; }
    if (params.get('product_id')) form.elements.service.value = 'Подбор и продажа кондиционера';
    function show(text, success) {
        hint.textContent = text;
        hint.classList.remove('hidden');
        hint.style.color = success ? '#b8e0bd' : '#f1c3a5';
    }
    form.addEventListener('submit', async function (event) {
        event.preventDefault();
        if (sending || !form.reportValidity()) return;
        var data = Object.fromEntries(new FormData(form).entries());
        var button = form.querySelector('[type="submit"]');
        sending = true; button.disabled = true; button.textContent = 'Отправляем…';
        try {
            if (!(await ready)) {
                var lines = ['Заявка с сайта vershina8848.ru:', 'Имя: ' + data.name, 'Телефон: ' + data.phone];
                if (data.service) lines.push('Услуга: ' + data.service);
                if (data.address) lines.push('Адрес: ' + data.address);
                if (data.area) lines.push('Площадь: ' + data.area + ' м²');
                if (productLabel) lines.push('Кондиционер: ' + productLabel);
                location.assign('https://wa.me/79996368698?text=' + encodeURIComponent(lines.join('\n')));
                return;
            }
            data.request_id = requestId;
            var controller = new AbortController();
            var timeout = setTimeout(function () { controller.abort(); }, 20000);
            var response;
            try {
                response = await fetch('/leads-api.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(data), signal: controller.signal });
            } finally { clearTimeout(timeout); }
            var result = await response.json();
            if (!response.ok || !result.ok) throw new Error(result.error || 'Не удалось сохранить заявку. Попробуйте ещё раз.');
            show('Спасибо! Заявка № ' + result.id + ' принята. Мы свяжемся с вами.', true);
            form.reset(); requestId = newRequestId();
            ['product_id', 'btu'].forEach(function (key) { form.elements[key].value = params.get(key) || ''; });
        } catch (error) {
            show(error.name === 'AbortError' ? 'Ответ задерживается. Повторите отправку — заявка не продублируется.' : error.message || 'Нет соединения. Повторите отправку или позвоните: +7 999 636-86-98.', false);
        } finally {
            sending = false; button.disabled = false; button.textContent = 'Отправить заявку';
        }
    });
})();
