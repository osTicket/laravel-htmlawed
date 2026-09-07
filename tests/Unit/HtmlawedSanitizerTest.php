<?php

declare(strict_types=1);

namespace osTicket\Htmlawed\Tests\Unit;

use InvalidArgumentException;
use LengthException;
use osTicket\Htmlawed\Contracts\HtmlTagHook;
use osTicket\Htmlawed\Exceptions\HtmlSanitizationException;
use osTicket\Htmlawed\HtmlawedSanitizer;
use osTicket\Htmlawed\HtmlSanitizationLimits;
use osTicket\Htmlawed\HtmlSanitizationPolicy;
use osTicket\Htmlawed\HtmlTag;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Prove the adapter enforces operation-local htmLawed policies.
 */
final class HtmlawedSanitizerTest extends TestCase
{
    /**
     * Remove executable markup, event attributes, and dangerous schemes.
     */
    public function test_safe_policy_neutralizes_executable_html(): void
    {
        $html = <<<'HTML'
            <p onclick="alert(1)">Hello <a href="javascript:alert(2)">there</a></p>
            <script>alert(3)</script>
            HTML;

        $clean = $this->sanitizer()->sanitize(
            $html,
            new HtmlSanitizationPolicy([
                'safe' => 1,
                'elements' => 'p,a',
                'deny_attribute' => 'on*',
                'schemes' => 'href: http, https, mailto',
            ], $this->limits())
        );

        $this->assertStringNotContainsString('onclick', $clean);
        $this->assertStringNotContainsString('<script', $clean);
        $this->assertStringNotContainsString('href="javascript:', $clean);
        $this->assertStringContainsString('Hello', $clean);
    }

    /**
     * Apply compact element specifications supplied independently of options.
     */
    public function test_specification_filters_runtime_sensitive_attributes(): void
    {
        $clean = $this->sanitizer()->sanitize(
            '<iframe src="https://trusted.example/embed"></iframe>'
                .'<iframe src="https://evil.example/embed"></iframe>',
            new HtmlSanitizationPolicy(
                configuration: ['elements' => 'iframe'],
                limits: $this->limits(),
                specification: 'iframe=-*,src(match="`^https://trusted\\.example/`i")'
            )
        );

        $this->assertStringContainsString(
            'src="https://trusted.example/embed"',
            $clean
        );
        $this->assertStringNotContainsString('evil.example', $clean);
    }

    /**
     * Adapt htmLawed's mixed callback to structured, safely rendered tags.
     */
    public function test_tag_hook_receives_attributes_and_null_for_closing_tags(): void
    {
        $hook = new class implements HtmlTagHook
        {
            /**
             * @var list<HtmlTag>
             */
            public array $calls = [];

            /**
             * Strip style while recording the normalized callback value.
             */
            public function transform(HtmlTag $tag): HtmlTag
            {
                $this->calls[] = $tag;

                return $tag->withoutAttributes('style');
            }
        };

        $clean = $this->sanitizer()->sanitize(
            '<p class="notice" style="color:red">Hello</p>',
            new HtmlSanitizationPolicy(
                configuration: ['elements' => 'p'],
                limits: $this->limits(),
                tagHook: $hook
            )
        );

        $this->assertSame('<p class="notice">Hello</p>', $clean);
        $this->assertSame('p', $hook->calls[0]->element());
        $this->assertSame(
            ['class' => 'notice', 'style' => 'color:red'],
            $hook->calls[0]->attributes()
        );
        $this->assertTrue($hook->calls[1]->isClosing());
        $this->assertNull($hook->calls[1]->attributes());
    }

    /**
     * Encode attributes changed by hooks without double encoding entities.
     */
    public function test_tag_hook_safely_renders_changed_attributes(): void
    {
        $hook = new class implements HtmlTagHook
        {
            /**
             * Replace the filtered title with an untrusted application value.
             */
            public function transform(HtmlTag $tag): HtmlTag
            {
                if ($tag->isClosing()) {
                    return $tag;
                }

                return $tag->withAttribute(
                    'title',
                    'A & B "quoted"'
                );
            }
        };

        $clean = $this->sanitizer()->sanitize(
            '<p data-note="already &amp; encoded">Hello</p>',
            new HtmlSanitizationPolicy(
                configuration: ['elements' => 'p'],
                limits: $this->limits(),
                tagHook: $hook
            )
        );

        $this->assertSame(
            '<p data-note="already &amp; encoded" '
                .'title="A &amp; B &quot;quoted&quot;">Hello</p>',
            $clean
        );
    }

