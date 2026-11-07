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

declare(strict_types=1);

/*
 * ps-module mono: the monorepos of the PrestaHaven package collection (workspace docs/packages, rules PKG-03..08, PKG-13).
 *
 *   ps-module mono new <runtime|build> <category> "<description>"   a new monorepo libraries/mono-<kind>-<category>
 *   ps-module mono package <dir> "<description>" "<use when>" <keyword,...>   a new package in the current monorepo
 *   ps-module mono validate | packages | matrix                       the rules / the package list (JSON)
 *   ps-module mono check                                               the definition of done of every package
 *   ps-module mono check-release <X.Y.Z> | set-version <X.Y.Z>          release checks / set the one version
 *   ps-module mono register [--dry-run]                                GitHub repos, deploy keys and secrets
 *
 * The rules of a monorepo:
 * - root composer.json: name prestashopheaven/mono-<kind>-<category>, "version" X.Y.Z (the one version, D2),
 *   extra.prestashopheaven.monorepo.kind runtime|build, a path repository packages/* that gives every package X.Y.Z;
 * - packages/<dir>/composer.json: name prestashopheaven/<kind>-<dir>, no "version", license MIT, autoload psr-4
 *   PrestaShopHeaven\<Runtime|Build>\<Studly dir>\ => src/, branch alias dev-main => X.x-dev,
 *   extra.prestashopheaven.kind/use-when/keywords; packages of the same monorepo require each other as ^X.Y;
 *   a runtime package never requires a build package.
 *
 * Exit code 0 = ok, 1 = a rule or check failed, 2 = wrong usage.
 */

namespace PrestaHavenMono;

const VENDOR = 'prestashopheaven';
const LICENSES = ['MIT'];

/**
 * Runs a command line; returns the exit code.
 *
 * @param list<string> $args
 */
function main(array $args): int
{
    $root = '.';
    $rest = [];
    $dryRun = false;
    foreach ($args as $arg) {
        if (str_starts_with($arg, '--root=')) {
            $root = substr($arg, 7);
        } elseif ($arg === '--dry-run') {
            $dryRun = true;
        } else {
            $rest[] = $arg;
        }
    }
    $command = $rest[0] ?? '';
    $root = rtrim(str_replace('\\', '/', $root), '/');

    try {
        switch ($command) {
            case 'packages':
                echo json_encode(array_map(static fn (array $p): array => array_diff_key($p, ['composer' => true]), packages($root)), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";

                return 0;
            case 'matrix':
                echo json_encode(array_map(static fn (array $p): array => ['dir' => $p['dir'], 'repo' => $p['repo'], 'key' => $p['key']], packages($root)), JSON_UNESCAPED_SLASHES), "\n";

                return 0;
            case 'validate':
                return report(validate($root));
            case 'check-release':
                requireVersion($rest[1] ?? '');

                return report(checkRelease($root, $rest[1], tagExists($root, 'v' . $rest[1])));
            case 'set-version':
                requireVersion($rest[1] ?? '');
                setVersion($root, $rest[1]);
                echo "Version set to {$rest[1]}.\n";

                return report(validate($root));
            case 'new':
                $dir = newMonorepo(workspaceDir($root) . '/libraries', $rest[1] ?? '', $rest[2] ?? '', $rest[3] ?? '');
                echo "Created $dir. Add packages with: ps-module mono package <dir> ... (in that folder).\n";

                return 0;
            case 'package':
                $dir = newPackage($root, $rest[1] ?? '', $rest[2] ?? '', $rest[3] ?? '', array_values(array_filter(array_map('trim', explode(',', $rest[4] ?? '')))));
                echo "Created $dir.\n";

                return report(validate($root));
            case 'check':
                return check($root);
            case 'register':
                return register($root, $dryRun);
            case 'fixture-tests':
                $copied = fixtureTests((string) getcwd());
                echo "Copied the integration tests of $copied package(s) into tests/Packages.\n";

                return 0;
            default:
                fwrite(STDERR, "Usage: ps-module mono new|package|validate|packages|matrix|check|check-release <X.Y.Z>|set-version <X.Y.Z>|register [--dry-run]\n");

                return 2;
        }
    } catch (\InvalidArgumentException $e) {
        fwrite(STDERR, $e->getMessage() . "\n");

        return 2;
    } catch (\RuntimeException $e) {
        fwrite(STDERR, $e->getMessage() . "\n");

        return 1;
    }
}

/**
 * Prints the problems; 0 when there are none.
 *
 * @param list<string> $problems
 */
function report(array $problems): int
{
    foreach ($problems as $problem) {
        fwrite(STDERR, "- $problem\n");
    }
    if ($problems === []) {
        echo "OK.\n";

        return 0;
    }

    return 1;
}

/**
 * Throws when the version is not X.Y.Z.
 *
 * @throws \InvalidArgumentException when the version is not X.Y.Z
 */
function requireVersion(string $version): void
{
    if (!preg_match('/^\d+\.\d+\.\d+$/', $version)) {
        throw new \InvalidArgumentException("The version must be X.Y.Z, got \"$version\".");
    }
}

/**
 * Reads a JSON file as an array.
 *
 * @return array<string, mixed>
 *
 * @throws \RuntimeException when the file is missing or not a JSON object
 */
function readJson(string $file): array
{
    $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
    if (!is_array($data)) {
        throw new \RuntimeException("$file is missing or not valid JSON.");
    }

    return $data;
}

/**
 * Writes an array as composer-style JSON (4 spaces, slashes unescaped, trailing newline).
 *
 * @param array<string, mixed> $data
 */
function writeJson(string $file, array $data): void
{
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
}

/**
 * The root composer.json of the monorepo.
 *
 * @return array<string, mixed>
 */
function root(string $root): array
{
    return readJson($root . '/composer.json');
}

/**
 * The kind of the monorepo: runtime or build.
 *
 * @throws \RuntimeException when extra.prestashopheaven.monorepo.kind is missing or unknown
 */
function kind(string $root): string
{
    $kind = root($root)['extra']['prestashopheaven']['monorepo']['kind'] ?? '';
    if (!in_array($kind, ['runtime', 'build'], true)) {
        throw new \RuntimeException('composer.json: extra.prestashopheaven.monorepo.kind must be "runtime" or "build".');
    }

    return $kind;
}

/**
 * The packages of the monorepo, sorted by folder.
 *
 * @return list<array{dir: string, name: string, repo: string, key: string, composer: array<string, mixed>}>
 */
function packages(string $root): array
{
    $packages = [];
    foreach (glob($root . '/packages/*/composer.json') ?: [] as $file) {
        $composer = readJson($file);
        $name = (string) ($composer['name'] ?? '');
        $packages[] = [
            'dir' => 'packages/' . basename(dirname($file)),
            'name' => $name,
            'repo' => $name,
            'key' => strtoupper(str_replace(['/', '-'], ['__', '_'], (string) preg_replace('#^' . VENDOR . '/#', '', $name))),
            'composer' => $composer,
        ];
    }
    usort($packages, static fn (array $a, array $b): int => strcmp($a['dir'], $b['dir']));

    return $packages;
}

/**
 * StudlyCase of a package folder: "shared-kernel" => "SharedKernel".
 */
function studly(string $dir): string
{
    return str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $dir)));
}

