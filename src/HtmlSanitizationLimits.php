<?php

declare(strict_types=1);

namespace osTicket\Htmlawed;

use InvalidArgumentException;

/**
 * Bound parser work and output growth for one content surface.
 *
 * Input, output, and individual markup-token limits address different
 * resource risks. Callers choose all three values explicitly because email,
 * thread messages, CMS documents, and other surfaces have different storage
 * and processing budgets.
 */
final readonly class HtmlSanitizationLimits
{
    /**
     * Create explicit resource limits for one sanitization policy.
     */
    public function __construct(
        private int $maxInputBytes,
        private int $maxOutputBytes,
        private int $maxTagBytes
    ) {
        if ($maxInputBytes < 1
            || $maxOutputBytes < 1
            || $maxTagBytes < 1) {
            throw new InvalidArgumentException(
                'HTML sanitization limits must be positive byte counts.'
            );
        }
    }

    /**
     * Return the maximum input accepted by each parser pass.
     */
    public function maxInputBytes(): int
    {
        return $this->maxInputBytes;
    }

    /**
     * Return the maximum sanitized body fragment returned to the caller.
     */
    public function maxOutputBytes(): int
    {
        return $this->maxOutputBytes;
    }

    /**
     * Return the maximum bytes accepted in one markup token.
     */
    public function maxTagBytes(): int
    {
        return $this->maxTagBytes;
    }
}
