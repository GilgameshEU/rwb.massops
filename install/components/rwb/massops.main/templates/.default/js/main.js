/**
 * Главный модуль — оркестратор всех обработчиков
 */
BX.ready(function () {
    'use strict';

    var config = (BX.RwbMassops && BX.RwbMassops.config) || {};
    var selectedEntityType = config.currentEntityType || null;

    // --- Геттер текущей сущности ---
    function getEntityType() {
        return selectedEntityType;
    }

    // --- Элементы ---
    var nextBtn1 = BX('rwb-wizard-next-1');
    var backBtn2 = BX('rwb-wizard-back-2');
    var uploadBtn = BX('rwb-wizard-upload');
    var backBtn3 = BX('rwb-wizard-back-3');
    var clearBtn = BX('rwb-import-clear');
    var importBtn = BX('rwb-import-run');
    var templateLink = BX('rwb-import-template');
    var dryRunToggle = BX('rwb-import-dry-run');
    var createCabinetsWrap = BX('rwb-create-cabinets-wrap');
    var createCabinetsToggle = BX('rwb-create-cabinets');

    // Функция показа/скрытия чекбокса кабинетов
    function updateCabinetsCheckbox(entityType) {
        if (createCabinetsWrap) {
            createCabinetsWrap.style.display = (entityType === 'company') ? '' : 'none';
        }
    }

    // --- 1. Табы ---
    window.RwbTabManager.init();

    // --- 2. Wizard ---
    var initialStep = 1;
    if (config.hasData && config.currentEntityType) {
        initialStep = 3;
        selectedEntityType = config.currentEntityType;
    }
    window.RwbWizardManager.init(initialStep);

    // --- 3. Entity Selector ---
    window.RwbEntitySelector.init(function (entityType) {
        selectedEntityType = entityType;
        if (nextBtn1) {
            nextBtn1.disabled = false;
        }
        // Показать/скрыть чекбокс кабинетов
        updateCabinetsCheckbox(entityType);
    }, config.currentEntityType);

    // Если есть предвыбранная сущность — активировать кнопку и чекбокс
    if (config.currentEntityType && nextBtn1) {
        nextBtn1.disabled = false;
        updateCabinetsCheckbox(config.currentEntityType);
    }

    // --- 4. Dropzone ---
    window.RwbDropzoneHandler.init(function (file) {
        if (uploadBtn) {
            uploadBtn.disabled = !file;
        }
    });

    // --- 5. Кнопки wizard ---

    // Шаг 1 → 2
    if (nextBtn1) {
        nextBtn1.addEventListener('click', function () {
            if (selectedEntityType) {
                window.RwbWizardManager.next();
            }
        });
    }

    // Шаг 2 → 1
    if (backBtn2) {
        backBtn2.addEventListener('click', function () {
            window.RwbWizardManager.back();
        });
    }

    // Шаг 3 → 2
    if (backBtn3) {
        backBtn3.addEventListener('click', function () {
            window.RwbWizardManager.back();
        });
    }

    // --- 6. Upload ---
    if (uploadBtn) {
        window.RwbImportUploadHandler.init(
            uploadBtn,
            getEntityType,
            function () {
                // Перезагружаем страницу, чтобы грид обновился с данными из сессии
                location.reload();
            }
        );
    }

    // --- 7. Template download ---
    if (templateLink) {
        window.RwbImportTemplateHandler.init(templateLink, getEntityType);
    }

    // --- 8. Import / Dry Run ---
    if (importBtn) {
        window.RwbImportImportHandler.init(importBtn, getEntityType);
    }

    // --- 9. Dry Run toggle — меняет текст кнопки и управляет чекбоксом кабинетов ---
    function updateCabinetsDisabledState() {
        if (createCabinetsToggle) {
            var isDryRun = dryRunToggle && dryRunToggle.checked;
            createCabinetsToggle.disabled = isDryRun;
            if (isDryRun) {
                createCabinetsToggle.checked = false;
            }
            // Визуально показываем неактивное состояние
            if (createCabinetsWrap) {
                createCabinetsWrap.classList.toggle('rwb-toggle--disabled', isDryRun);
            }
        }
    }

    if (dryRunToggle && importBtn) {
        dryRunToggle.addEventListener('change', function () {
            importBtn.textContent = dryRunToggle.checked ? 'Проверить' : 'Импортировать';
            updateCabinetsDisabledState();
        });
        // Инициализация при загрузке
        updateCabinetsDisabledState();
    }

    // --- 10. Clear ---
    if (clearBtn) {
        clearBtn.addEventListener('click', function () {
            BX.ajax.runComponentAction('rwb:massops.main', 'clear', {
                mode: 'class'
            }).then(function () {
                location.reload();
            });
        });
    }

    // --- 11. Stats tab (lazy-load при переключении) ---
    window.RwbStatsHandler.init();

    var origSwitchTo = window.RwbTabManager.switchTo.bind(window.RwbTabManager);
    window.RwbTabManager.switchTo = function (tabId) {
        origSwitchTo(tabId);
        if (tabId === 'stats') {
            window.RwbStatsHandler.load();
        }
    };
});
