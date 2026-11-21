# Changelog

All packages of this monorepo share one version. Per package details: its own `CHANGELOG.md`.

## 1.9.2

- `prestashopheaven/build-tools` 1.9.2: the multistore form check skips grid filter forms and, when Configure opens a
  list (heavenfooterpaymenticons), follows its link to the module's settings page.

## 1.9.1

- `prestashopheaven/build-tools` 1.9.1: the multistore form check keeps numeric markers inside `min`/`max`, counts a
  save that stored nothing as refused (the next field is tried) and ignores red alerts the page already showed before
  saving (heavencmsblog and heavenbingindexnow failed it after the move to 1.9).

## 1.9.0

- `prestashopheaven/build-tools` 1.9.0: `publish shop` keeps the whole sales-shop product up to date (George,
  2026-11-19): the theme features (PrestaShop versions, Multistore compatible, languages, overrides, demo links), a
  short unique reference (HPDA), the category (suggested, or created from docs.json), the changelog from CHANGELOG.md
  (Product Changelog 1.4.0) and the theme extra fields (heaventhemefields 1.7.0); `--update --only=<parts>` and
  `--all`; the friendly URL of an existing product is never changed.

## 1.8.0

- `prestashopheaven/build-tools` 1.8.0: the Bing IndexNow retro (proposals 2, 3, 5, 6, 7, 14, 15): TST-07 counts only real
  dispatches in code (also a named constructor), the verify gate wants every acceptance scenario named by a test
  method, the multistore form check also fills email inputs, the smoke checks match PHP error output instead of the
  words, the release gate names the sales-sync pin step, publish fetches the tags before the release check, and
  publish demo warms the home page before its test.

## 1.7.1

- Re-release of the 1.6.2 change above 1.7.0 (v1.6.2 was tagged after v1.7.0, so Composer picked 1.7.0 without it).

- `prestashopheaven/build-tools` 1.6.2: the multistore form check also tries number inputs (with the numeric marker).

## 1.7.0

- `prestashopheaven/build-tools` 1.7.0: shared shop and docs instances with a job queue (workspace
  `docs/factory/queue.md`): `ps-module instance` (status, enable/disable, template, down) and `ps-module queue`. With
  the instances on, `shops run|up|upgrade|multistore|integration|behat` and the docs screenshots wait for their turn
  (at most N per pool, one per module, `testing.json > exclusive` alone) and run on a slot of one shared instance,
  made from a template per PrestaShop version; the docs use a template with their languages installed. Off by default
  and never in CI.

## 1.6.1

- `prestashopheaven/build-tools` 1.6.1: the multistore round tries the text fields of the settings form in order until
  the form saves one (a field with its own validation, such as an email address, refused the test value).

## 1.6.0

- `prestashopheaven/build-tools` 1.6.0: `ps-module layout` (the layout check of the verify stage, in a language of
  choice), `ps-module factory abandon`, and `publish status --check` lists the module version per place (test shop,
  demo shop, sales shop).

## 1.5.1

- `prestashopheaven/build-tools` 1.5.1: `ps-module publish` no longer has an Addons step and no longer purges the
  Cloudflare cache; both are manual steps outside the tools (George, 2026-11-18). The spec schema has no publish.addons.

## 1.5.0

- `prestashopheaven/build-tools` 1.5.0: the CPU guard is removed (George, 2026-11-18): no waiting for the CPU load, no
  one-shop-stack-at-a-time lock, no CPU limits per container, PHPStan with its own number of workers again.

## 1.4.1

- `prestashopheaven/build-tools` 1.4.1: the design gate accepts empty lists (a change that adds no command, query,
  hook, setting or table); every field must still be present, and `covers` (plus `contexts` for a new module) filled.

## 1.4.0

- `prestashopheaven/build-tools` 1.4.0: the release gate asks the update server whether the version is accepted and
  whether the sales shop received its zip (signed with the release key; update-server endpoint
  `/internal/factory/v1/releases/{module}/{version}`).

## 1.3.0

- `prestashopheaven/build-tools` 1.3.0: `ps-module make cqrs` (commands, queries, handlers and their bus tags from
  `.factory/design.json`) and `ps-module find --pitfalls` (the pitfalls index of mono-build-knowledge 1.1.0).

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
