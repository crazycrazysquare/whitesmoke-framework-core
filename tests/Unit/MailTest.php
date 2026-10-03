<?php
declare(strict_types=1);

namespace Whitesmoke\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Whitesmoke\Mail\Attachment;
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

    /** Split a multipart body into [headers, content] pairs. */
    private function parts(string $body, string $boundary): array
    {
        $this->assertStringEndsWith("--{$boundary}--\r\n", $body);
        $chunks = array_slice(explode("--{$boundary}", $body), 1, -1);

        return array_map(function (string $chunk): array {
            [$head, $content] = explode("\r\n\r\n", ltrim($chunk, "\r\n"), 2);
            return [$head, substr($content, 0, -2)];
        }, $chunks);
    }

    public function testSeveralRecipientsCcAndHiddenBcc(): void
    {
        $message = new Message(['ana@example.test', 'ben@example.test'], 'Team', 'Hi all', cc: ['cleo@example.test', 'ANA@example.test'], bcc: ['boss@example.test']);
        $raw     = $message->render('app@example.test', 'App');
        $headers = $this->headers($raw);

        $this->assertSame("<ana@example.test>,\r\n <ben@example.test>", $headers['To']);
        $this->assertSame("<cleo@example.test>,\r\n <ANA@example.test>", $headers['Cc']);
        $this->assertArrayNotHasKey('Bcc', $headers);
        $this->assertStringNotContainsString('boss@example.test', $raw, 'Bcc never appears in the message');
        $this->assertSame(['ana@example.test', 'ben@example.test', 'cleo@example.test', 'boss@example.test'], $message->recipients(), 'each address once, Bcc included');
        $this->assertArrayNotHasKey('Cc', $this->headers((new Message('ana@example.test', 'x', 'y'))->render('app@example.test', '')));
    }

    public function testAttachmentsFollowTheBody(): void
    {
        $binary = random_bytes(5000);
        $csv    = "name;total\nAna;3\n";
        $raw    = (new Message('ana@example.test', 'Report', 'See attached.', '<p>See attached.</p>', attachments: [
            Attachment::fromData($binary, "Résumé \"final\"\r\nX-Evil: 1.pdf", 'application/pdf'),
            Attachment::fromData($csv, 'totals.csv', 'text/csv'),
        ]))->render('app@example.test', '');

        $headers = $this->headers($raw);
        $this->assertMatchesRegularExpression('~^multipart/mixed; boundary="(ws-[0-9a-f]{32})"$~', $headers['Content-Type']);
        preg_match('~boundary="([^"]+)"~', $headers['Content-Type'], $m);
        [, $body] = explode("\r\n\r\n", $raw, 2);
        $parts = $this->parts($body, $m[1]);

        $this->assertCount(3, $parts);
        $this->assertStringStartsWith('Content-Type: multipart/alternative;', $parts[0][0], 'text and HTML first');

        [$pdfHead, $pdfBody] = $parts[1];
        $this->assertSame($binary, base64_decode($pdfBody, true));
        $this->assertStringContainsString('Content-Type: application/pdf; name="R_sum_ _final_X-Evil_ 1.pdf"', $pdfHead);
        $this->assertStringContainsString("filename*=UTF-8''" . rawurlencode('Résumé "final"X-Evil: 1.pdf'), $pdfHead);
        $this->assertDoesNotMatchRegularExpression('~^X-Evil:~m', $raw, 'no header injection through the file name');
        foreach (explode("\r\n", $pdfBody) as $line) {
            $this->assertLessThanOrEqual(76, strlen($line));
        }

        $this->assertSame($csv, base64_decode($parts[2][1], true));
        $this->assertStringContainsString('Content-Disposition: attachment; filename="totals.csv"', $parts[2][0]);
    }

    public function testTextOnlyMessageWithAnAttachment(): void
    {
        $file = WS_TEST_TMP . '/invoice.pdf';
        file_put_contents($file, "%PDF-1.4\n%%EOF\n");

        $raw = (new Message('ana@example.test', 'Invoice', 'Your invoice.', attachments: [Attachment::fromPath($file)]))->render('app@example.test', '');
        preg_match('~boundary="([^"]+)"~', $this->headers($raw)['Content-Type'], $m);
        $parts = $this->parts(explode("\r\n\r\n", $raw, 2)[1], $m[1]);

        $this->assertStringContainsString('Content-Type: text/plain; charset=UTF-8', $parts[0][0]);
        $this->assertSame('Your invoice.', quoted_printable_decode($parts[0][1]));
        $this->assertStringContainsString('Content-Type: application/pdf; name="invoice.pdf"', $parts[1][0], 'type detected from the contents');
    }

    public function testRecipientAndAttachmentLimits(): void
    {
        $bad = [
            fn () => new Message([], 'x', 'y'),
            fn () => new Message(['ana@example.test', 'not-an-address'], 'x', 'y'),
            fn () => new Message('ana@example.test', 'x', 'y', cc: ["evil@example.test\r\nBcc: x@example.test"]),
            fn () => new Message('ana@example.test', 'x', 'y', bcc: [42]),
            fn () => new Message('ana@example.test', 'x', 'y', bcc: array_map(fn ($i) => "u{$i}@example.test", range(1, 100))),
            fn () => new Message('ana@example.test', 'x', 'y', attachments: ['file.pdf']),
            fn () => new Message('ana@example.test', 'x', 'y', attachments: [Attachment::fromData(str_repeat('a', 20 * 1024 * 1024 + 1), 'big.bin')]),
            fn () => Attachment::fromPath(WS_TEST_TMP . '/missing.pdf'),
            fn () => Attachment::fromData('x', '', 'text/plain'),
            fn () => Attachment::fromData('x', 'a.txt', 'text/plain; charset=x'),
        ];

        foreach ($bad as $i => $call) {
            try {
                $call();
                $this->fail("Case {$i} must be refused");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertCount(100, (new Message('ana@example.test', 'x', 'y', bcc: array_map(fn ($i) => "u{$i}@example.test", range(1, 99))))->recipients(), '100 is allowed');
    }

    public function testLogDriverListsEveryRecipient(): void
    {
        $dir = WS_TEST_TMP . '/mail-log-recipients';

        (new Mailer(['driver' => 'log', 'from_address' => 'app@example.test', 'log_path' => $dir]))
            ->send(new Message('ana@example.test', 'x', 'y', cc: ['cleo@example.test'], bcc: ['boss@example.test']));

        $this->assertStringContainsString('Envelope recipients: ana@example.test, cleo@example.test, boss@example.test', (string) file_get_contents($dir . '/mail-' . date('Y-m-d') . '.log'));
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
