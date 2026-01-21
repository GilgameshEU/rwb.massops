/**
 * Модуль для обработки скачивания шаблона
 */
(function() {
    'use strict';

    window.RwbImportTemplateHandler = {
        /**
         * Инициализирует обработчик скачивания шаблона
         *
         * @param {HTMLElement} templateBtn Кнопка скачивания шаблона
         */
        init: function(templateBtn) {
            templateBtn.onclick = function() {
                window.location.href =
                    '/bitrix/services/main/ajax.php?c=rwb:massops.main&action=downloadTemplate&mode=class';
            };
        }
    };
})();
