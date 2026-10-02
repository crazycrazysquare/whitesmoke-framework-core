<?php
declare(strict_types=1);

namespace Whitesmoke\Mail;

use InvalidArgumentException;

/**
 * Minimal SMTP client: one message per connection.
 *
 * Encryption is "tls" (STARTTLS, usually port 587), "ssl" (TLS from the start,
 * usually 465) or "none". Certificates are always verified. "none" is refused
 * unless the server is on this machine, so passwords and message text (such as
 * reset links) never cross a network unencrypted.
 */
final class SmtpTransport
{
    private const CRYPTO = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;

    /** @var resource|null */
    private $socket = null;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $encryption,
        private readonly string $username = '',
        private readonly string $password = '',
        private readonly int $timeout = 10,
        private readonly string $ehlo = 'localhost',
    ) {
        if (!preg_match('~^[A-Za-z0-9.-]+$|^\[?[0-9A-Fa-f:.]+\]?$~', $host)) {
            throw new InvalidArgumentException('Invalid SMTP host');
        }
        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException('Invalid SMTP port');
        }
        if (!in_array($encryption, ['tls', 'ssl', 'none'], true)) {
            throw new InvalidArgumentException('SMTP encryption must be tls, ssl or none');
        }
        if ($encryption === 'none' && !self::isLocal($host)) {
            throw new InvalidArgumentException('SMTP without encryption is only allowed to a server on this machine; use tls or ssl');
        }
        if ($timeout < 1) {
            throw new InvalidArgumentException('Invalid SMTP timeout');
        }
        if (!preg_match('~^[A-Za-z0-9.-]{1,253}$~', $ehlo)) {
            throw new InvalidArgumentException('Invalid EHLO name');
        }
        if (preg_match('~[\r\n\0]~', $username . $password)) {
            throw new InvalidArgumentException('Invalid SMTP credentials');
        }
    }

    /** Send one rendered message (Message::render()). */
    public function send(string $from, string $to, string $data): void
    {
        if (!Message::isAddress($from) || !Message::isAddress($to)) {
            throw new InvalidArgumentException('Invalid sender or recipient address');
        }

        $this->connect();

        try {
            $this->expect(220);
            $features = $this->hello();

            if ($this->encryption === 'tls') {
                if (!isset($features['STARTTLS'])) {
                    throw new MailException("SMTP server {$this->host} does not offer STARTTLS; nothing was sent");
                }
                $this->command('STARTTLS', [220]);
                if (@stream_socket_enable_crypto($this->socket, true, self::CRYPTO) !== true) {
                    throw new MailException("TLS with {$this->host} failed (certificate not trusted or name mismatch?); nothing was sent");
                }
                $features = $this->hello();
            }

            if ($this->username !== '') {
                $this->authenticate($features['AUTH'] ?? '');
            }

            $this->command("MAIL FROM:<{$from}>", [250]);
            $this->command("RCPT TO:<{$to}>", [250, 251]);
            $this->command('DATA', [354]);

            $data = preg_replace('~\r?\n~', "\r\n", rtrim($data, "\r\n"));
            $this->write(preg_replace('~^\.~m', '..', (string) $data) . "\r\n.\r\n");
            $this->expect(250);

            $this->write("QUIT\r\n");
        } finally {
            fclose($this->socket);
            $this->socket = null;
        }
    }

    private function connect(): void
    {
        $host   = str_contains($this->host, ':') && $this->host[0] !== '[' ? "[{$this->host}]" : $this->host;
        $scheme = $this->encryption === 'ssl' ? 'ssl' : 'tcp';

        $context = stream_context_create(['ssl' => [
            'verify_peer'       => true,
            'verify_peer_name'  => true,
            'allow_self_signed' => false,
            'peer_name'         => trim($this->host, '[]'),
            'SNI_enabled'       => true,
            'crypto_method'     => self::CRYPTO,
        ]]);

        $socket = @stream_socket_client("{$scheme}://{$host}:{$this->port}", $errno, $error, $this->timeout, STREAM_CLIENT_CONNECT, $context);

        if ($socket === false) {
            throw new MailException("Cannot connect to SMTP server {$this->host}:{$this->port}: " . ($error !== '' ? $error : 'connection or TLS handshake failed'));
        }

        stream_set_timeout($socket, $this->timeout);
        $this->socket = $socket;
    }

    /** EHLO; returns the advertised extensions, e.g. ['STARTTLS' => '', 'AUTH' => 'PLAIN LOGIN']. */
    private function hello(): array
    {
        $lines = $this->command("EHLO {$this->ehlo}", [250]);
        $features = [];

        foreach (array_slice($lines, 1) as $line) {
            $parts = explode(' ', strtoupper(trim($line)), 2);
            $features[$parts[0]] = $parts[1] ?? '';
        }

        return $features;
    }

    private function authenticate(string $mechanisms): void
    {
        $offered = explode(' ', $mechanisms);

        if (in_array('PLAIN', $offered, true)) {
            $this->command('AUTH PLAIN ' . base64_encode("\0{$this->username}\0{$this->password}"), [235]);
        } elseif (in_array('LOGIN', $offered, true)) {
            $this->command('AUTH LOGIN', [334]);
            $this->command(base64_encode($this->username), [334]);
            $this->command(base64_encode($this->password), [235]);
        } else {
            throw new MailException("SMTP server {$this->host} offers no supported login method (PLAIN or LOGIN)");
        }
    }

    /**
     * Send one command line and check the reply code. Errors quote the server's reply,
     * never the command, so credentials cannot end up in an exception or log.
     *
     * @return list<string> reply lines without codes
     */
    private function command(string $line, array $codes): array
    {
        $this->write("{$line}\r\n");

        return $this->expect(...$codes);
    }

    /** @return list<string> reply lines, if the code is one of $codes */
    private function expect(int ...$codes): array
    {
        $lines = [];

        do {
            $line = fgets($this->socket, 1024);
            if ($line === false) {
                $timedOut = (stream_get_meta_data($this->socket)['timed_out'] ?? false) === true;
                throw new MailException('SMTP server ' . ($timedOut ? 'timed out' : 'closed the connection'));
            }
            $code    = (int) substr($line, 0, 3);
            $lines[] = rtrim(substr($line, 4), "\r\n");
        } while (($line[3] ?? ' ') === '-');

        if (!in_array($code, $codes, true)) {
            throw new MailException("SMTP server {$this->host} replied {$code}: " . implode(' ', $lines));
        }

        return $lines;
    }

    private function write(string $data): void
    {
        if (@fwrite($this->socket, $data) !== strlen($data)) {
            throw new MailException('Writing to the SMTP server failed');
        }
    }

    private static function isLocal(string $host): bool
    {
        $host = trim($host, '[]');

        if (strtolower($host) === 'localhost') {
            return true;
        }

        $bin = @inet_pton($host);

        return $bin !== false && ($bin === inet_pton('::1') || (strlen($bin) === 4 && $bin[0] === "\x7F"));
    }
}