    /**
     * Remove tags through a nullable hook result without emitting raw markup.
     */
    public function test_tag_hook_can_remove_an_opening_tag(): void
    {
        $hook = new class implements HtmlTagHook
        {
            /**
             * Remove iframe tags whose source was rejected by the spec.
             */
            public function transform(HtmlTag $tag): ?HtmlTag
            {
                if ($tag->element() === 'iframe'
                    && $tag->isOpening()
                    && ! $tag->hasAttribute('src')) {
                    return null;
                }

                return $tag;
            }
        };

        $clean = $this->sanitizer()->sanitize(
            '<iframe src="https://rejected.example/embed">Fallback</iframe>',
            new HtmlSanitizationPolicy(
                configuration: ['elements' => 'iframe'],
                limits: $this->limits(),
                specification: 'iframe=-*,src(match="`^https://trusted\\.example/`i")',
                tagHook: $hook
            )
        );

        $this->assertSame('Fallback', $clean);
    }

    /**
     * Preserve htmLawed's void-element serialization through typed hooks.
     */
    public function test_tag_hook_renders_void_elements_without_closing_tags(): void
    {
        $hook = new class implements HtmlTagHook
        {
            /**
             * Accept every filtered tag unchanged.
             */
            public function transform(HtmlTag $tag): HtmlTag
            {
                return $tag;
            }
        };

        $clean = $this->sanitizer()->sanitize(
            '<p>Hello<br><img src="image.png" alt="Preview"></p>',
            new HtmlSanitizationPolicy(
                configuration: ['elements' => 'p,br,img'],
                limits: $this->limits(),
                tagHook: $hook
            )
        );

        $this->assertSame(
            '<p>Hello<br /><img src="image.png" alt="Preview" /></p>',
            $clean
        );
    }

    /**
     * Reject hooks which replace a tag after htmLawed accepts its type.
     */
    public function test_tag_hook_cannot_replace_the_filtered_element(): void
    {
        $hook = new class implements HtmlTagHook
        {
            /**
             * Attempt to replace an accepted paragraph with executable HTML.
             */
            public function transform(HtmlTag $tag): HtmlTag
            {
                return new HtmlTag(
                    'script',
                    $tag->attributes()
                );
            }
        };

        $this->expectException(HtmlSanitizationException::class);
        $this->expectExceptionMessage('HTML sanitization failed.');

        $this->sanitizer()->sanitize(
            '<p>Hello</p>',
            new HtmlSanitizationPolicy(
                configuration: ['elements' => 'p'],
                limits: $this->limits(),
                tagHook: $hook
            )
        );
    }

    /**
     * Keep independent policies from leaking options across calls.
     */
    public function test_repeated_operations_do_not_share_policy_state(): void
    {
        $sanitizer = $this->sanitizer();
        $html = '<p>Text</p><iframe src="https://example.com"></iframe>';

        $withIframe = $sanitizer->sanitize(
            $html,
            new HtmlSanitizationPolicy(
                ['elements' => 'p,iframe'],
                $this->limits()
            )
        );
        $withoutIframe = $sanitizer->sanitize(
            $html,
            new HtmlSanitizationPolicy([
                'safe' => 1,
                'elements' => 'p',
            ], $this->limits())
        );

        $this->assertStringContainsString('<iframe', $withIframe);
        $this->assertStringNotContainsString('<iframe', $withoutIframe);
    }

    /**
     * Preserve international text while filtering its surrounding markup.
     */
    public function test_unicode_content_is_preserved(): void
    {
        $clean = $this->sanitizer()->sanitize(
            '<p>Habari 👋 — こんにちは</p>',
            new HtmlSanitizationPolicy(['elements' => 'p'], $this->limits())
        );

        $this->assertSame('<p>Habari 👋 — こんにちは</p>', $clean);
    }

