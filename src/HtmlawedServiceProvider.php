<?php

declare(strict_types=1);

namespace osTicket\Htmlawed;

use Illuminate\Support\ServiceProvider;
use osTicket\Htmlawed\Contracts\HtmlSanitizer;

/**
 * Register the htmLawed adapter with Laravel's service container.
 *
 * The resolved singleton is safe because the adapter carries no mutable or
 * request-specific state. Policies and source HTML remain local to each call.
 */
final class HtmlawedServiceProvider extends ServiceProvider
{
    /**
     * Register the package contract and implementation.
     */
    public function register(): void
    {
        $this->app->singleton(
            HtmlSanitizer::class,
            HtmlawedSanitizer::class
        );
    }
}
