(function ($) {
    // Як у формах теми: помилку під полем і червону рамку малює jQuery Validate, а не бульбашка браузера.
    // Тема без плагіна лишається з вбудованою перевіркою за атрибутом required.
    if (!$ || !$.fn.validate) {
        return;
    }
    // validate() на наборі форм підключається лише до першої, а форм тут стільки, скільки товарів.
    $('.review_request__forms form').each(function () {
        $(this).validate({
            errorPlacement: function (error, element) {
                // Радіо-група малює п'ять міток; без цього помилка сіла б між першою зіркою та її міткою.
                if (element.attr('name') === 'rating') {
                    error.appendTo(element.closest('.review_rating'));
                    return;
                }
                error.insertAfter(element);
            }
        });
    });
})(window.jQuery);

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
    // Адреса веде на #review_item_…: Chromium, прокручуючи до якоря після завантаження, знімає фокус,
    // бо сам якір фокуса не приймає. Тож фокус ставиться вже після цього.
    function focus() {
        setTimeout(function () {
            consent.focus();
        }, 0);
    }
    if (d.readyState === 'complete') {
        focus();
    } else {
        window.addEventListener('load', focus);
    }
})(document);
