(function (d) {
    // Після відмови за згодою сторінка приходить редіректом, тож чекбокс позначає й фокусує цей скрипт:
    // плагін згоди бачить відмову лише в тому самому запиті.
    var marker = d.querySelector('[data-rr-consent-refused]');
    var form = marker && marker.closest('form');
    var consent = form && form.querySelector('#sviat-pd-review_request-' + marker.getAttribute('data-rr-consent-refused'));
    if (!consent) {
        return;
    }
    var error = d.getElementById(consent.getAttribute('aria-describedby'));
    if (error) {
        error.textContent = marker.textContent.trim();
        // Текст переходить під чекбокс; атрибут hidden тут не діє, бо тема задає .message_error свій display.
        marker.remove();
    }
    consent.setAttribute('aria-invalid', 'true');
    consent.addEventListener('change', function () {
        if (consent.checked) {
            consent.removeAttribute('aria-invalid');
            if (error) {
                error.textContent = '';
            }
        }
    });
    consent.focus();
})(document);
