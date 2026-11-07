# Changelog

All packages of this monorepo share one version. Per package details: its own `CHANGELOG.md`.

## 1.0.1

- `prestashopheaven/build-tools` 1.0.1: the coverage gate of `ps-module mono check` no longer counts the `if` of the
  `_PS_VERSION_` guard (PHP 8.1 reports it as a statement that runs before coverage starts).

## 1.0.0

- `prestashopheaven/build-tools` 1.0.0: `module-dev-tools` 1.26.0 moved into the package collection under its new name
  and the MIT license, with `ps-module mono` for the monorepos and `ps-module link` for the monorepo packages.