/**
 * The checks that hold at any time (not only at a release).
 *
 * @return list<string> the problems found
 */
function validate(string $root): array
{
    $problems = [];
    $composer = root($root);
    $kind = kind($root);
    $version = (string) ($composer['version'] ?? '');
    if (!preg_match('/^\d+\.\d+\.\d+$/', $version)) {
        $problems[] = 'composer.json: "version" must be X.Y.Z (the one version of the monorepo).';

        return $problems;
    }
    [$major, $minor] = array_map('intval', explode('.', $version));
    if (!preg_match('#^' . VENDOR . '/mono-' . $kind . '-[a-z0-9-]+$#', (string) ($composer['name'] ?? ''))) {
        $problems[] = 'composer.json: name must be ' . VENDOR . "/mono-$kind-<category>.";
    }
    $packages = packages($root);
    if ($packages === []) {
        $problems[] = 'No packages in packages/*/composer.json.';
    }
    $own = array_column($packages, 'name');
    $expected = array_fill_keys($own, $version);
    ksort($expected);
    if (rootPathRepositoryVersions($composer) !== $expected) {
        $problems[] = 'composer.json: the path repository packages/* must list every package with version ' . $version . ' (run ps-module mono set-version ' . $version . ').';
    }
    $namespaceRoot = 'PrestaShopHeaven\\' . ucfirst($kind) . '\\';
    foreach ($packages as $package) {
        $p = $package['composer'];
        $dir = basename($package['dir']);
        $where = $package['dir'] . '/composer.json';
        if (!isset($composer['require'][$package['name']])) {
            $problems[] = "composer.json: require {$package['name']} (\"*\") so the monorepo installs and tests it.";
        }
        if ($package['name'] !== VENDOR . "/$kind-$dir") {
            $problems[] = "$where: name must be " . VENDOR . "/$kind-$dir (kind and folder).";
        }
        if (isset($p['version'])) {
            $problems[] = "$where: no \"version\" (the tag gives the version; the root composer.json holds it).";
        }
        if (!in_array($p['license'] ?? '', LICENSES, true)) {
            $problems[] = "$where: license must be MIT.";
        }
        $namespace = $namespaceRoot . studly($dir) . '\\';
        if (($p['autoload']['psr-4'][$namespace] ?? null) !== 'src/') {
            $problems[] = "$where: autoload psr-4 must map $namespace to src/.";
        }
        if (($p['extra']['branch-alias']['dev-main'] ?? null) !== "$major.x-dev") {
            $problems[] = "$where: extra.branch-alias.dev-main must be $major.x-dev.";
        }
        $meta = $p['extra']['prestashopheaven'] ?? [];
        if (($meta['kind'] ?? null) !== $kind) {
            $problems[] = "$where: extra.prestashopheaven.kind must be $kind.";
        }
        if (trim((string) ($meta['use-when'] ?? '')) === '') {
            $problems[] = "$where: extra.prestashopheaven.use-when is empty (input for the package index, PKG-13).";
        }
        if (!is_array($meta['keywords'] ?? null) || $meta['keywords'] === []) {
            $problems[] = "$where: extra.prestashopheaven.keywords is empty (input for the package index, PKG-13).";
        }
        if (isset($meta['coverage-min']) && trim((string) ($meta['coverage-reason'] ?? '')) === '') {
            $problems[] = "$where: extra.prestashopheaven.coverage-min lowers the coverage gate (PKG-08) and needs a coverage-reason.";
        }
        // Classes that need PrestaShop are left out of the unit coverage only with an integration test of their own,
        // run in real shops by the fixture module (tests/Integration/<path>Test.php).
        foreach ((array) ($meta['integration-tested'] ?? []) as $file) {
            $file = (string) $file;
            if (!str_starts_with($file, 'src/') || !is_file("$root/{$package['dir']}/$file")) {
                $problems[] = "$where: extra.prestashopheaven.integration-tested lists $file, which is not a file in src/.";
            } elseif (!is_file("$root/{$package['dir']}/tests/Integration/" . substr($file, 4, -4) . 'Test.php')) {
                $problems[] = "$where: $file is integration-tested but has no tests/Integration/" . substr($file, 4, -4) . 'Test.php.';
            }
        }
        if (trim((string) ($p['description'] ?? '')) === '') {
            $problems[] = "$where: description is empty.";
        }
        foreach (['README.md', 'CHANGELOG.md', 'LICENSE.md', '.gitattributes'] as $file) {
            if (!is_file($root . '/' . $package['dir'] . '/' . $file)) {
                $problems[] = $package['dir'] . "/$file is missing.";
            }
        }
        foreach ((array) ($p['require'] ?? []) as $dependency => $constraint) {
            if (in_array($dependency, $own, true) && $constraint !== "^$major.$minor") {
                $problems[] = "$where: $dependency is in this monorepo, require it as ^$major.$minor (one version).";
            }
            if ($kind === 'runtime' && str_starts_with((string) $dependency, VENDOR . '/build-')) {
                $problems[] = "$where: a runtime package may never require a build package ($dependency, PKG-03).";
            }
        }
    }

    return $problems;
}

