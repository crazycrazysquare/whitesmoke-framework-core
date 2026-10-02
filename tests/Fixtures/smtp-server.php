<?php
declare(strict_types=1);

/*
 * Fake SMTP server for the mail tests:
 *   php smtp-server.php <port> <transcript file> <mode> [certificate.pem]
 *
 * Modes: "plain" (AUTH PLAIN and LOGIN), "login" (AUTH LOGIN only),
 * "tls" (offers STARTTLS with the given self-signed certificate), "mute" (never answers).
 * Every line received is appended to the transcript. The login "good" / "secret"
 * is accepted; recipients containing "reject" are refused with 550.
 */

[, $port, $log, $mode] = $argv;
$cert = $argv[4] ?? null;

$server = stream_socket_server("tcp://127.0.0.1:{$port}", $errno, $error);
if ($server === false) {
    fwrite(STDERR, "cannot listen: {$error}\n");
    exit(1);
}

$record = static fn (string $line) => file_put_contents($log, $line . "\n", FILE_APPEND);

while ($conn = @stream_socket_accept($server, -1)) {
    if ($mode === 'mute') {
        sleep(30);
        fclose($conn);
        continue;
    }

    $say  = static fn (string $line) => fwrite($conn, $line . "\r\n");
    $read = static function () use ($conn, $record): ?string {
        $line = fgets($conn);
        if ($line === false) {
            return null;
        }
        $line = rtrim($line, "\r\n");
        $record($line);
        return $line;
    };
    $login = static fn (string $user, string $pass): string => $user === 'good' && $pass === 'secret'
        ? '235 Authenticated' : '535 Authentication failed';

    $tls = false;
    $say('220 fake ESMTP ready');

    while (($line = $read()) !== null) {
        $verb = strtoupper((string) strtok($line, ' '));

        switch ($verb) {
            case 'EHLO':
                $say('250-fake.test');
                if ($mode === 'tls' && !$tls) {
                    $say('250-STARTTLS');
                }
                $say('250-AUTH ' . ($mode === 'login' ? 'LOGIN' : 'PLAIN LOGIN'));
                $say('250 8BITMIME');
                break;

            case 'STARTTLS':
                if ($mode !== 'tls') {
                    $say('454 TLS not available');
                    break;
                }
                $say('220 Go ahead');
                stream_context_set_option($conn, 'ssl', 'local_cert', $cert);
                if (@stream_socket_enable_crypto($conn, true, STREAM_CRYPTO_METHOD_TLS_SERVER) !== true) {
                    $record('[TLS handshake failed]');
                    break 2;
                }
                $tls = true;
                break;

            case 'AUTH':
                $parts = explode(' ', $line);
                if (strtoupper($parts[1] ?? '') === 'PLAIN' && $mode !== 'login') {
                    $creds = explode("\0", (string) base64_decode($parts[2] ?? ''));
                    $say($login($creds[1] ?? '', $creds[2] ?? ''));
                } elseif (strtoupper($parts[1] ?? '') === 'LOGIN') {
                    $say('334 VXNlcm5hbWU6');
                    $user = (string) base64_decode((string) $read());
                    $say('334 UGFzc3dvcmQ6');
                    $say($login($user, (string) base64_decode((string) $read())));
                } else {
                    $say('504 Unrecognized authentication type');
                }
                break;

            case 'MAIL':
                $say('250 OK');
                break;

            case 'RCPT':
                $say(str_contains($line, 'reject') ? '550 No such user here' : '250 OK');
                break;

            case 'DATA':
                $say('354 End data with <CR><LF>.<CR><LF>');
                while (($data = $read()) !== null && $data !== '.') {
                }
                $say('250 Queued');
                break;

            case 'QUIT':
                $say('221 Bye');
                break 2;

            default:
                $say('500 Unknown command');
        }
    }

    fclose($conn);
}
