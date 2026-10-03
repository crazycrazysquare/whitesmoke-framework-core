<?php
declare(strict_types=1);

/*
 * A TLS server with a self-signed certificate, for checking that clients refuse it:
 *   php tls-server.php <port> <certificate.pem> <log file>
 * Logs "handshake ok" or "handshake failed" per connection, and anything received.
 */

[, $port, $cert, $log] = $argv;

$context = stream_context_create(['ssl' => ['local_cert' => $cert]]);
$server  = stream_socket_server("tcp://127.0.0.1:{$port}", $errno, $error, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);

while ($conn = @stream_socket_accept($server, -1)) {
    $ok = @stream_socket_enable_crypto($conn, true, STREAM_CRYPTO_METHOD_TLS_SERVER);
    file_put_contents($log, $ok === true ? "handshake ok\n" : "handshake failed\n", FILE_APPEND);

    if ($ok === true) {
        stream_set_timeout($conn, 2);
        $data = (string) fread($conn, 8192);
        file_put_contents($log, "received: " . json_encode($data) . "\n", FILE_APPEND);
    }

    fclose($conn);
}
