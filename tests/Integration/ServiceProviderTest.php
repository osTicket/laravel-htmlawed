<?php

declare(strict_types=1);

namespace osTicket\Htmlawed\Tests\Integration;

use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase;
use osTicket\Htmlawed\Contracts\HtmlSanitizer;
use osTicket\Htmlawed\HtmlawedSanitizer;
use osTicket\Htmlawed\HtmlawedServiceProvider;

/**
 * Prove Laravel resolves the package through its public contract.
 */
final class ServiceProviderTest extends TestCase
{
    /**
     * Return package providers loaded by the Testbench application.
     *
     * @param  Application  $app
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [HtmlawedServiceProvider::class];
    }

    /**
     * Resolve one stateless sanitizer singleton through the contract.
     */
    public function test_container_resolves_the_sanitizer_contract(): void
    {
        $application = $this->app;

        if ($application === null) {
            self::fail('The Testbench application was not booted.');
        }

        $first = $application->make(HtmlSanitizer::class);
        $second = $application->make(HtmlSanitizer::class);

        $this->assertInstanceOf(HtmlawedSanitizer::class, $first);
        $this->assertSame($first, $second);
    }
}
