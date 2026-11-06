<?php
defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;

$app = $displayData['app'] ?? Factory::getApplication();

$ajaxUrl = $displayData['ajaxUrl'] ?? '';

if ($ajaxUrl === '') {
    $ajaxUrl = (string) Uri::getInstance();

    if (!str_contains($ajaxUrl, 'docx_action=1')) {
        $ajaxUrl .= (str_contains($ajaxUrl, '?') ? '&' : '?') . 'docx_action=1';
    }
}

$token = Session::getFormToken();

/** @var \Joomla\CMS\WebAsset\WebAssetManager $wa */
$wa = $app->getDocument()->getWebAssetManager();

try {
    $wa->useScript('bootstrap.modal');
} catch (\Throwable $e) {
    // Bootstrap modal может быть уже загружен шаблоном.
}
?>

<div class="card mod-docx-viewer mb-3">
    <div class="card-header">
        <h3 class="card-title mb-0">
            <?php echo Text::_('MOD_DOCX_VIEWER_TITLE'); ?>
        </h3>
    </div>

    <div class="card-body">
        <div class="d-flex flex-wrap align-items-center gap-2">
            <label for="modDocxFile" class="form-label mb-0">
                <?php echo Text::_('MOD_DOCX_VIEWER_SELECT_FILE'); ?>
            </label>

            <input
                type="file"
                id="modDocxFile"
                name="docx"
                accept=".docx,application/vnd.openxmlformats-officedocument.wordprocessingml.document"
                class="form-control flex-grow-1"
                style="max-width: 420px;"
            >

            <button
                type="button"
                id="modDocxPreview"
                class="btn btn-primary"
                disabled
            >
                <span class="icon-eye" aria-hidden="true"></span>
                <?php echo Text::_('MOD_DOCX_VIEWER_PREVIEW'); ?>
            </button>
        </div>

        <div id="modDocxStatus" class="mt-3"></div>
    </div>
</div>

<div
    class="modal fade"
    id="modDocxModal"
    tabindex="-1"
    aria-labelledby="modDocxModalLabel"
    aria-hidden="true"
