# Release Notes for Microscope

## 5.0.0

- Initial release.
- Performance scans across six areas: PHP, database, server, Craft configuration, content schema and Twig templates.
- Every finding carries a plain-English explanation of the cost and a step-by-step fix.
- Scheduled scans, daily/weekly/monthly, with email notification on new or worsening findings.
- Reports as a print-optimised page and as a downloadable PDF.
- Control panel dashboard with an overall score, per-area breakdown and score history.
- Dashboard widget, Twig variable and console commands (`microscope/scan/run`, `scheduled`, `report`, `prune`).
