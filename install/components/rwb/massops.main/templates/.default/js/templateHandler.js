/**
 * Модуль для обработки скачивания шаблона
 */
(function () {
    'use strict';

    window.RwbImportTemplateHandler = {
        /**
         * Инициализирует обработчик скачивания шаблона
         *
         * @param {HTMLElement} templateBtn Кнопка/ссылка скачивания шаблона
         * @param {Function} getEntityType Функция получения текущего типа сущности
         */
        init: function (templateBtn, getEntityType) {
            templateBtn.addEventListener('click', function (e) {
                e.preventDefault();

                var entityType = getEntityType();
                if (!entityType) {
                    return;
                }

                window.location.href =
                    '/bitrix/services/main/ajax.php?c=rwb:massops.main&action=downloadXlsxTemplate&mode=class&entityType=' +
                    encodeURIComponent(entityType);
            });
        }
    };
})();
