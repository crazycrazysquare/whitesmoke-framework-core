<?php
declare(strict_types=1);

namespace Whitesmoke\Mail;

use InvalidArgumentException;

/** A plain-text email to one recipient. */
final class Message
{
    public function __construct(
        public readonly string $to,
        public readonly string $subject,
        public readonly string $text,
    ) {
        if (!self::isAddress($to)) {
            throw new InvalidArgumentException('Invalid recipient address');
        }

        if (preg_match('~[\r\n\0]~', $subject) || !mb_check_encoding($subject . $text, 'UTF-8')) {
            throw new InvalidArgumentException('Subject must be one line of UTF-8 text, and the body UTF-8');
        }
    }

    /** A plain address (no display name) that is safe to put in a header or SMTP command. */
    public static function isAddress(string $address): bool
    {
        return filter_var($address, FILTER_VALIDATE_EMAIL) !== false && !preg_match('~[\s<>,;"\\\\]~', $address);
    }

    /** The full message as sent after SMTP DATA: CRLF line endings, UTF-8 quoted-printable body. */
    public function render(string $from, string $fromName): string
    {
        $domain = substr((string) strrchr($from, '@'), 1);

        $headers = [
            'Date'                      => date(DATE_RFC2822),
            'From'                      => ($fromName === '' ? '' : self::encodeHeader($fromName, true) . ' ') . "<{$from}>",
            'To'                        => "<{$this->to}>",
            'Subject'                   => self::encodeHeader($this->subject),
            'Message-ID'                => '<' . bin2hex(random_bytes(16)) . "@{$domain}>",
            'MIME-Version'              => '1.0',
            'Content-Type'              => 'text/plain; charset=UTF-8',
            'Content-Transfer-Encoding' => 'quoted-printable',
        ];

        $head = '';
        foreach ($headers as $name => $value) {
            $head .= "{$name}: {$value}\r\n";
        }

        $body = str_replace(["\r\n", "\r"], "\n", $this->text);

        return $head . "\r\n" . quoted_printable_encode(str_replace("\n", "\r\n", $body));
    }

    /**
     * Header text for a Subject, or for a display name ($phrase). Plain short ASCII
     * stays as it is; a display name with other ASCII characters is quoted; anything
     * else becomes RFC 2047 encoded words.
     */
    private static function encodeHeader(string $text, bool $phrase = false): string
    {
        if (!str_contains($text, '=?')) {
            if ($phrase && preg_match("~^[A-Za-z0-9 !#$%&'*+/=?^_`{|}\~-]{1,60}$~", $text)) {
                return $text;
            }
            if (preg_match('~^[\x20-\x7E]{0,60}$~', $text)) {
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
