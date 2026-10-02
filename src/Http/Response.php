<?php
declare(strict_types=1);

namespace Whitesmoke\Http;

final class Response
{
    /** Sent on every response unless the app sets the same header itself. */
    private const SECURITY_HEADERS = [
        'X-Content-Type-Options'       => 'nosniff',
        'X-Frame-Options'              => 'DENY',
        'Referrer-Policy'              => 'strict-origin-when-cross-origin',
        'Content-Security-Policy'      => "default-src 'self'; style-src 'self' 'unsafe-inline'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'",
        'Cross-Origin-Opener-Policy'   => 'same-origin',
        'Permissions-Policy'           => 'camera=(), microphone=(), geolocation=(), payment=()',
    ];

    /** Sent only over HTTPS. */
    private const HSTS = 'max-age=31536000';

    private array $headers = [];
    private ?bool $https = null;
    private ?string $file = null;

    /** Types a browser may show inline; everything else is always a download. */
    private const INLINE = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf'];

    public function __construct(private readonly string $body = '', private readonly int $status = 200) {}

    public static function html(string $body, int $status = 200): self
    {
        return (new self($body, $status))->header('Content-Type', 'text/html; charset=UTF-8');
    }

    public static function text(string $body, int $status = 200): self
    {
        return (new self($body, $status))->header('Content-Type', 'text/plain; charset=UTF-8');
    }

    public static function json(mixed $data, int $status = 200): self
    {
        return (new self(json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $status))
            ->header('Content-Type', 'application/json');
    }

    /**
     * Send a file from disk, streamed rather than loaded into memory. $name is the
     * file name the visitor sees (any characters; it is encoded safely). $inline
     * shows images and PDFs in the browser; every other type is always a download.
     */
    public static function download(string $path, string $name, string $type, bool $inline = false): self
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new \InvalidArgumentException('File to download does not exist');
        }
        if (!preg_match('~^[a-z0-9][a-z0-9.+-]*/[a-z0-9][a-z0-9.+-]*\z~i', $type)) {
            throw new \InvalidArgumentException('Invalid content type');
        }

        $name     = (string) preg_replace('~[\x00-\x1F\x7F]~u', '', mb_check_encoding($name, 'UTF-8') ? $name : '') ?: 'download';
        $fallback = trim((string) preg_replace('~[^A-Za-z0-9._ -]+~', '_', $name), ' .') ?: 'download';
        $mode     = $inline && in_array(strtolower($type), self::INLINE, true) ? 'inline' : 'attachment';

        $response = (new self('', 200))
            ->header('Content-Type', $type)
            ->header('Content-Length', (string) filesize($path))
            ->header('Content-Disposition', "{$mode}; filename=\"{$fallback}\"; filename*=UTF-8''" . rawurlencode($name))
            ->header('Cache-Control', 'private, no-cache')
            // Nothing in a served file may run. Chrome refuses to show PDFs under "sandbox".
            ->header('Content-Security-Policy', "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'"
                . ($mode === 'inline' && strtolower($type) === 'application/pdf' ? '' : '; sandbox'));

        $response->file = $path;

        return $response;
    }

    public static function redirect(string $to, int $status = 302): self
    {
        // Browsers strip tabs and newlines from URLs, so "/\t/evil.example" would become
        // "//evil.example". Reject control characters and spaces outright.
        if (!str_starts_with($to, '/') || str_starts_with($to, '//') || str_contains($to, '\\')
            || preg_match('~[\x00-\x20\x7F]~', $to)) {
            throw new \InvalidArgumentException('Redirects must be local paths');
        }

        return (new self('', $status))->header('Location', $to);
    }

    public function header(string $name, string $value): self
    {
        if (preg_match('~[\r\n]~', $name . $value)) {
            throw new \InvalidArgumentException('Header injection blocked');
        }

        $this->headers[$name] = $value;
        return $this;
    }

    /** Whether the request came over HTTPS (set by Application from Request::isSecure()). */
    public function https(bool $https): self
    {
        $this->https = $https;
        return $this;
    }

    /** Final headers: the app's own headers win over the security defaults. */
    public function headers(): array
    {
        $defaults = self::SECURITY_HEADERS;

        $https = $this->https ?? ((!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
            || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443');

        if ($https) {
            $defaults['Strict-Transport-Security'] = self::HSTS;
        }

        $set = array_change_key_case($this->headers, CASE_LOWER);

        foreach ($defaults as $name => $value) {
            if (!isset($set[strtolower($name)])) {
                $this->headers[$name] ??= $value;
            }
        }

        return $this->headers;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function send(): void
    {
        header_remove('X-Powered-By');
        http_response_code($this->status);

        foreach ($this->headers() as $name => $value) {
            header("{$name}: {$value}");
        }

        if ($this->file !== null) {
            readfile($this->file);
            return;
        }

        echo $this->body;
    }
}
