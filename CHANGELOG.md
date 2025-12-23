# Changelog

All notable changes to `mcp-tools` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- Comprehensive tests for API contract generation command (#006, #025)
- Contract structure validation in MCP tools (#014)
- Improved error handling in contract generation with `--strict` and `--detailed` options (#008)
- Configurable paths and namespaces via `config/mcp-tools.php` (#007)
- Shared `ContractLoader` service to eliminate code duplication (#024)
- Enhanced route search functionality - searches in API version, auth type, schemas, and rate limits (#013)
- Pagination support in `ListApiRoutes` with `page` and `offset` parameters (#015)
- Optional metadata inclusion in `ListApiRoutes` via `include_metadata` parameter (#017)
- Configuration file for package customization

### Changed
- Command signature standardized to `api:generate-contract` (was inconsistent) (#004)
- Paths and namespaces are now configurable instead of hardcoded (#007)
- Error messages are more descriptive and actionable (#008)
- Contract loading logic extracted to shared service (#024)

### Fixed
- Inconsistency in README about MCP tool registration (#001)
- Inconsistency between command signature and documentation (#004)
- Documentation inconsistencies between README and code (#040)
- Deprecated `setAccessible()` usage in tests (PHP 8.4+ compatibility)

### Documentation
- Added `CONTRIBUTING.md` guide (#033)
- Updated README with correct manual registration instructions (#001)
- Improved error messages with suggestions (#008)

## v0.1.0 - 2025-12-14

### What's Changed

* Bump actions/checkout from 5 to 6 by @dependabot[bot] in https://github.com/abr4xas/mcp-tools/pull/1

### New Contributors

* @dependabot[bot] made their first contribution in https://github.com/abr4xas/mcp-tools/pull/1

**Full Changelog**: https://github.com/abr4xas/mcp-tools/commits/v0.1.0
