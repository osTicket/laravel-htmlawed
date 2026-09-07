<?php

declare(strict_types=1);

namespace osTicket\Htmlawed\Contracts;

use osTicket\Htmlawed\HtmlTag;

/**
 * Transform one structured tag after htmLawed has filtered its attributes.
 *
 * Returning null removes the tag. Returned tags are rendered and encoded by
 * the package, then passed through htmLawed again so hook mutations remain
 * subject to the operation's element, attribute, and scheme policy.
 */
interface HtmlTagHook
{
    /**
     * Return the accepted, transformed, or removed tag.
     */
    public function transform(HtmlTag $tag): ?HtmlTag;
}
