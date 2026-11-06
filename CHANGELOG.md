# Changelog

All notable changes to this project are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added
- Article contracts (`ArticleInterface`, `ArticleStorageInterface`) with bundled in-memory and Doctrine storages.
- Sections and locales declared in bundle configuration.
- Article workflow: draft, in_review, scheduled, published, archived, with `ArticleTransitioned` events.
- Scoped API keys (`articles:read`, `articles:draft`, `articles:approve`, `articles:publish`, `stats:read`, `keys:manage`), a bearer-token authenticator and the `agent-publishing:api-key:create` command.
- REST API: sections, article list/create/read/update, submit, approve, reject, publish, archive.