/**
 * The checks of a release: validate() plus the version, the changelog and the tag.
 *
 * @return list<string> the problems found
 */
function checkRelease(string $root, string $version, bool $tagExists): array
{
    $problems = validate($root);
    $current = (string) (root($root)['version'] ?? '');
    if ($current !== $version) {
        $problems[] = "composer.json: \"version\" is \"$current\", the release is $version (run ps-module mono set-version $version).";
    }
    $changelog = is_file($root . '/CHANGELOG.md') ? (string) file_get_contents($root . '/CHANGELOG.md') : '';
    if (!preg_match('/^## \[?' . preg_quote($version, '/') . '\]?/m', $changelog)) {
        $problems[] = "CHANGELOG.md has no section \"## $version\".";
    }
    if ($tagExists) {
        $problems[] = "Tag v$version already exists.";
    }
    foreach ((array) (root($root)['repositories'] ?? []) as $repository) {
        if (is_array($repository) && ($repository['type'] ?? '') === 'path' && ($repository['url'] ?? '') !== 'packages/*') {
            $problems[] = "composer.json: linked path repository {$repository['url']} (run ps-module unlink; a release uses Packagist).";
        }
    }

    return $problems;
}

/**
 * True when the git tag exists in the repository at $root.
 */
function tagExists(string $root, string $tag): bool
{
    $out = [];
    exec('git -C ' . escapeshellarg($root) . ' tag --list ' . escapeshellarg($tag) . ' 2>&1', $out, $code);

    return $code === 0 && in_array($tag, array_map('trim', $out), true);
}

/**
 * Sets the one version: root "version" and path repository, every branch alias and every internal constraint.
 */
function setVersion(string $root, string $version): void
{
    [$major, $minor] = array_map('intval', explode('.', $version));
    $composer = root($root);
    $composer['version'] = $version;
    $own = array_column(packages($root), 'name');
    $versions = array_fill_keys($own, $version);
    ksort($versions);
    $repositories = array_values(array_filter(
        (array) ($composer['repositories'] ?? []),
        static fn ($r): bool => !(is_array($r) && ($r['type'] ?? '') === 'path' && ($r['url'] ?? '') === 'packages/*')
    ));
    array_unshift($repositories, ['type' => 'path', 'url' => 'packages/*', 'options' => ['symlink' => true, 'versions' => $versions]]);
    $composer['repositories'] = $repositories;
    writeJson($root . '/composer.json', $composer);
    foreach (packages($root) as $package) {
        $p = $package['composer'];
        $p['extra']['branch-alias']['dev-main'] = "$major.x-dev";
        foreach (array_keys((array) ($p['require'] ?? [])) as $dependency) {
            if (in_array($dependency, $own, true)) {
                $p['require'][$dependency] = "^$major.$minor";
            }
        }
        writeJson($root . '/' . $package['dir'] . '/composer.json', $p);
    }
    // Fixture modules (tests/fixture/<module>) require the packages of this monorepo at exactly its version.
    foreach (glob($root . '/tests/fixture/*/composer.json') ?: [] as $file) {
        $fixture = readJson($file);
        foreach ((array) ($fixture['repositories'] ?? []) as $i => $repository) {
            if (is_array($repository) && ($repository['url'] ?? '') === '../../../packages/*') {
                $fixture['repositories'][$i]['options']['versions'] = $versions;
            }
        }
        foreach (array_keys((array) ($fixture['require'] ?? [])) as $dependency) {
            if (in_array($dependency, $own, true)) {
                $fixture['require'][$dependency] = $version;
            }
        }
        writeJson($file, $fixture);
    }
}

