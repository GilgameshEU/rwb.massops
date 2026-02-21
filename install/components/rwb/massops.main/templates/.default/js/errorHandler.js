/**
 * Модуль для обработки и отображения результатов импорта
 */
(function () {
    'use strict';

    window.RwbImportErrorHandler = {
        /**
         * Отображает ошибки загрузки файла
         *
         * @param {Array} errors Массив ошибок
         * @param {HTMLElement} container Контейнер для вставки блока ошибок
         */
        showUploadErrors: function (errors, container) {
            this.removeBlock('rwb-upload-errors');

            var block = this.createBlock('rwb-upload-errors', 'warning');

            block.appendChild(this.createTitle('Ошибки при загрузке файла:', block));

            var list = this.createList(errors.map(function (e) {
                return typeof e === 'string' ? e : (e.message || JSON.stringify(e));
            }));
            block.appendChild(list);

            container.appendChild(block);
            block.scrollIntoView({behavior: 'smooth', block: 'nearest'});
        },

        /**
         * Отображает результаты импорта в компактном формате
         *
         * @param {Array} allErrors Плоский массив всех ошибок
         * @param {Object} errorsByRow Ошибки по строкам {rowIdx: [errors]}
         * @param {number} successCount Количество успешных записей
         * @param {HTMLElement} container Контейнер
         * @param {boolean} isDryRun Флаг dry run
         */
        showImportErrors: function (allErrors, errorsByRow, successCount, container, isDryRun, jobId) {
            var self = this;
            isDryRun = isDryRun || false;

            this.removeBlock('rwb-import-errors');

            var errorRowCount = Object.keys(errorsByRow).length;
            var totalRows = successCount + errorRowCount;

            var modifier;
            if (errorRowCount === 0) {
                modifier = 'success';
            } else if (successCount === 0) {
                modifier = 'error';
            } else {
                modifier = isDryRun ? 'info' : 'warning';
            }

            var block = this.createBlock('rwb-import-errors', modifier);

            var title = isDryRun ? 'Результаты проверки' : 'Результаты импорта';
            block.appendChild(this.createTitle(title, block));

            var successLabel = isDryRun ? 'Готово к добавлению' : 'Успешно';
            var statsText = 'Всего: ' + totalRows
                + '  |  ' + successLabel + ': ' + successCount
                + '  |  Ошибки: ' + errorRowCount;

            var statsEl = BX.create('div', {
                props: {className: 'rwb-result-stats'}
            });
            statsEl.textContent = statsText;

            if (errorRowCount > 0) {
                statsEl.style.marginBottom = '10px';
            }

            block.appendChild(statsEl);

            if (allErrors.length > 0) {
                var grouped = this.buildGroupedErrors(allErrors);
                var groupedHtml = this.buildGroupedErrorsHtml(grouped);
                block.appendChild(groupedHtml);

                if (isDryRun) {
                    var downloadBtn = BX.create('button', {
                        props: {className: 'ui-btn ui-btn-light ui-btn-xs rwb-result-download'},
                        text: 'Скачать отчёт с ошибками'
                    });
                    downloadBtn.onclick = function () {
                        self.downloadErrorReport(errorsByRow);
                    };
                    block.appendChild(downloadBtn);
                }
            }

            if (!isDryRun && jobId) {
                var downloadResultBtn = BX.create('button', {
                    props: {className: 'ui-btn ui-btn-light ui-btn-xs rwb-result-download'},
                    text: 'Скачать отчёт с результатами'
                });
                downloadResultBtn.onclick = function () {
                    self.downloadStatsReport(jobId);
                };
                block.appendChild(downloadResultBtn);
            }

            container.appendChild(block);
            block.scrollIntoView({behavior: 'smooth', block: 'nearest'});
        },

        /**
         * Скачивает XLSX-отчёт с ошибками
         *
         * @param {Object} errorsByRow Ошибки по строкам
         */
        downloadErrorReport: function (errorsByRow) {
            var form = document.createElement('form');
            form.method = 'POST';
            form.action = '/bitrix/services/main/ajax.php?c=rwb:massops.main&action=downloadErrorReport&mode=class';
            form.style.display = 'none';

            var sessidInput = document.createElement('input');
            sessidInput.type = 'hidden';
            sessidInput.name = 'sessid';
            sessidInput.value = BX.bitrix_sessid();
            form.appendChild(sessidInput);

            var errorsInput = document.createElement('input');
            errorsInput.type = 'hidden';
            errorsInput.name = 'errors';
            errorsInput.value = JSON.stringify(errorsByRow);
            form.appendChild(errorsInput);

            document.body.appendChild(form);
            form.submit();
            document.body.removeChild(form);
        },

        /**
         * Скачивает XLSX-отчёт с результатами импорта
         *
         * @param {number} jobId ID задачи импорта
         */
        downloadStatsReport: function (jobId) {
            var form = document.createElement('form');
            form.method = 'POST';
            form.action = '/bitrix/services/main/ajax.php?c=rwb:massops.main&action=downloadStatsReport&mode=class';
            form.style.display = 'none';

            var jobInput = document.createElement('input');
            jobInput.type = 'hidden';
            jobInput.name = 'jobId';
            jobInput.value = jobId;
            form.appendChild(jobInput);

            var sessidInput = document.createElement('input');
            sessidInput.type = 'hidden';
            sessidInput.name = 'sessid';
            sessidInput.value = BX.bitrix_sessid();
            form.appendChild(sessidInput);

            document.body.appendChild(form);
            form.submit();
            document.body.removeChild(form);
        },

        /**
         * Группирует ошибки по категориям в человекочитаемом формате
         *
         * @param {Array} errors
         * @returns {Object} Объект с категориями ошибок
         */
        buildGroupedErrors: function (errors) {
            var validationErrors = {};   // code -> {message, rows: []}
            var fileDuplicates = {};     // value -> [rowNumbers]
            var crmDuplicates = [];      // [{row, entityId, message}]

            errors.forEach(function (error) {
                if (!error) return;

                var code = error.code || '';
                var type = error.type || '';
                var row = error.row;
                var context = error.context || {};

                if (code === 'DUPLICATE_IN_FILE') {
                    var dupKey = String(context.inn || context.value || row);
                    if (!fileDuplicates[dupKey]) {
                        fileDuplicates[dupKey] = [];
                    }
                    if (row && fileDuplicates[dupKey].indexOf(row) === -1) {
                        fileDuplicates[dupKey].push(row);
                    }
                } else if (code === 'DUPLICATE_IN_CRM') {
                    var existingId = context.existingCompanyId || context.existingContactId || null;
                    crmDuplicates.push({
                        row: row,
                        entityId: existingId,
                        message: error.message || ''
                    });
                } else {
                    var key = code || error.message || 'UNKNOWN';
                    if (!validationErrors[key]) {
                        validationErrors[key] = {
                            message: error.message || key,
                            rows: []
                        };
                    }
                    if (row && validationErrors[key].rows.indexOf(row) === -1) {
                        validationErrors[key].rows.push(row);
                    }
                }
            });

            return {
                validation: validationErrors,
                fileDuplicates: fileDuplicates,
                crmDuplicates: crmDuplicates
            };
        },

        /**
         * Строит HTML-содержимое для группированных ошибок
         *
         * @param {Object} grouped Результат buildGroupedErrors
         * @returns {DocumentFragment}
         */
        buildGroupedErrorsHtml: function (grouped) {
            var fragment = document.createDocumentFragment();
            var self = this;

            var validationKeys = Object.keys(grouped.validation);
            if (validationKeys.length > 0) {
                var valSection = BX.create('div', {props: {className: 'rwb-error-section'}});
                var valTitle = BX.create('div', {
                    props: {className: 'rwb-error-section-title'},
                    text: 'Ошибки валидации:'
                });
                valSection.appendChild(valTitle);

                var valList = BX.create('ul', {props: {className: 'rwb-result-list'}});
                validationKeys.forEach(function (key) {
                    var item = grouped.validation[key];
                    var li = BX.create('li');
                    var rowsText = item.rows.length > 0
                        ? ' — строки: ' + item.rows.sort(function (a, b) {
                        return a - b;
                    }).join(', ')
                        : '';
                    li.textContent = item.message + rowsText;
                    valList.appendChild(li);
                });
                valSection.appendChild(valList);
                fragment.appendChild(valSection);
            }

            var fileInnKeys = Object.keys(grouped.fileDuplicates);
            if (fileInnKeys.length > 0) {
                var fileSection = BX.create('div', {props: {className: 'rwb-error-section'}});
                var fileTitle = BX.create('div', {
                    props: {className: 'rwb-error-section-title'},
                    text: 'Дубликаты внутри файла:'
                });
                fileSection.appendChild(fileTitle);

                var fileList = BX.create('ul', {props: {className: 'rwb-result-list'}});
                fileInnKeys.forEach(function (dupKey) {
                    var rows = grouped.fileDuplicates[dupKey].sort(function (a, b) {
                        return a - b;
                    });
                    var li = BX.create('li');
                    li.textContent = '"' + dupKey + '" — строки: ' + rows.join(', ');
                    fileList.appendChild(li);
                });
                fileSection.appendChild(fileList);
                fragment.appendChild(fileSection);
            }

            if (grouped.crmDuplicates.length > 0) {
                var crmSection = BX.create('div', {props: {className: 'rwb-error-section'}});
                var crmTitle = BX.create('div', {
                    props: {className: 'rwb-error-section-title'},
                    text: 'Дубликаты в CRM:'
                });
                crmSection.appendChild(crmTitle);

                var crmList = BX.create('ul', {props: {className: 'rwb-result-list'}});
                grouped.crmDuplicates
                    .sort(function (a, b) {
                        return a.row - b.row;
                    })
                    .forEach(function (dup) {
                        var li = BX.create('li');
                        var dupText = dup.message
                            ? 'Строка ' + dup.row + ': ' + dup.message
                            : 'Строка ' + dup.row + ': запись уже существует' + (dup.entityId ? ' (ID: ' + dup.entityId + ')' : '');
                        li.textContent = dupText;
                        crmList.appendChild(li);
                    });
                crmSection.appendChild(crmList);
                fragment.appendChild(crmSection);
            }

            return fragment;
        },


        createBlock: function (id, modifier) {
            return BX.create('div', {
                props: {
                    id: id,
                    className: 'rwb-result-block rwb-result-block--' + modifier
                }
            });
        },

        createTitle: function (text, block) {
            var header = BX.create('div', {
                props: {className: 'rwb-result-header'}
            });

            var titleEl = BX.create('div', {
                props: {className: 'rwb-result-title'}
            });
            titleEl.textContent = text;

            var closeBtn = BX.create('span', {
                props: {className: 'rwb-result-close'}
            });
            closeBtn.textContent = '\u00d7';
            closeBtn.onclick = function () {
                block.remove();
            };

            header.appendChild(titleEl);
            header.appendChild(closeBtn);
            return header;
        },

        createList: function (items) {
            var ul = BX.create('ul', {
                props: {className: 'rwb-result-list'}
            });

            items.forEach(function (text) {
                var li = BX.create('li');
                li.textContent = text;
                ul.appendChild(li);
            });

            return ul;
        },

        removeBlock: function (id) {
            var el = BX(id);
            if (el) {
                el.remove();
            }
        },

        parseBitrixError: function (error) {
            var errors = [];

            if (error.status && error.errors && error.errors.length > 0) {
                errors = error.errors.map(function (e) {
                    return {message: e.message || String(e), code: e.code || 'ERROR'};
                });
            } else if (error.message) {
                errors = [{message: error.message}];
            } else if (typeof error === 'string') {
                errors = [{message: error}];
            }

            return errors;
        }
    };
})();
