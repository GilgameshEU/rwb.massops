/**
 * Модуль для обработки и отображения ошибок импорта
 */
(function() {
    'use strict';

    window.RwbImportErrorHandler = {
        /**
         * Отображает ошибки загрузки файла
         *
         * @param {Array} errors Массив ошибок
         * @param {HTMLElement} container Контейнер для вставки блока ошибок
         */
        showUploadErrors: function(errors, container) {
            this.showErrors(
                errors,
                container,
                'rwb-upload-errors',
                'Ошибки при загрузке файла:'
            );
        },

        /**
         * Отображает ошибки импорта
         *
         * @param {Array} errors Массив ошибок
         * @param {Object} errorsByRow Ошибки по строкам
         * @param {number} addedCount Количество успешно добавленных записей
         * @param {HTMLElement} container Контейнер для вставки блока ошибок
         * @param {boolean} isDryRun Флаг режима dry run
         * @param {Object} wouldBeAddedDetails Детали записей, которые были бы добавлены (для dry run)
         */
        showImportErrors: function(errors, errorsByRow, addedCount, container, isDryRun, wouldBeAddedDetails) {
            isDryRun = isDryRun || false;
            wouldBeAddedDetails = wouldBeAddedDetails || {};

            let titleText = isDryRun ? 'Результаты Dry Run (тестовый режим)' : 'Результаты импорта';
            
            // Добавляем информацию о количестве добавленных/готовых к добавлению
            if (addedCount > 0) {
                if (isDryRun) {
                    titleText += ' (Будет добавлено компаний: ' + addedCount + ')';
                } else {
                    titleText += ' (Добавлено компаний: ' + addedCount + ')';
                }
            }

            // Определяем цвет блока в зависимости от режима
            const blockStyle = isDryRun ? {
                backgroundColor: '#dbeafe',
                border: '1px solid #93c5fd',
                color: '#1e40af'
            } : {
                backgroundColor: '#ffeaa7',
                border: '1px solid #fdcb6e',
                color: '#2d3436'
            };

            this.showErrors(
                errors,
                container,
                'rwb-import-errors',
                titleText,
                { maxHeight: '300px', overflowY: 'auto' },
                blockStyle,
                isDryRun,
                wouldBeAddedDetails,
                addedCount
            );

            // Сохраняем ошибки для возможного подсвечивания строк в гриде
            if (Object.keys(errorsByRow).length > 0) {
                window.rwbImportErrors = errorsByRow;
            }
        },

        /**
         * Общий метод для отображения ошибок
         *
         * @param {Array} errors Массив ошибок
         * @param {HTMLElement} container Контейнер для вставки
         * @param {string} errorBlockId ID блока ошибок
         * @param {string} title Заголовок блока ошибок
         * @param {Object} listStyles Дополнительные стили для списка ошибок
         * @param {Object} blockStyle Стили для блока ошибок
         * @param {boolean} isDryRun Флаг режима dry run
         * @param {Object} wouldBeAddedDetails Детали записей, которые были бы добавлены
         * @param {number} addedCount Количество успешно добавленных записей
         */
        showErrors: function(errors, container, errorBlockId, title, listStyles, blockStyle, isDryRun, wouldBeAddedDetails, addedCount) {
            // Удаляем предыдущие ошибки, если есть
            const existingErrorBlock = BX(errorBlockId);
            if (existingErrorBlock) {
                existingErrorBlock.remove();
            }

            // Определяем стили блока
            const defaultBlockStyle = {
                marginTop: '15px',
                marginBottom: '15px',
                padding: '15px',
                backgroundColor: '#ffeaa7',
                border: '1px solid #fdcb6e',
                borderRadius: '4px',
                color: '#2d3436'
            };

            const finalBlockStyle = blockStyle ? Object.assign({}, defaultBlockStyle, blockStyle) : defaultBlockStyle;

            // Создаем блок для отображения ошибок
            const errorBlock = BX.create('div', {
                props: {
                    id: errorBlockId,
                    className: 'rwb-import-errors'
                },
                style: finalBlockStyle
            });

            // Заголовок с кнопкой закрытия
            const errorTitleContainer = this.createErrorTitle(title, errorBlock);

            // Список ошибок
            const errorList = this.createErrorList(errors, listStyles || {});

            errorBlock.appendChild(errorTitleContainer);
            errorBlock.appendChild(errorList);

            // Добавляем информацию о успешных добавлениях
            if (addedCount > 0) {
                const successSection = this.createSuccessSection(addedCount, isDryRun, wouldBeAddedDetails);
                errorBlock.appendChild(successSection);
            }

            // Вставляем блок ошибок в контейнер
            container.appendChild(errorBlock);

            // Прокручиваем к блоку ошибок
            errorBlock.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        },

        /**
         * Создает заголовок с кнопкой закрытия
         *
         * @param {string} title Текст заголовка
         * @param {HTMLElement} errorBlock Блок ошибок для удаления
         * @returns {HTMLElement}
         */
        createErrorTitle: function(title, errorBlock) {
            const container = BX.create('div', {
                style: {
                    display: 'flex',
                    justifyContent: 'space-between',
                    alignItems: 'center',
                    marginBottom: '10px'
                }
            });

            const errorTitle = BX.create('div', {
                props: {
                    className: 'rwb-import-errors-title'
                },
                style: {
                    fontWeight: 'bold',
                    fontSize: '14px'
                },
                text: title
            });

            const closeBtn = BX.create('span', {
                props: {
                    className: 'rwb-import-errors-close'
                },
                style: {
                    cursor: 'pointer',
                    fontSize: '18px',
                    fontWeight: 'bold',
                    color: '#636e72',
                    padding: '0 5px'
                },
                text: '×'
            });

            closeBtn.onclick = function() {
                errorBlock.remove();
            };

            container.appendChild(errorTitle);
            container.appendChild(closeBtn);

            return container;
        },

        /**
         * Создает список ошибок
         *
         * @param {Array} errors Массив ошибок
         * @param {Object} styles Дополнительные стили
         * @returns {HTMLElement}
         */
        createErrorList: function(errors, styles) {
            const errorList = BX.create('ul', {
                props: {
                    className: 'rwb-import-errors-list'
                },
                style: Object.assign({
                    margin: '0',
                    paddingLeft: '20px'
                }, styles)
            });

            errors.forEach(function(error) {
                const errorText = this.formatError(error);
                const errorItem = BX.create('li', {
                    style: {
                        marginBottom: '5px'
                    },
                    text: errorText
                });
                errorList.appendChild(errorItem);
            }.bind(this));

            return errorList;
        },

        /**
         * Форматирует ошибку в строку
         *
         * @param {string|Object} error Ошибка (строка или объект ImportError)
         * @returns {string}
         */
        formatError: function(error) {
            if (typeof error === 'string') {
                return error;
            }

            if (error && error.message) {
                // ImportError формат: {type, code, message, row, field, context}
                let errorText = error.message;
                if (error.field) {
                    errorText += ' (Поле: ' + error.field + ')';
                }
                if (error.row !== null && error.row !== undefined) {
                    errorText += ' (Строка: ' + error.row + ')';
                }
                return errorText;
            }

            return JSON.stringify(error);
        },

        /**
         * Создает секцию с информацией об успешных добавлениях
         *
         * @param {number} addedCount Количество успешно добавленных записей
         * @param {boolean} isDryRun Флаг режима dry run
         * @param {Object} wouldBeAddedDetails Детали записей, которые были бы добавлены
         * @returns {HTMLElement}
         */
        createSuccessSection: function(addedCount, isDryRun, wouldBeAddedDetails) {
            const successSection = BX.create('div', {
                style: {
                    marginTop: '15px',
                    paddingTop: '15px',
                    borderTop: '1px solid rgba(0, 0, 0, 0.1)'
                }
            });

            const successTitle = BX.create('div', {
                style: {
                    fontWeight: 'bold',
                    marginBottom: '10px',
                    fontSize: '14px'
                },
                text: isDryRun ? 'Компании, которые будут добавлены:' : 'Успешно добавленные компании:'
            });

            successSection.appendChild(successTitle);

            const successList = BX.create('ul', {
                props: {
                    className: 'rwb-import-success-list'
                },
                style: {
                    margin: '0',
                    paddingLeft: '20px',
                    maxHeight: '200px',
                    overflowY: 'auto'
                }
            });

            // Если есть детали, показываем их
            if (wouldBeAddedDetails && Object.keys(wouldBeAddedDetails).length > 0) {
                Object.keys(wouldBeAddedDetails).forEach(function(rowIndex) {
                    const item = wouldBeAddedDetails[rowIndex];
                    const companyName = item.data && item.data.TITLE ? item.data.TITLE : 'Компания';
                    const listItem = BX.create('li', {
                        style: {
                            marginBottom: '5px',
                            color: '#16a085'
                        },
                        text: 'Строка ' + item.row + ': ' + companyName
                    });
                    successList.appendChild(listItem);
                });
            } else {
                // Если деталей нет, просто показываем количество
                const listItem = BX.create('li', {
                    style: {
                        marginBottom: '5px',
                        color: '#16a085'
                    },
                    text: 'Всего: ' + addedCount + ' компаний'
                });
                successList.appendChild(listItem);
            }

            successSection.appendChild(successList);

            return successSection;
        },

        /**
         * Обрабатывает ошибки из Bitrix AJAX
         *
         * @param {Object} error Объект ошибки от Bitrix
         * @returns {Array}
         */
        parseBitrixError: function(error) {
            let errors = [];

            if (error.status && error.errors && error.errors.length > 0) {
                errors = error.errors.map(function(e) {
                    return {
                        message: e.message || String(e),
                        code: e.code || 'ERROR'
                    };
                });
            } else if (error.message) {
                errors = [{message: error.message}];
            } else if (typeof error === 'string') {
                errors = [{message: error}];
            }

            return errors;
        }
    };
})();
