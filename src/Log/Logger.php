<?php
declare(strict_types=1);

namespace Whitesmoke\Log;

use InvalidArgumentException;
use Throwable;

final class Logger
{
    private const LEVELS = ['debug' => 0, 'info' => 1, 'warning' => 2, 'error' => 3];
    private const REDACT = ['password', 'password_confirmation', 'token', '_token', 'secret', 'api_key', 'authorization'];

    private int $min;

    /**
     * @param string $path  directory for daily log files
     * @param string $level lowest level written: debug, info, warning, error
     * @param int    $days  daily files kept before deletion (0 = keep forever)
     */
    public function __construct(
        private readonly string $path,
        string $level = 'info',
        private readonly int $days = 14,
    ) {
        $this->min = self::LEVELS[$level] ?? throw new InvalidArgumentException("Unknown log level: {$level}");
    }

    public function debug(string $message, array $context = []): void
    {
        $this->write('debug', $message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->write('info', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->write('warning', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->write('error', $message, $context);
    }

    private function write(string $level, string $message, array $context): void
    {
        if (self::LEVELS[$level] < $this->min) {
            return;
        }

        $line = sprintf(
            "[%s] %s: %s%s\n",
            date('Y-m-d H:i:s'),
            strtoupper($level),
            str_replace(["\r", "\n"], ' ', $message),
            $context === [] ? '' : ' ' . json_encode($this->redact($context), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR)
        );

        try {
            if (!is_dir($this->path)) {
                mkdir($this->path, 0750, true);
            }

            $file = $this->path . '/whitesmoke-' . date('Y-m-d') . '.log';
            $new  = !is_file($file);

            file_put_contents($file, $line, FILE_APPEND | LOCK_EX);

            if ($new) {
                @chmod($file, 0640);
                $this->prune();
            }
        } catch (Throwable $e) {
            error_log('Whitesmoke logger failed: ' . $e->getMessage() . ' | ' . $line);
        }
    }

    private function redact(array $context): array
    {
        foreach ($context as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::REDACT, true)) {
                $context[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $context[$key] = $this->redact($value);
            } elseif ($value instanceof Throwable) {
                $context[$key] = get_class($value) . ': ' . $value->getMessage()
                    . ' at ' . $value->getFile() . ':' . $value->getLine() . "\n" . $value->getTraceAsString();
            }
        }

        return $context;
    }

    private function prune(): void
    {
        if ($this->days < 1) {
            return;
        }

        $cutoff = date('Y-m-d', strtotime("-{$this->days} days"));

        foreach (glob($this->path . '/whitesmoke-*.log') ?: [] as $file) {
            if (preg_match('~whitesmoke-(\d{4}-\d{2}-\d{2})\.log$~', $file, $m) && $m[1] < $cutoff) {
                @unlink($file);
            }
        }
    }
}
