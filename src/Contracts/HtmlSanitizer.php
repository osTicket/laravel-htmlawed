<?php

declare(strict_types=1);

namespace osTicket\Htmlawed\Contracts;

use osTicket\Htmlawed\HtmlSanitizationPolicy;
use SensitiveParameter;

/**
 * Sanitize untrusted HTML according to one explicit policy.
 *
 * Implementations must not retain source HTML, policies, callbacks, or
 * derived state between calls. Applications remain responsible for choosing
 * the policy and resource limits appropriate to each content boundary.
 * Returned values are HTML body fragments and are not safe for other output
 * contexts.
 */
interface HtmlSanitizer
{
    /**
     * Return HTML accepted by the supplied sanitization policy.
     */
    public function sanitize(
        #[SensitiveParameter]
        string $html,
        HtmlSanitizationPolicy $policy
    ): string;
}