    /**
     * Revalidate attributes introduced by a privileged tag hook.
     */
    public function test_tag_hook_cannot_reintroduce_rejected_content(): void
    {
        $hook = new class implements HtmlTagHook
        {
            /**
             * Attempt to add executable attributes after the first pass.
             */
            public function transform(HtmlTag $tag): HtmlTag
            {
                if ($tag->isClosing()) {
                    return $tag;
                }

                return $tag->withAttributes([
                    'href' => 'javascript:alert(1)',
                    'onclick' => 'alert(2)',
                    'style' => 'position:fixed;inset:0;z-index:999999',
                ]);
            }
        };

        $clean = $this->sanitizer()->sanitize(
            '<a href="https://example.com">Open</a>',
            new HtmlSanitizationPolicy(
                configuration: [
                    'safe' => 1,
                    'elements' => 'a',
                    'deny_attribute' => 'on*,style',
                    'schemes' => 'href: http, https, mailto',
                ],
                limits: $this->limits(),
                specification: 'a=href',
                tagHook: $hook
            )
        );

        $this->assertSame(
            '<a href="denied:javascript:alert(1)">Open</a>',
            $clean
        );
        $this->assertStringNotContainsString('onclick', $clean);
        $this->assertStringNotContainsString('style=', $clean);
    }

    /**
     * Reject malformed encodings which the underlying parser does not check.
     */
    public function test_invalid_utf8_is_rejected_before_parsing(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('HTML input must be valid UTF-8.');

        $this->sanitizer()->sanitize(
            "<p>Invalid \xC3\x28</p>",
            new HtmlSanitizationPolicy(['safe' => 1], $this->limits())
        );
    }

    /**
     * Bound parser work using the operation-specific byte limit.
     */
    public function test_oversized_input_is_rejected_before_parsing(): void
    {
        $this->expectException(LengthException::class);
        $this->expectExceptionMessage(
            'HTML input exceeds the configured sanitization byte limit.'
        );

        $this->sanitizer()->sanitize(
            '<p>Too large</p>',
            new HtmlSanitizationPolicy(
                ['safe' => 1],
                $this->limits(maxInputBytes: 8)
            )
        );
    }

    /**
     * Apply the same resource bound before parsing expanded hook output.
     */
    public function test_oversized_hook_output_is_rejected_before_second_pass(): void
    {
        $hook = new class implements HtmlTagHook
        {
            /**
             * Expand one accepted tag beyond the operation's parser limit.
             */
            public function transform(HtmlTag $tag): HtmlTag
            {
                if ($tag->isClosing()) {
                    return $tag;
                }

                return $tag->withAttribute('title', str_repeat('x', 128));
            }
        };

        $this->expectException(LengthException::class);

        $this->sanitizer()->sanitize(
            '<p>Small</p>',
            new HtmlSanitizationPolicy(
                configuration: ['elements' => 'p'],
                limits: $this->limits(maxInputBytes: 64),
                tagHook: $hook
            )
        );
    }

    /**
     * Reject markup tokens which can trigger super-linear parser work.
     */
    public function test_oversized_markup_token_is_rejected_before_parsing(): void
    {
        $this->expectException(LengthException::class);
        $this->expectExceptionMessage(
            'HTML markup exceeds the configured tag byte limit.'
        );

        $this->sanitizer()->sanitize(
            '<p '.str_repeat('title="value" ', 16).'>Hello</p>',
            new HtmlSanitizationPolicy(
                ['safe' => 1, 'elements' => 'p'],
                $this->limits(maxTagBytes: 64)
            )
        );
    }

    /**
     * Ignore ordinary less-than text when enforcing markup-token limits.
     */
    public function test_markup_limit_does_not_treat_comparison_text_as_a_tag(): void
    {
        $html = str_repeat('2 < 3 and 5 > 4. ', 16);

        $clean = $this->sanitizer()->sanitize(
            $html,
            new HtmlSanitizationPolicy(
                ['safe' => 1, 'elements' => 'p'],
                $this->limits(maxTagBytes: 16)
            )
        );

        $this->assertStringContainsString('2 &lt; 3', $clean);
    }

