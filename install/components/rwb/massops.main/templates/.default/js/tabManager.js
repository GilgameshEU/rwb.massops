/**
 * Управление табами навигации
 */
(function () {
    'use strict';

    window.RwbTabManager = {
        _tabs: null,
        _contents: null,

        /**
         * Инициализирует менеджер табов
         */
        init: function () {
            this._tabs = document.querySelectorAll('.rwb-massops__tab:not(.rwb-massops__tab--disabled)');
            this._contents = document.querySelectorAll('.rwb-massops__tab-content');

            var self = this;
            this._tabs.forEach(function (tab) {
                tab.addEventListener('click', function () {
                    self.switchTo(tab.getAttribute('data-tab'));
                });
            });
        },

        /**
         * Переключает на указанный таб
         *
         * @param {string} tabId Идентификатор таба
         */
        switchTo: function (tabId) {
            // Деактивируем все табы
            document.querySelectorAll('.rwb-massops__tab').forEach(function (t) {
                t.classList.remove('rwb-massops__tab--active');
            });

            this._contents.forEach(function (c) {
                c.classList.remove('rwb-massops__tab-content--active');
            });

            // Активируем нужный
            var tab = document.querySelector('.rwb-massops__tab[data-tab="' + tabId + '"]');
            var content = document.getElementById('rwb-tab-' + tabId);

            if (tab) {
                tab.classList.add('rwb-massops__tab--active');
            }
            if (content) {
                content.classList.add('rwb-massops__tab-content--active');
            }
        }
    };
})();
