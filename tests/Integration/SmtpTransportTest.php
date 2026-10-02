<?php
declare(strict_types=1);

namespace Whitesmoke\Tests\Integration;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Whitesmoke\Mail\MailException;
use Whitesmoke\Mail\Mailer;
use Whitesmoke\Mail\Message;
use Whitesmoke\Mail\SmtpTransport;

/** Talks to tests/Fixtures/smtp-server.php, which records every line it receives. */
final class SmtpTransportTest extends TestCase
{
    /** @var resource|null */
    private $server = null;
    private int $port = 0;
    private string $transcript = '';

    protected function tearDown(): void
    {
        if ($this->server !== null) {
            proc_terminate($this->server);
            proc_close($this->server);
            $this->server = null;
        }
    }

    private function startServer(string $mode, ?string $cert = null): void
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $this->port = (int) substr((string) strrchr((string) stream_socket_get_name($probe, false), ':'), 1);
        fclose($probe);

        $this->transcript = WS_TEST_TMP . '/smtp-' . bin2hex(random_bytes(4)) . '.log';
        touch($this->transcript);

        $args = [PHP_BINARY, dirname(__DIR__) . '/Fixtures/smtp-server.php', (string) $this->port, $this->transcript, $mode];
        if ($cert !== null) {
            $args[] = $cert;
        }

        $this->server = proc_open($args, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes) ?: null;

        for ($i = 0; $i < 50; $i++) {
            $socket = @fsockopen('127.0.0.1', $this->port, $errno, $error, 0.2);
            if ($socket !== false) {
                fclose($socket);
                return;
            }
            usleep(100_000);
        }

