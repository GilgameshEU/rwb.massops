BX.ready(function () {
    const uploadBtn = BX('rwb-import-upload');
    const fileInput = BX('rwb-import-file');
    const clearBtn = BX('rwb-import-clear');

    // Код загрузки
    uploadBtn.addEventListener('click', function () {
        if (!fileInput.files.length) {
            alert('Выберите файл');
            return;
        }
        const formData = new FormData();
        formData.append('file', fileInput.files[0]);
        BX.addClass(uploadBtn, 'ui-btn-wait');

        BX.ajax.runComponentAction('rwb:massops.main', 'uploadFile', {
            mode: 'class',
            data: formData
        }).then(function (response) {
            BX.removeClass(uploadBtn, 'ui-btn-wait');
            const gridObject = BX.Main.gridManager.getInstanceById('RWB_MASSOPS_GRID');
            if (gridObject) {
                gridObject.reload();
            }
        }).catch(function (error) {
            BX.removeClass(uploadBtn, 'ui-btn-wait');
            alert(error.errors ? error.errors[0].message : 'Ошибка');
        });
    });

    // Логика кнопки очистки
    clearBtn.addEventListener('click', function () {
        if (!confirm('Вы уверены, что хотите очистить таблицу?')) {
            return;
        }

        BX.addClass(clearBtn, 'ui-btn-wait');

        BX.ajax.runComponentAction('rwb:massops.main', 'clear', {
            mode: 'class'
        }).then(function (response) {
            BX.removeClass(clearBtn, 'ui-btn-wait');
            fileInput.value = ''; // Сбрасываем выбранный файл в инпуте

            const gridObject = BX.Main.gridManager.getInstanceById('RWB_MASSOPS_GRID');
            if (gridObject) {
                gridObject.reload(); // Перезагружаем грид (он придет пустым из сессии)
            }
        }).catch(function (error) {
            BX.removeClass(clearBtn, 'ui-btn-wait');
            console.error(error);
        });
    });
});
