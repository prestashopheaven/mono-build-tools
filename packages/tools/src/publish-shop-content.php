<?php
/**
 * NOTICE OF LICENSE
 *
 * This source file is subject to the MIT License
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/MIT
 *
 * @author    prestahaven.com
 * @copyright Since 2026 George van Engers
 * @license   https://opensource.org/licenses/MIT MIT License
 */

/*
 * The content of a sales-shop product beyond texts and images (George, 2026-11-19), used by publish-shop.php:
 *   - features    the data sheet the PrestaHaven theme shows as special blocks: PrestaShop versions, Multistore
 *                 compatible, Supported languages, Has overrides, Demo frontend, Demo backend (one value per version or
 *                 language); the old plain "Multistore" feature is taken off the product
 *   - reference   H + the first letter of every other word of the repository name (heaven-price-drop-alert: HPDA),
 *                 longer when another product has it; unique in the shop
 *   - category    Home + All modules + the module's category (docs.json > sales.category, else suggested from the
 *                 Addons category); a new category only from a definition file with names and SEO texts per language
 *   - changelog   the English summary of every version in CHANGELOG.md (the lines under "## [X.Y.Z] - date", before
 *                 the first "###"), in every shop language, through the webservice of Product Changelog
 *                 (heavenproductchangelog_entries, 1.4.0+); versions that are not in CHANGELOG.md are left alone
 *   - extra       the extra fields of the theme module heaventhemefields (resource heaventhemefields_products):
 *                 features = the <ul> list of the description (then taken out of the description), documentation_url
 *                 = the manual on the docs site in that language
 * Each part can be updated on its own: publish shop --update --only=texts,images,features,reference,category,
 * changelog,extra. A part whose webservice resource the shop lacks is skipped with a note.
 */

declare(strict_types=1);

/** The parts of a product that publish shop --update can write (--only=...). */
const PUBLISH_SHOP_PARTS = ['texts', 'images', 'features', 'reference', 'category', 'changelog', 'extra'];

/** PrestaShop minor versions, oldest first: the values of the feature "PrestaShop versions". */
const PUBLISH_SHOP_PS_MINORS = ['1.7', '8.0', '8.1', '8.2', '9.0', '9.1', '9.2', '9.3', '9.4', '9.5'];

/** The webservice resource of Product Changelog (heavenproductchangelog 1.4.0+). */
const PUBLISH_SHOP_CHANGELOG_RESOURCE = 'heavenproductchangelog_entries';

/** The webservice resource of the theme's extra product fields (heaventhemefields 1.7.0+). */
const PUBLISH_SHOP_EXTRA_RESOURCE = 'heaventhemefields_products';

/**
 * The parts of an --only=a,b option (all parts without it).
 *
 * @param string[] $flags
 *
 * @return string[]
 *
 * @throws InvalidArgumentException for an unknown part
 */
function publish_shop_parts(array $flags): array
{
    foreach ($flags as $flag) {
        if (str_starts_with($flag, '--only=')) {
            $parts = array_values(array_filter(array_map(static fn (string $p): string => strtolower(trim($p, " \t\n\r")), explode(',', substr($flag, 7))), static fn (string $p): bool => $p !== ''));
            $unknown = array_diff($parts, PUBLISH_SHOP_PARTS);
            if ($parts === [] || $unknown !== []) {
                throw new InvalidArgumentException('--only takes ' . implode(', ', PUBLISH_SHOP_PARTS) . ($unknown !== [] ? ' (not: ' . implode(', ', $unknown) . ')' : ''));
            }

            return array_values(array_unique($parts));
        }
    }

    return PUBLISH_SHOP_PARTS;
}

/**
 * The customer changelog from CHANGELOG.md: per version (newest first) its date and the English summary lines between
 * the version heading and the first "###" subsection (one change per line; markdown marks and links removed). A version
 * without such lines is left out: the developer notes below "###" are not for customers.
 *
 * @return list<array{version: string, date: string, text: string}>
 */