        $this->fail('Fake SMTP server did not start');
    }

    /** @return list<string> */
    private function received(): array
    {
        usleep(100_000);
        return array_values(array_filter(explode("\n", (string) file_get_contents($this->transcript)), fn (string $l): bool => $l !== ''));
    }

    private function transport(string $encryption = 'none', string $user = 'good', string $pass = 'secret', int $timeout = 5): SmtpTransport
    {
        return new SmtpTransport('127.0.0.1', $this->port, $encryption, $user, $pass, $timeout);
    }

    private function message(string $to = 'ana@example.test'): string
    {
        return (new Message($to, 'Hello', "Line one\n.hidden line starts with a dot\nLast"))->render('app@example.test', 'App');
    }

    public function testSendsWithAuthPlainAndDotStuffing(): void
    {
        $this->startServer('plain');
        $this->transport()->send('app@example.test', 'ana@example.test', $this->message());

        $lines = $this->received();

        $this->assertSame('EHLO localhost', $lines[0]);
        $this->assertSame('AUTH PLAIN ' . base64_encode("\0good\0secret"), $lines[1]);
        $this->assertSame('MAIL FROM:<app@example.test>', $lines[2]);
        $this->assertSame('RCPT TO:<ana@example.test>', $lines[3]);
        $this->assertSame('DATA', $lines[4]);
        $this->assertContains('Subject: Hello', $lines);
        $this->assertContains('..hidden line starts with a dot', $lines, 'a leading dot is doubled');
        $this->assertSame(['.', 'QUIT'], array_slice($lines, -2));
    }

    public function testUsesAuthLoginWhenPlainIsNotOffered(): void
    {
        $this->startServer('login');
        $this->transport()->send('app@example.test', 'ana@example.test', $this->message());

        $lines = $this->received();

        $this->assertSame('AUTH LOGIN', $lines[1]);
        $this->assertSame([base64_encode('good'), base64_encode('secret')], [$lines[2], $lines[3]]);
        $this->assertContains('RCPT TO:<ana@example.test>', $lines);
    }

    public function testWrongPasswordFailsWithoutLeakingIt(): void
    {
        $this->startServer('plain');

        try {
            $this->transport('none', 'good', 'Wrong-Pass-123')->send('app@example.test', 'ana@example.test', $this->message());
            $this->fail('Login must fail');
        } catch (MailException $e) {
            $this->assertStringContainsString('535', $e->getMessage());
            $this->assertStringNotContainsString('Wrong-Pass-123', $e->getMessage());
            $this->assertStringNotContainsString(base64_encode("\0good\0Wrong-Pass-123"), $e->getMessage());
        }

        $this->assertNotContains('MAIL FROM:<app@example.test>', $this->received(), 'nothing sent after a failed login');
    }

    public function testRejectedRecipientFails(): void
    {
        $this->startServer('plain');

        $this->expectException(MailException::class);
        $this->expectExceptionMessage('550');
        $this->transport()->send('app@example.test', 'reject@example.test', $this->message('reject@example.test'));
    }

    public function testTlsRequiredButNotOfferedSendsNothing(): void
    {
        $this->startServer('plain');

        try {
            new SmtpTransport('127.0.0.1', $this->port, 'tls', 'good', 'secret', 5);
            $this->transport('tls')->send('app@example.test', 'ana@example.test', $this->message());
            $this->fail('Must refuse to continue without STARTTLS');
        } catch (MailException $e) {
            $this->assertStringContainsString('does not offer STARTTLS', $e->getMessage());
        }

        $this->assertSame(['EHLO localhost'], $this->received(), 'no password and no message without TLS');
    }

    public function testUntrustedCertificateIsRefused(): void
    {
        $cert = $this->selfSignedCertificate();
        $this->startServer('tls', $cert);

        try {
            $this->transport('tls')->send('app@example.test', 'ana@example.test', $this->message());
            $this->fail('A self-signed certificate must be refused');
        } catch (MailException $e) {
            $this->assertStringContainsString('TLS with 127.0.0.1 failed', $e->getMessage());
        }

        $lines = $this->received();
        $this->assertSame(['EHLO localhost', 'STARTTLS'], array_slice($lines, 0, 2));
        $this->assertEmpty(preg_grep('~^(AUTH|MAIL|RCPT)~', $lines), 'nothing sent over an unverified connection');
    }

    public function testSilentServerTimesOut(): void
    {
        $this->startServer('mute');

        $this->expectException(MailException::class);
        $this->expectExceptionMessage('timed out');
        $this->transport('none', 'good', 'secret', 1)->send('app@example.test', 'ana@example.test', $this->message());
    }

    public function testUnencryptedIsOnlyAllowedLocally(): void
    {
        foreach (['localhost', '127.0.0.1', '127.0.0.2', '::1', '[::1]'] as $host) {
            new SmtpTransport($host, 1025, 'none');
        }

        foreach (['smtp.example.com', '10.0.0.1', '192.168.1.5', '2001:db8::1'] as $host) {
            try {
                new SmtpTransport($host, 25, 'none');
                $this->fail("Unencrypted SMTP to {$host} must be refused");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('only allowed to a server on this machine', $e->getMessage());
            }
        }
    }

    public function testInvalidSettingsAreRefused(): void
    {
        $bad = [
            fn () => new SmtpTransport('smtp example.com', 587, 'tls'),
            fn () => new SmtpTransport("smtp.example.com\r\nRCPT", 587, 'tls'),
            fn () => new SmtpTransport('smtp.example.com', 0, 'tls'),
            fn () => new SmtpTransport('smtp.example.com', 70000, 'tls'),
            fn () => new SmtpTransport('smtp.example.com', 587, 'starttls'),
            fn () => new SmtpTransport('smtp.example.com', 587, 'tls', "user\r\nRSET"),
            fn () => new SmtpTransport('smtp.example.com', 587, 'tls', 'u', 'p', 0),
            fn () => new SmtpTransport('smtp.example.com', 587, 'tls', 'u', 'p', 10, 'bad name'),
        ];

        foreach ($bad as $i => $make) {
            try {
                $make();
                $this->fail("Setting #{$i} must be refused");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testMailerSendsThroughSmtp(): void
    {
        $this->startServer('plain');

        (new Mailer([
            'driver' => 'smtp', 'host' => '127.0.0.1', 'port' => $this->port, 'encryption' => 'none',
            'username' => 'good', 'password' => 'secret', 'from_address' => 'app@example.test', 'from_name' => 'My App',
        ]))->send(new Message('ana@example.test', 'Reset your password', 'Open the link.'));

        $lines = $this->received();
        $this->assertContains('From: My App <app@example.test>', $lines);
        $this->assertContains('Subject: Reset your password', $lines);
        $this->assertContains('Open the link.', $lines);
    }

    public function testMailerSendsHtmlWithTextAlternative(): void
    {
        $this->startServer('plain');

        (new Mailer([
            'driver' => 'smtp', 'host' => '127.0.0.1', 'port' => $this->port, 'encryption' => 'none',
            'from_address' => 'app@example.test',
        ]))->send(new Message('ana@example.test', 'Report', "Plain version\n.dot line", "<p>HTML version</p>\n.dot line"));

        $lines = $this->received();
        $this->assertNotEmpty(preg_grep('~^Content-Type: multipart/alternative; boundary="ws-[0-9a-f]{32}"$~', $lines));
        $this->assertContains('Content-Type: text/plain; charset=UTF-8', $lines);
        $this->assertContains('Content-Type: text/html; charset=UTF-8', $lines);
        $this->assertContains('<p>HTML version</p>', $lines);
        $this->assertSame(2, count(array_keys($lines, '..dot line')), 'lines starting with a dot are doubled in both parts');
    }

    /** A self-signed certificate for 127.0.0.1, or skip where OpenSSL cannot make one. */
    private function selfSignedCertificate(): string
    {
        $config = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'digest_alg' => 'sha256'];

        // Windows PHP builds ship openssl.cnf in extras/ssl but do not point OpenSSL at it.
        $bundled = dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf';
        if (getenv('OPENSSL_CONF') === false && is_file($bundled)) {
            $config['config'] = $bundled;
        }
        $key = @openssl_pkey_new($config);
        $csr = $key === false ? false : @openssl_csr_new(['commonName' => '127.0.0.1'], $key, $config);
        $crt = $csr === false ? false : @openssl_csr_sign($csr, null, $key, 1, $config);

        if ($crt === false || !openssl_x509_export($crt, $pem) || !openssl_pkey_export($key, $keyPem, null, $config)) {
            $this->markTestSkipped('OpenSSL cannot create a test certificate here (missing openssl.cnf?)');
        }

        $file = WS_TEST_TMP . '/smtp-cert.pem';
        file_put_contents($file, $pem . $keyPem);

        return $file;
    }
}
