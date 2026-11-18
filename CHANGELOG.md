# Changelog

All packages of this monorepo share one version. Per package details: its own `CHANGELOG.md`.

## 1.2.0

- `prestashopheaven/build-tools` 1.2.0: the intake gate checks `.factory/spec.json` against
  `resources/factory/spec.schema.json` (everything the autonomous run needs) and asks an acceptance scenario per need;
  the stage order is now intake, research, design, build, content, verify, release, retro.

## 1.1.0

- `prestashopheaven/build-tools` 1.1.0: the Module-Factory pipeline (`ps-module factory`: state, gates in order with
  stamps on their inputs, final approval, reviewer verdicts, waiting for a package on Packagist), the CPU guard (heavy
  work starts below 60 % CPU, limited workers, one test-shop stack at a time, CPU limits per container) and
  `ps-module rules` (the rules registry of mono-build-rules).

## 1.0.4

- `prestashopheaven/build-tools` 1.0.4: the multistore check of the configuration page (`ps-module shops run`) reads
  the field after a multistore checkbox again. Its form parser let every `<input>` swallow the page up to the next
  `</select>` or `</textarea>`, so in a shop or group context the checked field was lost and the check reported "the page
  does not show the own value" for a page that does.

## 1.0.3

- `prestashopheaven/build-tools` 1.0.3: `ps-module mono register` adds the Packagist webhook to every split repo, so
  Packagist updates on each push and no longer shows the package as not auto-updated.

## 1.0.2

- `prestashopheaven/build-tools` 1.0.2: `PS_TEST_BASE_PORT` overrides `testing.json > base_port` (CI uses 20000,
  outside the runners' ephemeral port range).

## 1.0.1

- `prestashopheaven/build-tools` 1.0.1: the coverage gate of `ps-module mono check` no longer counts the `if` of the
  `_PS_VERSION_` guard (PHP 8.1 reports it as a statement that runs before coverage starts).

## 1.0.0

- `prestashopheaven/build-tools` 1.0.0: `module-dev-tools` 1.26.0 moved into the package collection under its new name
  and the MIT license, with `ps-module mono` for the monorepos and `ps-module link` for the monorepo packages.