function publish_shop_changelog(string $markdown): array
{
    $entries = [];
    $current = null;
    $inSummary = false;
    foreach (preg_split('/\R/', $markdown) ?: [] as $line) {
        if (preg_match('/^##\s+\[?v?(\d+\.\d+\.\d+)\]?(?:\s*[-–(]\s*(\d{4}-\d{2}-\d{2}))?/', $line, $m) === 1) {
            if ($current !== null) {
                $entries[] = $current;
            }
            $current = ['version' => $m[1], 'date' => $m[2] ?? '', 'lines' => []];
            $inSummary = true;
            continue;
        }
        if ($current === null) {
            continue;
        }
        if (preg_match('/^#{1,3}\s/', $line) === 1) {
            $inSummary = false;
            continue;
        }
        if (!$inSummary) {
            continue;
        }
        $text = trim($line, " \t");
        if ($text === '') {
            continue;
        }
        $text = (string) preg_replace('/^[-*]\s+/', '', $text);
        $text = (string) preg_replace('/\[([^\]]+)\]\([^)]+\)/', '$1', $text);
        $text = str_replace(['**', '`'], '', $text);
        $current['lines'][] = $text;
    }
    if ($current !== null) {
        $entries[] = $current;
    }
    $result = [];
    foreach ($entries as $entry) {
        if ($entry['lines'] !== []) {
            $result[] = ['version' => $entry['version'], 'date' => $entry['date'], 'text' => mb_substr(implode("\n", $entry['lines']), 0, 4000)];
        }
    }

    return $result;
}

/**
 * The product reference: H + the first letter of every word of the repository name after "heaven"
 * (heaven-price-drop-alert: HPDA). When another product has it, the last word gives one more letter at a time
 * (HPDAL, HPDALE ...), then a number. $own is the product's current reference: it may keep it.
 *
 * @param string[] $taken references of the other products
 */
function publish_shop_reference(string $repoSlug, array $taken, string $own = ''): string
{
    $words = array_values(array_filter(preg_split('/[^a-z0-9]+/', strtolower($repoSlug)) ?: [], static fn (string $w): bool => $w !== ''));
    if (($words[0] ?? '') === 'heaven') {
        array_shift($words);
    }
    if ($words === []) {
        $words = ['module'];
    }
    $base = 'H' . strtoupper(implode('', array_map(static fn (string $w): string => $w[0], $words)));
    $taken = array_map('strtoupper', $taken);
    $last = (string) end($words);
    $candidates = [$base];
    for ($i = 1; $i < strlen($last); ++$i) {
        $candidates[] = $base . strtoupper(substr($last, 1, $i));
    }
    foreach ($candidates as $candidate) {
        if (strlen($candidate) <= 8 && (!in_array($candidate, $taken, true) || strtoupper($own) === $candidate)) {
            return $candidate;
        }
    }
    for ($n = 2;; ++$n) {
        if (!in_array($base . $n, $taken, true)) {
            return $base . $n;
        }
    }
}

/**
 * The values of the feature "PrestaShop versions": every known minor version from the module's minimum up to the
 * newest version it is tested on (testing.json), e.g. 8.1, 8.2, 9.0, 9.1, 9.2.
 *
 * @return string[]
 */
function publish_shop_ps_versions(string $min, string $newestTested): array
{
    $from = implode('.', array_slice(explode('.', $min !== '' ? $min : '8.1'), 0, 2));
    $to = implode('.', array_slice(explode('.', $newestTested !== '' ? $newestTested : $from), 0, 2));

    return array_values(array_filter(PUBLISH_SHOP_PS_MINORS, static fn (string $v): bool => version_compare($v, $from, '>=') && version_compare($v, $to, '<=')));
}

/**
 * The features the PrestaHaven theme shows for a module (names in English as the shop has them; one product feature
 * per value). The demo URLs are left out when no demo shop is configured.
 *
 * @param array<string, mixed> $ctx publish context
 *
 * @return list<array{name: string, values: string[]}>
 */
function publish_shop_managed_features(array $ctx, string $demoIntroUrl): array
{
    $languages = array_values(array_unique(array_map(static fn (string $iso): string => strtoupper($iso), (array) $ctx['languages'])));
    sort($languages);
    $features = [
        ['name' => 'PrestaShop versions', 'values' => publish_shop_ps_versions((string) ($ctx['ps_min'] ?? ''), (string) ($ctx['ps_tested'] ?? ''))],
        ['name' => 'Multistore compatible', 'values' => ['yes']],
        ['name' => 'Supported languages', 'values' => $languages],
        ['name' => 'Has overrides', 'values' => [publish_shop_has_overrides((string) ($ctx['module_dir'] ?? '')) ? 'yes' : 'no']],
    ];
    if ($demoIntroUrl !== '') {
        $features[] = ['name' => 'Demo frontend', 'values' => [$demoIntroUrl]];
        $features[] = ['name' => 'Demo backend', 'values' => [$demoIntroUrl]];
    }

    return array_values(array_filter($features, static fn (array $f): bool => $f['values'] !== []));
}

