# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.2] - 2026-03-06

### Added

- Account deletion, admin endpoints, and CLI commands
- Locale-based translation for default categories and groups
- File access endpoint with link attribute on transactions
- Index and update endpoints for transfers
- Duplicate transaction prevention during transfer sync
- Code coverage checks and test database configuration
- Static analysis tooling (phpmd, phpstan, phpcs)

### Fixed

- Transactions and transfers now ordered by datetime desc, created_at desc
- Flaky test failures in StatsControllerTest by freezing time
- Integer ID handling via PDO options and wallet ID casting
- Multipart/form-data encoding for transaction file uploads
- Duplicate transfers prevented during mobile sync

### Changed

- Updated OpenAPI documentation
- Customized phpcs rules for less aggressive linting

## [1.0.1] - 2025-01-18

### Fixed

- Transactions API now returns newest transactions first (ordered by created_at)
- User model now allows mass assignment of email_verified_at field

## [1.0.0] - 2025-01-13

### Added

- REST API for transactions, wallets, categories, groups, and parties
- User authentication via Laravel Sanctum
- OAuth support for social login
- File attachments for transactions
- Transfer support between wallets
- Statistics and reporting endpoints
- CSV/JSON import functionality
- AI chat integration
- OpenAPI documentation
- Syncable trait for offline-first mobile support
