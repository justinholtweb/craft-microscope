# Release Notes for Microscope

## 5.0.2

### Fixed

- `relationInLoop` no longer flags a relation that has already been lazily eager-loaded. Craft 5's
  `entry.topics.eagerly().all()` runs one query for the whole loop, but the rule matched any
  `.all()`, `.one()`, `.count()`, `.exists()` or `.nth()` inside a loop and so kept reporting it —
  which meant following the rule's own remediation could never clear the warning. The check now
  reads the method chain the call belongs to, so `.eagerly()` anywhere in it (including
  `.eagerly('alias')` and `.eagerly().limit(3).all()`) is skipped, while a bare relation elsewhere
  on the same line is still reported. ([#2])

[#2]: https://github.com/justinholtweb/craft-microscope/issues/2

## 5.0.1

### Fixed

- CLI scans no longer report the console binary's PHP configuration as the site's. `memory_limit`,
  `upload_max_filesize`, `post_max_size`, `max_input_vars`, `max_execution_time` and the realpath
  cache are read from whichever `php.ini` the running SAPI loaded, which on Herd, Valet, DDEV,
  Docker and most shared hosting is not the one PHP-FPM uses — so a healthy site could be flagged
  with critical and warning findings it didn't have. The memory limit, request limits and realpath
  cache checks are now skipped outside a web SAPI, with the reason recorded and no effect on the
  score, and OPcache's sizing analysis is skipped the same way. ([#1])

[#1]: https://github.com/justinholtweb/craft-microscope/issues/1

## 5.0.0

- Initial release.
- Performance scans across six areas: PHP, database, server, Craft configuration, content schema and Twig templates.
- Every finding carries a plain-English explanation of the cost and a step-by-step fix.
- Scheduled scans, daily/weekly/monthly, with email notification on new or worsening findings.
- Reports as a print-optimised page and as a downloadable PDF.
- Control panel dashboard with an overall score, per-area breakdown and score history.
- Dashboard widget, Twig variable and console commands (`microscope/scan/run`, `scheduled`, `report`, `prune`).