/** The feature names publish shop takes off a product: replaced by "Multistore compatible" (George, 2026-11-19). */
const PUBLISH_SHOP_DROPPED_FEATURES = ['Multistore'];

/**
 * Whether the module ships overrides (a PHP file under override/).
 */
function publish_shop_has_overrides(string $moduleDir): bool
{
    if ($moduleDir === '' || !is_dir("$moduleDir/override")) {
        return false;
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$moduleDir/override", FilesystemIterator::SKIP_DOTS)) as $file) {
        if (str_ends_with($file->getFilename(), '.php') && $file->getFilename() !== 'index.php') {
            return true;
        }
    }

    return false;
}

/**
 * Splits the description HTML of publish_shop_html() into the paragraphs and the <ul> list of features (the list
 * goes to the theme's extra field "Features" when the shop has it).
 *
 * @return array{0: string, 1: string} [paragraphs, list] ('' when there is no list)
 */
function publish_shop_split_description(string $html): array
{
    if (preg_match('#<ul>.*?</ul>\s*#s', $html, $m, PREG_OFFSET_CAPTURE) !== 1) {
        return [$html, ''];
    }
    $list = trim($m[0][0], " \n\r\t");
    $rest = substr($html, 0, (int) $m[0][1]) . substr($html, (int) $m[0][1] + strlen($m[0][0]));

    return [$rest, $list . "\n"];
}

/**
 * The sales-shop category for a module: docs.json > sales.category (an id or an English name), else the one that fits
 * its Addons category (resources/publish/shop-categories.json). Null when nothing fits: then a category must be chosen
 * or created (publish shop --update --only=category with docs.json > sales.category or sales.new_category).
 *
 * @param array<string, mixed> $docs docs.json
 * @param array<int, string> $categories id => English name of the subcategories of "All modules"
 */
function publish_shop_suggest_category(array $docs, array $categories, ?array $map = null): ?int
{
    $wanted = $docs['sales']['category'] ?? null;
    if (is_int($wanted) || (is_string($wanted) && ctype_digit($wanted))) {
        return isset($categories[(int) $wanted]) ? (int) $wanted : null;
    }
    $byName = array_change_key_case(array_flip(array_map('strtolower', $categories)), CASE_LOWER);
    if (is_string($wanted) && $wanted !== '') {
        return $byName[strtolower($wanted)] ?? null;
    }
    $addons = strtolower((string) ($docs['marketing']['category'] ?? ''));
    $map ??= (array) (publish_json(__DIR__ . '/../resources/publish/shop-categories.json')['map'] ?? []);
    foreach ($map as $prefix => $name) {
        if ($addons !== '' && str_starts_with($addons, strtolower((string) $prefix)) && isset($byName[strtolower((string) $name)])) {
            return $byName[strtolower((string) $name)];
        }
    }

    return null;
}

/**
 * The documentation URL of a module in a language: <DOCS_SITE_URL>/<module>/index.php?lang=<iso>.
 */
function publish_shop_documentation_url(string $docsSiteUrl, string $module, string $iso): string
{
    return $docsSiteUrl === '' ? '' : rtrim($docsSiteUrl, '/') . '/' . $module . '/index.php?lang=' . rawurlencode($iso);
}

/**
 * Whether the shop has a webservice resource the key may use: a GET of it answers (false for an unknown resource or
 * a key without GET).
 *
 * @param array{api: string, key: string} $ws
 */
function publish_shop_has_resource(array $ws, string $resource): bool
{
    try {
        publish_shop_ws($ws, 'GET', $resource, ['limit' => '1']);

        return true;
    } catch (RuntimeException $e) {
        ps_vlog("resource $resource: not usable (" . publish_mask($e->getMessage()) . ')');

        return false;
    }
}

/**
 * The feature pairs (feature id, value id) of the managed features, made in the shop when missing, and the ids of the
 * features to take off the product (PUBLISH_SHOP_DROPPED_FEATURES).
 *
 * @param array{api: string, key: string} $ws
 * @param list<array{name: string, values: string[]}> $managed
 * @param array<int, array{iso: string, name: string, module: string|null}> $languages
 *
 * @return array{pairs: array<int, array{feature: int, value: int, label: string}>, drop: int[]}
 */
