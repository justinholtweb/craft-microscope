<?php

namespace justinholtweb\microscope\checks\schema;

use Craft;
use justinholtweb\microscope\checks\BaseCheck;
use justinholtweb\microscope\checks\CheckContext;
use justinholtweb\microscope\models\Category;

/**
 * Fields that exist but aren't used, and fields that exist in quantity.
 *
 * In Craft 5 every custom field gets a column in `elements_sites`' content JSON rather than its own
 * table, so the per-field cost is lower than it was in Craft 3 — but the field *definitions* are
 * still loaded and instantiated on every request that touches a layout, and unused fields still
 * appear in the project config, the field index, and every "choose a field" dropdown an editor
 * ever sees.
 */
class FieldUsageCheck extends BaseCheck
{
    private const MANY_FIELDS = 150;

    public function handle(): string
    {
        return 'fieldUsage';
    }

    public function category(): string
    {
        return Category::SCHEMA;
    }

    public function label(): string
    {
        return Craft::t('microscope', 'Field usage');
    }

    public function description(): string
    {
        return Craft::t('microscope', 'Counts custom fields and finds any that aren’t used in a single field layout.');
    }

    public function run(CheckContext $context): array
    {
        $craft = $context->craft;
        $fields = $craft->fields();
        $used = $craft->usedFieldHandles();
        $findings = [];

        $unused = [];

        foreach ($fields as $field) {
            if (!in_array($field->handle, $used, true)) {
                $unused[] = [
                    'field' => $field->name,
                    'handle' => $field->handle,
                    'type' => substr((string)strrchr($field::class, '\\'), 1),
                ];
            }
        }

        if ($unused !== []) {
            $findings[] = $this->notice([
                'title' => Craft::t('microscope', 'Some fields aren’t used anywhere'),
                'summary' => Craft::t('microscope', '{count} of {total} custom fields don’t appear in any field layout.', [
                    'count' => count($unused),
                    'total' => count($fields),
                ]),
                'observed' => Craft::t('microscope', '{count} unused fields', ['count' => count($unused)]),
                'expected' => Craft::t('microscope', 'Every field in use'),
                'target' => 'schema.unusedFields',
                'occurrences' => count($unused),
                'evidence' => $unused,
                'impact' => Craft::t('microscope', 'Each one is still instantiated when Craft builds its field list, still carried in the project config that every deploy applies, and still offered to editors building layouts. Individually trivial; collectively they are the reason a field index takes a moment to load and a project config apply takes a minute.'),
                'remediation' => $this->unusedInstructions(),
            ]);
        }

        if (count($fields) > self::MANY_FIELDS) {
            $findings[] = $this->notice([
                'title' => Craft::t('microscope', 'The site has a large number of fields'),
                'summary' => Craft::t('microscope', '{count} custom fields are defined.', ['count' => count($fields)]),
                'observed' => (string)count($fields),
                'expected' => Craft::t('microscope', 'Under {n}', ['n' => self::MANY_FIELDS]),
                'target' => 'schema.fieldCount',
                'impact' => Craft::t('microscope', 'Craft loads and instantiates every field definition when it builds a layout. At this scale that is measurable on control panel requests, and it makes the content model hard for editors to reason about — which usually causes more problems than the milliseconds do.'),
                'remediation' => "Field counts grow through duplication rather than genuine need. Look for:\n\n- **The same field remade per section** — `newsIntro`, `eventsIntro`, `productsIntro`. Craft 5 lets one field be reused across layouts with a different label and instructions per layout, so these should be a single `intro` field.\n- **Fields distinguished only by their label.** Reuse the field and override the label in the layout designer.\n- **Fields left over from a previous content model.** These are the unused ones above.\n\nCraft 5's field-reuse model is the thing to lean on here: **Settings → Fields** defines a field once, and each field layout can override its name, handle-in-context, instructions and requiredness.",
            ]);
        }

        if ($findings === []) {
            $findings[] = $this->pass(
                Craft::t('microscope', 'Fields are all in use'),
                Craft::t('microscope', '{count} fields, all in at least one layout', ['count' => count($fields)]),
            );
        }

        return $findings;
    }

    private function unusedInstructions(): string
    {
        return <<<'MD'
**Verify before deleting.** A field can be absent from every layout and still be referenced —
by a template, by a plugin, or by a layout on an element type this check doesn't enumerate.
Search the codebase first:

```bash
grep -rn "fieldHandle" templates/ modules/ --include='*.twig' --include='*.php'
```

**Deleting a field deletes its content**, irreversibly and for every element. Take a database
backup first:

```bash
php craft db/backup
```

Then remove them in **Settings → Fields**. With `allowAdminChanges` off in production, do this
locally and deploy the project config change:

```bash
php craft up
```

If you're not confident enough to delete, the middle ground is to move them into a "Deprecated"
field group so they stop cluttering the layout designer, and revisit in a release or two.
MD;
    }
}
