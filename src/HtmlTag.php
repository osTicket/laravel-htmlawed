<?php

declare(strict_types=1);

namespace osTicket\Htmlawed;

use InvalidArgumentException;

/**
 * Represent one tag after htmLawed has filtered its attributes.
 *
 * Hooks work with this value instead of assembling markup themselves. The
 * package can therefore preserve htmLawed's filtering boundary and encode
 * every changed attribute before returning the tag to the sanitizer.
 */
final readonly class HtmlTag
{
    /**
     * HTML elements which do not have closing tags.
     *
     * @var list<string>
     */
    private const VOID_ELEMENTS = [
        'area',
        'br',
        'col',
        'command',
        'embed',
        'hr',
        'img',
        'input',
        'isindex',
        'keygen',
        'link',
        'meta',
        'param',
        'source',
        'track',
        'wbr',
    ];

    /**
     * Filtered element name supplied by htmLawed.
     */
    private string $element;

    /**
     * Filtered attributes, or null when this is a closing tag.
     *
     * @var array<string,string>|null
     */
    private ?array $attributes;

    /**
     * Create one filtered opening or closing tag.
     *
     * A null attribute map identifies a closing tag. Attribute values remain
     * entity encoded when supplied by htmLawed and are safely re-encoded when
     * a hook replaces them.
     *
     * @param  array<mixed,mixed>|null  $attributes
     */
    public function __construct(
        string $element,
        ?array $attributes
    ) {
        if (! preg_match('/^[a-z][a-z0-9:-]*$/i', $element)) {
            throw new InvalidArgumentException(
                'HTML tag element names must be valid identifiers.'
            );
        }

        if ($attributes === null) {
            $this->element = $element;
            $this->attributes = null;

            return;
        }

        $validatedAttributes = [];

        foreach ($attributes as $name => $value) {
            if (! is_string($name)
                || ! preg_match('/^[a-z_:][a-z0-9:._-]*$/i', $name)) {
                throw new InvalidArgumentException(
                    'HTML tag attribute names must be valid identifiers.'
                );
            }

            if (! is_string($value)) {
                throw new InvalidArgumentException(
                    'HTML tag attribute values must be strings.'
                );
            }

            if (! mb_check_encoding($value, 'UTF-8')) {
                throw new InvalidArgumentException(
                    'HTML tag attribute values must be valid UTF-8.'
                );
            }

            $validatedAttributes[$name] = $value;
        }

        $this->element = $element;
        $this->attributes = $validatedAttributes;
    }

    /**
     * Return the filtered element name.
     */
    public function element(): string
    {
        return $this->element;
    }

    /**
     * Determine whether this value represents a closing tag.
     */
    public function isClosing(): bool
    {
        return $this->attributes === null;
    }

    /**
     * Determine whether this value represents an opening tag.
     */
    public function isOpening(): bool
    {
        return $this->attributes !== null;
    }

    /**
     * Return the filtered opening-tag attributes.
     *
     * Closing tags return null rather than htmLawed's historical integer
     * sentinel.
     *
     * @return array<string,string>|null
     */
    public function attributes(): ?array
    {
        return $this->attributes;
    }

    /**
     * Determine whether an opening tag carries the named attribute.
     */
    public function hasAttribute(string $name): bool
    {
        return $this->attributes !== null
            && array_key_exists($name, $this->attributes);
    }

    /**
     * Return one opening-tag attribute when it is present.
     */
    public function attribute(string $name): ?string
    {
        return $this->attributes[$name] ?? null;
    }

    /**
     * Return a copy with one opening-tag attribute added or replaced.
     */
    public function withAttribute(string $name, string $value): self
    {
        if ($this->attributes === null) {
            throw new InvalidArgumentException(
                'Closing tags cannot carry attributes.'
            );
        }

        return new self(
            $this->element,
            [...$this->attributes, $name => $value]
        );
    }

    /**
     * Return a copy with the supplied opening-tag attributes.
     *
     * @param  array<string,string>  $attributes
     */
    public function withAttributes(array $attributes): self
    {
        if ($this->attributes === null) {
            throw new InvalidArgumentException(
                'Closing tags cannot carry attributes.'
            );
        }

        return new self($this->element, $attributes);
    }

    /**
     * Return a copy without the named opening-tag attributes.
     */
    public function withoutAttributes(string ...$names): self
    {
        if ($this->attributes === null) {
            return $this;
        }

        $attributes = $this->attributes;

        foreach ($names as $name) {
            unset($attributes[$name]);
        }

        return new self($this->element, $attributes);
    }

    /**
     * Render the filtered tag for htmLawed's callback boundary.
     *
     * Attribute values received from htmLawed may already contain entities.
     * Disabling double encoding preserves them while still encoding values
     * newly supplied by an application hook.
     */
    public function toHtml(): string
    {
        if ($this->attributes === null) {
            return sprintf('</%s>', $this->element);
        }

        $attributes = $this->attributes;
        $renderedAttributes = '';

        foreach ($attributes as $name => $value) {
            $renderedAttributes .= sprintf(
                ' %s="%s"',
                $name,
                htmlspecialchars(
                    $value,
                    ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5,
                    'UTF-8',
                    false
                )
            );
        }

        return sprintf(
            '<%s%s%s>',
            $this->element,
            $renderedAttributes,
            in_array($this->element, self::VOID_ELEMENTS, true) ? ' /' : ''
        );
    }
}
