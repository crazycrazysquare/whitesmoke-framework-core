<?php
declare(strict_types=1);

namespace Whitesmoke\Mail;

use InvalidArgumentException;

/**
 * An email: plain text, optionally with an HTML version (multipart/alternative; mail
 * programs show the HTML and fall back to the text), to one or more recipients, with
 * optional Cc, Bcc and attachments (multipart/mixed). Bcc addresses never appear in
 * the headers; they only receive the message.
 */
final class Message
{
    private const MAX_RECIPIENTS  = 100;
    private const MAX_ATTACHMENTS = 20 * 1024 * 1024;

    /**
     * @param string|list<string> $to
     * @param list<string>        $cc
     * @param list<string>        $bcc
     * @param list<Attachment>    $attachments
     */
    public function __construct(
        public readonly string|array $to,
        public readonly string $subject,
        public readonly string $text,
        public readonly ?string $html = null,
        public readonly array $cc = [],
        public readonly array $bcc = [],
        public readonly array $attachments = [],
    ) {
        if (self::addresses($to) === []) {
            throw new InvalidArgumentException('A message needs at least one recipient');
        }
        self::addresses($cc);
        self::addresses($bcc);

        if (count($this->recipients()) > self::MAX_RECIPIENTS) {
            throw new InvalidArgumentException('A message can have at most ' . self::MAX_RECIPIENTS . ' recipients');
        }

        if (preg_match('~[\r\n\0]~', $subject) || !mb_check_encoding($subject . $text . $html, 'UTF-8')) {
            throw new InvalidArgumentException('Subject must be one line of UTF-8 text, and the body UTF-8');
        }

        if ($html !== null && (trim($html) === '' || str_contains($html, "\0"))) {
            throw new InvalidArgumentException('The HTML body must not be empty or contain null bytes');
        }

        $size = 0;
        foreach ($attachments as $attachment) {
            if (!$attachment instanceof Attachment) {
                throw new InvalidArgumentException('Attachments must be Attachment objects (Attachment::fromPath() or fromData())');
            }
            $size += strlen($attachment->data);
        }
        if ($size > self::MAX_ATTACHMENTS) {
            throw new InvalidArgumentException('Attachments may total at most 20 MB');
        }
    }

    /** Every address that receives the message: To, Cc and Bcc, each once. */
    public function recipients(): array
    {
        $unique = [];
        foreach ([...self::addresses($this->to), ...self::addresses($this->cc), ...self::addresses($this->bcc)] as $address) {
            $unique[strtolower($address)] ??= $address;
        }

        return array_values($unique);
    }

    /** @return list<string> */
    private static function addresses(string|array $value): array
    {
        $list = is_array($value) ? array_values($value) : [$value];

        foreach ($list as $address) {
            if (!is_string($address) || !self::isAddress($address)) {
                throw new InvalidArgumentException('Invalid recipient address');
            }
        }

        return $list;
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

        $list = static fn (array $addresses): string => implode(",\r\n ", array_map(fn (string $a): string => "<{$a}>", $addresses));

        $headers = [
            'Date'         => date(DATE_RFC2822),
            'From'         => ($fromName === '' ? '' : self::encodeHeader($fromName, true) . ' ') . "<{$from}>",
            'To'           => $list(self::addresses($this->to)),
            'Cc'           => $list(self::addresses($this->cc)),
            'Subject'      => self::encodeHeader($this->subject),
            'Message-ID'   => '<' . bin2hex(random_bytes(16)) . "@{$domain}>",
            'MIME-Version' => '1.0',
        ];
        if ($this->cc === []) {
            unset($headers['Cc']);
        }

        $text  = self::encodeBody($this->text);
        $html  = $this->html === null ? null : self::encodeBody($this->html);
        $files = array_map(static fn (Attachment $a): string => $a->part(), $this->attachments);
        $all   = $text . $html . implode('', $files);

        // The body: plain text, or text and HTML as alternatives.
        if ($html === null) {
            $body    = ['Content-Type' => 'text/plain; charset=UTF-8', 'Content-Transfer-Encoding' => 'quoted-printable'];
            $content = $text;
        } else {
            $alt     = self::boundary($all);
            $part    = static fn (string $type, string $encoded): string => "--{$alt}\r\n"
                . "Content-Type: {$type}; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n{$encoded}\r\n";
            $body    = ['Content-Type' => "multipart/alternative; boundary=\"{$alt}\""];
            $content = $part('text/plain', $text) . $part('text/html', $html) . "--{$alt}--\r\n";
            $all    .= $alt;
        }

        if ($files === []) {
            return self::head($headers + $body) . "\r\n" . $content;
        }

        // With attachments: the body first, then each file.
        $mixed = self::boundary($all);
        $headers['Content-Type'] = "multipart/mixed; boundary=\"{$mixed}\"";

        $out = self::head($headers) . "\r\n--{$mixed}\r\n" . self::head($body) . "\r\n" . $content . "\r\n";
        foreach ($files as $file) {
            $out .= "--{$mixed}\r\n" . $file;
        }

        return $out . "--{$mixed}--\r\n";
    }

    /** A random MIME boundary that does not occur in $content. */
    private static function boundary(string $content): string
    {
        do {
            $boundary = 'ws-' . bin2hex(random_bytes(16));
        } while (str_contains($content, $boundary));

        return $boundary;
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
