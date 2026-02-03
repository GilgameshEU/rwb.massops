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
         * Обрабатывает импорт / dry-run
         *
         * @param {HTMLElement} importBtn
         * @param {Function} getEntityType
         */
        handleImport: function (importBtn, getEntityType) {
            var originalText = importBtn.textContent;
            var dryRunToggle = BX('rwb-import-dry-run');
            var isDryRun = dryRunToggle && dryRunToggle.checked;

            var loadingText = isDryRun ? 'Проверка...' : 'Запуск импорта...';
            this.setLoading(importBtn, true, loadingText);

            var entityType = getEntityType();
            var container = document.getElementById('rwb-results-container');

            if (isDryRun) {
                this._runDryRun(importBtn, originalText, entityType, container);
            } else {
                this._runAsyncImport(importBtn, originalText, entityType, container);
            }
        },

        /**
         * Синхронный dry run
         */
        _runDryRun: function (importBtn, originalText, entityType, container) {
            BX.ajax.runComponentAction('rwb:massops.main', 'runDryRun', {
                mode: 'class',
                data: {entityType: entityType}
            }).then(function (response) {
                this.setLoading(importBtn, false, originalText);

                var allErrors = this._collectErrors(response.data.errors);
                var wouldBeAdded = response.data.wouldBeAdded || 0;
                var gridErrors = response.data.errors || {};
                var successRows = response.data.wouldBeAddedDetails || {};

                window.RwbImportErrorHandler.showImportErrors(
                    allErrors, gridErrors, wouldBeAdded, container, true
                );

                if (window.RwbGridHighlighter) {
                    window.RwbGridHighlighter.highlight(gridErrors, successRows);
                    window.RwbGridHighlighter.setupObserver(gridErrors, successRows);
                }

                this._updateButtonText(importBtn);
            }.bind(this)).catch(function (error) {
                this.setLoading(importBtn, false, originalText);
                this._showError(error, container);
            }.bind(this));
        },

        /**
         * Асинхронный импорт через очередь
         */
        _runAsyncImport: function (importBtn, originalText, entityType, container) {
            BX.ajax.runComponentAction('rwb:massops.main', 'startImport', {
                mode: 'class',
                data: {entityType: entityType}
            }).then(function (response) {
                var jobId = response.data.jobId;

                window.RwbProgressHandler.start(jobId, function (result) {
                    this.setLoading(importBtn, false, originalText);
                    window.RwbProgressHandler.hide();

                    var allErrors = this._collectErrors(result.errors);
                    var gridErrors = result.errors || {};

                    window.RwbImportErrorHandler.showImportErrors(
                        allErrors, gridErrors, result.successCount || 0, container, false
                    );

                    // Вычисляем successRows: все строки, которых нет в ошибках
                    var successRows = this._buildSuccessRows(
                        result.totalRows || 0, gridErrors
                    );

                    if (window.RwbGridHighlighter) {
                        window.RwbGridHighlighter.highlight(gridErrors, successRows);
                        window.RwbGridHighlighter.setupObserver(gridErrors, successRows);
                    }

                    this._updateButtonText(importBtn);
                }.bind(this));

            }.bind(this)).catch(function (error) {
                this.setLoading(importBtn, false, originalText);
                this._showError(error, container);
            }.bind(this));
        },

        /**
         * Собирает плоский массив ошибок из grid-формата
         *
         * @param {Object} errorsObj
         * @returns {Array}
         */
        _collectErrors: function (errorsObj) {
            var allErrors = [];
            if (errorsObj && Object.keys(errorsObj).length > 0) {
                Object.keys(errorsObj).forEach(function (rowIndex) {
                    errorsObj[rowIndex].forEach(function (error) {
                        allErrors.push(error);
                    });
                });
            }
            return allErrors;
        },

        /**
         * Строит объект успешных строк из общего числа минус ошибочные
         *
         * @param {number} totalRows Общее количество строк
         * @param {Object} gridErrors Ошибки по индексам строк
         * @returns {Object} Объект с ключами — индексами успешных строк
         */
        _buildSuccessRows: function (totalRows, gridErrors) {
            var successRows = {};
            for (var i = 0; i < totalRows; i++) {
                if (!gridErrors[i]) {
                    successRows[i] = {row: i + 1};
                }
            }
            return successRows;
        },

        /**
         * Показывает ошибку
         *
         * @param {Object} error
         * @param {HTMLElement} container
         */
        _showError: function (error, container) {
            var errors = window.RwbImportErrorHandler.parseBitrixError(error);
            if (errors.length === 0) {
                errors.push({message: 'Произошла ошибка при импорте'});
            }
            window.RwbImportErrorHandler.showImportErrors(errors, {}, 0, container);
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