/**
 * The versions the root's path repository packages/* gives the packages (empty when there is none), sorted by name.
 *
 * @param array<string, mixed> $composer
 *
 * @return array<string, string>
 */
function rootPathRepositoryVersions(array $composer): array
{
    foreach ((array) ($composer['repositories'] ?? []) as $repository) {
        if (is_array($repository) && ($repository['type'] ?? '') === 'path' && ($repository['url'] ?? '') === 'packages/*') {
            $versions = (array) ($repository['options']['versions'] ?? []);
            ksort($versions);

            return $versions;
        }
    }

    return [];
}

/**
 * The workspace folder: the nearest parent of $from with a libraries/ folder.
 *
 * @throws \RuntimeException when there is none
 */
function workspaceDir(string $from): string
{
    $dir = rtrim(str_replace('\\', '/', (string) (realpath($from) ?: $from)), '/');
    for ($i = 0; $i < 8 && $dir !== dirname($dir); ++$i, $dir = dirname($dir)) {
        if (is_dir($dir . '/libraries') && is_dir($dir . '/modules')) {
            return $dir;
        }
    }
    throw new \RuntimeException("No workspace (a folder with libraries/ and modules/) above $from.");
}

/**
 * Fills a template from resources/mono with {{KEY}} values.
 *
 * @param array<string, string> $values
 */
function template(string $name, array $values): string
{
    $text = (string) file_get_contents(dirname(__DIR__) . '/resources/mono/' . $name);

    return strtr($text, array_combine(array_map(static fn (string $k): string => '{{' . $k . '}}', array_keys($values)), array_values($values)));
}

/**
 * Creates libraries/mono-<kind>-<category> with its root files and workflows; returns its folder.
 *
 * @throws \InvalidArgumentException on a wrong kind, category or empty description
 * @throws \RuntimeException when the folder exists
 */
function newMonorepo(string $libraries, string $kind, string $category, string $description): string
{
    if (!in_array($kind, ['runtime', 'build'], true) || !preg_match('/^[a-z][a-z0-9-]*$/', $category) || trim($description) === '') {
        throw new \InvalidArgumentException('Usage: ps-module mono new <runtime|build> <category> "<description>" (category: lowercase letters, digits, dashes).');
    }
    $dir = "$libraries/mono-$kind-$category";
    if (file_exists($dir)) {
        throw new \RuntimeException("$dir exists already.");
    }
    $values = ['KIND' => $kind, 'CATEGORY' => $category, 'DESCRIPTION' => $description, 'YEAR' => date('Y'), 'DATE' => date('Y-m-d')];
    $files = [
        'composer.json' => 'root-composer.json',
        'README.md' => 'root-README.md',
        'CHANGELOG.md' => 'root-CHANGELOG.md',
        'LICENSE.md' => 'LICENSE.md',
        '.gitignore' => 'root-gitignore',
        '.gitattributes' => 'root-gitattributes',
        '.github/workflows/ci.yml' => 'root-ci.yml',
        '.github/workflows/release.yml' => 'root-release.yml',
    ];
    foreach ($files as $target => $source) {
        @mkdir(dirname("$dir/$target"), 0777, true);
        file_put_contents("$dir/$target", template($source, $values));
    }
    @mkdir("$dir/packages", 0777, true);
    exec('git -C ' . escapeshellarg($dir) . ' init -b main -q');

    return $dir;
}

/**
 * Creates packages/<dir> in the monorepo at $root, then refreshes the one version; returns its folder.
 *
 * @param list<string> $keywords
 *
 * @throws \InvalidArgumentException on a wrong folder name or empty texts
 * @throws \RuntimeException when the package exists
 */
