# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.1.1] - 2026-05-03

### Added

- Negative-balance transactions when the user opts in via configuration; transfer responses surface "insufficient balance" as the explicit blocker when the guard fires
- Outgoing emails redesigned and localised to the recipient's language, with a dev preview endpoint
- Imports confirm step resolves targets by id and can auto-create missing entities
- Configurations API exposes the full list of allowed keys in OpenAPI; inactivity reminder keys can now be set through it

### Changed

- Transaction attachments now record their real type (`image`, `pdf`, or `document`) derived from the upload's MIME, and the file validators on both transaction endpoints accept the formats users actually attach (images, PDFs, common office formats, plain text and CSV) up to 5 MB
- Notification mark-as-read accepts `client_id` and `read_at` so mobile-driven sync stays consistent across devices

### Fixed

- Restored the service-level negative-balance guard on transfers
- Pinned the budget progress test clock so date-sensitive assertions stop flaking depending on when the suite runs

## [1.1.0] - 2026-04-19

### Added

- Budgets with polymorphic owner (user today, workspace/couple tomorrow) and polymorphic targets across categories, groups, and wallets; weekly / monthly / yearly / custom period types; opt-in rollover with a `budget_period_states` history and a manual `close-period` endpoint; threshold and forecast alerts surfaced through the existing Reminder pipeline
- Explicit refund tracking via a `Refund` model and `Refundable` trait — marked refunds reduce net spend on matching budgets
- Syncable refunds and budget period states with paginated sync endpoints so mobile clients can mirror the tables offline
- Schema conformance service with `schema:verify` / `schema:conform` commands (auto-applied on migrate) and a middleware that returns 503 on drift
- AI chat sessions with messages and async processing, a Prism-based classifier that routes questions to SmartQL with a language-model fallback, and AI-generated chat titles after the first reply
- Advanced document importer with pluggable processors and configurable auto-creation of entities during import
- Transaction index now supports filtering and returns totals alongside the list
- Transfers gained `show`, `destroy`, update and soft-delete endpoints; transactions are embedded in Transfer responses and responses include the transfer client-generated id
- Insights and inactivity emails are now sent by default

### Changed

- Polymorphic `Remindable` trait replaces the one-off `budget_id` column on reminders, so future subjects can opt in without another migration
- Stats extracted from the controller into a dedicated `StatsService`; transfer transactions are excluded from aggregation
- Transfer relations are eager-loaded with cascade deletes, and transfer-transaction serialization is optimized
- Dev Docker setup migrated to the `laravel-docker-dev` image; obsolete dev Docker build CI job removed
- SmartQL is wired for Gemini and now requires an LLM key; LLM defaults harmonized across AI services; chat plumbing simplified
- OpenAPI docs regenerated

### Fixed

- Chat authorization hardened and data extraction made null-safe; classifier and fallback prompts made more data-aware; assistant replies are now reliably paired with the correct user question
- Exchange rates greater than zero are accepted during transfers
- Transfer and transaction responses return a fresh `syncState` on write

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
