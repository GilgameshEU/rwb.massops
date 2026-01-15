BX.ready(function () {

    const componentName = BX.message('RWB_MASSOPS_COMPONENT');
    const signedParams = BX.message('RWB_MASSOPS_SIGNED_PARAMS');

    const btn = BX('rwb-test-btn');
    if (!btn) {
        return;
    }

    BX.bind(btn, 'click', function () {

        BX.ajax.runComponentAction(
            componentName,
            'test',
            {
                mode: 'class',
                signedParameters: signedParams,
                data: {}
            }
        ).then(
            function (response) {
                BX.UI.Notification.Center.notify({
                    content: 'Ответ сервера: ' + response.data.time
                });
            },
            function (response) {
                BX.UI.Notification.Center.notify({
                    content: 'AJAX ошибка'
                });
            }
        );
    });
});