function newPackage(string $root, string $dir, string $description, string $useWhen, array $keywords): string
{
    if (!preg_match('/^[a-z][a-z0-9-]*$/', $dir) || trim($description) === '' || trim($useWhen) === '' || $keywords === []) {
        throw new \InvalidArgumentException('Usage: ps-module mono package <dir> "<description>" "<use when>" <keyword,keyword,...>');
    }
    $kind = kind($root);
    $path = "$root/packages/$dir";
    if (file_exists($path)) {
        throw new \RuntimeException("$path exists already.");
    }
    $version = (string) (root($root)['version'] ?? '0.1.0');
    $major = (int) explode('.', $version)[0];
    $namespace = 'PrestaShopHeaven\\' . ucfirst($kind) . '\\' . studly($dir) . '\\';
    $monorepo = basename((string) realpath($root));
    @mkdir("$path/src", 0777, true);
    @mkdir("$path/tests/Unit", 0777, true);
    writeJson("$path/composer.json", [
        'name' => VENDOR . "/$kind-$dir",
        'description' => $description,
        'type' => 'library',
        'license' => 'MIT',
        'authors' => [['name' => 'George van Engers', 'email' => 'vanengers@gmail.com']],
        'require' => ['php' => '>=8.1'],
        'autoload' => ['psr-4' => [$namespace => 'src/']],
        'autoload-dev' => ['psr-4' => [$namespace . 'Tests\\' => 'tests/']],
        'extra' => [
            'branch-alias' => ['dev-main' => "$major.x-dev"],
            'prestashopheaven' => ['kind' => $kind, 'use-when' => $useWhen, 'keywords' => $keywords],
        ],
    ]);
    $values = ['NAME' => VENDOR . "/$kind-$dir", 'DESCRIPTION' => $description, 'USE_WHEN' => $useWhen, 'MONOREPO' => $monorepo, 'YEAR' => date('Y')];
    file_put_contents("$path/README.md", template('package-README.md', $values));
    file_put_contents("$path/CHANGELOG.md", template('package-CHANGELOG.md', $values));
    file_put_contents("$path/LICENSE.md", template('LICENSE.md', $values));
    file_put_contents("$path/.gitattributes", template('package-gitattributes', $values));
    $composer = root($root);
    $composer['require'][VENDOR . "/$kind-$dir"] = '*';
    ksort($composer['require']);
    writeJson("$root/composer.json", $composer);
    setVersion($root, $version);

    return $path;
}

/**
 * The definition of done of every package of the monorepo (PKG-08), in order, stopping at the first failure:
 * rules, PHP lint, coding standard, PHPStan per PrestaShop version (runtime packages), PHP compatibility, docblocks and
 * conventions per package, unit tests with the coverage gate per package. Integration tests in real shops run in CI
 * through the fixture module (mono-ci.yml).
 */
function check(string $root): int
{
    $root = rtrim(str_replace('\\', '/', (string) realpath($root)), '/');
    $tools = str_replace('\\', '/', dirname(__DIR__));
    $cache = "$root/.cache/mono";
    @mkdir($cache, 0777, true);
    $php = escapeshellarg(PHP_BINARY);
    $bin = static fn (string $tool): string => $php . ' ' . escapeshellarg("$root/vendor/bin/$tool");

    if (report(validate($root)) !== 0) {
        return 1;
    }
    $steps = [];
    $steps['lint'] = $bin('parallel-lint') . ' --exclude vendor --exclude .cache packages';
    file_put_contents("$cache/php-cs-fixer.php", "<?php\n\$factory = require " . var_export("$tools/resources/php-cs-fixer.php", true) . ";\n\nreturn \$factory(" . var_export($root, true) . ");\n");
    $steps['coding standard'] = $bin('php-cs-fixer') . ' fix --dry-run --diff --config=' . escapeshellarg("$cache/php-cs-fixer.php");
    if (kind($root) === 'runtime' || is_file("$root/phpstan.neon")) {
        writePhpstanConfig($root, "$cache/phpstan");
        $steps['phpstan'] = 'PS_PHPSTAN_DIR=' . escapeshellarg("$cache/phpstan") . " $php " . escapeshellarg("$tools/src/phpstan.php");
    }
    $steps['php compatibility'] = $bin('phpcs') . ' --standard=PHPCompatibility --runtime-set testVersion 8.1- --extensions=php -p --ignore=*/vendor/*,*/.cache/* packages';
    foreach (packages($root) as $package) {
        $steps['conventions ' . $package['dir']] = "$php " . escapeshellarg("$tools/src/conventions-check.php") . ' ' . escapeshellarg("$root/{$package['dir']}");
    }
    writePhpunitConfig($root, $cache);
    $coverage = coverageDriver();
    $steps['unit tests'] = ($coverage === 'xdebug' ? 'XDEBUG_MODE=coverage ' : '') . $bin('phpunit') . ' -c ' . escapeshellarg("$cache/phpunit.xml")
        . ($coverage !== '' ? ' --coverage-clover ' . escapeshellarg("$cache/clover.xml") : '');

    foreach ($steps as $label => $command) {
        echo "\n##### $label #####\n";
        passthru(PHP_OS_FAMILY === 'Windows' ? envForWindows($command) : $command, $code);
        if ($code !== 0) {
            fwrite(STDERR, "\nps-module mono check: '$label' failed.\n");

            return 1;
        }
    }
    echo "\n##### coverage #####\n";
    if ($coverage === '') {
        fwrite(STDERR, "No coverage driver (pcov or Xdebug): the coverage gate (PKG-08) cannot run. CI runs it with pcov.\n");

        return getenv('CI') ? 1 : 0;
    }

    return report(coverageProblems($root, "$cache/clover.xml"));
}

