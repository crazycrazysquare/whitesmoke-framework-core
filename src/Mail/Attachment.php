<?php
declare(strict_types=1);

namespace Whitesmoke\Mail;

use finfo;
use InvalidArgumentException;

/** A file attached to a Message. */
final class Attachment
{
    private function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly string $data,
    ) {}

    /**
     * Attach a file from disk. $name defaults to the file's name; $type defaults to the
     * type detected from its contents.
     */
    public static function fromPath(string $path, ?string $name = null, ?string $type = null): self
    {
        if (str_contains($path, "\0") || !is_file($path) || !is_readable($path)) {
            throw new InvalidArgumentException('Attachment file does not exist or cannot be read');
        }

        $data = (string) file_get_contents($path);

        return self::fromData($data, $name ?? basename($path), $type ?? ((new finfo(FILEINFO_MIME_TYPE))->buffer($data) ?: 'application/octet-stream'));
    }

    /** Attach content made in code, such as a generated CSV or PDF. */
    public static function fromData(string $data, string $name, string $type = 'application/octet-stream'): self
    {
        $name = trim((string) preg_replace('~[\x00-\x1F\x7F]~u', '', mb_check_encoding($name, 'UTF-8') ? $name : ''));
        $name = basename(str_replace('\\', '/', $name));

        if ($name === '' || $name === '.' || mb_strlen($name) > 200) {
            throw new InvalidArgumentException('Attachment needs a file name of 1 to 200 characters');
        }
        if (!preg_match('~^[a-z0-9][a-z0-9.+-]*/[a-z0-9][a-z0-9.+-]*\z~i', $type)) {
            throw new InvalidArgumentException('Invalid attachment content type');
        }

        return new self($name, strtolower($type), $data);
    }

    /** This attachment as a MIME part (base64), without the boundary line. */
    public function part(): string
    {
        $fallback = trim((string) preg_replace('~[^A-Za-z0-9._ -]+~', '_', $this->name), ' .') ?: 'attachment';
        $encoded  = "filename=\"{$fallback}\"; filename*=UTF-8''" . rawurlencode($this->name);

        return "Content-Type: {$this->type}; name=\"{$fallback}\"\r\n"
            . "Content-Transfer-Encoding: base64\r\n"
            . "Content-Disposition: attachment; {$encoded}\r\n\r\n"
            . rtrim(chunk_split(base64_encode($this->data), 76, "\r\n"), "\r\n") . "\r\n";
    }
}
