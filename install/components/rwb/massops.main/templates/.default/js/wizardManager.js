/**
 * Управление степпером wizard
 */
(function () {
    'use strict';

    window.RwbWizardManager = {
        _currentStep: 1,
        _steps: null,
        _panels: null,

        /**
         * Инициализирует wizard
         *
         * @param {number} [initialStep] Начальный шаг
         */
        init: function (initialStep) {
            this._steps = document.querySelectorAll('.rwb-wizard__step');
            this._panels = document.querySelectorAll('.rwb-wizard__panel');

            if (initialStep) {
                this.goTo(initialStep);
            }
        },

        /**
         * Переходит к указанному шагу
         *
         * @param {number} step Номер шага (1-3)
         */
        goTo: function (step) {
            if (step < 1 || step > this._steps.length) {
                return;
            }

            this._currentStep = step;
            this._updateStepper();
            this._updatePanels();
        },

        /**
         * Переходит к следующему шагу
         */
        next: function () {
            this.goTo(this._currentStep + 1);
        },

        /**
         * Переходит к предыдущему шагу
         */
        back: function () {
            this.goTo(this._currentStep - 1);
        },

        /**
         * Возвращает номер текущего шага
         *
         * @returns {number}
         */
        getCurrentStep: function () {
            return this._currentStep;
        },

        /**
         * Обновляет визуальное состояние степпера
         */
        _updateStepper: function () {
            var current = this._currentStep;

            this._steps.forEach(function (stepEl) {
                var stepNum = parseInt(stepEl.getAttribute('data-step'), 10);

                stepEl.classList.remove(
                    'rwb-wizard__step--active',
                    'rwb-wizard__step--completed'
                );

                if (stepNum === current) {
                    stepEl.classList.add('rwb-wizard__step--active');
                } else if (stepNum < current) {
                    stepEl.classList.add('rwb-wizard__step--completed');
                }
            });
        },

        /**
         * Обновляет видимость панелей
         */
        _updatePanels: function () {
            var current = this._currentStep;

            this._panels.forEach(function (panel) {
                var panelNum = parseInt(panel.getAttribute('data-panel'), 10);

                if (panelNum === current) {
                    panel.classList.add('rwb-wizard__panel--active');
                } else {
                    panel.classList.remove('rwb-wizard__panel--active');
                }
            });
        }
    };
})();