function publish_shop_feature_pairs(array $ws, array $managed, array $languages): array
{
    $definitions = [];
    foreach ($managed as $feature) {
        foreach ($feature['values'] as $value) {
            $definitions[] = ['name' => ['en' => $feature['name']], 'value' => ['en' => $value]];
        }
    }
    $pairs = $definitions === [] ? [] : publish_shop_features($ws, $definitions, $languages);
    $existing = (array) (publish_shop_ws($ws, 'GET', 'product_features', ['display' => 'full'])['body']['product_features'] ?? []);
    $drop = [];
    foreach (PUBLISH_SHOP_DROPPED_FEATURES as $name) {
        $id = publish_shop_match($existing, 'name', ['en' => $name]);
        if ($id !== null) {
            $drop[] = $id;
        }
    }

    return ['pairs' => $pairs, 'drop' => $drop];
}

/**
 * The category "All modules" (a child of Home) and its subcategories: [id of All modules, id => English name].
 * [0, []] when the shop has no such category.
 *
 * @param array{api: string, key: string} $ws
 * @param array<int, array{iso: string, name: string, module: string|null}> $languages
 *
 * @return array{0: int, 1: array<int, string>}
 */
function publish_shop_category_tree(array $ws, array $languages): array
{
    $english = 1;
    foreach ($languages as $id => $language) {
        if ($language['iso'] === 'en') {
            $english = (int) $id;
        }
    }
    $rows = (array) (publish_shop_ws($ws, 'GET', 'categories', ['display' => '[id,id_parent,name]'])['body']['categories'] ?? []);
    $name = static function ($value) use ($english): string {
        if (!is_array($value)) {
            return (string) $value;
        }
        foreach ($value as $l) {
            if ((int) ($l['id'] ?? 0) === $english) {
                return (string) ($l['value'] ?? '');
            }
        }

        return (string) ($value[0]['value'] ?? '');
    };
    $all = 0;
    foreach ($rows as $row) {
        if (is_array($row) && strtolower($name($row['name'] ?? '')) === 'all modules') {
            $all = (int) $row['id'];
        }
    }
    $children = [];
    foreach ($rows as $row) {
        if (is_array($row) && $all > 0 && (int) ($row['id_parent'] ?? 0) === $all) {
            $children[(int) $row['id']] = $name($row['name'] ?? '');
        }
    }

    return [$all, $children];
}

/**
 * Creates a subcategory of "All modules" from docs.json > sales.new_category: name, description, meta title and meta
 * description per module language (link_rewrite from the name), active. Returns its id.
 *
 * @param array{api: string, key: string} $ws
 * @param array<string, mixed> $definition field => module language => text
 * @param array<int, array{iso: string, name: string, module: string|null}> $languages
 *
 * @throws RuntimeException when the definition has no English name or the shop refuses it
 */
function publish_shop_create_category(array $ws, int $parent, array $definition, array $languages): int
{
    $names = array_map('strval', (array) ($definition['name'] ?? []));
    if (($names['en'] ?? '') === '') {
        throw new RuntimeException('docs.json > sales.new_category needs at least name.en (and description, meta_title, meta_description per language).');
    }
    $slugs = array_map(static fn (string $n): string => publish_shop_slug($n) ?: publish_shop_slug($names['en']), $names);
    $multilang = [
        'name' => publish_shop_lang_values($names, $languages, 128),
        'link_rewrite' => publish_shop_lang_values($slugs, $languages, 128),
        'description' => publish_shop_lang_values(array_map('strval', (array) ($definition['description'] ?? [])), $languages),
        'meta_title' => publish_shop_lang_values(array_map('strval', (array) ($definition['meta_title'] ?? $names)), $languages, 255),
        'meta_description' => publish_shop_lang_values(array_map('strval', (array) ($definition['meta_description'] ?? [])), $languages, 512),
    ];
    $multilang = array_filter($multilang, static fn (array $v): bool => $v !== [] && implode('', $v) !== '');
    $xml = publish_shop_xml('category', ['id_parent' => (string) $parent, 'active' => '1'], $multilang);
    $id = (int) (publish_shop_ws($ws, 'POST', 'categories', [], $xml)['body']['category']['id'] ?? 0);
    if ($id <= 0) {
        throw new RuntimeException('the shop did not return an id for the new category.');
    }

    return $id;
}

/**
 * Keeps the product's changelog in Product Changelog in step with CHANGELOG.md: a version that is missing is added, a
 * version whose English text or date differs gets the new ones; the text is the same English in every shop language
 * (George, 2026-11-19). Versions that are not in CHANGELOG.md stay. Returns lines for the report.
 *
 * @param array{api: string, key: string} $ws
 * @param list<array{version: string, date: string, text: string}> $entries
 * @param array<int, array{iso: string, name: string, module: string|null}> $languages
 *
 * @return string[]
 */
