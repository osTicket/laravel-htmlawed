<?php

declare(strict_types=1);

namespace osTicket\Htmlawed\Tests\Unit;

use InvalidArgumentException;
use osTicket\Htmlawed\HtmlSanitizationLimits;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Prove sanitizer resource limits are explicit and internally valid.
 */
final class HtmlSanitizationLimitsTest extends TestCase
{
    /**
     * Preserve the independent parser and output boundaries.
     */
    public function test_limits_expose_their_configured_byte_counts(): void
    {
        $limits = new HtmlSanitizationLimits(
            maxInputBytes: 262_144,
            maxOutputBytes: 524_288,
            maxTagBytes: 16_384
        );

        $this->assertSame(262_144, $limits->maxInputBytes());
        $this->assertSame(524_288, $limits->maxOutputBytes());
        $this->assertSame(16_384, $limits->maxTagBytes());
    }

    /**
     * Reject any resource boundary which cannot accept content.
     */
    #[DataProvider('invalidLimits')]
    public function test_limits_must_be_positive(
        int $maxInputBytes,
        int $maxOutputBytes,
        int $maxTagBytes
    ): void {
        $this->expectException(InvalidArgumentException::class);

        new HtmlSanitizationLimits(
            $maxInputBytes,
            $maxOutputBytes,
            $maxTagBytes
        );
    }

    /**
     * Return invalid resource-limit combinations.
     *
     * @return iterable<string,array{int,int,int}>
     */
    public static function invalidLimits(): iterable
    {
        yield 'zero input' => [0, 1, 1];
        yield 'negative output' => [1, -1, 1];
        yield 'zero tag' => [1, 1, 0];
    }
}
