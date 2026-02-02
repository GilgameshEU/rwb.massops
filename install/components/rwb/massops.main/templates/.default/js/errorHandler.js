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
        showImportErrors: function (allErrors, errorsByRow, successCount, container, isDryRun) {
            isDryRun = isDryRun || false;

            this.removeBlock('rwb-import-errors');

            var errorRowCount = Object.keys(errorsByRow).length;
            var totalRows = successCount + errorRowCount;

            // Модификатор цвета по результату
            var modifier;
            if (errorRowCount === 0) {
                modifier = 'success';
            } else if (successCount === 0) {
                modifier = 'error';
            } else {
                modifier = isDryRun ? 'info' : 'warning';
            }

            var block = this.createBlock('rwb-import-errors', modifier);

            var title = isDryRun ? 'Результаты Dry Run' : 'Результаты импорта';
            block.appendChild(this.createTitle(title, block));

            // Компактная статистика
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

            // Сводка по типам ошибок
            if (allErrors.length > 0) {
                var summary = this.buildErrorSummary(allErrors);
                if (summary.length > 0) {
                    var summaryTitle = BX.create('div', {
                        props: {className: 'rwb-result-summary-title'}
                    });
                    summaryTitle.textContent = 'Типы ошибок:';

                    block.appendChild(summaryTitle);
                    block.appendChild(this.createList(summary));
                }
            }

            container.appendChild(block);
            block.scrollIntoView({behavior: 'smooth', block: 'nearest'});
        },

        /**
         * Группирует ошибки по тексту сообщения
         *
         * @param {Array} errors
         * @returns {string[]}
         */
        buildErrorSummary: function (errors) {
            var counts = {};

            errors.forEach(function (error) {
                var msg = (error && error.message) ? error.message : String(error);
                counts[msg] = (counts[msg] || 0) + 1;
            });

            var result = [];
            Object.keys(counts).forEach(function (msg) {
                var count = counts[msg];
                result.push(count > 1 ? msg + ' (' + count + ' стр.)' : msg);
            });

            return result;
        },

        // --- Вспомогательные методы ---

        /**
         * Создаёт блок-обёртку с CSS-модификатором
         *
         * @param {string} id       ID блока
         * @param {string} modifier Модификатор цвета (warning|success|error|info)
         * @returns {HTMLElement}
         */
        createBlock: function (id, modifier) {
            return BX.create('div', {
                props: {
                    id: id,
                    className: 'rwb-result-block rwb-result-block--' + modifier
                }
            });
        },

        /**
         * Создаёт заголовок с кнопкой закрытия
         *
         * @param {string} text  Текст заголовка
         * @param {HTMLElement} block Блок для удаления
         * @returns {HTMLElement}
         */
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

        /**
         * Создаёт список <ul>
         *
         * @param {string[]} items
         * @returns {HTMLElement}
         */
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

        /**
         * Удаляет блок по ID
         *
         * @param {string} id
         */
        removeBlock: function (id) {
            var el = BX(id);
            if (el) {
                el.remove();
            }
        },

        /**
         * Парсит ошибки из Bitrix AJAX
         *
         * @param {Object} error
         * @returns {Array}
         */
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
