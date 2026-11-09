# Changelog

All notable changes to `laravel-personalized-sort` will be documented in this file.

## Unreleased

- Implemented per-model weighted scoring with callbacks, container-resolved scorers, and explicit user context.
- Added preference providers, opt-in user/context-specific caching, and explicit cache invalidation.
- Added collection ranking, paginator annotation, and Eloquent recommendation pagination with candidate limits and deterministic tie-breaking.
- Protected shared result models by returning scored copies and preserved database ordering when ranking is disabled.
- Added a tested Sportboeker adapter and complete installation and usage documentation.
- Replaced incomplete signal/vector/storage scaffolding with the supported scoring API.
- Corrected the PHP/Testbench CI matrix and added read-only style and static-analysis checks on pull requests.
- Set the release's supported range to PHP 8.4+ and Laravel 12/13, retaining Composer security advisory blocking.
