<?php
declare(strict_types=1);

namespace Whitesmoke\Mail;

use InvalidArgumentException;

/**
 * Sends Messages with the driver from config/mail.php:
 * "smtp" sends through an SMTP server; "log" writes each message to
 * storage/logs/mail-YYYY-MM-DD.log instead (development only).
 */
final class Mailer
{
    private string $driver;
    private string $from;
    private string $fromName;

    public function __construct(private readonly array $config)
    {
        $this->driver   = (string) ($config['driver'] ?? '');
        $this->from     = (string) ($config['from_address'] ?? '');
        $this->fromName = (string) ($config['from_name'] ?? '');

        if (!in_array($this->driver, ['smtp', 'log'], true)) {
            throw new InvalidArgumentException('Mail driver must be smtp or log (MAIL_DRIVER)');
        }
        if (!Message::isAddress($this->from)) {
            throw new InvalidArgumentException('Mail from_address must be a valid email address (MAIL_FROM_ADDRESS)');
        }
        if (preg_match('~[\r\n\0]~', $this->fromName)) {
            throw new InvalidArgumentException('Invalid mail from_name');
        }
    }

    public function send(Message $message): void
    {
        $data = $message->render($this->from, $this->fromName);

        if ($this->driver === 'log') {
            $this->log($data);
            return;
        }

        (new SmtpTransport(
            (string) ($this->config['host'] ?? ''),
            (int) ($this->config['port'] ?? 0),
            (string) ($this->config['encryption'] ?? ''),
            (string) ($this->config['username'] ?? ''),
            (string) ($this->config['password'] ?? ''),
            (int) ($this->config['timeout'] ?? 10),
            (string) ($this->config['ehlo'] ?? 'localhost'),
        ))->send($this->from, $message->to, $data);
    }

    private function log(string $data): void
    {
        $dir = (string) ($this->config['log_path'] ?? BASE_PATH . '/storage/logs');

        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }

        $file = $dir . '/mail-' . date('Y-m-d') . '.log';
        $new  = !is_file($file);

        file_put_contents($file, "===== " . date('Y-m-d H:i:s') . " =====\r\n" . $data . "\r\n\r\n", FILE_APPEND | LOCK_EX);

        if ($new) {
            @chmod($file, 0640);
        }
    }
}
