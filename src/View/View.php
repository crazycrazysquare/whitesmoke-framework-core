<?php
declare(strict_types=1);

namespace Whitesmoke\View;

final class View
{
    public function __construct(private readonly string $dir) {}

    public function render(string $view, array $data = [], ?string $layout = null): string
    {
        $html = $this->load($view, $data);

        return $layout === null
            ? $html
            : $this->load($layout, ['content' => $html] + $data);
    }

    private function load(string $view, array $data): string
    {
        if (!preg_match('~^[a-z0-9_]+(?:/[a-z0-9_]+)*$~', $view)) {
            throw new \InvalidArgumentException('Invalid view name');
        }

        $file = $this->dir . '/' . $view . '.php';

        ob_start();
        try {
            (static function () use ($file, $data): void {
                extract($data, EXTR_SKIP);
                require $file;
            })();
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }

        return (string) ob_get_clean();
    }
}