function publish_shop_sync_changelog(array $ws, int $productId, array $entries, array $languages, bool $dryRun): array
{
    $rows = (array) (publish_shop_ws($ws, 'GET', PUBLISH_SHOP_CHANGELOG_RESOURCE, ['display' => 'full', 'filter[id_product]' => "[$productId]"])['body'][PUBLISH_SHOP_CHANGELOG_RESOURCE] ?? []);
    $byVersion = [];
    foreach ($rows as $row) {
        if (is_array($row)) {
            $byVersion[(string) ($row['version'] ?? '')] = $row;
        }
    }
    $report = [];
    foreach (array_reverse($entries) as $entry) {
        $text = [];
        foreach (array_keys($languages) as $languageId) {
            $text[$languageId] = $entry['text'];
        }
        $fields = ['id_product' => (string) $productId, 'version' => $entry['version']] + ($entry['date'] !== '' ? ['date' => $entry['date']] : []);
        $row = $byVersion[$entry['version']] ?? null;
        if ($row === null) {
            $report[] = "add {$entry['version']}";
            if (!$dryRun) {
                publish_shop_ws($ws, 'POST', PUBLISH_SHOP_CHANGELOG_RESOURCE, [], publish_shop_xml('heavenproductchangelog_entry', $fields, ['text' => $text]));
            }
            continue;
        }
        $current = [];
        foreach ((array) ($row['text'] ?? []) as $l) {
            if (is_array($l)) {
                $current[(int) ($l['id'] ?? 0)] = (string) ($l['value'] ?? '');
            }
        }
        $differs = array_filter($text, static fn (string $t, int $id): bool => trim($current[$id] ?? '', " \n\r\t") !== trim($t, " \n\r\t"), ARRAY_FILTER_USE_BOTH);
        $sameDate = $entry['date'] === '' || (string) ($row['date'] ?? '') === $entry['date'];
        if ($differs === [] && $sameDate) {
            continue;
        }
        $report[] = "update {$entry['version']}" . ($differs === [] ? ' (date)' : '');
        if (!$dryRun) {
            unset($fields['id_product']);
            publish_shop_ws($ws, 'PUT', PUBLISH_SHOP_CHANGELOG_RESOURCE . '/' . (int) $row['id'], [], publish_shop_xml('heavenproductchangelog_entry', ['id' => (string) (int) $row['id']] + $fields, ['text' => $text]));
        }
    }

    return $report;
}

/**
 * Fills the theme's extra fields of the product (heaventhemefields_products): "features" with the <ul> list of the
 * description and "documentation_url" with the manual in that language. Only fields that are empty or differ are
 * sent. Returns lines for the report.
 *
 * @param array{api: string, key: string} $ws
 * @param array<int, string> $featuresHtml language id => <ul> list
 * @param array<int, string> $documentation language id => URL
 *
 * @return string[]
 */
function publish_shop_sync_extra(array $ws, int $productId, array $featuresHtml, array $documentation, bool $dryRun): array
{
    $body = (array) (publish_shop_ws($ws, 'GET', PUBLISH_SHOP_EXTRA_RESOURCE . "/$productId", [])['body'] ?? []);
    $node = (string) (array_key_first($body) ?? '');
    $record = is_array($body[$node] ?? null) ? $body[$node] : [];
    $multilang = [];
    $report = [];
    foreach (['features' => $featuresHtml, 'documentation_url' => $documentation] as $field => $wanted) {
        if (!array_key_exists($field, $record)) {
            $report[] = "$field: not in the resource, skipped";
            continue;
        }
        $current = [];
        foreach ((array) $record[$field] as $l) {
            if (is_array($l)) {
                $current[(int) ($l['id'] ?? 0)] = (string) ($l['value'] ?? '');
            }
        }
        $changed = array_filter($wanted, static fn (string $v, int $id): bool => $v !== '' && trim($current[$id] ?? '', " \n\r\t") !== trim($v, " \n\r\t"), ARRAY_FILTER_USE_BOTH);
        if ($changed !== []) {
            $multilang[$field] = $changed;
            $report[] = "$field: " . count($changed) . ' language(s)';
        }
    }
    if ($multilang !== [] && !$dryRun && $node !== '') {
        publish_shop_ws($ws, 'PUT', PUBLISH_SHOP_EXTRA_RESOURCE . "/$productId", [], publish_shop_xml($node, ['id' => (string) $productId], $multilang));
    }

    return $report;
}
