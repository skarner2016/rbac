# Changelog

All notable changes to `skarner2016/rbac` will be documented in this file.

## [Unreleased]

## [0.1.0] - 2026-10-25

### Added

- Initial release.
- Role-only permission grants; no direct subject permissions.
- Unified permission tree with `menu`, `route`, and `button` types.
- Ancestor completion for menu trees and per-menu granted code aggregation.
- Case-insensitive permission and role codes (ASCII).
- Route authorization via `Access::canRoute()`.
- Super role support and disabled role/permission handling.
- Instance-level cache with automatic invalidation on writes.
- MySQL/MariaDB and SQLite schema installer.
- PHPUnit test suite and GitHub Actions workflow.
