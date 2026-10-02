<?php
declare(strict_types=1);

namespace Whitesmoke\Mail;

use InvalidArgumentException;

/**
 * An email to one recipient: plain text, optionally with an HTML version. With HTML,
 * both are sent as multipart/alternative; mail programs show the HTML and fall back
 * to the text.
 */
final class Message
{
    public function __construct(
        public readonly string $to,
        public readonly string $subject,
        public readonly string $text,
        public readonly ?string $html = null,
    ) {
        if (!self::isAddress($to)) {
            throw new InvalidArgumentException('Invalid recipient address');
        }

        if (preg_match('~[\r\n\0]~', $subject) || !mb_check_encoding($subject . $text . $html, 'UTF-8')) {
            throw new InvalidArgumentException('Subject must be one line of UTF-8 text, and the body UTF-8');
        }

        if ($html !== null && (trim($html) === '' || str_contains($html, "\0"))) {
            throw new InvalidArgumentException('The HTML body must not be empty or contain null bytes');
        }
    }

    /** A plain address (no display name) that is safe to put in a header or SMTP command. */
    public static function isAddress(string $address): bool
    {
        return filter_var($address, FILTER_VALIDATE_EMAIL) !== false && !preg_match('~[\s<>,;"\\\\]~', $address);
    }

    /** The full message as sent after SMTP DATA: CRLF line endings, UTF-8 quoted-printable parts. */
    public function render(string $from, string $fromName): string
    {
        $domain = substr((string) strrchr($from, '@'), 1);

        $headers = [
            'Date'         => date(DATE_RFC2822),
            'From'         => ($fromName === '' ? '' : self::encodeHeader($fromName, true) . ' ') . "<{$from}>",
            'To'           => "<{$this->to}>",
            'Subject'      => self::encodeHeader($this->subject),
            'Message-ID'   => '<' . bin2hex(random_bytes(16)) . "@{$domain}>",
            'MIME-Version' => '1.0',
        ];

        $text = self::encodeBody($this->text);

        if ($this->html === null) {
            $headers['Content-Type'] = 'text/plain; charset=UTF-8';
            $headers['Content-Transfer-Encoding'] = 'quoted-printable';

            return self::head($headers) . "\r\n" . $text;
        }

        $html = self::encodeBody($this->html);

        do {
            $boundary = 'ws-' . bin2hex(random_bytes(16));
        } while (str_contains($text . $html, $boundary));

        $headers['Content-Type'] = "multipart/alternative; boundary=\"{$boundary}\"";
        $part = static fn (string $type, string $body): string => "--{$boundary}\r\n"
            . "Content-Type: {$type}; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n{$body}\r\n";

        return self::head($headers) . "\r\n"
            . $part('text/plain', $text)
            . $part('text/html', $html)
            . "--{$boundary}--\r\n";
    }

    private static function head(array $headers): string
    {
        $head = '';
        foreach ($headers as $name => $value) {
            $head .= "{$name}: {$value}\r\n";
        }

        return $head;
    }

    /** CRLF line endings, then quoted-printable (lines of at most 76 characters). */
    private static function encodeBody(string $body): string
    {
        return quoted_printable_encode(str_replace(["\r\n", "\r", "\n"], ["\n", "\n", "\r\n"], $body));
    }

    /**
     * Header text for a Subject, or for a display name ($phrase). Plain short ASCII
     * stays as it is; a display name with other ASCII characters is quoted; anything
     * else becomes RFC 2047 encoded words.
     */
    private static function encodeHeader(string $text, bool $phrase = false): string
    {
        if (!str_contains($text, '=?')) {
            if ($phrase && preg_match("~^[A-Za-z0-9 !#$%&'*+/=?^_`{|}\~-]{1,60}\z~", $text)) {
                return $text;
            }
            if (preg_match('~^[\x20-\x7E]{0,60}\z~', $text)) {
                return $phrase ? '"' . addcslashes($text, '"\\') . '"' : $text;
            }
        }

        // Encoded words of at most 39 bytes of text (64 characters each), so even "Subject: " plus
        // the first word stays under 78 characters. Characters are never split.
        $words = [];
        $chunk = '';
        foreach (mb_str_split($text, 1, 'UTF-8') as $char) {
            if (strlen($chunk . $char) > 39) {
                $words[] = '=?UTF-8?B?' . base64_encode($chunk) . '?=';
                $chunk = '';
            }
            $chunk .= $char;
        }
        $words[] = '=?UTF-8?B?' . base64_encode($chunk) . '?=';

        return implode("\r\n ", $words);
    }
}
