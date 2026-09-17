# Release Notes for Microscope

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
