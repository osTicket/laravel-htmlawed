<?php

declare(strict_types=1);

namespace osTicket\Htmlawed;

use InvalidArgumentException;
use LengthException;
use osTicket\Htmlawed\Contracts\HtmlSanitizer;
use osTicket\Htmlawed\Exceptions\HtmlSanitizationException;
use SensitiveParameter;
use Throwable;
use UnexpectedValueException;

/**
 * Execute htmLawed behind the package's typed sanitization contract.
 *
 * The adapter is stateless. Every call receives its complete policy so one
 * request, tenant, channel, or content surface cannot influence another.
 */
final class HtmlawedSanitizer implements HtmlSanitizer
{
    /**
     * Sanitize the supplied HTML without retaining operation state.
     */
    public function sanitize(
        #[SensitiveParameter]
        string $html,
        HtmlSanitizationPolicy $policy
    ): string {
        $this->validateInput($html, $policy);

        $configuration = $policy->configuration();

        if ($hook = $policy->tagHook()) {
            $configuration['hook_tag'] = static function (
                string $element,
                array|int $attributes = 0
            ) use ($hook): string {
                $tag = new HtmlTag(
                    $element,
                    self::normalizeAttributes($attributes)
                );

                $transformed = $hook->transform($tag);

                if ($transformed === null) {
                    return '';
                }

                self::validateTransformation($tag, $transformed);

                return $transformed->toHtml();
            };

            $html = $this->filter(
                $html,
                $configuration,
                $policy->specification()
            );

            $this->validateInput($html, $policy);

            unset($configuration['hook_tag']);
        }

        $html = $this->filter(
            $html,
            $configuration,
            $policy->specification()
        );

        $this->validateOutput($html, $policy);

        return $html;
    }

    /**
     * Reject malformed or oversized input before invoking the parser.
     *
     * Limits are expressed in bytes because they guard memory and parser
     * work, while UTF-8 validation keeps malformed byte sequences from
     * crossing a boundary htmLawed does not validate itself.
     */
    private function validateInput(
        #[SensitiveParameter]
        string $html,
        HtmlSanitizationPolicy $policy
    ): void {
        if (strlen($html) > $policy->limits()->maxInputBytes()) {
            throw new LengthException(
                'HTML input exceeds the configured sanitization byte limit.'
            );
        }

        if (! mb_check_encoding($html, 'UTF-8')) {
            throw new InvalidArgumentException(
                'HTML input must be valid UTF-8.'
            );
        }

        $this->validateMarkupTokens(
            $html,
            $policy->limits()->maxTagBytes()
        );
    }

    /**
     * Reject sanitized fragments which exceed their storage boundary.
     */
    private function validateOutput(
        #[SensitiveParameter]
        string $html,
        HtmlSanitizationPolicy $policy
    ): void {
        if (strlen($html) > $policy->limits()->maxOutputBytes()) {
            throw new LengthException(
                'HTML output exceeds the configured sanitization byte limit.'
            );
        }

        if (! mb_check_encoding($html, 'UTF-8')) {
            throw HtmlSanitizationException::failed();
        }
    }

    /**
     * Reject abnormally large markup tokens using a linear byte scan.
     *
     * Attribute-heavy tags can drive super-linear work inside htmLawed while
     * remaining below the total input limit. This scanner does not interpret
     * HTML policy. It only tracks plausible tag boundaries and quoted values
     * so oversized tags, comments, and declarations fail before parsing.
     */
    private function validateMarkupTokens(
        #[SensitiveParameter]
        string $html,
        int $maxTagBytes
    ): void {
        $length = strlen($html);
        $start = null;
        $quote = null;

        for ($index = 0; $index < $length; $index++) {
            $character = $html[$index];

            if ($start === null) {
                if ($character !== '<'
                    || ! $this->startsMarkupToken($html, $index, $length)) {
                    continue;
                }

                $start = $index;

                continue;
            }

            if (($index - $start + 1) > $maxTagBytes) {
                throw new LengthException(
                    'HTML markup exceeds the configured tag byte limit.'
                );
            }

            if ($quote !== null) {
                if ($character === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($character === '"' || $character === "'") {
                $quote = $character;

                continue;
            }

            if ($character === '>') {
                $start = null;
            }
        }

        if ($start !== null && ($length - $start) > $maxTagBytes) {
            throw new LengthException(
                'HTML markup exceeds the configured tag byte limit.'
            );
        }
    }

    /**
     * Determine whether a less-than sign plausibly begins markup.
     */
    private function startsMarkupToken(
        #[SensitiveParameter]
        string $html,
        int $index,
        int $length
    ): bool {
        if (($index + 1) >= $length) {
            return false;
        }

        $next = $html[$index + 1];

        return $next === '!'
            || $next === '?'
            || $next === '/'
            || ($next >= 'A' && $next <= 'Z')
            || ($next >= 'a' && $next <= 'z');
    }

    /**
     * Execute one isolated htmLawed pass and restore all parser globals.
     *
     * htmLawed uses process globals for its active configuration,
     * specification, and document ID registry. Capturing and clearing all
     * three values makes sequential and nested calls deterministic without
     * destroying an outer invocation's state.
     *
     * @param  array<string,mixed>  $configuration
     * @param  array<string,mixed>|string  $specification
     */
    private function filter(
        #[SensitiveParameter]
        string $html,
        array $configuration,
        array|string $specification
    ): string {
        $globals = [];

        foreach (['C', 'S', 'hl_Ids'] as $name) {
            $globals[$name] = [
                'exists' => array_key_exists($name, $GLOBALS),
                'value' => $GLOBALS[$name] ?? null,
            ];

            unset($GLOBALS[$name]);
        }

        try {
            return \htmLawed($html, $configuration, $specification);
        } catch (Throwable) {
            throw HtmlSanitizationException::failed();
        } finally {
            foreach ($globals as $name => $state) {
                if ($state['exists']) {
                    $GLOBALS[$name] = $state['value'];
                } else {
                    unset($GLOBALS[$name]);
                }
            }
        }
    }

    /**
     * Hide htmLawed's mixed closing-tag sentinel from application hooks.
     *
     * @param  array<mixed,mixed>|int  $attributes
     * @return array<string,string>|null
     */
    private static function normalizeAttributes(array|int $attributes): ?array
    {
        if (! is_array($attributes)) {
            return null;
        }

        $normalized = [];

        foreach ($attributes as $name => $value) {
            if (! is_string($name) || ! is_string($value)) {
                throw new UnexpectedValueException(
                    'htmLawed returned an invalid tag attribute map.'
                );
            }

            $normalized[$name] = $value;
        }

        return $normalized;
    }

    /**
     * Keep hooks from replacing a filtered tag with unrelated markup.
     *
     * Hooks may remove a tag or adjust its attributes. Changing the element
     * or converting between opening and closing tags would escape the policy
     * htmLawed applied before invoking the hook.
     */
    private static function validateTransformation(
        HtmlTag $original,
        HtmlTag $transformed
    ): void {
        if ($transformed->element() !== $original->element()
            || $transformed->isClosing() !== $original->isClosing()) {
            throw new UnexpectedValueException(
                'HTML tag hooks may not replace the filtered tag type.'
            );
        }
    }
}
