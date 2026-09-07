<?php

declare(strict_types=1);

namespace osTicket\Htmlawed;

use InvalidArgumentException;
use osTicket\Htmlawed\Contracts\HtmlTagHook;

/**
 * Immutable input policy for one htmLawed sanitization operation.
 *
 * htmLawed exposes a broad, evolving configuration language. This value
 * object preserves that language without moving application policy into the
 * package, while validating the structural boundary before execution.
 */
final readonly class HtmlSanitizationPolicy
{
    /**
     * Resource limits applied to this operation.
     */
    private HtmlSanitizationLimits $limits;

    /**
     * Options passed to htmLawed for this operation.
     *
     * @var array<string,mixed>
     */
    private array $configuration;

    /**
     * Attribute rules passed to htmLawed for this operation.
     *
     * @var array<string,mixed>|string
     */
    private array|string $specification;

    /**
     * Optional application-owned typed tag hook.
     */
    private ?HtmlTagHook $tagHook;

    /**
     * Create one explicit sanitization policy.
     *
     * Configuration keys map to htmLawed options. The specification may use
     * either its compact string language or its structured array form.
     * Hooks are operation-local values and are never retained by the
     * sanitizer. They use a dedicated contract so applications never depend
     * on htmLawed's historical closing-tag sentinel.
     *
     * @param  array<mixed,mixed>  $configuration
     * @param  array<mixed,mixed>|string  $specification
     */
    public function __construct(
        array $configuration,
        HtmlSanitizationLimits $limits,
        array|string $specification = [],
        ?HtmlTagHook $tagHook = null
    ) {
        if ($configuration === []) {
            throw new InvalidArgumentException(
                'HTML sanitization configuration must not be empty.'
            );
        }

        $validatedConfiguration = [];

        foreach ($configuration as $key => $value) {
            if (! is_string($key) || trim($key) === '') {
                throw new InvalidArgumentException(
                    'HTML sanitization configuration keys must be non-empty strings.'
                );
            }

            $validatedConfiguration[$key] = $value;
        }

        foreach (['hook', 'hook_tag', 'show_setting', 'spec'] as $reserved) {
            if (array_key_exists($reserved, $configuration)) {
                throw new InvalidArgumentException(sprintf(
                    'HTML sanitization option [%s] has a dedicated policy argument.',
                    $reserved
                ));
            }
        }

        if (is_array($specification)) {
            $validatedSpecification = [];

            foreach ($specification as $key => $value) {
                if (! is_string($key) || trim($key) === '') {
                    throw new InvalidArgumentException(
                        'HTML sanitization specification keys must be non-empty strings.'
                    );
                }

                $validatedSpecification[$key] = $value;
            }

            $specification = $validatedSpecification;
        } elseif (trim($specification) === '') {
            throw new InvalidArgumentException(
                'HTML sanitization specification strings must not be blank.'
            );
        }

        $this->limits = $limits;
        $this->configuration = $validatedConfiguration;
        $this->specification = $specification;
        $this->tagHook = $tagHook;
    }

    /**
     * Return the resource limits applied to this operation.
     */
    public function limits(): HtmlSanitizationLimits
    {
        return $this->limits;
    }

    /**
     * Return the underlying htmLawed configuration.
     *
     * @return array<string,mixed>
     */
    public function configuration(): array
    {
        return $this->configuration;
    }

    /**
     * Return the optional htmLawed element specification.
     *
     * @return array<string,mixed>|string
     */
    public function specification(): array|string
    {
        return $this->specification;
    }

    /**
     * Return the optional application-owned typed tag hook.
     */
    public function tagHook(): ?HtmlTagHook
    {
        return $this->tagHook;
    }
}
