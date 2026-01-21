/**
 * Модуль для обработки импорта данных
 */
(function() {
    'use strict';

    window.RwbImportImportHandler = {
        /**
         * Инициализирует обработчик импорта
         *
         * @param {HTMLElement} importBtn Кнопка импорта
         * @param {HTMLElement} container Контейнер для отображения ошибок
         */
        init: function(importBtn, container) {
            importBtn.onclick = function() {
                this.handleImport(importBtn, container);
            }.bind(this);
        },

        /**
         * Обрабатывает импорт данных
         *
         * @param {HTMLElement} importBtn Кнопка импорта
         * @param {HTMLElement} container Контейнер для отображения ошибок
         */
        handleImport: function(importBtn, container) {
            const originalText = importBtn.textContent;

            // Показываем индикатор загрузки
            this.setLoading(importBtn, true, 'Импорт...');

            BX.ajax.runComponentAction('rwb:massops.main', 'importCompanies', {
                mode: 'class'
            }).then(function(response) {
                this.setLoading(importBtn, false, originalText);

                // Проверяем наличие ошибок валидации
                if (response.data && response.data.errors && Object.keys(response.data.errors).length > 0) {
                    // Собираем все ошибки в один массив для отображения
                    const allErrors = [];
                    Object.keys(response.data.errors).forEach(function(rowIndex) {
                        response.data.errors[rowIndex].forEach(function(error) {
                            allErrors.push(error);
                        });
                    });

                    window.RwbImportErrorHandler.showImportErrors(
                        allErrors,
                        response.data.errors,
                        response.data.added || 0,
                        container
                    );
                } else {
                    // Если ошибок нет, показываем успешное сообщение
                    alert('Импорт завершён. Добавлено компаний: ' + (response.data.added || 0));
                    location.reload();
                }
            }.bind(this)).catch(function(error) {
                this.setLoading(importBtn, false, originalText);

                const errors = window.RwbImportErrorHandler.parseBitrixError(error);
                if (errors.length === 0) {
                    errors.push({message: 'Произошла ошибка при импорте'});
                }

                window.RwbImportErrorHandler.showImportErrors(errors, {}, 0, container);
            }.bind(this));
        },

        /**
         * Устанавливает состояние загрузки для кнопки
         *
         * @param {HTMLElement} button Кнопка
         * @param {boolean} isLoading Состояние загрузки
         * @param {string} text Текст кнопки
         */
        setLoading: function(button, isLoading, text) {
            button.disabled = isLoading;
            button.textContent = text;
        }
    };
})();
