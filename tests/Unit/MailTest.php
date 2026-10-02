<?php
declare(strict_types=1);

namespace Whitesmoke\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Whitesmoke\Mail\Mailer;
use Whitesmoke\Mail\Message;

final class MailTest extends TestCase
{
    private function headers(string $raw): array
    {
        [$head] = explode("\r\n\r\n", $raw, 2);
        $headers = [];
        foreach (preg_split('~\r\n(?![ \t])~', $head) as $line) {
            [$name, $value] = explode(': ', $line, 2);
            $headers[$name] = $value;
        }
        return $headers;
    }

    public function testRendersAPlainTextMessageWithCrlf(): void
    {
        $raw = (new Message('ana@example.test', 'Reset your password', "Hello Ana,\nopen the link.\n"))->render('app@example.test', 'Whitesmoke App');
        $headers = $this->headers($raw);

        $this->assertSame('Whitesmoke App <app@example.test>', $headers['From']);
        $this->assertSame('<ana@example.test>', $headers['To']);
        $this->assertSame('Reset your password', $headers['Subject']);
        $this->assertSame('text/plain; charset=UTF-8', $headers['Content-Type']);
        $this->assertSame('quoted-printable', $headers['Content-Transfer-Encoding']);
        $this->assertMatchesRegularExpression('~^<[0-9a-f]{32}@example\.test>$~', $headers['Message-ID']);
        $this->assertStringEndsWith("\r\n\r\nHello Ana,\r\nopen the link.\r\n", $raw);
        $this->assertDoesNotMatchRegularExpression('~(?<!\r)\n~', $raw, 'only CRLF line endings');
    }

    public function testEncodesUtf8AndLongLines(): void
    {
        $raw = (new Message('ana@example.test', 'Grüße, Ana', 'Grüße ' . str_repeat('x', 200)))->render('app@example.test', 'Whitesmoke, Inc.');
        $headers = $this->headers($raw);
        [, $body] = explode("\r\n\r\n", $raw, 2);

        $this->assertStringStartsWith('=?UTF-8?B?', $headers['Subject']);
        $this->assertSame('Grüße, Ana', mb_decode_mimeheader($headers['Subject']));
        $this->assertSame('"Whitesmoke, Inc." <app@example.test>', $headers['From'], 'a comma in the display name must be quoted');

        $long = (new Message('ana@example.test', str_repeat('Ünïcødé ', 20), 'x'))->render('app@example.test', 'Café "Zürich"');
        $longHeaders = $this->headers($long);
        $this->assertSame(str_repeat('Ünïcødé ', 20), mb_decode_mimeheader($longHeaders['Subject']));
        $this->assertSame('Café "Zürich" <app@example.test>', mb_decode_mimeheader($longHeaders['From']));
        foreach (explode("\r\n", explode("\r\n\r\n", $long, 2)[0]) as $line) {
            $this->assertLessThanOrEqual(78, strlen($line), 'header lines are folded');
        }

        $this->assertStringStartsWith('=?UTF-8?B?', $this->headers((new Message('ana@example.test', 'Fake =?UTF-8?B?x?= word', 'x'))->render('app@example.test', ''))['Subject'], 'text that looks encoded is encoded');
        $this->assertStringStartsWith('Gr=C3=BC=C3=9Fe', $body);
        foreach (explode("\r\n", $body) as $line) {
            $this->assertLessThanOrEqual(76, strlen($line));
        }
        $this->assertSame('Grüße ' . str_repeat('x', 200), quoted_printable_decode($body));
    }