    /**
     * Bound entity and balancing expansion in the returned fragment.
     */
    public function test_expanded_output_is_rejected_at_its_own_limit(): void
    {
        $this->expectException(LengthException::class);
        $this->expectExceptionMessage(
            'HTML output exceeds the configured sanitization byte limit.'
        );

        $this->sanitizer()->sanitize(
            str_repeat('&', 16),
            new HtmlSanitizationPolicy(
                ['safe' => 1],
                $this->limits(maxOutputBytes: 64)
            )
        );
    }

    /**
     * Keep duplicate-ID tracking local to one sanitizer operation.
     */
    public function test_repeated_operations_do_not_share_id_state(): void
    {
        $sanitizer = $this->sanitizer();
        $policy = new HtmlSanitizationPolicy([
            'elements' => 'p',
            'unique_ids' => 1,
        ], $this->limits());

        $first = $sanitizer->sanitize('<p id="notice">One</p>', $policy);
        $second = $sanitizer->sanitize('<p id="notice">Two</p>', $policy);

        $this->assertSame('<p id="notice">One</p>', $first);
        $this->assertSame('<p id="notice">Two</p>', $second);
        $this->assertFalse(array_key_exists('C', $GLOBALS));
        $this->assertFalse(array_key_exists('S', $GLOBALS));
        $this->assertFalse(array_key_exists('hl_Ids', $GLOBALS));
    }

    /**
     * Restore parser globals which belong to an outer caller.
     */
    public function test_existing_parser_globals_are_restored(): void
    {
        $GLOBALS['C'] = ['outer-configuration'];
        $GLOBALS['S'] = ['outer-specification'];
        $GLOBALS['hl_Ids'] = ['outer-id' => 1];

        try {
            $this->sanitizer()->sanitize(
                '<p id="notice">Hello</p>',
                new HtmlSanitizationPolicy(
                    ['elements' => 'p'],
                    $this->limits()
                )
            );

            $this->assertSame(['outer-configuration'], $GLOBALS['C']);
            $this->assertSame(['outer-specification'], $GLOBALS['S']);
            $this->assertSame(['outer-id' => 1], $GLOBALS['hl_Ids']);
        } finally {
            unset($GLOBALS['C'], $GLOBALS['S'], $GLOBALS['hl_Ids']);
        }
    }

    /**
     * Hide source content and restore globals when a tag hook fails.
     */
    public function test_hook_failure_is_replaced_without_source_content(): void
    {
        $secret = 'TOP-SECRET-THREAD-BODY';
        $hook = new class implements HtmlTagHook
        {
            /**
             * Simulate a failing privileged policy hook.
             */
            public function transform(HtmlTag $tag): ?HtmlTag
            {
                throw new RuntimeException('Hook failed.');
            }
        };

        try {
            $this->sanitizer()->sanitize(
                sprintf('<p>%s</p>', $secret),
                new HtmlSanitizationPolicy(
                    configuration: ['elements' => 'p'],
                    limits: $this->limits(),
                    tagHook: $hook
                )
            );

            self::fail('The failing hook unexpectedly completed.');
        } catch (HtmlSanitizationException $exception) {
            $trace = json_encode(
                $exception->getTrace(),
                JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_THROW_ON_ERROR
            );

            $this->assertSame(
                'HTML sanitization failed.',
                $exception->getMessage()
            );
            $this->assertNull($exception->getPrevious());
            $this->assertStringNotContainsString($secret, $trace);
            $this->assertStringNotContainsString('Hook failed.', $trace);
            $this->assertFalse(array_key_exists('C', $GLOBALS));
            $this->assertFalse(array_key_exists('S', $GLOBALS));
            $this->assertFalse(array_key_exists('hl_Ids', $GLOBALS));
        }
    }

