/**
 * Главный модуль — оркестратор всех обработчиков
 */
BX.ready(function () {
    'use strict';

    var config = (BX.RwbMassops && BX.RwbMassops.config) || {};
    var selectedEntityType = config.currentEntityType || null;

    function getEntityType() {
        return selectedEntityType;
    }

    var nextBtn1 = BX('rwb-wizard-next-1');
    var backBtn2 = BX('rwb-wizard-back-2');
    var uploadBtn = BX('rwb-wizard-upload');
    var backBtn3 = BX('rwb-wizard-back-3');
    var clearBtn = BX('rwb-import-clear');
    var checkBtn = BX('rwb-import-run');
    var importBtn = BX('rwb-import-start');
    var templateLink = BX('rwb-import-template');

    window.RwbTabManager.init();

    var initialStep = 1;
    if (config.hasData && config.currentEntityType) {
        initialStep = 3;
        selectedEntityType = config.currentEntityType;
    }
    window.RwbWizardManager.init(initialStep);

    window.RwbEntitySelector.init(function (entityType) {
        selectedEntityType = entityType;
        if (nextBtn1) {
            nextBtn1.disabled = false;
        }
    }, config.currentEntityType);

    if (config.currentEntityType && nextBtn1) {
        nextBtn1.disabled = false;
    }

    window.RwbDropzoneHandler.init(function (file) {
        if (uploadBtn) {
            uploadBtn.disabled = !file;
        }
    });


    if (nextBtn1) {
        nextBtn1.addEventListener('click', function () {
            if (selectedEntityType) {
                window.RwbWizardManager.next();
            }
        });
    }

    if (backBtn2) {
        backBtn2.addEventListener('click', function () {
            window.RwbWizardManager.back();
        });
    }

    if (backBtn3) {
        backBtn3.addEventListener('click', function () {
            window.RwbWizardManager.back();
        });
    }

    if (uploadBtn) {
        window.RwbImportUploadHandler.init(
            uploadBtn,
            getEntityType,
            function () {
                location.reload();
            }
        );
    }

    if (templateLink) {
        window.RwbImportTemplateHandler.init(templateLink, getEntityType);
    }

    if (checkBtn) {
        window.RwbImportImportHandler.init(checkBtn, importBtn, getEntityType);
    }

    if (clearBtn) {
        clearBtn.addEventListener('click', function () {
            BX.ajax.runComponentAction('rwb:massops.main', 'clear', {
                mode: 'class'
            }).then(function () {
                location.reload();
            });
        });
    }

    window.RwbStatsHandler.init();

    var origSwitchTo = window.RwbTabManager.switchTo.bind(window.RwbTabManager);
    window.RwbTabManager.switchTo = function (tabId) {
        origSwitchTo(tabId);
        if (tabId === 'stats') {
            window.RwbStatsHandler.load();
        }
    };
});
