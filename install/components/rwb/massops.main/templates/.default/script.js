BX.ready(function () {

    const input = BX('rwb-import-file');
    const btn = BX('rwb-import-btn');
    const container = BX('rwb-grid-container');

    BX.bind(btn, 'click', function () {

        if (!input.files.length) {
            BX.UI.Notification.Center.notify({content: 'Выберите файл'});
            return;
        }

        const formData = new FormData();
        formData.append('file', input.files[0]);

        BX.ajax.runComponentAction(
            BX.message('RWB_COMPONENT'),
            'uploadFile',
            {
                mode: 'class',
                data: formData
            }
        ).then(function (response) {

            container.innerHTML = '';
            BX.ajax.insertHTML(
                container,
                BX.create('div', {
                    html: response.data.HTML || ''
                })
            );

        }, function (error) {
            BX.UI.Notification.Center.notify({content: 'Ошибка импорта'});
        });
    });
});
