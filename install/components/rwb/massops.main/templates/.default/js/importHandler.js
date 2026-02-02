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
         * @param {Function} getEntityType Функция получения текущего типа сущности
         */
        init: function (importBtn, getEntityType) {
            var self = this;
            importBtn.addEventListener('click', function () {
                self.handleImport(importBtn, getEntityType);
            });
        },

        /**
         * Обрабатывает импорт данных
         *
         * @param {HTMLElement} importBtn
         * @param {Function} getEntityType
         */
        handleImport: function (importBtn, getEntityType) {
            var originalText = importBtn.textContent;
            var dryRunToggle = BX('rwb-import-dry-run');
            var isDryRun = dryRunToggle && dryRunToggle.checked;

            var loadingText = isDryRun ? 'Проверка...' : 'Импорт...';
            this.setLoading(importBtn, true, loadingText);

            var action = isDryRun ? 'runDryRun' : 'runImport';
            var entityType = getEntityType();

            var container = document.getElementById('rwb-results-container');

            BX.ajax.runComponentAction('rwb:massops.main', action, {
                mode: 'class',
                data: {entityType: entityType}
            }).then(function (response) {
                this.setLoading(importBtn, false, originalText);

                var allErrors = [];
                if (response.data && response.data.errors && Object.keys(response.data.errors).length > 0) {
                    Object.keys(response.data.errors).forEach(function (rowIndex) {
                        response.data.errors[rowIndex].forEach(function (error) {
                            allErrors.push(error);
                        });
                    });
                }

                var addedCount = response.data.added || response.data.wouldBeAdded || 0;
                var gridErrors = response.data.errors || {};
                var successRows = response.data.wouldBeAddedDetails || response.data.addedDetails || {};

                window.RwbImportErrorHandler.showImportErrors(
                    allErrors,
                    gridErrors,
                    addedCount,
                    container,
                    isDryRun
                );

                if (window.RwbGridHighlighter) {
                    window.RwbGridHighlighter.highlight(gridErrors, successRows);
                    window.RwbGridHighlighter.setupObserver(gridErrors, successRows);
                }

                // Обновляем текст кнопки в зависимости от режима
                this._updateButtonText(importBtn);

            }.bind(this)).catch(function (error) {
                this.setLoading(importBtn, false, originalText);

                var errors = window.RwbImportErrorHandler.parseBitrixError(error);
                if (errors.length === 0) {
                    errors.push({message: 'Произошла ошибка при импорте'});
                }

                window.RwbImportErrorHandler.showImportErrors(errors, {}, 0, container);
            }.bind(this));
        },

        /**
         * Обновляет текст кнопки по состоянию toggle
         *
         * @param {HTMLElement} btn
         */
        _updateButtonText: function (btn) {
            var dryRunToggle = BX('rwb-import-dry-run');
            var isDryRun = dryRunToggle && dryRunToggle.checked;
            btn.textContent = isDryRun ? 'Проверить' : 'Импортировать';
        },

        /**
         * Устанавливает состояние загрузки для кнопки
         *
         * @param {HTMLElement} button
         * @param {boolean} isLoading
         * @param {string} text
         */
        setLoading: function (button, isLoading, text) {
            button.disabled = isLoading;
            button.textContent = text;
        }
    };
})();
