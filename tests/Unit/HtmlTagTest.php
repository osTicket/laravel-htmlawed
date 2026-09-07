<?php

declare(strict_types=1);

namespace osTicket\Htmlawed\Tests\Unit;

use InvalidArgumentException;
use osTicket\Htmlawed\HtmlTag;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Prove structured tag transformations remain safe and predictable.
 */
final class HtmlTagTest extends TestCase
{
    /**
     * Inspect, replace, and remove accepted opening-tag attributes.
     */
    public function test_opening_tag_attributes_can_be_transformed(): void
    {
        $tag = new HtmlTag('a', [
            'href' => 'https://example.com',
            'class' => 'external',
        ]);

        $transformed = $tag
            ->withAttribute('rel', 'noopener noreferrer')
            ->withoutAttributes('class');

        $this->assertTrue($tag->isOpening());
        $this->assertFalse($tag->isClosing());
        $this->assertTrue($tag->hasAttribute('href'));
        $this->assertSame('https://example.com', $tag->attribute('href'));
        $this->assertNull($tag->attribute('title'));
        $this->assertSame(
            '<a href="https://example.com" rel="noopener noreferrer">',
            $transformed->toHtml()
        );
    }

    /**
     * Reject invalid element, attribute, and value shapes.
     *
     * @param  array<mixed,mixed>|null  $attributes
     */
    #[DataProvider('invalidTags')]
    public function test_invalid_tag_shapes_are_rejected(
        string $element,
        ?array $attributes
    ): void {
        $this->expectException(InvalidArgumentException::class);

        new HtmlTag($element, $attributes);
    }

    /**
     * Return tag shapes which cannot cross the structured hook boundary.
     *
     * @return iterable<string,array{string,array<mixed,mixed>|null}>
     */
    public static function invalidTags(): iterable
    {
        yield 'element markup' => ['p><script', []];
        yield 'numeric attribute' => ['p', ['title']];
        yield 'attribute markup' => ['p', ['on click' => 'alert(1)']];
        yield 'non-string value' => ['p', ['title' => 42]];
        yield 'invalid UTF-8 value' => ['p', ['title' => "\xC3\x28"]];
    }

    /**
     * Keep closing tags immutable and free from attributes.
     */
    public function test_closing_tags_cannot_receive_attributes(): void
    {
        $tag = new HtmlTag('p', null);

        $this->assertTrue($tag->isClosing());
        $this->assertFalse($tag->isOpening());
        $this->assertSame('</p>', $tag->toHtml());

        $this->expectException(InvalidArgumentException::class);

        $tag->withAttribute('class', 'notice');
    }
}