    /**
     * Isolate nested sanitizer calls without corrupting the outer parser.
     */
    public function test_nested_sanitizer_calls_restore_outer_state(): void
    {
        $sanitizer = $this->sanitizer();
        $hook = new class($sanitizer) implements HtmlTagHook
        {
            /**
             * Sanitized result produced by the nested operation.
             */
            public ?string $inner = null;

            /**
             * Build a hook which invokes a second sanitization operation.
             */
            public function __construct(
                private readonly HtmlawedSanitizer $sanitizer
            ) {}

            /**
             * Sanitize independent content while the outer hook is active.
             */
            public function transform(HtmlTag $tag): HtmlTag
            {
                if ($tag->isOpening()) {
                    $this->inner = $this->sanitizer->sanitize(
                        '<strong id="inner">Nested</strong>',
                        new HtmlSanitizationPolicy([
                            'elements' => 'strong',
                            'unique_ids' => 1,
                        ], new HtmlSanitizationLimits(
                            maxInputBytes: 65_536,
                            maxOutputBytes: 131_072,
                            maxTagBytes: 16_384
                        ))
                    );
                }

                return $tag;
            }
        };

        $outer = $sanitizer->sanitize(
            '<p id="outer">Hello</p>',
            new HtmlSanitizationPolicy(
                configuration: [
                    'elements' => 'p',
                    'unique_ids' => 1,
                ],
                limits: $this->limits(),
                tagHook: $hook
            )
        );

        $this->assertSame('<p id="outer">Hello</p>', $outer);
        $this->assertSame(
            '<strong id="inner">Nested</strong>',
            $hook->inner
        );
        $this->assertFalse(array_key_exists('C', $GLOBALS));
        $this->assertFalse(array_key_exists('S', $GLOBALS));
        $this->assertFalse(array_key_exists('hl_Ids', $GLOBALS));
    }

    /**
     * Remove active and deceptive markup under a strict surface policy.
     *
     * @param  list<string>  $forbidden
     */
    #[DataProvider('hostileHtml')]
    public function test_strict_policy_rejects_hostile_markup(
        string $html,
        array $forbidden
    ): void {
        $clean = $this->sanitizer()->sanitize(
            $html,
            new HtmlSanitizationPolicy([
                'safe' => 1,
                'elements' => 'p,br,a,strong,em',
                'deny_attribute' => 'id,style,on*',
                'schemes' => 'href: http, https, mailto',
            ], $this->limits(), 'a=href,title')
        );

        foreach ($forbidden as $needle) {
            $this->assertStringNotContainsString($needle, $clean);
        }
    }

    /**
     * Return hostile markup classes relevant to browser rendering contexts.
     *
     * @return iterable<string,array{string,list<string>}>
     */
    public static function hostileHtml(): iterable
    {
        yield 'event attribute' => [
            '<p onmouseover="alert(1)">Hover</p>',
            ['onmouseover'],
        ];
        yield 'encoded executable scheme' => [
            '<a href="jav&#x61;script:alert(1)">Open</a>',
            ['href="javascript:', 'href="jav&#x61;script:'],
        ];
        yield 'overlay style' => [
            '<p style="position:fixed;inset:0">Cover</p>',
            ['style='],
        ];
        yield 'foreign namespaces' => [
            '<svg><a xlink:href="javascript:alert(1)">SVG</a></svg>'
                .'<math><mtext>Math</mtext></math>',
            ['<svg', 'xlink:', '<math', '<mtext'],
        ];
        yield 'inert and fallback containers' => [
            '<template><script>alert(1)</script></template>'
                .'<noscript><img src=x onerror=alert(2)></noscript>',
            ['<template', '<script', '<noscript', '<img', 'onerror'],
        ];
        yield 'credential collection form' => [
            '<form action="https://evil.example">'
                .'<input type="password"><button>Continue</button></form>',
            ['<form', '<input', '<button'],
        ];
        yield 'comments and malformed nesting' => [
            '<!--[if IE]><script>alert(1)</script><![endif]-->'
                .'<p><strong>Text</p></strong>',
            ['<script', '<!--'],
        ];
    }

    /**
     * Build the stateless adapter under test.
     */
    private function sanitizer(): HtmlawedSanitizer
    {
        return new HtmlawedSanitizer;
    }

    /**
     * Build the default limits used by focused sanitizer tests.
     */
    private function limits(
        int $maxInputBytes = 65_536,
        int $maxOutputBytes = 131_072,
        int $maxTagBytes = 16_384
    ): HtmlSanitizationLimits {
        return new HtmlSanitizationLimits(
            $maxInputBytes,
            $maxOutputBytes,
            $maxTagBytes
        );
    }
}
