# Laravel htmLawed

`osticket/laravel-htmlawed` provides a typed, stateless Laravel integration for
the [htmLawed](https://www.bioinformatics.org/phplabware/internal_utilities/htmLawed/)
HTML sanitizer.

The package deliberately does not define what safe HTML means for an
application. Each caller supplies an explicit policy for its content boundary.
This keeps thread content, imported email, CMS pages, and plugin-owned content
free to enforce different rules without relying on mutable global state.

## Requirements

- PHP 8.2 or newer
- Laravel 11, 12, or 13
- The `mbstring` PHP extension

## Installation

```shell
composer require osticket/laravel-htmlawed
```

Laravel discovers `HtmlawedServiceProvider` automatically. Applications can
then inject the package contract:

```php
use osTicket\Htmlawed\Contracts\HtmlSanitizer;
use osTicket\Htmlawed\HtmlSanitizationLimits;
use osTicket\Htmlawed\HtmlSanitizationPolicy;

final readonly class CleanArticle
{
    public function __construct(
        private HtmlSanitizer $sanitizer
    ) {}

    public function handle(string $html): string
    {
        return $this->sanitizer->sanitize(
            $html,
            new HtmlSanitizationPolicy(
                configuration: [
                    'safe' => 1,
                    'elements' => 'p,br,a,strong,em',
                    'deny_attribute' => 'id,style,on*',
                    'schemes' => 'href: http, https, mailto',
                ],
                limits: new HtmlSanitizationLimits(
                    maxInputBytes: 262_144,
                    maxOutputBytes: 524_288,
                    maxTagBytes: 16_384,
                ),
                specification: 'a=href,title'
            )
        );
    }
}
```

## Policies

`HtmlSanitizationPolicy` keeps htmLawed configuration and element
specifications separate. The distinction matters when an application derives
part of a specification at runtime, such as an iframe host allowlist.

The package validates the policy boundary but does not maintain its own list of
htmLawed options. The upstream option language is intentionally available so
applications are not blocked from using supported sanitizer capabilities.

A non-empty configuration and explicit resource limits are always required.
This prevents a sanitizer call from quietly falling back to htmLawed's
permissive defaults and forces each content surface to bound parser work and
output expansion. Limits are measured in bytes because they protect memory,
processing cost, and storage. Thread messages, imported email, and CMS
documents can therefore choose limits which reflect their actual boundaries.

`maxInputBytes` applies to every htmLawed parser pass. `maxTagBytes` rejects an
abnormally large tag, comment, or declaration before it can cause
super-linear attribute processing. `maxOutputBytes` bounds entity encoding and
tag-balancing expansion in the fragment returned to the application.

The package intentionally has no generic `safe()` policy. htmLawed's `safe`
mode still permits content, including `style`, which can be unsafe for a
particular product surface. Applications should define narrow element,
attribute, scheme, and specification rules for every use case.

## Tag hooks

htmLawed can pass every accepted tag through a final hook. The package replaces
its historical mixed callback signature and raw-markup return value with a
structured `HtmlTagHook`. Applications transform accepted tags while the
package retains responsibility for encoding and rendering them.

```php
use osTicket\Htmlawed\Contracts\HtmlTagHook;
use osTicket\Htmlawed\HtmlTag;

final class RichTextTagHook implements HtmlTagHook
{
    public function transform(HtmlTag $tag): ?HtmlTag
    {
        if (in_array($tag->element(), ['iframe', 'embed'], true)
            && $tag->isOpening()
            && ! $tag->hasAttribute('src')) {
            return null;
        }

        return $tag->withoutAttributes('style');
    }
}
```

Pass the hook as part of the operation-local policy:

```php
$policy = new HtmlSanitizationPolicy(
    configuration: [
        'safe' => 1,
        'elements' => 'p,a,strong,em',
    ],
    limits: new HtmlSanitizationLimits(
        maxInputBytes: 262_144,
        maxOutputBytes: 524_288,
        maxTagBytes: 16_384,
    ),
    tagHook: new RichTextTagHook
);
```

Returning `null` removes the current tag. `HtmlTag::withAttributes()` replaces
an opening tag's attribute map, `HtmlTag::withAttribute()` adds or replaces one
attribute, and `HtmlTag::withoutAttributes()` removes named attributes. Hooks
can inspect accepted values through `attribute()` and `hasAttribute()`. The
package renders the result and encodes changed values, so hooks never need to
concatenate sanitized data into raw markup. A hook cannot replace the accepted
element or convert an opening tag into a closing tag. Hook output is sanitized
again under the same policy. A hook therefore cannot add an event handler,
unsafe URL scheme, or other attribute rejected by that policy.

## Application pipeline

Sanitization is one stage of rich-content handling. The package does not:

- decode application-specific transport encodings;
- remove quoted email replies;
- localize inline attachment references;
- decide which iframe hosts an installation trusts;
- balance content with a separate DOM implementation;
- convert sanitized HTML to plain text; or
- decide when sanitized content is persisted or re-sanitized.

Those decisions belong to the consuming application. A typical pipeline is:

```text
transport normalization
    -> application content preparation
    -> explicit HtmlSanitizationPolicy
    -> HtmlSanitizer
    -> storage or presentation conversion
```

htmLawed may retain the text inside a forbidden element after removing its
tags. That text is no longer executable HTML. Applications which do not want
such text in previews or search documents should remove it during their content
preparation stage.

## Security

- Treat every policy change as a security-sensitive code change.
- Sanitize at the server boundary even when a frontend editor limits markup.
- Prefer narrow element and attribute allowlists.
- Avoid `style` unless a specification strictly constrains every accepted
  property and value. A full-screen overlay can be a security problem without
  executing JavaScript.
- Never accept htmLawed configuration directly from an untrusted request.
- Treat tag hooks as privileged application code even though their output is
  filtered a second time.
- Keep dynamic allowlists, such as trusted iframe hosts, under application
  configuration and authorization.
- Send and interpret source and output as UTF-8. The sanitizer rejects invalid
  byte sequences, but the application must also declare an explicit UTF-8 HTTP
  charset.
- Use a restrictive Content Security Policy as defense in depth. Sanitization
  is not a replacement for browser security headers.
- Treat `HtmlSanitizationException` as a fail-closed result. The package
  deliberately removes the underlying exception and its trace because
  htmLawed can otherwise retain confidential source HTML in trace arguments.

Sanitized output is safe only as an **HTML body fragment under the policy that
produced it**. It is not safe to interpolate into an HTML attribute, URL,
JavaScript, CSS, or another executable context. JSON responses must use a JSON
encoder; embedding the returned HTML directly in a script block is unsafe.

## Development

```shell
composer install
composer format:check
composer phpstan
composer test
```

The test suite includes malformed markup, malformed UTF-8, dangerous schemes
and attributes, deceptive styles, foreign namespaces, element specifications,
typed tag hooks, resource limits, container resolution, nested operations, and
repeated operations which prove parser globals do not leak between calls.
