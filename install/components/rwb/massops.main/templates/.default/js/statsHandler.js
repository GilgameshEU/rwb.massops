/**
 * Модуль загрузки и отображения статистики импорта
 */
(function () {
    'use strict';

    window.RwbStatsHandler = {
        _currentPage: 1,
        _totalPages: 1,

        /**
         * Инициализация модуля
         */
        init: function () {
            var refreshBtn = BX('rwb-stats-refresh');
            if (refreshBtn) {
                refreshBtn.addEventListener('click', function () {
                    this._currentPage = 1;
                    this.load();
                }.bind(this));
            }
        },

        /**
         * Загружает данные статистики с сервера
         */
        load: function () {
            this._showLoading(true);
            this._hideEmpty();
            this._hideTable();
            this._hidePagination();

            BX.ajax.runComponentAction('rwb:massops.main', 'getStats', {
                mode: 'class',
                data: { page: this._currentPage }
            }).then(function (response) {
                this._showLoading(false);
                var data = response.data;
                this._totalPages = data.pagination.totalPages;
                this._currentPage = data.pagination.page;

                if (data.items.length === 0) {
                    this._showEmpty();
                    return;
                }

                this._renderTable(data.items);
                this._showTable();

                if (this._totalPages > 1) {
                    this._renderPagination(data.pagination);
                    this._showPagination();
                }
            }.bind(this)).catch(function (error) {
                this._showLoading(false);
                console.error('Stats load error:', error);
            }.bind(this));
        },

        /**
         * Рендерит тело таблицы
         *
         * @param {Array} items
         */
        _renderTable: function (items) {
            var tbody = BX('rwb-stats-tbody');
            tbody.innerHTML = '';

            var self = this;
            items.forEach(function (item) {
                var tr = document.createElement('tr');

                // Простые ячейки (текст / HTML)
                var simpleCells = [
                    { text: item.userName },
                    { text: item.entityTitle },
                    { html: self._renderStatusBadge(item.status) },
                    { text: item.totalRows },
                    { text: item.successCount },
                    { text: item.errorCount }
                ];

                simpleCells.forEach(function (cell) {
                    var td = document.createElement('td');
                    if (cell.html) {
                        td.innerHTML = cell.html;
                    } else {
                        td.textContent = cell.text;
                    }
                    tr.appendChild(td);
                });

                // Даты
                var dateCells = [
                    self._formatDate(item.createdAt),
                    self._formatDate(item.startedAt),
                    self._formatDate(item.finishedAt)
                ];

                dateCells.forEach(function (dateText) {
                    var td = document.createElement('td');
                    td.textContent = dateText;
                    tr.appendChild(td);
                });

                // Кнопка скачивания отчёта
                var reportTd = document.createElement('td');
                reportTd.innerHTML = self._renderDownloadButton(item);
                tr.appendChild(reportTd);

                tbody.appendChild(tr);
            });
        },

        /**
         * Рендерит кнопку скачивания отчёта
         *
         * @param {Object} item
         * @returns {string}
         */
        _renderDownloadButton: function (item) {
            // Показываем кнопку только для завершённых задач
            if (item.status !== 'completed' && item.status !== 'error') {
                return '\u2014';
            }

            return '<button class="ui-btn ui-btn-xs ui-btn-light-border rwb-stats__download-btn" '
                + 'onclick="RwbStatsHandler.downloadReport(' + item.id + ')" '
                + 'title="Скачать отчёт">'
                + '\u2193 XLSX'
                + '</button>';
        },

        /**
         * Скачивает отчёт по задаче импорта
         *
         * @param {number} jobId
         */
        downloadReport: function (jobId) {
            var form = document.createElement('form');
            form.method = 'POST';
            form.action = '/bitrix/services/main/ajax.php?c=rwb:massops.main&action=downloadStatsReport&mode=class';
            form.style.display = 'none';

            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'jobId';
            input.value = jobId;
            form.appendChild(input);

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
         * Возвращает HTML бейджа статуса
         *
         * @param {string} status
         * @returns {string}
         */
        _renderStatusBadge: function (status) {
            var labels = {
                'pending': '\u041e\u0436\u0438\u0434\u0430\u043d\u0438\u0435',
                'processing': '\u0412 \u043f\u0440\u043e\u0446\u0435\u0441\u0441\u0435',
                'completed': '\u0417\u0430\u0432\u0435\u0440\u0448\u0451\u043d',
                'error': '\u041e\u0448\u0438\u0431\u043a\u0430'
            };
            var label = labels[status] || status;
            return '<span class="rwb-stats__status rwb-stats__status--' + status + '">'
                + label + '</span>';
        },

        /**
         * Форматирует дату для отображения
         *
         * @param {string|null} dateStr
         * @returns {string}
         */
        _formatDate: function (dateStr) {
            if (!dateStr) {
                return '\u2014';
            }
            return dateStr;
        },

        /**
         * Рендерит пагинацию
         *
         * @param {Object} pagination
         */
        _renderPagination: function (pagination) {
            var container = BX('rwb-stats-pagination');
            container.innerHTML = '';
            var self = this;

            if (pagination.page > 1) {
                var prevBtn = document.createElement('button');
                prevBtn.className = 'ui-btn ui-btn-light ui-btn-xs';
                prevBtn.textContent = '\u2190 \u041d\u0430\u0437\u0430\u0434';
                prevBtn.addEventListener('click', function () {
                    self._currentPage = pagination.page - 1;
                    self.load();
                });
                container.appendChild(prevBtn);
            }

            var info = document.createElement('span');
            info.className = 'rwb-stats__page-info';
            info.textContent = '\u0421\u0442\u0440\u0430\u043d\u0438\u0446\u0430 ' + pagination.page
                + ' \u0438\u0437 ' + pagination.totalPages
                + ' (\u0432\u0441\u0435\u0433\u043e: ' + pagination.totalCount + ')';
            container.appendChild(info);

            if (pagination.page < pagination.totalPages) {
                var nextBtn = document.createElement('button');
                nextBtn.className = 'ui-btn ui-btn-light ui-btn-xs';
                nextBtn.textContent = '\u0414\u0430\u043b\u0435\u0435 \u2192';
                nextBtn.addEventListener('click', function () {
                    self._currentPage = pagination.page + 1;
                    self.load();
                });
                container.appendChild(nextBtn);
            }
        },

        // --- Хелперы видимости ---

        _showLoading: function (show) {
            var el = BX('rwb-stats-loading');
            if (el) el.style.display = show ? 'block' : 'none';
        },
        _showEmpty: function () {
            var el = BX('rwb-stats-empty');
            if (el) el.style.display = 'block';
        },
        _hideEmpty: function () {
            var el = BX('rwb-stats-empty');
            if (el) el.style.display = 'none';
        },
        _showTable: function () {
            var el = BX('rwb-stats-table-wrap');
            if (el) el.style.display = 'block';
        },
        _hideTable: function () {
            var el = BX('rwb-stats-table-wrap');
            if (el) el.style.display = 'none';
        },
        _showPagination: function () {
            var el = BX('rwb-stats-pagination');
            if (el) el.style.display = 'flex';
        },
        _hidePagination: function () {
            var el = BX('rwb-stats-pagination');
            if (el) el.style.display = 'none';
        }
    };
})();
