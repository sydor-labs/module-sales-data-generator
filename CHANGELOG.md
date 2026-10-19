# Changelog

All notable changes to this module are documented in this file.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and the module uses [Semantic Versioning](https://semver.org/).

## [1.2.1] - 2026-10-19

### Fixed

- Consumer memory grew with every customer and order, by about 5–10 KB each; it now stays at the
  same level during a job.

### Changed

- README: memory, speed and number of consumers, and the `app/etc/env.php` settings for parallel
  processing.

## [1.2.0] - 2026-10-19

### Added

- **Catalog Segment Size** setting: categories and products of a job are created by several
  workers in parallel.
- `var/log/sales_data_generator.log` with the time and memory of every batch.

### Changed

- Category and product saves that fail on a deadlock or a lock wait timeout are retried, and a batch
  blocked by a lock, e.g. by an indexer, is postponed instead of stopping its message chain.
- New table `perspective_sales_data_generator_category`; run `bin/magento setup:upgrade`.

### Fixed

- Memory growth while creating categories.

## [1.1.0] - 2026-10-19

### Added

- Warning under **Parallel Workers** when cron starts fewer consumers than there are workers.
- A consumer stops after 60 seconds without messages, so its memory is freed after a job ends.

## [1.0.0] - 2026-10-16

### Added

- Initial release.
