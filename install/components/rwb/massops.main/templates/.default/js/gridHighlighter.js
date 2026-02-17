/**
 * Модуль для подсветки строк грида по результатам импорта
 */
(function () {
    'use strict';

    window.RwbGridHighlighter = {
        CSS_ERROR_ROW: 'rwb-grid-row-error',
        CSS_DUPLICATE_ROW: 'rwb-grid-row-duplicate',
        CSS_SUCCESS_ROW: 'rwb-grid-row-success',

        _observer: null,
        _observerTimeout: null,
        _lastErrorsByRow: null,
        _lastSuccessRows: null,

        /**
         * Подсвечивает строки грида по результатам импорта/dry-run
         *
         * @param {Object} errorsByRow Ошибки по индексу строки {0: [{message, ...}], ...}
         * @param {Object} successRows Успешные строки (ключи — индексы строк)
         */
        highlight: function (errorsByRow, successRows) {
            this.clearHighlights();

            var self = this;

            // Подсвечиваем строки с ошибками (красные или синие для дублей)
            Object.keys(errorsByRow).forEach(function (rowIdx) {
                var rowEl = self.getRowElement(rowIdx);
                if (!rowEl) {
                    return;
                }

                var rowErrors = errorsByRow[rowIdx];
                // Приводим к массиву (PHP может вернуть объект вместо массива)
                if (!Array.isArray(rowErrors)) {
                    rowErrors = typeof rowErrors === 'object' && rowErrors !== null
                        ? Object.values(rowErrors)
                        : [rowErrors];
                }

                var messages = rowErrors.map(function (e) {
                    return e.message || String(e);
                });

                // Проверяем, есть ли ошибки дублей
                var hasDuplicateError = rowErrors.some(function (e) {
                    return e.type === 'duplicate';
                });

                // Синий для дублей, красный для остальных ошибок
                var cssClass = hasDuplicateError ? self.CSS_DUPLICATE_ROW : self.CSS_ERROR_ROW;
                rowEl.classList.add(cssClass);
                rowEl.setAttribute('title', messages.join('\n'));
            });

            // Зелёные успешные строки (не ошибки и не дубли)
            Object.keys(successRows).forEach(function (rowIdx) {
                var rowEl = self.getRowElement(rowIdx);
                if (rowEl &&
                    !rowEl.classList.contains(self.CSS_ERROR_ROW) &&
                    !rowEl.classList.contains(self.CSS_DUPLICATE_ROW)
                ) {
                    rowEl.classList.add(self.CSS_SUCCESS_ROW);
                }
            });
        },

        /**
         * Находит элемент строки <tr> по индексу
         *
         * @param {string|number} rowIdx Индекс строки
         * @returns {HTMLElement|null}
         */
        getRowElement: function (rowIdx) {
            var container = BX('rwb-grid-container');
            if (!container) {
                return null;
            }
            return container.querySelector('tr.main-grid-row[data-id="row_' + rowIdx + '"]');
        },

        /**
         * Убирает все подсветки с грида
         */
        clearHighlights: function () {
            var classes = [this.CSS_ERROR_ROW, this.CSS_DUPLICATE_ROW, this.CSS_SUCCESS_ROW];
            classes.forEach(function (cls) {
                var elements = document.querySelectorAll('.' + cls);
                for (var i = 0; i < elements.length; i++) {
                    elements[i].classList.remove(cls);
                    elements[i].removeAttribute('title');
                }
            });
        },

        /**
         * MutationObserver для переприменения подсветки при перерисовке грида
         *
         * @param {Object} errorsByRow
         * @param {Object} successRows
         */
        setupObserver: function (errorsByRow, successRows) {
            this.destroyObserver();

            this._lastErrorsByRow = errorsByRow;
            this._lastSuccessRows = successRows;

            var gridContainer = BX('rwb-grid-container');
            if (!gridContainer) {
                return;
            }

            var self = this;
            this._observer = new MutationObserver(function () {
                clearTimeout(self._observerTimeout);
                self._observerTimeout = setTimeout(function () {
                    self.highlight(
                        self._lastErrorsByRow,
                        self._lastSuccessRows
                    );
                }, 150);
            });

            this._observer.observe(gridContainer, {
                childList: true,
                subtree: true
            });
        },

        /**
         * Отключает observer
         */
        destroyObserver: function () {
            if (this._observer) {
                this._observer.disconnect();
                this._observer = null;
            }
            clearTimeout(this._observerTimeout);
            this._lastErrorsByRow = null;
            this._lastSuccessRows = null;
        }
    };
})();
