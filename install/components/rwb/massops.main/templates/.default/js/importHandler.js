/**
 * Модуль для обработки импорта данных
 */
(function () {
    'use strict';

    window.RwbImportImportHandler = {
        /**
         * Инициализирует обработчик импорта
         *
         * @param {HTMLElement} importBtn Кнопка импорта
         * @param {HTMLElement} container Контейнер для отображения ошибок
         */
        init: function (importBtn, container) {
            importBtn.onclick = function () {
                this.handleImport(importBtn, container);
            }.bind(this);
        },

        /**
         * Обрабатывает импорт данных
         *
         * @param {HTMLElement} importBtn Кнопка импорта
         * @param {HTMLElement} container Контейнер для отображения ошибок
         */
        handleImport: function (importBtn, container) {
            const originalText = importBtn.textContent;
            const dryRunCheckbox = BX('rwb-import-dry-run');
            const isDryRun = dryRunCheckbox && dryRunCheckbox.checked;

            // Показываем индикатор загрузки
            const loadingText = isDryRun ? 'Dry Run...' : 'Импорт...';
            this.setLoading(importBtn, true, loadingText);

            const action = isDryRun ? 'dryRunImport' : 'importCompanies';

            BX.ajax.runComponentAction('rwb:massops.main', action, {
                mode: 'class'
            }).then(function (response) {
                this.setLoading(importBtn, false, originalText);

                // Собираем все ошибки в один массив для отображения
                const allErrors = [];
                if (response.data && response.data.errors && Object.keys(response.data.errors).length > 0) {
                    Object.keys(response.data.errors).forEach(function (rowIndex) {
                        response.data.errors[rowIndex].forEach(function (error) {
                            allErrors.push(error);
                        });
                    });
                }

                const addedCount = response.data.added || response.data.wouldBeAdded || 0;
                const gridErrors = response.data.errors || {};
                const successRows = response.data.wouldBeAddedDetails || response.data.addedDetails || {};

                // Показываем компактные результаты
                window.RwbImportErrorHandler.showImportErrors(
                    allErrors,
                    gridErrors,
                    addedCount,
                    container,
                    isDryRun
                );

                // Подсветка строк грида
                if (window.RwbGridHighlighter) {
                    window.RwbGridHighlighter.highlight(gridErrors, successRows);
                    window.RwbGridHighlighter.setupObserver(gridErrors, successRows);
                }

            }.bind(this)).catch(function (error) {
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
        setLoading: function (button, isLoading, text) {
            button.disabled = isLoading;
            button.textContent = text;
        }
    };
})();
