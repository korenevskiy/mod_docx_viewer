<?php
namespace Joomla\Module\DocxViewer\Helper;

defined('_JEXEC') or die;

use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Helper\ModuleHelper;
use Joomla\CMS\Session\Session;
use Joomla\Registry\Registry;

class DocxViewerHelper
{
    private const WORD_NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    private const XML_NS = 'http://www.w3.org/XML/1998/namespace';
    private const DOCX_MIME = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

    private const FILENAME_PATTERN = '/^docx_[a-f0-9]{24}\.docx$/i';

    public static function handleAjax(CMSApplicationInterface $app): array
    {
        self::checkAccess($app);

        $task = strtolower((string) $app->getInput()->getCmd('docx_task', ''));
        $tmpDir = self::prepareTmpDir();

        switch ($task) {
            case 'upload':
                return self::handleUpload($app, $tmpDir);

            case 'save':
                return self::handleSave($app, $tmpDir);

            case 'download':
                return self::handleDownload($app, $tmpDir);

            default:
                throw new \RuntimeException('Неизвестная задача.', 400);
        }
    }

    private static function checkAccess(CMSApplicationInterface $app): void
    {
        $user = $app->getIdentity();

        if (!$user || $user->guest) {
            throw new \RuntimeException('Требуется авторизация в административной панели.', 403);
        }

        if (!$user->authorise('core.login.admin')) {
            throw new \RuntimeException('Недостаточно прав для выполнения действия.', 403);
        }

        if (!Session::checkToken('request')) {
            throw new \RuntimeException('Недействительный токен запроса.', 403);
        }
    }