/**
 * On Windows "VAR=value cmd" does not work in cmd.exe: turns the leading assignments into putenv() calls.
 */
function envForWindows(string $command): string
{
    while (preg_match('/^([A-Z_]+)=(\'[^\']*\'|"[^"]*"|\S+)\s+(.*)$/s', $command, $m)) {
        putenv($m[1] . '=' . trim($m[2], "'\""));
        $command = $m[3];
    }

    return $command;
}

/**
 * The coverage driver PHP has: pcov, xdebug or ''.
 */
function coverageDriver(): string
{
    if (extension_loaded('pcov')) {
        return 'pcov';
    }

    return extension_loaded('xdebug') ? 'xdebug' : '';
}

/**
 * Writes the generated PHPStan config of the monorepo: config.json (PrestaShop versions, paths of every package) and
 * phpstan.neon (level 6, PHP 8.1; with the PrestaShop extension for runtime packages).
 */
function writePhpstanConfig(string $root, string $dir): void
{
    @mkdir($dir, 0777, true);
    $versions = root($root)['extra']['prestashopheaven']['monorepo']['phpstan'] ?? ['8.1.0', '9.2.0'];
    $paths = [];
    foreach (packages($root) as $package) {
        // Default src/ and tests/Unit; a package whose unit tests use PrestaShop stubs lists its own paths
        // (extra.prestashopheaven.phpstan-paths), because the stubs clash with the real PrestaShop classes.
        $own = $package['composer']['extra']['prestashopheaven']['phpstan-paths'] ?? ['src', 'tests/Unit'];
        foreach ((array) $own as $sub) {
            if (file_exists("$root/{$package['dir']}/$sub")) {
                $paths[] = "{$package['dir']}/$sub";
            }
        }
    }
    $cacheDir = getenv('PS_CACHE_DIR') ?: dirname(workspaceDir($root)) . '/.cache/prestashop';
    writeJson("$dir/config.json", ['cache_dir' => $cacheDir, 'versions' => $versions, 'paths' => $paths]);
    $includes = kind($root) === 'runtime' ? "includes:\n    - $root/vendor/prestashop/php-dev-tools/phpstan/ps-module-extension.neon\n\n" : '';
    file_put_contents("$dir/phpstan.neon", $includes . "parameters:\n    level: 6\n    phpVersion: 80100\n    reportUnmatchedIgnoredErrors: false\n    tmpDir: $root/.cache/phpstan\n");
}

/**
 * Writes the generated PHPUnit config: one test suite per package (its tests/ without tests/Integration, which runs in
 * a real shop), the bootstrap (root autoloader plus each package's tests/bootstrap.php) and the source of every package.
 */
function writePhpunitConfig(string $root, string $cache): void
{
    $suites = '';
    $sources = '';
    $bootstrap = "<?php\n// Generated by ps-module mono check.\nrequire_once " . var_export("$root/vendor/autoload.php", true) . ";\n";
    foreach (packages($root) as $package) {
        $dir = "$root/{$package['dir']}";
        if (is_dir("$dir/tests")) {
            $suites .= "        <testsuite name=\"{$package['name']}\">\n            <directory>$dir/tests</directory>\n"
                . (is_dir("$dir/tests/Integration") ? "            <exclude>$dir/tests/Integration</exclude>\n" : '')
                . "        </testsuite>\n";
        }
        if (is_dir("$dir/src")) {
            $sources .= "            <directory>$dir/src</directory>\n";
        }
        if (is_file("$dir/tests/bootstrap.php")) {
            $bootstrap .= 'require_once ' . var_export("$dir/tests/bootstrap.php", true) . ";\n";
        }
    }
    file_put_contents("$cache/bootstrap.php", $bootstrap);
    file_put_contents("$cache/phpunit.xml", "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<!-- Generated by ps-module mono check. -->\n"
        . "<phpunit bootstrap=\"$cache/bootstrap.php\" colors=\"true\" cacheDirectory=\"$cache/phpunit\" failOnRisky=\"true\" failOnWarning=\"true\">\n"
        . "    <testsuites>\n$suites    </testsuites>\n    <source>\n        <include>\n$sources        </include>\n    </source>\n</phpunit>\n");
}

/**
 * The coverage gate (PKG-08): every package's src/ must reach its coverage-min (default 100 %) of lines, counted from
 * a Clover report.
 *
 * @return list<string> the problems found
 */
