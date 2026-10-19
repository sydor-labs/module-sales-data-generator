# Changelog

All notable changes to this module are documented in this file.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and the module uses [Semantic Versioning](https://semver.org/).

## [1.1.0] - 2026-10-19

### Added

- Warning under **Parallel Workers** when cron starts fewer consumers than there are workers.
- A consumer stops after 60 seconds without messages, so its memory is freed after a job ends.

## [1.0.0] - 2026-10-16

### Added

- Initial release.
