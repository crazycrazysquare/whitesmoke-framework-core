<?php
declare(strict_types=1);

namespace Whitesmoke\Console;

final class Output
{
    private bool $color;

    /** @var resource */
    private $out;

    /** @var resource */
    private $err;

    /**
     * @param resource|null $out defaults to STDOUT
     * @param resource|null $err defaults to STDERR
     */
    public function __construct($out = null, $err = null)
    {
        $this->out = $out ?? STDOUT;
        $this->err = $err ?? STDERR;

        $this->color = getenv('NO_COLOR') === false
            && \function_exists('stream_isatty')
            && @stream_isatty($this->out);

        if ($this->color && PHP_OS_FAMILY === 'Windows' && \function_exists('sapi_windows_vt100_support')) {
            $this->color = @sapi_windows_vt100_support($this->out, true);
        }
    }

    public function line(string $text = ''): void
    {
        @fwrite($this->out, $text . PHP_EOL);
    }

    public function info(string $text): void
    {
        $this->line($this->paint($text, '32'));
    }

    public function comment(string $text): void
    {
        $this->line($this->paint($text, '33'));
    }

    public function error(string $text): void
    {
        @fwrite($this->err, $this->paint($text, '31') . PHP_EOL);
    }

    public function table(array $headers, array $rows): void
    {
        $widths = array_map('mb_strlen', $headers);

        foreach ($rows as $row) {
            foreach (array_values($row) as $i => $cell) {
                $widths[$i] = max($widths[$i] ?? 0, mb_strlen((string) $cell));
            }
        }

        $format = fn (array $cells): string => implode('  ', array_map(
            fn ($cell, int $i): string => str_pad((string) $cell, $widths[$i] + strlen((string) $cell) - mb_strlen((string) $cell)),
            array_values($cells),
            array_keys(array_values($cells))
        ));

        $this->comment(rtrim($format($headers)));
        foreach ($rows as $row) {
            $this->line(rtrim($format($row)));
        }
    }

    public function paint(string $text, string $code): string
    {
        return $this->color ? "\033[{$code}m{$text}\033[0m" : $text;
    }
}