function coverageProblems(string $root, string $clover): array
{
    $xml = is_file($clover) ? @simplexml_load_file($clover) : false;
    if ($xml === false) {
        return ["No coverage report at $clover."];
    }
    $lines = [];
    foreach ($xml->xpath('//file') ?: [] as $file) {
        $path = str_replace('\\', '/', (string) $file['name']);
        $source = is_file($path) ? (file($path, FILE_IGNORE_NEW_LINES) ?: []) : [];
        $statements = $hit = 0;
        foreach ($file->line as $line) {
            if ((string) $line['type'] !== 'stmt' || isGuardExit($source, (int) $line['num'])) {
                continue;
            }
            ++$statements;
            $hit += (int) $line['count'] > 0 ? 1 : 0;
        }
        if ($file->line->count() === 0) {
            $statements = (int) ($file->metrics['statements'] ?? 0);
            $hit = (int) ($file->metrics['coveredstatements'] ?? 0);
        }
        $lines[$path] = [$statements, $hit];
    }
    $problems = [];
    foreach (packages($root) as $package) {
        $min = (float) ($package['composer']['extra']['prestashopheaven']['coverage-min'] ?? 100);
        $src = str_replace('\\', '/', (string) realpath("$root/{$package['dir']}/src")) . '/';
        $integration = array_map(static fn (string $f): string => substr($f, 4), (array) ($package['composer']['extra']['prestashopheaven']['integration-tested'] ?? []));
        $total = $covered = 0;
        $missing = [];
        foreach ($lines as $path => [$statements, $hit]) {
            if (str_starts_with($path, $src) && !in_array(substr($path, strlen($src)), $integration, true)) {
                $total += $statements;
                $covered += $hit;
                if ($hit < $statements) {
                    $missing[] = substr($path, strlen($src)) . " ($hit/$statements)";
                }
            }
        }
        $percent = $total === 0 ? 100.0 : round($covered / $total * 100, 2);
        echo "{$package['name']}: $percent % of $total lines (gate $min %)\n";
        if ($percent < $min) {
            $problems[] = "{$package['name']}: coverage $percent % is below $min %; not fully covered: " . implode(', ', array_slice($missing, 0, 10));
        }
    }

    return $problems;
}

/**
 * In a fixture module inside a monorepo (tests/fixture/<module>): copies every package's tests/Integration into
 * <module>/tests/Packages/<dir>/, because Composer leaves tests/ out of a path package (export-ignore) and the test shop
 * only gets the module folder. The fixture runs this after composer install (scripts) and its phpunit.xml.dist runs
 * tests/Packages. Returns the number of packages copied.
 *
 * @throws \RuntimeException when $fixture is not inside a monorepo
 */
function fixtureTests(string $fixture): int
{
    $fixture = rtrim(str_replace('\\', '/', (string) (realpath($fixture) ?: $fixture)), '/');
    $root = '';
    for ($dir = dirname($fixture); $dir !== dirname($dir); $dir = dirname($dir)) {
        $composer = is_file("$dir/composer.json") ? json_decode((string) file_get_contents("$dir/composer.json"), true) : null;
        if (is_array($composer) && isset($composer['extra']['prestashopheaven']['monorepo'])) {
            $root = $dir;
            break;
        }
    }
    if ($root === '') {
        throw new \RuntimeException("$fixture is not inside a monorepo (no composer.json with extra.prestashopheaven.monorepo above it).");
    }
    $target = "$fixture/tests/Packages";
    removeTree($target);
    $copied = 0;
    foreach (packages($root) as $package) {
        $source = "$root/{$package['dir']}/tests/Integration";
        if (is_dir($source)) {
            copyTree($source, "$target/" . basename($package['dir']));
            ++$copied;
        }
    }

    return $copied;
}

/**
 * Copies a folder tree.
 */
function copyTree(string $from, string $to): void
{
    @mkdir($to, 0777, true);
    foreach (scandir($from) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        is_dir("$from/$entry") ? copyTree("$from/$entry", "$to/$entry") : copy("$from/$entry", "$to/$entry");
    }
}

/**
 * Removes a folder tree (nothing when it does not exist).
 */
function removeTree(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            is_dir("$dir/$entry") && !is_link("$dir/$entry") ? removeTree("$dir/$entry") : unlink("$dir/$entry");
        }
    }
    rmdir($dir);
}

/**
 * True when line $number (1-based) is part of the `if (!defined('_PS_VERSION_')) { exit; }` guard at the top of a
 * PrestaShop file, so the coverage gate does not count it: the `exit;` never runs in a test, and the `if` (a statement
 * on PHP 8.1) runs when the class loads, which is often before coverage starts (a data provider, the bootstrap).
 *
 * @param list<string> $source the lines of the file
 */
function isGuardExit(array $source, int $number): bool
{
    $line = trim($source[$number - 1] ?? '');
    if (str_contains($line, "defined('_PS_VERSION_')")) {
        return trim($source[$number] ?? '') === 'exit;';
    }
    if ($line !== 'exit;') {
        return false;
    }
    for ($i = $number - 2; $i >= 0 && $i >= $number - 3; --$i) {
        if (str_contains($source[$i], "defined('_PS_VERSION_')")) {
            return true;
        }
    }

    return false;
}

/**
 * Creates what a monorepo needs on GitHub (PKG-04): the monorepo repo itself, a public read-only repo per package, a
 * deploy key with write access per split repo (its private key as secret SPLIT_KEY_<KEY> of the monorepo), and the
 * secrets PACKAGIST_USERNAME / PACKAGIST_TOKEN from the workspace .env. Idempotent: existing repos, keys and secrets
 * are kept. Packagist itself is created by the first release (mono-release.yml).
 */
