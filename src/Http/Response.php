<?php
declare(strict_types=1);

namespace Whitesmoke\Http;

final class Response
{
    private const SECURITY_HEADERS = [
        'X-Content-Type-Options'  => 'nosniff',
        'X-Frame-Options'         => 'DENY',
        'Referrer-Policy'         => 'strict-origin-when-cross-origin',
        'Content-Security-Policy' => "default-src 'self'; style-src 'self' 'unsafe-inline'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'",
    ];

    private array $headers = [];

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

    public static function redirect(string $to, int $status = 302): self
    {
        if (!str_starts_with($to, '/') || str_starts_with($to, '//') || str_contains($to, '\\')) {
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

        foreach (self::SECURITY_HEADERS + $this->headers as $name => $value) {
            header("{$name}: {$value}");
        }

        echo $this->body;
    }
}
