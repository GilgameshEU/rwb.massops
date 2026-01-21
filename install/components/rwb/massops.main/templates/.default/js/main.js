/**
 * Главный модуль для инициализации всех обработчиков
 */
BX.ready(function () {
    'use strict';

    // Получаем элементы
    const uploadBtn = BX('rwb-import-upload');
    const clearBtn = BX('rwb-import-clear');
    const templateBtn = BX('rwb-import-template');
    const importBtn = BX('rwb-import-run');
    const fileInput = BX('rwb-import-file');

    // Контейнер для отображения ошибок (родительский элемент кнопок)
    const container = uploadBtn ? uploadBtn.parentNode : null;

    // Инициализируем обработчики
    if (uploadBtn && fileInput && container) {
        window.RwbImportUploadHandler.init(uploadBtn, fileInput, container);
    }

    if (importBtn && container) {
        window.RwbImportImportHandler.init(importBtn, container);
    }

    if (templateBtn) {
        window.RwbImportTemplateHandler.init(templateBtn);
    }

    // Обработчик очистки
    if (clearBtn) {
        clearBtn.onclick = function () {
            BX.ajax.runComponentAction('rwb:massops.main', 'clear', {
                mode: 'class'
            }).then(function() {
                location.reload();
            });
        };
    }
});
