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
 * ps-module layout: the layout check of the Module-Factory's verify stage (proposal of the Cart Journey 1.4.1 pilot).
 * Opens one page of the module's test shop in Playwright (the docs-tools container of `ps-module docs`), in a back-office
 * or shop language of choice, and reports for every selector: how many elements match, whether the first is visible,
 * and its box. A full-page screenshot goes to .cache/layout/.
 *
 *   ps-module layout <ps-version> <path> [--selector=<css> ...] [--lang=<iso>] [--fo] [--viewport=desktop|tablet|mobile]
 *
 *   <path>   a back-office path like in docs.json (@configure, @menu:1, /sell/orders, index.php?controller=AdminX)
 *            or, with --fo, a front-office path (/, /en/3-clothes)
 *   --lang   the language of the employee (back office) or of the shop page (front office); installed when missing
 *
 * Exit code 0 = every selector found and visible, 1 = a selector missing or hidden (or the page failed), 2 = usage.
 */

declare(strict_types=1);

require_once __DIR__ . '/docs.php';

/**
 * The rows of the report: one line per measured selector.
 *
 * @param list<array<string, mixed>> $measure
 *
 * @return array{lines: list<string>, ok: bool}
 */
function layout_report(array $measure): array
{
    $lines = [];
    $ok = true;
    foreach ($measure as $m) {
        $box = is_array($m['box'] ?? null) ? sprintf('x %d, y %d, %d x %d', $m['box']['x'], $m['box']['y'], $m['box']['width'], $m['box']['height']) : 'no box';
        $state = ($m['count'] ?? 0) === 0 ? 'MISSING' : (!empty($m['visible']) ? 'visible' : 'HIDDEN');
        if ($state !== 'visible') {
            $ok = false;
        }
        $lines[] = sprintf('  %-8s %-40s %d match(es), %s%s', $state, (string) ($m['selector'] ?? '?'), (int) ($m['count'] ?? 0), $box, ($m['text'] ?? '') !== '' ? ' "' . $m['text'] . '"' : '');
    }

    return ['lines' => $lines, 'ok' => $ok];
}

/**
 * The command line. Returns the exit code.
 *
 * @param list<string> $args
 */
function layout_main(string $repoDir, string $moduleDir, array $args): int
{
    $options = ['selector' => []];
    $rest = [];
    foreach ($args as $arg) {
        if (preg_match('/^--([a-z]+)(?:=(.*))?$/s', $arg, $m) === 1) {
            if ($m[1] === 'selector') {
                $options['selector'][] = $m[2] ?? '';
            } else {
                $options[$m[1]] = $m[2] ?? '1';
            }
        } else {
            $rest[] = $arg;
        }
    }
    [$version, $path] = $rest + ['', ''];
    if (preg_match('/^\d+\.\d+\.\d+$/', $version) !== 1 || $path === '') {
        fwrite(STDERR, "Usage: ps-module layout <ps-version> <path> [--selector=<css> ...] [--lang=<iso>] [--fo] [--viewport=desktop|tablet|mobile]\n");

        return 2;
    }
    $ctx = docs_context($repoDir, $moduleDir);
    $iso = (string) ($options['lang'] ?? 'en');
    $ctx['languages'] = [$iso];
    $ctx['source'] = 'en';
    $ctx['config']['shop']['configuration'] = [];
    $id = 'layout-' . preg_replace('/[^a-z0-9]+/', '-', strtolower($path)) . '-' . $iso;
    $shot = [
        'id' => trim($id, '-'),
        'area' => isset($options['fo']) ? 'fo' : 'bo',
        'path' => $path,
        'viewport' => (string) ($options['viewport'] ?? 'desktop'),
        'full_page' => true,
        'measure' => array_values(array_filter($options['selector'], static fn (string $s): bool => $s !== '')),
    ];
    $image = docs_ensure_image();
    if (($image['error'] ?? '') !== '') {
        fwrite(STDERR, "ps-module layout: {$image['error']}\n");

        return 1;
    }
    // A shop that was not running is stopped again afterwards; the employee goes back to English.
    $run = docs_shots_on_shop($ctx, $version, [$shot], false);
    if (isset($run['error'])) {
        fwrite(STDERR, "ps-module layout: {$run['error']}\n");

        return 1;
    }
    $result = $run['results'][0] ?? null;
    if (!is_array($result) || empty($result['ok'])) {
        fwrite(STDERR, 'ps-module layout: the page failed: ' . (is_array($result) ? ($result['error'] ?? '?') : 'no result') . "\n");

        return 1;
    }
    $out = "$repoDir/.cache/layout";
    @mkdir($out, 0775, true);
    $source = $ctx['docs'] . "/screenshots/$iso/{$shot['id']}.png";
    $target = "$out/{$shot['id']}-ps" . str_replace('.', '', $version) . '.png';
    if (is_file($source)) {
        rename($source, $target);
    }
    file_put_contents(preg_replace('/\.png$/', '.json', $target), json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    $report = layout_report((array) ($result['measure'] ?? []));
    echo "Layout of $path (PrestaShop $version, $iso, {$shot['viewport']}): " . (string) ($result['url'] ?? '') . "\n";
    echo implode("\n", $report['lines']) . ($report['lines'] === [] ? '  (no --selector given)' : '') . "\n";
    echo "Screenshot: $target\n";

    return $report['ok'] ? 0 : 1;
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {
    exit(layout_main((string) ($argv[1] ?? ''), (string) ($argv[2] ?? ''), array_slice($argv, 3)));
}
