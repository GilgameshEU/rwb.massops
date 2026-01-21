/**
 * Модуль для обработки загрузки файла
 */
(function() {
    'use strict';

    window.RwbImportUploadHandler = {
        /**
         * Инициализирует обработчик загрузки файла
         *
         * @param {HTMLElement} uploadBtn Кнопка загрузки
         * @param {HTMLElement} fileInput Поле выбора файла
         * @param {HTMLElement} container Контейнер для отображения ошибок
         */
        init: function(uploadBtn, fileInput, container) {
            uploadBtn.onclick = function() {
                this.handleUpload(uploadBtn, fileInput, container);
            }.bind(this);
        },

        /**
         * Обрабатывает загрузку файла
         *
         * @param {HTMLElement} uploadBtn Кнопка загрузки
         * @param {HTMLElement} fileInput Поле выбора файла
         * @param {HTMLElement} container Контейнер для отображения ошибок
         */
        handleUpload: function(uploadBtn, fileInput, container) {
            if (!fileInput.files.length) {
                alert('Выберите файл');
                return;
            }

            const formData = new FormData();
            formData.append('file', fileInput.files[0]);

            // Показываем индикатор загрузки
            this.setLoading(uploadBtn, true, 'Загрузка...');

            BX.ajax.runComponentAction('rwb:massops.main', 'uploadFile', {
                mode: 'class',
                data: formData
            }).then(function(response) {
                this.setLoading(uploadBtn, false, 'Загрузить файл');

                // Проверяем наличие ошибок
                if (response.data && response.data.success === false && response.data.errors) {
                    window.RwbImportErrorHandler.showUploadErrors(
                        response.data.errors,
                        container
                    );
                    return;
                }

                // Если всё успешно - перезагружаем страницу
                if (response.data && response.data.total !== undefined) {
                    location.reload();
                } else {
                    location.reload();
                }
            }.bind(this)).catch(function(error) {
                this.setLoading(uploadBtn, false, 'Загрузить файл');

                const errors = window.RwbImportErrorHandler.parseBitrixError(error);
                if (errors.length > 0) {
                    errors.push({message: 'Произошла ошибка при загрузке файла'});
                }

                window.RwbImportErrorHandler.showUploadErrors(errors, container);
            }.bind(this));
        },

        /**
         * Устанавливает состояние загрузки для кнопки
         *
         * @param {HTMLElement} button Кнопка
         * @param {boolean} isLoading Состояние загрузки
         * @param {string} text Текст кнопки
         */
        setLoading: function(button, isLoading, text) {
            button.disabled = isLoading;
            button.textContent = text;
        }
    };
})();
