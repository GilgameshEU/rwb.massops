/**
 * Модуль загрузки и отображения статистики импорта
 */
(function () {
    'use strict';

    /** Сколько ID показывать без раскрытия */
    var IDS_PREVIEW_COUNT = 5;

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
                    { text: item.id },
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

                // Ячейка «Добавлено» — раскрывающийся список ID
                var idsTd = document.createElement('td');
                idsTd.innerHTML = self._renderCreatedIds(item.createdIds || [], item.entityType);
                tr.appendChild(idsTd);

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

                tbody.appendChild(tr);
            });
        },

        /**
         * Возвращает HTML для списка созданных ID
         *
         * До IDS_PREVIEW_COUNT — показываем все.
         * Больше — показываем первые N и кнопку «ещё X».
         * По клику раскрывается полный список.
         *
         * @param {Array} ids
         * @param {string} entityType
         * @returns {string}
         */
        _renderCreatedIds: function (ids, entityType) {
            if (!ids || ids.length === 0) {
                return '\u2014';
            }

            var crmPath = this._getCrmPath(entityType);

            // Мало ID — показываем все
            if (ids.length <= IDS_PREVIEW_COUNT) {
                return this._renderIdLinks(ids, crmPath);
            }

            // Много — показываем превью + кнопку раскрытия
            var previewIds = ids.slice(0, IDS_PREVIEW_COUNT);
            var restIds = ids.slice(IDS_PREVIEW_COUNT);
            var uid = 'rwb-ids-' + Math.random().toString(36).substr(2, 8);

            var html = '<span class="rwb-stats__ids">';
            html += this._renderIdLinks(previewIds, crmPath);
            html += '<span class="rwb-stats__ids-rest" id="' + uid + '" style="display:none;">';
            html += ', ' + this._renderIdLinks(restIds, crmPath);
            html += '</span>';
            html += ' <a class="rwb-stats__ids-toggle" href="javascript:void(0)" '
                + 'onclick="var el=document.getElementById(\'' + uid + '\');'
                + 'el.style.display=el.style.display===\'none\'?\'inline\':\'none\';'
                + 'this.textContent=el.style.display===\'none\''
                + '?\'\u0435\u0449\u0451 ' + restIds.length + '\''
                + ':\'\u0441\u043a\u0440\u044b\u0442\u044c\';">'
                + '\u0435\u0449\u0451 ' + restIds.length + '</a>';
            html += '</span>';

            return html;
        },

        /**
         * Рендерит ссылки на ID сущностей
         *
         * @param {Array} ids
         * @param {string} crmPath
         * @returns {string}
         */
        _renderIdLinks: function (ids, crmPath) {
            if (!crmPath) {
                return ids.join(', ');
            }

            return ids.map(function (id) {
                return '<a class="rwb-stats__id-link" href="' + crmPath + id + '/" target="_blank">'
                    + id + '</a>';
            }).join(', ');
        },

        /**
         * Возвращает путь CRM для типа сущности
         *
         * @param {string} entityType
         * @returns {string}
         */
        _getCrmPath: function (entityType) {
            var paths = {
                'company': '/crm/company/details/',
                'contact': '/crm/contact/details/',
                'deal': '/crm/deal/details/'
            };
            return paths[entityType] || '';
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
