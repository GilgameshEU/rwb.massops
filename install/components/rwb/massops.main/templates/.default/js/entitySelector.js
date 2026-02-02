/**
 * Модуль выбора CRM-сущности
 */
(function () {
    'use strict';

    window.RwbEntitySelector = {
        _selectedEntity: null,
        _cards: null,
        _onSelect: null,

        /**
         * Инициализирует селектор сущностей
         *
         * @param {Function} onSelect Callback при выборе сущности
         * @param {string|null} preselected Предвыбранная сущность
         */
        init: function (onSelect, preselected) {
            this._onSelect = onSelect;
            this._cards = document.querySelectorAll('.rwb-entity-card');

            var self = this;
            this._cards.forEach(function (card) {
                card.addEventListener('click', function () {
                    self.select(card.getAttribute('data-entity'));
                });
            });

            if (preselected) {
                this.select(preselected);
            }
        },

        /**
         * Выбирает сущность
         *
         * @param {string} entityType Тип сущности
         */
        select: function (entityType) {
            this._selectedEntity = entityType;

            // Обновляем визуальное состояние
            this._cards.forEach(function (card) {
                if (card.getAttribute('data-entity') === entityType) {
                    card.classList.add('rwb-entity-card--selected');
                } else {
                    card.classList.remove('rwb-entity-card--selected');
                }
            });

            if (this._onSelect) {
                this._onSelect(entityType);
            }
        },

        /**
         * Возвращает выбранную сущность
         *
         * @returns {string|null}
         */
        getSelected: function () {
            return this._selectedEntity;
        }
    };
})();
