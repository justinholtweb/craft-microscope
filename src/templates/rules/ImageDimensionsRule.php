<?php

namespace justinholtweb\microscope\templates\rules;

use Craft;
use justinholtweb\microscope\models\Finding;
use justinholtweb\microscope\templates\BaseTemplateRule;
use justinholtweb\microscope\templates\TemplateSource;
use justinholtweb\microscope\templates\TemplateStructure;

/**
 * `<img>` tags without intrinsic dimensions or a loading hint.
 *
 * The only front-end rule in the set, and it earns its place: missing width/height is the leading
 * cause of layout shift, which is a third of the Core Web Vitals score and something a scan can
 * detect statically with no false positives worth worrying about.
 */
class ImageDimensionsRule extends BaseTemplateRule
{
    public function handle(): string
    {
        return 'imageDimensions';
    }

    public function title(): string
    {
        return Craft::t('microscope', 'Images without dimensions or loading hints');
    }

    public function severity(): string
    {
        return Finding::SEVERITY_NOTICE;
    }

    public function impact(): string
    {
        return Craft::t('microscope', 'Without `width` and `height` the browser can’t reserve space before the image arrives, so the page reflows as each one loads — that is Cumulative Layout Shift, and it is a ranking signal as well as an annoyance. Without `loading="lazy"`, images far below the fold compete with content the visitor can actually see.');
    }

    public function remediation(): string
    {
        return <<<'MD'
Craft knows both dimensions for any transformed asset, so there's no bookkeeping to do:

```twig
{% set image = entry.heroImage.one() %}
{% if image %}
  {% set thumb = image.getUrl({ width: 800, height: 600 }) %}
  <img
    src="{{ thumb }}"
    width="{{ image.getWidth({ width: 800, height: 600 }) }}"
    height="{{ image.getHeight({ width: 800, height: 600 }) }}"
    alt="{{ image.alt ?? '' }}"
    loading="lazy"
    decoding="async">
{% endif %}
```

Two rules of thumb:

- Set `width` and `height` to the image's *intrinsic* size, then size it in CSS
  (`img { max-width: 100%; height: auto; }`). The attributes give the browser the aspect ratio;
  CSS still controls the layout.
- Use `loading="lazy"` for everything below the fold and **not** for the hero image — lazy-loading
  the largest visible image delays your Largest Contentful Paint instead of improving it.

For responsive sources, `{{ image.getSrcset(['1.5x', '2x']) }}` generates the `srcset` for you.
MD;
    }

    public function docsUrl(): ?string
    {
        return 'https://web.dev/articles/optimize-cls';
    }

    public function analyze(TemplateSource $source, TemplateStructure $structure): array
    {
        return $this->matchAll(
            '/<img\b[^>]*>/is',
            $source,
            $structure,
            filter: function(int $offset, array $match) {
                return $this->problemsWith($match[0]) !== [];
            },
            detail: function(int $offset, array $match) {
                return 'missing ' . implode(', ', $this->problemsWith($match[0]));
            },
        );
    }

    /**
     * @return string[]
     */
    private function problemsWith(string $tag): array
    {
        $problems = [];

        // Attributes may be output by Twig (`width="{{ … }}"`), which is fine — the check is
        // whether the attribute is present at all, not what's in it.
        if (!preg_match('/\bwidth\s*=/i', $tag) || !preg_match('/\bheight\s*=/i', $tag)) {
            $problems[] = 'width/height';
        }

        if (!preg_match('/\bloading\s*=/i', $tag)) {
            $problems[] = 'loading';
        }

        return $problems;
    }
}
