<?php

declare(strict_types=1);

namespace Studbook;

/**
 * Renders PHP templates from `templates/`. Templates escape output with `e()`;
 * a page template is wrapped in `layout.php` unless rendered with `$layout = null`.
 */
final class View
{
    /** @param array<string, mixed> $shared variables available to every template */
    public function __construct(private readonly string $directory, private array $shared = [])
    {
    }

    public function share(string $name, mixed $value): void
    {
        $this->shared[$name] = $value;
    }

    /** @param array<string, mixed> $data */
    public function render(string $template, array $data = [], ?string $layout = 'layout'): string
    {
        $content = $this->renderFile($template, $data);
        if ($layout === null) {
            return $content;
        }

        return $this->renderFile($layout, $data + ['content' => $content]);
    }

    /** @param array<string, mixed> $data */
    private function renderFile(string $template, array $data): string
    {
        $file = $this->directory . '/' . $template . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException(sprintf('Template %s not found.', $template));
        }
        $vars = $data + $this->shared;
        $render = static function (string $__file, array $__vars): void {
            extract($__vars, EXTR_SKIP);
            require $__file;
        };
        ob_start();
        try {
            $render($file, $vars);
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }

        return (string) ob_get_clean();
    }
}