    public function testHtmlIsSentWithAPlainTextAlternative(): void
    {
        $text = "Hello Ana,\nopen https://app.example.test/reset-password?token=abc\n" . str_repeat('long line ', 20);
        $html = "<!doctype html>\n<p>Hello <b>Ana</b>,</p>\n<p><a href=\"https://app.example.test/reset-password?token=abc\">Choose a new password</a></p>\n<p>Grüße</p>";

        $raw     = (new Message('ana@example.test', 'Reset', $text, $html))->render('app@example.test', 'App');
        $headers = $this->headers($raw);

        $this->assertSame('1.0', $headers['MIME-Version']);
        $this->assertArrayNotHasKey('Content-Transfer-Encoding', $headers);
        $this->assertMatchesRegularExpression('~^multipart/alternative; boundary="(ws-[0-9a-f]{32})"$~', $headers['Content-Type']);
        preg_match('~boundary="([^"]+)"~', $headers['Content-Type'], $m);
        $boundary = $m[1];

        [, $body] = explode("\r\n\r\n", $raw, 2);
        $this->assertStringEndsWith("\r\n--{$boundary}--\r\n", $body);

        $parts = array_slice(explode("--{$boundary}", $body), 1, 2);
        $this->assertCount(2, $parts);

        $decoded = [];
        foreach ($parts as $part) {
            [$partHead, $partBody] = explode("\r\n\r\n", ltrim($part, "\r\n"), 2);
            $this->assertStringContainsString('Content-Transfer-Encoding: quoted-printable', $partHead);
            foreach (explode("\r\n", $partBody) as $line) {
                $this->assertLessThanOrEqual(76, strlen($line));
            }
            preg_match('~Content-Type: (text/\w+); charset=UTF-8~', $partHead, $type);
            $decoded[$type[1]] = quoted_printable_decode(substr($partBody, 0, -2));
        }

        $this->assertSame(['text/plain', 'text/html'], array_keys($decoded), 'text first, so the HTML is preferred');
        $this->assertSame(str_replace("\n", "\r\n", $text), $decoded['text/plain']);
        $this->assertSame(str_replace("\n", "\r\n", $html), $decoded['text/html']);
        $this->assertSame(3, substr_count($raw, "--{$boundary}"), 'the boundary appears only as delimiters');
    }

    public function testInvalidHtmlBodiesAreRefused(): void
    {
        foreach (['', "  \n", "<p>a\0b</p>", "<p>\xC3\x28</p>"] as $html) {
            try {
                new Message('ana@example.test', 'Hi', 'text', $html);
                $this->fail('Should refuse ' . json_encode($html));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testHeaderInjectionAndBadAddressesAreRefused(): void
    {
        $bad = [
            ['ana@example.test', "Hi\r\nBcc: evil@example.test", 'x'],
            ['ana@example.test', "Hi\nBcc: evil@example.test", 'x'],
            ["ana@example.test\r\nBcc: evil@example.test", 'Hi', 'x'],
            ['ana@example.test>, <evil@example.test', 'Hi', 'x'],
            ['"ana"@example.test', 'Hi', 'x'],
            ['not-an-address', 'Hi', 'x'],
            ['ana@example.test', 'Hi', "\xC3\x28"],
        ];

        foreach ($bad as [$to, $subject, $text]) {
            try {
                new Message($to, $subject, $text);
                $this->fail('Should refuse ' . json_encode([$to, $subject]));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testLogDriverWritesTheMessage(): void
    {
        $dir = WS_TEST_TMP . '/mail-log';

        (new Mailer(['driver' => 'log', 'from_address' => 'app@example.test', 'log_path' => $dir]))
            ->send(new Message('ana@example.test', 'Reset your password', 'https://app.example.test/reset-password?token=abc'));

        $content = (string) file_get_contents($dir . '/mail-' . date('Y-m-d') . '.log');
        $this->assertStringContainsString('Subject: Reset your password', $content);
        $this->assertStringContainsString('To: <ana@example.test>', $content);
        $this->assertStringContainsString('token=3Dabc', $content, 'body is quoted-printable');
    }

    public function testMailerSettingsFailClosed(): void
    {
        $bad = [
            [],
            ['driver' => 'sendmail', 'from_address' => 'app@example.test'],
            ['driver' => 'log'],
            ['driver' => 'log', 'from_address' => 'not-an-address'],
            ['driver' => 'log', 'from_address' => 'app@example.test', 'from_name' => "App\r\nBcc: evil@example.test"],
        ];

        foreach ($bad as $config) {
            try {
                new Mailer($config);
                $this->fail('Should refuse ' . json_encode($config));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
