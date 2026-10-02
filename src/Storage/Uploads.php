<?php
declare(strict_types=1);

namespace Whitesmoke\Storage;

use finfo;
use InvalidArgumentException;
use RuntimeException;
use Whitesmoke\Http\NotFound;
use Whitesmoke\Http\Response;

/**
 * Stores uploaded files outside public/ under random ids, and serves them back.
 *
 * A file is accepted only when its extension is one of the allowed types AND its
 * contents (detected by fileinfo) match that type, so a script renamed to
 * photo.jpg is refused. Visitors' file names are never used as paths.
 */
final class Uploads
{
    /** Extension => MIME types fileinfo may detect for it. No SVG, HTML or scripts on purpose. */
    private const TYPES = [
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png'  => ['image/png'],
        'gif'  => ['image/gif'],
        'webp' => ['image/webp'],
        'pdf'  => ['application/pdf'],
        'txt'  => ['text/plain'],
        'csv'  => ['text/csv', 'text/plain', 'application/csv'],
        'zip'  => ['application/zip'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip'],
    ];

    public function __construct(private readonly string $path)
    {
        if ($path === '' || str_contains($path, "\0")) {
            throw new InvalidArgumentException('Invalid upload directory');
        }
    }

    /** The extensions store() can accept. */
    public static function types(): array
    {
        return array_keys(self::TYPES);
    }

    /**
     * Check and store one file from $request->file(). Throws UploadRejected, with a
     * message for the visitor, when the file is missing, too large, empty, of a type
     * not in $types, or its contents don't match its extension.
     *
     * @param list<string> $types allowed extensions, e.g. ['jpg', 'png', 'pdf']
     */
    public function store(?array $file, array $types, int $maxBytes): StoredFile
    {
        $types = array_map('strtolower', $types);
        foreach ($types as $type) {
            if (!isset(self::TYPES[$type])) {
                throw new InvalidArgumentException("Unknown upload type: {$type}. Allowed: " . implode(', ', self::types()));
            }
        }
        if ($types === [] || $maxBytes < 1) {
            throw new InvalidArgumentException('store() needs at least one type and a positive size limit');
        }

        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        $tmp   = (string) ($file['tmp_name'] ?? '');

        if ($error === UPLOAD_ERR_NO_FILE) {
            throw new UploadRejected('Choose a file to upload.');
        }
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            throw new UploadRejected('The file is too large (at most ' . self::readable($maxBytes) . ').');
        }
        if ($error !== UPLOAD_ERR_OK || !is_uploaded_file($tmp)) {
            throw new UploadRejected('The upload failed. Try again.');
        }

        $size = (int) filesize($tmp);
        if ($size === 0) {
            throw new UploadRejected('The file is empty.');
        }
        if ($size > $maxBytes) {
            throw new UploadRejected('The file is too large (at most ' . self::readable($maxBytes) . ').');
        }

        $name      = self::cleanName((string) ($file['name'] ?? ''));
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        if (!in_array($extension, $types, true)) {
            throw new UploadRejected('This type of file is not allowed. Allowed: ' . implode(', ', $types) . '.');
        }

        $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
        if (!in_array($mime, self::TYPES[$extension], true)) {
            throw new UploadRejected("The file's contents don't match its type (.{$extension}).");
        }

        $id  = bin2hex(random_bytes(16));
        $dir = dirname($this->path($id));
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException("Cannot create upload directory {$dir}");
        }
        if (!move_uploaded_file($tmp, $this->path($id))) {
            throw new RuntimeException('Cannot move the uploaded file into ' . $dir);
        }
        @chmod($this->path($id), 0640);

        return new StoredFile($id, $name, $mime, $size, $extension);
    }

    /** Where a stored file lives. Only accepts ids made by store(). */
    public function path(string $id): string
    {
        if (!preg_match('~^[0-9a-f]{32}\z~', $id)) {
            throw new InvalidArgumentException('Invalid upload id');
        }

        return rtrim($this->path, '/\\') . '/' . substr($id, 0, 2) . '/' . $id;
    }

    public function exists(string $id): bool
    {
        return is_file($this->path($id));
    }

    /** Delete a stored file; false if it was not there. */
    public function delete(string $id): bool
    {
        return is_file($this->path($id)) && unlink($this->path($id));
    }

    /**
     * A response that sends the stored file. $name and $type come from your table
     * (StoredFile). Throws NotFound when the file is gone. See Response::download().
     */
    public function download(string $id, string $name, string $type, bool $inline = false): Response
    {
        if (!$this->exists($id)) {
            throw new NotFound();
        }

        return Response::download($this->path($id), $name, $type, $inline);
    }

    /** The visitor's file name without folders or control characters, at most 200 characters. */
    private static function cleanName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = (string) preg_replace('~[\x00-\x1F\x7F]~u', '', mb_check_encoding($name, 'UTF-8') ? $name : '');
        $name = mb_substr(trim($name, " .\t"), -200);

        return $name === '' ? 'file' : $name;
    }

    private static function readable(int $bytes): string
    {
        return match (true) {
            $bytes >= 1048576 => round($bytes / 1048576, 1) . ' MB',
            $bytes >= 1024    => round($bytes / 1024) . ' KB',
            default           => $bytes . ' bytes',
        };
    }
}
