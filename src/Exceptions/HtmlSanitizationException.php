<?php

declare(strict_types=1);

namespace osTicket\Htmlawed\Exceptions;

use RuntimeException;

/**
 * Report a sanitizer failure without retaining untrusted source content.
 *
 * htmLawed does not mark its source argument as sensitive. Any exception
 * escaping its stack can therefore retain the original HTML in trace
 * arguments when PHP is configured to collect them. This exception replaces
 * that throwable without chaining it across the package boundary.
 */
final class HtmlSanitizationException extends RuntimeException
{
    /**
     * Build the stable failure exposed to consuming applications.
     */
    public static function failed(): self
    {
        return new self('HTML sanitization failed.');
    }
}
