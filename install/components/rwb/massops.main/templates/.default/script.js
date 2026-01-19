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

        BX.ajax.runComponentAction('rwb:massops.main', 'uploadFile', {
            mode: 'class',
            data: formData
        }).then(() => location.reload());
    };

    templateBtn.onclick = function () {
        window.location.href =
            '/bitrix/services/main/ajax.php?c=rwb:massops.main&action=downloadTemplate&mode=class';
    };

    importBtn.onclick = function () {
        BX.ajax.runComponentAction('rwb:massops.main', 'importCompanies', {
            mode: 'class'
        }).then(function (response) {
            alert('Импорт завершён. Добавлено компаний: ' + response.data.added);
        });
    };

    clearBtn.onclick = function () {
        BX.ajax.runComponentAction('rwb:massops.main', 'clear', {
            mode: 'class'
        }).then(() => location.reload());
    };
});