    private static function prepareTmpDir(): string
    {
        $dir = JPATH_ROOT . '/tmp/mod_docx_viewer';

        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new \RuntimeException('Не удалось создать временную папку.', 500);
            }
        }

        if (!is_writable($dir)) {
            throw new \RuntimeException('Временная папка недоступна для записи.', 500);
        }

        return $dir;
    }

    private static function getMaxSize(): int
    {
        $module = ModuleHelper::getModule('mod_docx_viewer');
        $mb = 10;

        if ($module && isset($module->params)) {
            if ($module->params instanceof Registry) {
                $mb = (int) $module->params->get('max_size', 10);
            } elseif (is_string($module->params)) {
                $registry = new Registry($module->params);
                $mb = (int) $registry->get('max_size', 10);
            }
        }

        $mb = max(1, min(100, $mb));

        return $mb * 1024 * 1024;
    }

    private static function handleUpload(CMSApplicationInterface $app, string $tmpDir): array
    {
        $file = $app->getInput()->files->get('docx');

        if (!is_array($file)) {
            throw new \RuntimeException('Файл не передан.', 400);
        }

        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

        if ($error !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('Ошибка загрузки файла. Код: ' . $error, 400);
        }

        $size = (int) ($file['size'] ?? 0);
        $maxSize = self::getMaxSize();

        if ($size <= 0) {
            throw new \RuntimeException('Пустой файл.', 400);
        }

        if ($size > $maxSize) {
            throw new \RuntimeException(
                'Файл слишком большой. Максимум: ' . round($maxSize / 1048576, 1) . ' МБ.',
                400
            );
        }

        $originalName = basename((string) ($file['name'] ?? ''));
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        if ($extension !== 'docx') {
            throw new \RuntimeException('Разрешены только файлы .docx.', 400);
        }

        $tmpName = (string) ($file['tmp_name'] ?? '');

        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            throw new \RuntimeException('Некорректный временный файл загрузки.', 400);
        }

        self::validateDocxMime($tmpName);

        $destName = 'docx_' . bin2hex(random_bytes(12)) . '.docx';
        $destPath = $tmpDir . '/' . $destName;

        if (!move_uploaded_file($tmpName, $destPath)) {
            throw new \RuntimeException('Не удалось сохранить загруженный файл.', 500);
        }

        try {
            $text = self::extractText($destPath);
        } catch (\Throwable $e) {
            @unlink($destPath);
            throw $e;
        }

        return [
            'filename' => $destName,
            'original' => $originalName,
            'text'     => $text,
        ];
    }

    private static function handleSave(CMSApplicationInterface $app, string $tmpDir): array
    {
        $filename = basename((string) $app->getInput()->getString('filename', ''));
        self::validateFilename($filename);

        $path = $tmpDir . '/' . $filename;

        if (!is_file($path)) {
            throw new \RuntimeException('Файл не найден.', 404);
        }

        $text = (string) $app->getInput()->post->get('text', '', 'raw');

        self::saveText($path, $text);

        return [
            'message' => 'Изменения сохранены.',
        ];
    }

    private static function handleDownload(CMSApplicationInterface $app, string $tmpDir): array
    {
        $filename = basename((string) $app->getInput()->getString('filename', ''));
        self::validateFilename($filename);

        $path = $tmpDir . '/' . $filename;

        if (!is_file($path)) {
            throw new \RuntimeException('Файл не найден.', 404);
        }

        $content = file_get_contents($path);

        if ($content === false) {
            throw new \RuntimeException('Не удалось прочитать файл.', 500);
        }

        return [
            'filename' => $filename,
            'mimetype' => self::DOCX_MIME,
            'content'  => base64_encode($content),
        ];
    }

    private static function validateFilename(string $filename): void
    {
        if ($filename === '' || !preg_match(self::FILENAME_PATTERN, $filename)) {
            throw new \RuntimeException('Некорректное имя файла.', 400);
        }
    }

    private static function validateDocxMime(string $path): void
    {
        if (!class_exists('finfo')) {
            return;
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($path);

        $allowed = [
            self::DOCX_MIME,
            'application/zip',
            'application/x-zip-compressed',
            'multipart/x-zip',
        ];

        if (!in_array($mime, $allowed, true)) {
            throw new \RuntimeException('Файл не похож на корректный DOCX. MIME: ' . $mime, 400);
        }
    }

    public static function extractText(string $filePath): string
    {
        if (!is_file($filePath)) {
            throw new \RuntimeException('Файл не найден.', 404);
        }

        $zip = new \ZipArchive();
        $open = $zip->open($filePath);

        if ($open !== true) {
            throw new \RuntimeException('Не удалось открыть DOCX. Код ZipArchive: ' . $open, 400);
        }

        $xml = $zip->getFromName('word/document.xml');
        $zip->close();

        if ($xml === false) {
            throw new \RuntimeException('В DOCX отсутствует word/document.xml.', 400);
        }

        $dom = new \DOMDocument();
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = false;

        libxml_use_internal_errors(true);

        if (!$dom->loadXML($xml, LIBXML_NONET | LIBXML_NOCDATA)) {
            $errors = libxml_get_errors();
            libxml_clear_errors();

            $message = $errors ? trim($errors[0]->message) : 'Не удалось разобрать XML документа.';

            throw new \RuntimeException('Ошибка XML: ' . $message, 400);
        }

        $paragraphs = [];

        foreach ($dom->getElementsByTagNameNS(self::WORD_NS, 'p') as $p) {
            $text = '';

            foreach ($p->getElementsByTagNameNS(self::WORD_NS, 't') as $t) {
                $text .= $t->textContent;
            }

            $paragraphs[] = $text;
        }

        return implode("\n\n", $paragraphs);
    }

    public static function saveText(string $filePath, string $newText): bool
    {
        if (!is_file($filePath)) {
            throw new \RuntimeException('Файл не найден.', 404);
        }

        $zip = new \ZipArchive();
        $open = $zip->open($filePath);

        if ($open !== true) {
            throw new \RuntimeException('Не удалось открыть DOCX для записи. Код ZipArchive: ' . $open, 400);
        }

        $xml = $zip->getFromName('word/document.xml');

        if ($xml === false) {
            $zip->close();
            throw new \RuntimeException('В DOCX отсутствует word/document.xml.', 400);
        }

        $dom = new \DOMDocument();
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = false;

        libxml_use_internal_errors(true);

        if (!$dom->loadXML($xml, LIBXML_NONET | LIBXML_NOCDATA)) {
            $errors = libxml_get_errors();
            libxml_clear_errors();
            $zip->close();

            $message = $errors ? trim($errors[0]->message) : 'Не удалось разобрать XML документа.';

            throw new \RuntimeException('Ошибка XML: ' . $message, 400);
        }

        $body = $dom->getElementsByTagNameNS(self::WORD_NS, 'body')->item(0);

        if (!$body) {
            $zip->close();
            throw new \RuntimeException('В document.xml отсутствует элемент body.', 400);
        }

        $sectPr = null;

        foreach ($body->childNodes as $node) {
            if ($node->nodeType === XML_ELEMENT_NODE && $node->localName === 'sectPr') {
                $sectPr = $node->cloneNode(true);
                break;
            }
        }

        while ($body->firstChild) {
            $body->removeChild($body->firstChild);
        }

        $lines = preg_split("/\r\n|\n|\r/", $newText);

        if ($lines === false) {
            $lines = [$newText];
        }

        foreach ($lines as $line) {
            $p = $dom->createElementNS(self::WORD_NS, 'w:p');
            $r = $dom->createElementNS(self::WORD_NS, 'w:r');
            $t = $dom->createElementNS(self::WORD_NS, 'w:t');

            $t->appendChild($dom->createTextNode((string) $line));
            $t->setAttributeNS(self::XML_NS, 'xml:space', 'preserve');

            $r->appendChild($t);
            $p->appendChild($r);
            $body->appendChild($p);
        }

        if ($sectPr instanceof \DOMElement) {
            $body->appendChild($sectPr);
        }

        $saved = $zip->addFromString('word/document.xml', $dom->saveXML());
        $zip->close();

        if ($saved === false) {
            throw new \RuntimeException('Не удалось записать измененный document.xml.', 500);
        }

        return true;
    }
}