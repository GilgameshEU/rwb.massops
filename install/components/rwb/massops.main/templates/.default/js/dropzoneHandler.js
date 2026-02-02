/**
 * Модуль drag & drop загрузки файлов
 */
(function () {
    'use strict';

    window.RwbDropzoneHandler = {
        _dropzone: null,
        _fileInput: null,
        _fileNameEl: null,
        _removeBtn: null,
        _selectedFile: null,
        _onChange: null,

        /**
         * Инициализирует dropzone
         *
         * @param {Function} onChange Callback при изменении файла (file или null)
         */
        init: function (onChange) {
            this._onChange = onChange;
            this._dropzone = document.getElementById('rwb-dropzone');
            this._fileInput = document.getElementById('rwb-import-file');
            this._fileNameEl = this._dropzone.querySelector('.rwb-dropzone__file-name');
            this._removeBtn = this._dropzone.querySelector('.rwb-dropzone__file-remove');

            this._bindEvents();
        },

        /**
         * Возвращает выбранный файл
         *
         * @returns {File|null}
         */
        getFile: function () {
            return this._selectedFile;
        },

        /**
         * Сбрасывает выбранный файл
         */
        clear: function () {
            this._selectedFile = null;
            this._fileInput.value = '';
            this._dropzone.classList.remove('rwb-dropzone--has-file');
            this._fileNameEl.textContent = '';

            if (this._onChange) {
                this._onChange(null);
            }
        },

        /**
         * Привязывает обработчики событий
         */
        _bindEvents: function () {
            var self = this;

            // Клик по зоне — открыть диалог
            this._dropzone.addEventListener('click', function (e) {
                if (e.target === self._removeBtn) {
                    return;
                }
                self._fileInput.click();
            });

            // Выбор через диалог
            this._fileInput.addEventListener('change', function () {
                if (self._fileInput.files.length > 0) {
                    self._setFile(self._fileInput.files[0]);
                }
            });

            // Удаление файла
            this._removeBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                self.clear();
            });

            // Drag & Drop
            this._dropzone.addEventListener('dragover', function (e) {
                e.preventDefault();
                self._dropzone.classList.add('rwb-dropzone--dragover');
            });

            this._dropzone.addEventListener('dragleave', function () {
                self._dropzone.classList.remove('rwb-dropzone--dragover');
            });

            this._dropzone.addEventListener('drop', function (e) {
                e.preventDefault();
                self._dropzone.classList.remove('rwb-dropzone--dragover');

                if (e.dataTransfer.files.length > 0) {
                    var file = e.dataTransfer.files[0];
                    var ext = file.name.split('.').pop().toLowerCase();

                    if (ext === 'csv' || ext === 'xlsx') {
                        self._setFile(file);
                    }
                }
            });
        },

        /**
         * Устанавливает файл
         *
         * @param {File} file
         */
        _setFile: function (file) {
            this._selectedFile = file;
            this._dropzone.classList.add('rwb-dropzone--has-file');
            this._fileNameEl.textContent = file.name;

            if (this._onChange) {
                this._onChange(file);
            }
        }
    };
})();
