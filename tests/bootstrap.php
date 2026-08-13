<?php

/**
 * Bootstrap for Microscope's test suites.
 *
 * There is no Craft application here, deliberately. The parts of Microscope worth testing in
 * isolation are the parts that are pure by design — the template analyzer, the byte and version
 * helpers, the scoring arithmetic and the schedule's date maths — and keeping them testable
 * without booting Craft is the reason they're structured the way they are.
 *
 * The integration suite talks to a real MySQL or MariaDB (DDEV provides one) and skips itself
 * when there isn't one. Anything that genuinely needs a live Craft is exercised against the
 * plugin-testing harness instead; see tests/README.md.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
