<?php

declare(strict_types=1);

namespace ZimaBackup\Controller;

use ZimaBackup\Core\Application;
use ZimaBackup\Security\Csrf;

abstract class AbstractController
{
    public function __construct(protected readonly Application $app)
    {
    }

    protected function render(string $template, array $context = []): string
    {
        /** @var Csrf $csrf */
        $csrf = $this->app->service(Csrf::class);
        $context['csrf_token'] ??= $csrf->token();

        return $this->app->twig()->render($template, $context);
    }

    protected function redirect(string $routeName, array $params = []): string
    {
        header('Location: ' . $this->app->router()->generate($routeName, $params), true, 303);
        return '';
    }
}