>
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modDocxModalLabel">
                    <?php echo Text::_('MOD_DOCX_VIEWER_MODAL_TITLE'); ?>
                </h5>

                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="modal"
                    aria-label="Close"
                ></button>
            </div>

            <div class="modal-body">
                <label for="modDocxEditor" class="form-label">
                    <?php echo Text::_('MOD_DOCX_VIEWER_EDITOR_LABEL'); ?>
                </label>

                <textarea
                    id="modDocxEditor"
                    class="form-control font-monospace"
                    rows="18"
                    placeholder="<?php echo Text::_('MOD_DOCX_VIEWER_EDITOR_PLACEHOLDER'); ?>"
                ></textarea>

                <div id="modDocxModalStatus" class="mt-3"></div>
            </div>

            <div class="modal-footer">
                <button
                    type="button"
                    id="modDocxDownload"
                    class="btn btn-success"
                    disabled
                >
                    <span class="icon-download" aria-hidden="true"></span>
                    <?php echo Text::_('MOD_DOCX_VIEWER_DOWNLOAD'); ?>
                </button>

                <button
                    type="button"
                    id="modDocxSave"
                    class="btn btn-primary"
                    disabled
                >
                    <span class="icon-save" aria-hidden="true"></span>
                    <?php echo Text::_('MOD_DOCX_VIEWER_SAVE'); ?>
                </button>

                <button
                    type="button"
                    class="btn btn-secondary"
                    data-bs-dismiss="modal"
                >
                    <?php echo Text::_('JCANCEL'); ?>
                </button>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    'use strict';

    const AJAX_URL = <?php echo json_encode($ajaxUrl, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
    const TOKEN = <?php echo json_encode($token, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;

    const fileInput = document.getElementById('modDocxFile');
    const previewBtn = document.getElementById('modDocxPreview');
    const statusBox = document.getElementById('modDocxStatus');

    const modalElement = document.getElementById('modDocxModal');
    const modalTitle = document.getElementById('modDocxModalLabel');
    const modalStatus = document.getElementById('modDocxModalStatus');

    const editor = document.getElementById('modDocxEditor');
    const saveBtn = document.getElementById('modDocxSave');
    const downloadBtn = document.getElementById('modDocxDownload');

    let currentFile = '';
    let modal = null;

    if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
        modal = new bootstrap.Modal(modalElement);
    }

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function setStatus(type, message) {
        if (!statusBox) {
            return;
        }

        if (!message) {
            statusBox.innerHTML = '';
            return;
        }

        statusBox.innerHTML = '<div class="alert alert-' + type + ' mb-0">' + escapeHtml(message) + '</div>';
    }

    function setModalStatus(type, message) {
        if (!modalStatus) {
            return;
        }

        if (!message) {
            modalStatus.innerHTML = '';
            return;
        }

        modalStatus.innerHTML = '<div class="alert alert-' + type + ' mb-0 py-2">' + escapeHtml(message) + '</div>';
    }

    function updatePreviewButton() {
        if (!previewBtn || !fileInput) {
            return;
        }

        previewBtn.disabled = !(fileInput.files && fileInput.files.length > 0);
    }

    async function ajaxRequest(task, formData) {
        formData = formData || new FormData();

        formData.set('docx_task', task);
        formData.set(TOKEN, '1');

        const response = await fetch(AJAX_URL, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        });

        let json;

        try {
            json = await response.json();
        } catch (e) {
            throw new Error('Сервер вернул не JSON. HTTP статус: ' + response.status);
        }

        if (!response.ok || json.success === false) {
            throw new Error(json.message || ('Ошибка сервера. HTTP статус: ' + response.status));
        }

        return json.data ?? {};
    }

    if (fileInput) {
        fileInput.addEventListener('change', function () {
            updatePreviewButton();
            setStatus('', '');
        });
    }

    if (previewBtn) {
        previewBtn.addEventListener('click', async function () {
            if (!fileInput || !fileInput.files || fileInput.files.length === 0) {
                setStatus('warning', 'Сначала выберите файл .docx.');
                return;
            }

            if (!modal) {
                setStatus('danger', 'Bootstrap Modal не загружен.');
                return;
            }

            previewBtn.disabled = true;
            setStatus('info', 'Загрузка и разбор DOCX...');

            const formData = new FormData();
            formData.append('docx', fileInput.files[0]);

            try {
                const data = await ajaxRequest('upload', formData);

                currentFile = data.filename || '';
                editor.value = data.text || '';

                modalTitle.textContent = data.original || 'DOCX';

                saveBtn.disabled = !currentFile;
                downloadBtn.disabled = !currentFile;

                setModalStatus('', '');
                modal.show();

                setStatus('success', 'Файл загружен: ' + (data.original || ''));
            } catch (err) {
                setStatus('danger', err.message || 'Неизвестная ошибка.');
            } finally {
                updatePreviewButton();
            }
        });
    }

    if (saveBtn) {
        saveBtn.addEventListener('click', async function () {
            if (!currentFile) {
                setModalStatus('warning', 'Нет загруженного файла.');
                return;
            }

            saveBtn.disabled = true;
            setModalStatus('info', 'Сохранение изменений...');

            const formData = new FormData();
            formData.append('filename', currentFile);
            formData.append('text', editor.value);

            try {
                const data = await ajaxRequest('save', formData);
                setModalStatus('success', data.message || 'Изменения сохранены.');
            } catch (err) {
                setModalStatus('danger', err.message || 'Ошибка сохранения.');
            } finally {
                saveBtn.disabled = false;
            }
        });
    }

    if (downloadBtn) {
        downloadBtn.addEventListener('click', async function () {
            if (!currentFile) {
                setModalStatus('warning', 'Нет загруженного файла.');
                return;
            }

            downloadBtn.disabled = true;
            setModalStatus('info', 'Подготовка файла к скачиванию...');

            const formData = new FormData();
            formData.append('filename', currentFile);

            try {
                const data = await ajaxRequest('download', formData);

                if (!data.content) {
                    throw new Error('Сервер не вернул содержимое файла.');
                }

                const binary = atob(data.content);
                const bytes = new Uint8Array(binary.length);

                for (let i = 0; i < binary.length; i++) {
                    bytes[i] = binary.charCodeAt(i);
                }

                const blob = new Blob([bytes], {
                    type: data.mimetype || 'application/octet-stream'
                });

                const url = URL.createObjectURL(blob);
                const link = document.createElement('a');

                link.href = url;
                link.download = data.filename || 'document.docx';

                document.body.appendChild(link);
                link.click();
                link.remove();

                setTimeout(function () {
                    URL.revokeObjectURL(url);
                }, 1000);

                setModalStatus('success', 'Файл скачан.');
            } catch (err) {
                setModalStatus('danger', err.message || 'Ошибка скачивания.');
            } finally {
                downloadBtn.disabled = false;
            }
        });
    }

    updatePreviewButton();
});
</script>