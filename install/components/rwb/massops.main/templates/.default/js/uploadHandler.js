/**
 * Модуль для обработки загрузки файла
 */
(function () {
    'use strict';

    window.RwbImportUploadHandler = {
        /**
         * Инициализирует обработчик загрузки файла
         *
         * @param {HTMLElement} uploadBtn Кнопка загрузки
         * @param {Function} getEntityType Функция получения текущего типа сущности
         * @param {Function} onSuccess Callback при успешной загрузке
         */
        init: function (uploadBtn, getEntityType, onSuccess) {
            var self = this;
            uploadBtn.addEventListener('click', function () {
                self.handleUpload(uploadBtn, getEntityType, onSuccess);
            });
        },

        /**
         * Обрабатывает загрузку файла
         *
         * @param {HTMLElement} uploadBtn
         * @param {Function} getEntityType
         * @param {Function} onSuccess
         */
        handleUpload: function (uploadBtn, getEntityType, onSuccess) {
            var file = window.RwbDropzoneHandler.getFile();
            if (!file) {
                return;
            }

            var entityType = getEntityType();
            if (!entityType) {
                return;
            }

            var formData = new FormData();
            formData.append('file', file);
            formData.append('entityType', entityType);

            this.setLoading(uploadBtn, true, 'Загрузка...');

            var errContainer = document.getElementById('rwb-upload-errors-container');

            BX.ajax.runComponentAction('rwb:massops.main', 'uploadFile', {
                mode: 'class',
                data: formData
            }).then(function (response) {
                this.setLoading(uploadBtn, false, 'Загрузить');

                if (response.data && response.data.success === false && response.data.errors) {
                    window.RwbImportErrorHandler.showUploadErrors(
                        response.data.errors,
                        errContainer
                    );
                    return;
                }

                if (onSuccess) {
                    onSuccess(response.data);
                }
            }.bind(this)).catch(function (error) {
                this.setLoading(uploadBtn, false, 'Загрузить');

                var errors = window.RwbImportErrorHandler.parseBitrixError(error);
                if (errors.length === 0) {
                    errors.push({message: 'Произошла ошибка при загрузке файла'});
                }

                window.RwbImportErrorHandler.showUploadErrors(errors, errContainer);
            }.bind(this));
        },

        /**
         * Устанавливает состояние загрузки для кнопки
         *
         * @param {HTMLElement} button
         * @param {boolean} isLoading
         * @param {string} text
         */
        setLoading: function (button, isLoading, text) {
            button.disabled = isLoading;
            button.textContent = text;
        }
    };
})();
