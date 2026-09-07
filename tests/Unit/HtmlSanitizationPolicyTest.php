<?php

declare(strict_types=1);

namespace osTicket\Htmlawed\Tests\Unit;

use InvalidArgumentException;
use osTicket\Htmlawed\HtmlSanitizationLimits;
use osTicket\Htmlawed\HtmlSanitizationPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Prove sanitizer policies preserve valid htmLawed configuration safely.
 */
final class HtmlSanitizationPolicyTest extends TestCase
{
    /**
     * Preserve configuration and specification as distinct values.
     */
    public function test_policy_keeps_configuration_and_specification_separate(): void
    {
        $policy = new HtmlSanitizationPolicy(
            configuration: [
                'safe' => 1,
                'elements' => 'p,a',
            ],
            limits: new HtmlSanitizationLimits(65_536, 131_072, 16_384),
            specification: 'a=href,title'
        );

        $this->assertSame([
            'safe' => 1,
            'elements' => 'p,a',
        ], $policy->configuration());
        $this->assertSame(65_536, $policy->limits()->maxInputBytes());
        $this->assertSame(131_072, $policy->limits()->maxOutputBytes());
        $this->assertSame(16_384, $policy->limits()->maxTagBytes());
        $this->assertSame('a=href,title', $policy->specification());
        $this->assertNull($policy->tagHook());
    }

    /**
     * Reject malformed configuration maps before invoking htmLawed.
     *
     * @param  array<mixed>  $configuration
     */
    #[DataProvider('invalidConfigurations')]
    public function test_policy_rejects_invalid_configuration(
        array $configuration
    ): void {
        $this->expectException(InvalidArgumentException::class);

        new HtmlSanitizationPolicy(
            $configuration,
            new HtmlSanitizationLimits(65_536, 131_072, 16_384)
        );
    }

    /**
     * Return malformed and reserved configuration entries.
     *
     * @return iterable<string,array{array<mixed>}>
     */
    public static function invalidConfigurations(): iterable
    {
        yield 'numeric key' => [['safe']];
        yield 'blank key' => [[' ' => true]];
        yield 'embedded specification' => [['spec' => 'a=href']];
        yield 'raw pre-filter hook' => [['hook' => 'trim']];
        yield 'untyped tag hook' => [['hook_tag' => 'trim']];
        yield 'global settings export' => [['show_setting' => 'settings']];
    }

    /**
     * Reject a blank compact specification.
     */
    public function test_policy_rejects_blank_specification(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new HtmlSanitizationPolicy(
            configuration: ['safe' => 1],
            limits: new HtmlSanitizationLimits(65_536, 131_072, 16_384),
            specification: '   '
        );
    }

    /**
     * Reject policies which rely on htmLawed's permissive defaults.
     */
    public function test_policy_rejects_empty_configuration(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new HtmlSanitizationPolicy(
            [],
            new HtmlSanitizationLimits(65_536, 131_072, 16_384)
        );
    }
}
