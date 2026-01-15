BX.ready(function () {

    const uploadBtn = BX('rwb-import-upload');
    const fileInput = BX('rwb-import-file');

    uploadBtn.addEventListener('click', function () {

        if (!fileInput.files.length) {
            alert('Выберите файл');
            return;
        }

        const formData = new FormData();
        formData.append('file', fileInput.files[0]);

        BX.ajax.runComponentAction(
            'rwb:massops.main',
            'uploadFile',
            {
                mode: 'class',
                data: formData
            }
        ).then(function (response) {

            console.log('AJAX response:', response.data);

            alert(
                'Файл успешно разобран. Строк: ' + response.data.total
            );

        }).catch(function (error) {

            console.error(error);

            if (error.errors && error.errors.length) {
                alert(error.errors[0].message);
            } else {
                alert('Ошибка при загрузке файла');
            }
        });
    });

});
