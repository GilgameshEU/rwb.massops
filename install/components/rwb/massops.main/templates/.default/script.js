BX.ready(function () {
    const uploadBtn = BX('rwb-import-upload');
    const clearBtn = BX('rwb-import-clear');
    const templateBtn = BX('rwb-import-template');
    const importBtn = BX('rwb-import-run');
    const fileInput = BX('rwb-import-file');

    uploadBtn.onclick = function () {
        if (!fileInput.files.length) {
            alert('Выберите файл');
            return;
        }

        const formData = new FormData();
        formData.append('file', fileInput.files[0]);

        // Показываем индикатор загрузки
        uploadBtn.disabled = true;
        uploadBtn.textContent = 'Загрузка...';

        BX.ajax.runComponentAction('rwb:massops.main', 'uploadFile', {
            mode: 'class',
            data: formData
        }).then(function (response) {
            uploadBtn.disabled = false;
            uploadBtn.textContent = 'Загрузить файл';

            // Проверяем наличие ошибок
            if (response.data && response.data.success === false && response.data.errors) {
                showUploadErrors(response.data.errors);
                return;
            }

            // Если всё успешно - перезагружаем страницу
            if (response.data && response.data.total !== undefined) {
                location.reload();
            } else {
                location.reload();
            }
        }).catch(function (error) {
            uploadBtn.disabled = false;
            uploadBtn.textContent = 'Загрузить файл';
            
            let errors = [];
            
            // Обрабатываем формат ошибок Bitrix
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
            } else {
                errors = [{message: 'Произошла ошибка при загрузке файла'}];
            }
            
            showUploadErrors(errors);
        });
    };

    function showUploadErrors(errors) {
        // Удаляем предыдущие ошибки, если есть
        const existingErrorBlock = BX('rwb-upload-errors');
        if (existingErrorBlock) {
            existingErrorBlock.remove();
        }

        // Создаем блок для отображения ошибок
        const errorBlock = BX.create('div', {
            props: {
                id: 'rwb-upload-errors',
                className: 'rwb-upload-errors'
            },
            style: {
                marginTop: '15px',
                padding: '15px',
                backgroundColor: '#ffeaa7',
                border: '1px solid #fdcb6e',
                borderRadius: '4px',
                color: '#2d3436'
            }
        });

        const errorTitleContainer = BX.create('div', {
            style: {
                display: 'flex',
                justifyContent: 'space-between',
                alignItems: 'center',
                marginBottom: '10px'
            }
        });

        const errorTitle = BX.create('div', {
            props: {
                className: 'rwb-upload-errors-title'
            },
            style: {
                fontWeight: 'bold',
                fontSize: '14px'
            },
            text: 'Ошибки при загрузке файла:'
        });

        const closeBtn = BX.create('span', {
            props: {
                className: 'rwb-upload-errors-close'
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

        errorTitleContainer.appendChild(errorTitle);
        errorTitleContainer.appendChild(closeBtn);

        const errorList = BX.create('ul', {
            props: {
                className: 'rwb-upload-errors-list'
            },
            style: {
                margin: '0',
                paddingLeft: '20px'
            }
        });

        errors.forEach(function(error) {
            let errorText = '';
            
            // Обрабатываем разные форматы ошибок
            if (typeof error === 'string') {
                errorText = error;
            } else if (error && error.message) {
                // ImportError формат: {type, code, message, row, field, context}
                errorText = error.message;
                if (error.field) {
                    errorText += ' (Поле: ' + error.field + ')';
                }
                if (error.row) {
                    errorText += ' (Строка: ' + error.row + ')';
                }
            } else {
                errorText = JSON.stringify(error);
            }
            
            const errorItem = BX.create('li', {
                style: {
                    marginBottom: '5px'
                },
                text: errorText
            });
            errorList.appendChild(errorItem);
        });

        errorBlock.appendChild(errorTitleContainer);
        errorBlock.appendChild(errorList);

        // Вставляем блок ошибок после кнопок
        const container = uploadBtn.parentNode;
        container.appendChild(errorBlock);

        // Прокручиваем к блоку ошибок
        errorBlock.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    templateBtn.onclick = function () {
        window.location.href =
            '/bitrix/services/main/ajax.php?c=rwb:massops.main&action=downloadTemplate&mode=class';
    };

    importBtn.onclick = function () {
        // Показываем индикатор загрузки
        importBtn.disabled = true;
        const originalText = importBtn.textContent;
        importBtn.textContent = 'Импорт...';

        BX.ajax.runComponentAction('rwb:massops.main', 'importCompanies', {
            mode: 'class'
        }).then(function (response) {
            importBtn.disabled = false;
            importBtn.textContent = originalText;

            // Проверяем наличие ошибок валидации
            if (response.data && response.data.errors && Object.keys(response.data.errors).length > 0) {
                // Собираем все ошибки в один массив для отображения
                const allErrors = [];
                Object.keys(response.data.errors).forEach(function(rowIndex) {
                    response.data.errors[rowIndex].forEach(function(error) {
                        allErrors.push(error);
                    });
                });

                showImportErrors(allErrors, response.data.errors, response.data.added);
            } else {
                // Если ошибок нет, показываем успешное сообщение
                alert('Импорт завершён. Добавлено компаний: ' + (response.data.added || 0));
                location.reload();
            }
        }).catch(function (error) {
            importBtn.disabled = false;
            importBtn.textContent = originalText;
            
            let errors = [];
            
            // Обрабатываем формат ошибок Bitrix
            if (error.status && error.errors && error.errors.length > 0) {
                errors = error.errors.map(function(e) {
                    return {
                        message: e.message || String(e),
                        code: e.code || 'ERROR'
                    };
                });
            } else if (error.message) {
                errors = [{message: error.message}];
            } else {
                errors = [{message: 'Произошла ошибка при импорте'}];
            }
            
            showImportErrors(errors, {}, 0);
        });
    };

    function showImportErrors(errors, errorsByRow, addedCount) {
        // Удаляем предыдущие ошибки, если есть
        const existingErrorBlock = BX('rwb-import-errors');
        if (existingErrorBlock) {
            existingErrorBlock.remove();
        }

        // Создаем блок для отображения ошибок
        const errorBlock = BX.create('div', {
            props: {
                id: 'rwb-import-errors',
                className: 'rwb-import-errors'
            },
            style: {
                marginTop: '15px',
                marginBottom: '15px',
                padding: '15px',
                backgroundColor: '#ffeaa7',
                border: '1px solid #fdcb6e',
                borderRadius: '4px',
                color: '#2d3436'
            }
        });

        const errorTitleContainer = BX.create('div', {
            style: {
                display: 'flex',
                justifyContent: 'space-between',
                alignItems: 'center',
                marginBottom: '10px'
            }
        });

        let titleText = 'Ошибки при импорте';
        if (addedCount > 0) {
            titleText += ' (Добавлено компаний: ' + addedCount + ')';
        }

        const errorTitle = BX.create('div', {
            props: {
                className: 'rwb-import-errors-title'
            },
            style: {
                fontWeight: 'bold',
                fontSize: '14px'
            },
            text: titleText
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

        errorTitleContainer.appendChild(errorTitle);
        errorTitleContainer.appendChild(closeBtn);

        const errorList = BX.create('ul', {
            props: {
                className: 'rwb-import-errors-list'
            },
            style: {
                margin: '0',
                paddingLeft: '20px',
                maxHeight: '300px',
                overflowY: 'auto'
            }
        });

        errors.forEach(function(error) {
            let errorText = '';
            
            // Обрабатываем разные форматы ошибок
            if (typeof error === 'string') {
                errorText = error;
            } else if (error && error.message) {
                // ImportError формат: {type, code, message, row, field, context}
                errorText = error.message;
                if (error.field) {
                    errorText += ' (Поле: ' + error.field + ')';
                }
                if (error.row !== null && error.row !== undefined) {
                    errorText += ' (Строка: ' + error.row + ')';
                }
            } else {
                errorText = JSON.stringify(error);
            }
            
            const errorItem = BX.create('li', {
                style: {
                    marginBottom: '5px'
                },
                text: errorText
            });
            errorList.appendChild(errorItem);
        });

        errorBlock.appendChild(errorTitleContainer);
        errorBlock.appendChild(errorList);

        // Вставляем блок ошибок после кнопок
        const container = importBtn.parentNode;
        container.appendChild(errorBlock);

        // Прокручиваем к блоку ошибок
        errorBlock.scrollIntoView({ behavior: 'smooth', block: 'nearest' });

        // Сохраняем ошибки для возможного подсвечивания строк в гриде
        if (Object.keys(errorsByRow).length > 0) {
            window.rwbImportErrors = errorsByRow;
            // Можно добавить подсветку строк в гриде здесь, если нужно
        }
    }

    clearBtn.onclick = function () {
        BX.ajax.runComponentAction('rwb:massops.main', 'clear', {
            mode: 'class'
        }).then(() => location.reload());
    };
});
