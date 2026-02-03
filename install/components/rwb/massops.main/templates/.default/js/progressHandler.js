/**
 * Модуль отслеживания прогресса фонового импорта
 */
(function () {
    'use strict';

    window.RwbProgressHandler = {
        jobId: null,
        pollInterval: null,
        onComplete: null,

        /**
         * Запускает отслеживание прогресса
         *
         * @param {number} jobId ID задачи
         * @param {Function} onComplete Вызывается при завершении (result)
         */
        start: function (jobId, onComplete) {
            this.jobId = jobId;
            this.onComplete = onComplete;

            this.show();
            this.updateUi({progress: 0, status: 'pending', processedRows: 0, totalRows: 0});
            this.poll();

            this.pollInterval = setInterval(function () {
                this.poll();
            }.bind(this), 1500);
        },

        /**
         * Опрашивает сервер о прогрессе
         */
        poll: function () {
            BX.ajax.runComponentAction('rwb:massops.main', 'getProgress', {
                mode: 'class',
                data: {jobId: this.jobId}
            }).then(function (response) {
                var data = response.data;
                this.updateUi(data);

                if (data.isComplete) {
                    this.stop();
                    if (this.onComplete) {
                        this.onComplete(data);
                    }
                }
            }.bind(this)).catch(function (error) {
                this.stop();
                console.error('Progress poll error:', error);

                var container = document.getElementById('rwb-results-container');
                var errors = window.RwbImportErrorHandler.parseBitrixError(error);
                if (errors.length === 0) {
                    errors.push({message: 'Ошибка при получении статуса импорта'});
                }
                window.RwbImportErrorHandler.showImportErrors(errors, {}, 0, container);
            }.bind(this));
        },

        /**
         * Обновляет UI прогресса
         *
         * @param {Object} data
         */
        updateUi: function (data) {
            var fillEl = BX('rwb-progress-fill');
            var percentEl = BX('rwb-progress-percent');
            var labelEl = BX('rwb-progress-label');
            var statsEl = BX('rwb-progress-stats');

            if (fillEl) {
                fillEl.style.width = data.progress + '%';
            }

            if (percentEl) {
                percentEl.textContent = Math.round(data.progress) + '%';
            }

            if (labelEl) {
                var statusText = {
                    'pending': 'Ожидание очереди...',
                    'processing': 'Импорт...',
                    'completed': 'Завершено',
                    'error': 'Ошибка'
                };
                labelEl.textContent = statusText[data.status] || 'Импорт...';
            }

            if (statsEl) {
                statsEl.textContent = 'Обработано: ' + data.processedRows + ' из ' + data.totalRows;
            }
        },

        /**
         * Показывает прогресс-бар
         */
        show: function () {
            var el = BX('rwb-import-progress');
            if (el) {
                el.style.display = 'block';
            }
        },

        /**
         * Скрывает прогресс-бар
         */
        hide: function () {
            var el = BX('rwb-import-progress');
            if (el) {
                el.style.display = 'none';
            }
        },

        /**
         * Останавливает polling
         */
        stop: function () {
            if (this.pollInterval) {
                clearInterval(this.pollInterval);
                this.pollInterval = null;
            }
        }
    };
})();
