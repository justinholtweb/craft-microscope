<?php

namespace justinholtweb\microscope\checks\schema;

use Craft;
use justinholtweb\microscope\checks\BaseCheck;
use justinholtweb\microscope\checks\CheckContext;
use justinholtweb\microscope\models\Category;

/**
 * Field layouts wide enough to make the entry editor slow.
 *
 * The cost here lands on editors rather than visitors, which is why it's easy to miss: a layout
 * with eighty fields renders eighty field inputs, each with its own JavaScript, on every entry
 * edit screen. It's also the fastest route to exceeding `max_input_vars` on save.
 */
class FieldLayoutCheck extends BaseCheck
{
    private const WIDE_LAYOUT = 40;
    private const VERY_WIDE_LAYOUT = 70;

    public function handle(): string
    {
        return 'fieldLayouts';
    }

    public function category(): string
    {
        return Category::SCHEMA;
    }

    public function label(): string
    {
        return Craft::t('microscope', 'Field layouts');
    }

    public function description(): string
    {
        return Craft::t('microscope', 'Finds field layouts wide enough to slow the entry editor down or overflow PHP’s input limits.');
    }

    public function run(CheckContext $context): array
    {
        $layouts = $context->craft->largestLayouts(50);
        $wide = array_values(array_filter($layouts, fn(array $l) => $l['fields'] >= self::WIDE_LAYOUT));

        if ($wide === []) {
            $widest = $layouts[0]['fields'] ?? 0;

            return [$this->pass(
                Craft::t('microscope', 'Field layouts are a manageable size'),
                Craft::t('microscope', 'Widest layout has {count} fields', ['count' => $widest]),
            )];
        }

        $widest = $wide[0]['fields'];
        $severity = $widest >= self::VERY_WIDE_LAYOUT ? 'warning' : 'notice';

        return [$this->finding($severity, [
            'title' => Craft::t('microscope', 'Some field layouts are very wide'),
            'summary' => Craft::t('microscope', '{count} layouts have {threshold} or more fields; the widest has {widest}.', [
                'count' => count($wide),
                'threshold' => self::WIDE_LAYOUT,
                'widest' => $widest,
            ]),
            'observed' => Craft::t('microscope', '{widest} fields in the widest layout', ['widest' => $widest]),
            'expected' => Craft::t('microscope', 'Under {n} fields', ['n' => self::WIDE_LAYOUT]),
            'target' => 'schema.layoutWidth',
            'occurrences' => count($wide),
            'evidence' => $wide,
            'impact' => Craft::t('microscope', 'Every field in the layout is instantiated and rendered on each entry edit screen, with its own markup and JavaScript. Past about forty the editor becomes visibly slow to open and to save — and a wide layout with Matrix fields in it is the single most common way to exceed PHP’s `max_input_vars`, which silently discards content on save.'),
            'remediation' => $this->instructions(),
            'docsUrl' => 'https://craftcms.com/docs/5.x/system/fields.html',
        ])];
    }

    private function instructions(): string
    {
        return <<<'MD'
**First, make sure the save isn't already losing data.** A wide layout with Matrix fields can post
more inputs than PHP accepts, and PHP discards the excess without an error. Check
`max_input_vars` — Microscope's PHP category reports it — and count the inputs on your largest
entry form in the browser console:

```js
document.querySelectorAll('#main-form [name]').length
```

If that number is anywhere near `max_input_vars`, raise the limit **before** anything else.

**Then narrow the layout itself:**

- **Use tabs.** Craft renders all tabs' contents but the browser only lays out the visible one, so
  this helps the editor's experience considerably even though the markup is the same size. It's
  also the cheapest change here.
- **Move repeating groups into Matrix.** Ten fields that describe one "section of the page" belong
  in a Matrix entry type, not in the layout side by side.
- **Split by entry type.** If half the fields only apply to some entries, that's two entry types
  rather than one layout with conditional fields.
- **Use conditional fields.** Craft 5 can show a field only when another field has a given value.
  The field is still instantiated server-side, so this helps the editor rather than the server —
  but a layout nobody can navigate is its own problem.

**A note on Matrix**: nested Matrix inside Matrix multiplies input counts fast, and the entry
editor instantiates every block type's field layout to render the "add block" menu. One level of
nesting is normal; two is where sites start reporting that the editor takes seconds to open.
MD;
    }
}