function register(string $root, bool $dryRun): int
{
    $monorepo = VENDOR . '/' . basename((string) realpath($root));
    $run = static function (string $command, ?string $input = null) use ($dryRun): int {
        echo ($dryRun ? '[dry-run] ' : '') . preg_replace('/(--body|-b)\s+\S+/', '$1 ***', $command) . "\n";
        if ($dryRun) {
            return 0;
        }
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => STDOUT, 2 => STDERR], $pipes);
        if (!is_resource($process)) {
            return 1;
        }
        fwrite($pipes[0], (string) $input);
        fclose($pipes[0]);

        return proc_close($process);
    };
    $exists = static function (string $repo) use ($dryRun): bool {
        if ($dryRun) {
            return false; // a dry run shows everything it would create, without asking GitHub
        }
        exec('gh repo view ' . escapeshellarg($repo) . ' --json name 2>&1', $out, $code);

        return $code === 0;
    };
    $secrets = static function (string $repo): array {
        exec('gh secret list -R ' . escapeshellarg($repo) . ' 2>&1', $out, $code);

        return $code === 0 ? array_map(static fn (string $l): string => (string) strtok($l, "\t "), $out) : [];
    };

    if (!$exists($monorepo)) {
        $description = repoDescription((string) (root($root)['description'] ?? ''));
        if ($run('gh repo create ' . escapeshellarg($monorepo) . ' --public --description ' . escapeshellarg($description)) !== 0) {
            return 1;
        }
    }
    $have = $dryRun ? [] : $secrets($monorepo);
    foreach (packages($root) as $package) {
        $repo = $package['repo'];
        if (!$exists($repo)) {
            $description = repoDescription((string) ($package['composer']['description'] ?? ''), " (read-only split of $monorepo)");
            if ($run('gh repo create ' . escapeshellarg($repo) . ' --public --disable-issues --disable-wiki --description ' . escapeshellarg($description)) !== 0) {
                return 1;
            }
        }
        $secret = 'SPLIT_KEY_' . $package['key'];
        if (in_array($secret, $have, true)) {
            continue;
        }
        $keyFile = sys_get_temp_dir() . '/split-' . bin2hex(random_bytes(6));
        if ($run('ssh-keygen -q -t ed25519 -N "" -C ' . escapeshellarg("split $monorepo") . ' -f ' . escapeshellarg($keyFile)) !== 0
            || $run('gh repo deploy-key add ' . escapeshellarg("$keyFile.pub") . ' -R ' . escapeshellarg($repo) . ' --allow-write --title ' . escapeshellarg("split from $monorepo")) !== 0
            || $run('gh secret set ' . escapeshellarg($secret) . ' -R ' . escapeshellarg($monorepo), $dryRun ? '' : (string) file_get_contents($keyFile)) !== 0) {
            @unlink($keyFile);
            @unlink("$keyFile.pub");

            return 1;
        }
        @unlink($keyFile);
        @unlink("$keyFile.pub");
    }
    $env = envValues(workspaceDir($root));
    foreach (['PACKAGIST_USERNAME', 'PACKAGIST_TOKEN'] as $name) {
        if (in_array($name, $have, true)) {
            continue;
        }
        if (($env[$name] ?? '') === '') {
            fwrite(STDERR, "$name is not in the workspace .env: Packagist is not updated by the release.\n");
            continue;
        }
        if ($run('gh secret set ' . $name . ' -R ' . escapeshellarg($monorepo), $env[$name]) !== 0) {
            return 1;
        }
    }
    echo "Registered $monorepo and its packages.\n";

    return 0;
}

/**
 * A GitHub repo description from a package text: one line, "%" spelled out (escapeshellarg drops it on Windows) and at
 * most 350 characters (GitHub's limit), cut on a word with "…" before the suffix, which is always kept.
 */
function repoDescription(string $text, string $suffix = ''): string
{
    $text = trim((string) preg_replace('/\s+/', ' ', str_replace('%', 'percent', $text)));
    $text = (string) preg_replace('/(\d) ?percent/', '$1 percent', $text);
    $room = 350 - mb_strlen($suffix);
    if (mb_strlen($text) > $room) {
        $cut = mb_substr($text, 0, $room - 1);
        $space = mb_strrpos($cut, ' ');
        $text = rtrim($space === false ? $cut : mb_substr($cut, 0, $space), ' ,;:.') . '…';
    }

    return $text . $suffix;
}

/**
 * The values of the workspace .env (never printed).
 *
 * @return array<string, string>
 */
function envValues(string $workspace): array
{
    $values = [];
    foreach (is_file("$workspace/.env") ? (file("$workspace/.env", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) : [] as $line) {
        if ($line[0] !== '#' && preg_match('/^\s*([A-Z0-9_]+)\s*=\s*(.*?)\s*$/', $line, $m)) {
            $values[$m[1]] = trim($m[2], "\"'");
        }
    }

    return $values;
}

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    exit(main(array_slice($argv, 1)));
}
