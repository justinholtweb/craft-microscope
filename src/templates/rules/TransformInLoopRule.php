<?php

namespace justinholtweb\microscope\templates\rules;

use Craft;
use justinholtweb\microscope\models\Finding;
use justinholtweb\microscope\templates\BaseTemplateRule;
use justinholtweb\microscope\templates\TemplateSource;
use justinholtweb\microscope\templates\TemplateStructure;

/**
 * Image transforms requested one at a time inside a loop.
 */
class TransformInLoopRule extends BaseTemplateRule
{
    public function handle(): string
    {
        return 'transformInLoop';
    }

    public function title(): string
    {
        return Craft::t('microscope', 'Image transforms generated inside a loop');
    }

    public function severity(): string
    {
        return Finding::SEVERITY_NOTICE;
    }

    public function impact(): string
    {
        return Craft::t('microscope', 'Each transform URL is a query against the transform index, and — the first time round — an image manipulation too. In a loop that means a query per image on every request, and a burst of image processing inside the request that generated them.');
    }

    public function remediation(): string
    {
        return <<<'MD'
Eager-load the transforms along with the assets, so their index rows come back in the same query:

```twig
{% set entries = craft.entries()
  .section('news')
  .with([
    ['heroImage', { withTransforms: ['thumb', { width: 800, height: 600 }] }]
  ])
  .all() %}

{% for entry in entries %}
  {% set image = entry.heroImage[0] ?? null %}
  {% if image %}<img src="{{ image.getUrl('thumb') }}" …>{% endif %}
{% endfor %}
```

Then make sure the images aren't being *generated* during the request. In `config/general.php`:

```php
'generateTransformsBeforePageLoad' => false,
```

With this off, Craft returns a URL that generates the transform on first request to the image
itself, so a page of forty new images renders immediately instead of resizing forty files inline.

Naming your transforms in **Settings → Assets → Image Transforms** rather than defining them
inline also helps: named transforms are indexed and reused across templates, where two inline
definitions that differ by a single parameter produce two separate files.
MD;
    }

    public function docsUrl(): ?string
    {
        return 'https://craftcms.com/docs/5.x/development/image-transforms.html';
    }

    public function analyze(TemplateSource $source, TemplateStructure $structure): array
    {
        return $this->matchAll(
            '/(?:\.getUrl\(\s*[\{\'"]|\|\s*transform\s*\()/',
            $source,
            $structure,
            filter: fn(int $offset) => $structure->isInsideLoopAt($offset),
            detail: fn() => 'transform requested per iteration',
        );
    }
}
