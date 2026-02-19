/**
 * Модуль для обработки импорта данных
 *
 * Последовательный flow:
 *   Шаг 1: Кнопка «Проверить» (#rwb-import-run) — запускает dry run
 *   Шаг 2 (readyCount > 0): «Проверить» скрывается, появляется
 *          блок #rwb-import-actions с кнопкой «Импортировать компании (N шт.)»
 *          и toggle «Создать кабинеты» (только для company)
 *   Шаг 2 (readyCount = 0): «Проверить» скрывается, появляется
 *          надпись «Нет компаний для добавления»
 *   После импорта: возврат к Шагу 1
 */
(function () {
    'use strict';

    window.RwbImportImportHandler = {
        /** @type {HTMLElement|null} */
        checkBtn: null,
        /** @type {HTMLElement|null} */
        importBtn: null,
        /** @type {Function|null} */
        getEntityType: null,
        /** Количество строк, готовых к импорту (из последнего dry run) */
        lastReadyCount: null,

        /**
         * Инициализирует обработчик
         *
         * @param {HTMLElement} checkBtn   Кнопка «Проверить»
         * @param {HTMLElement} importBtn  Кнопка «Импортировать N ...»
         * @param {Function}    getEntityType
         */
        init: function (checkBtn, importBtn, getEntityType) {
            var self = this;
            this.checkBtn = checkBtn;
            this.importBtn = importBtn;
            this.getEntityType = getEntityType;

            checkBtn.addEventListener('click', function () {
                self._handleCheck();
            });

            if (importBtn) {
                importBtn.addEventListener('click', function () {
                    self._handleImport();
                });
            }
        },


        _handleCheck: function () {
            var self = this;
            var originalText = this.checkBtn.textContent;

            this.setLoading(this.checkBtn, true, 'Проверка...');

            var entityType = this.getEntityType();
            var container = document.getElementById('rwb-results-container');
            var createCabinets = this._getCreateCabinets();

            BX.ajax.runComponentAction('rwb:massops.main', 'runDryRun', {
                mode: 'class',
                data: {entityType: entityType, createCabinets: createCabinets ? 'Y' : 'N'}
            }).then(function (response) {
                self.setLoading(self.checkBtn, false, originalText);

                var allErrors = self._collectErrors(response.data.errors);
                var wouldBeAdded = response.data.wouldBeAdded || 0;
                var gridErrors = response.data.errors || {};
                var successRows = response.data.wouldBeAddedDetails || {};

                self.lastReadyCount = wouldBeAdded;

                window.RwbImportErrorHandler.showImportErrors(
                    allErrors, gridErrors, wouldBeAdded, container, true
                );

                if (window.RwbGridHighlighter) {
                    window.RwbGridHighlighter.highlight(gridErrors, successRows);
                    window.RwbGridHighlighter.setupObserver(gridErrors, successRows);
                }

                self._showPostCheckState(wouldBeAdded);
            }).catch(function (error) {
                self.setLoading(self.checkBtn, false, originalText);
                self._showError(error, container);
            });
        },


        _handleImport: function () {
            var self = this;
            var originalText = this.importBtn.textContent;

            this.setLoading(this.importBtn, true, 'Запуск импорта...');

            var entityType = this.getEntityType();
            var container = document.getElementById('rwb-results-container');
            var createCabinets = this._getCreateCabinets();

            BX.ajax.runComponentAction('rwb:massops.main', 'startImport', {
                mode: 'class',
                data: {entityType: entityType, createCabinets: createCabinets ? 'Y' : 'N'}
            }).then(function (response) {
                var jobId = response.data.jobId;

                window.RwbProgressHandler.start(jobId, function (result) {
                    self.setLoading(self.importBtn, false, originalText);
                    window.RwbProgressHandler.hide();

                    var allErrors = self._collectErrors(result.errors);
                    var gridErrors = result.errors || {};

                    if (result.status === 'error' && result.errorMessage) {
                        allErrors.push({
                            type: 'system',
                            code: 'PROCESSING_EXCEPTION',
                            message: result.errorMessage
                        });
                    }

                    window.RwbImportErrorHandler.showImportErrors(
                        allErrors, gridErrors, result.successCount || 0, container, false, result.jobId
                    );

                    var successRows = self._buildSuccessRows(
                        result.totalRows || 0, gridErrors
                    );

                    if (window.RwbGridHighlighter) {
                        window.RwbGridHighlighter.highlight(gridErrors, successRows);
                        window.RwbGridHighlighter.setupObserver(gridErrors, successRows);
                    }

                    self._showCompleteState(result);
                    self.lastReadyCount = null;
                });

            }).catch(function (error) {
                self.setLoading(self.importBtn, false, originalText);
                self._showError(error, container);
            });
        },


        /**
         * Показывает состояние после проверки
         */
        _showPostCheckState: function (readyCount) {
            var actionsWrap = BX('rwb-import-actions');
            var nothingMsg = BX('rwb-import-nothing');
            var cabinetsWrap = BX('rwb-create-cabinets-wrap');

            this.checkBtn.style.display = 'none';

            if (readyCount > 0) {
                if (actionsWrap) {
                    actionsWrap.style.display = '';
                }
                if (nothingMsg) {
                    nothingMsg.style.display = 'none';
                }

                if (cabinetsWrap) {
                    var entityType = this.getEntityType();
                    cabinetsWrap.style.display = (entityType === 'company') ? '' : 'none';
                }

                if (this.importBtn) {
                    this.importBtn.textContent = 'Импортировать компании (' + readyCount + ' шт.)';
                    this.importBtn.disabled = false;
                }
            } else {
                if (actionsWrap) {
                    actionsWrap.style.display = 'none';
                }
                if (nothingMsg) {
                    nothingMsg.style.display = '';
                }
            }
        },

        /**
         * Показывает состояние после завершения импорта
         */
        _showCompleteState: function (result) {
            var actionsWrap = BX('rwb-import-actions');
            var nothingMsg = BX('rwb-import-nothing');
            var completeActions = BX('rwb-import-complete-actions');
            var infoEl = document.querySelector('.rwb-preview__info');

            this.checkBtn.style.display = 'none';

            if (actionsWrap) {
                actionsWrap.style.display = 'none';
            }
            if (nothingMsg) {
                nothingMsg.style.display = 'none';
            }
            if (completeActions) {
                completeActions.style.display = '';
            }
            if (infoEl) {
                infoEl.innerHTML = 'Добавлено компаний: <strong>' + (result.successCount || 0) + '</strong>';
            }
        },

        /**
         * Возврат toolbar в начальное состояние (только «Проверить»)
         */
        _resetToolbar: function () {
            var actionsWrap = BX('rwb-import-actions');
            var nothingMsg = BX('rwb-import-nothing');

            this.checkBtn.style.display = '';

            if (actionsWrap) {
                actionsWrap.style.display = 'none';
            }
            if (nothingMsg) {
                nothingMsg.style.display = 'none';
            }
        },


        _getCreateCabinets: function () {
            var toggle = BX('rwb-create-cabinets');
            return toggle && toggle.checked;
        },

        /**
         * Собирает плоский массив ошибок из grid-формата
         */
        _collectErrors: function (errorsObj) {
            var allErrors = [];
            if (errorsObj && typeof errorsObj === 'object') {
                Object.keys(errorsObj).forEach(function (rowIndex) {
                    var rowErrors = errorsObj[rowIndex];
                    if (Array.isArray(rowErrors)) {
                        rowErrors.forEach(function (error) {
                            allErrors.push(error);
                        });
                    } else if (rowErrors && typeof rowErrors === 'object') {
                        allErrors.push(rowErrors);
                    }
                });
            }
            return allErrors;
        },

        /**
         * Строит объект успешных строк из общего числа минус ошибочные
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

        _showError: function (error, container) {
            var errors = window.RwbImportErrorHandler.parseBitrixError(error);
            if (errors.length === 0) {
                errors.push({message: 'Произошла ошибка при импорте'});
            }
            window.RwbImportErrorHandler.showImportErrors(errors, {}, 0, container);
        },

        /**
         * Устанавливает состояние загрузки для кнопки
         */
        setLoading: function (button, isLoading, text) {
            button.disabled = isLoading;
            button.textContent = text;
        }
    };
})();
