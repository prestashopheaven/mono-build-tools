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

namespace PrestaHavenMono\Tests;

require_once __DIR__ . '/../src/mono.php';

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function PrestaHavenMono\checkRelease;
use function PrestaHavenMono\coverageProblems;
use function PrestaHavenMono\envForWindows;
use function PrestaHavenMono\envValues;
use function PrestaHavenMono\fixtureTests;
use function PrestaHavenMono\isGuardExit;
use function PrestaHavenMono\kind;
use function PrestaHavenMono\main;
use function PrestaHavenMono\newMonorepo;
use function PrestaHavenMono\newPackage;
use function PrestaHavenMono\packages;
use function PrestaHavenMono\readJson;
use function PrestaHavenMono\register;
use function PrestaHavenMono\repoDescription;
use function PrestaHavenMono\requireVersion;
use function PrestaHavenMono\setVersion;
use function PrestaHavenMono\studly;
use function PrestaHavenMono\validate;
use function PrestaHavenMono\writePhpstanConfig;
use function PrestaHavenMono\writePhpunitConfig;

/**
 * Tests of the monorepo rules on throw-away monorepos in the temp directory.
 */
final class MonoTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/mono-test-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/packages', 0777, true);
        $this->writeRoot([
            'name' => 'prestashopheaven/mono-runtime-core',
            'version' => '1.2.0',
            'require' => ['prestashopheaven/runtime-cqrs' => '*', 'prestashopheaven/runtime-kit' => '*'],
            'repositories' => [['type' => 'path', 'url' => 'packages/*', 'options' => ['symlink' => true, 'versions' => ['prestashopheaven/runtime-kit' => '1.2.0', 'prestashopheaven/runtime-cqrs' => '1.2.0']]]],
            'extra' => ['prestashopheaven' => ['monorepo' => ['kind' => 'runtime']]],
        ]);
        $this->writePackage('cqrs', []);
        $this->writePackage('kit', ['prestashopheaven/runtime-cqrs' => '^1.2', 'php' => '>=8.1']);
        file_put_contents($this->root . '/CHANGELOG.md', "# Changelog\n\n## 1.2.0\n\n- Something.\n");
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    public function testAValidMonorepoHasNoProblems(): void
    {
        self::assertSame([], validate($this->root));
    }

    public function testPackagesAreListedSortedWithRepoAndSecretKey(): void
    {
        $packages = packages($this->root);

        self::assertSame(['packages/cqrs', 'packages/kit'], array_column($packages, 'dir'));
        self::assertSame('prestashopheaven/runtime-kit', $packages[1]['repo']);
        self::assertSame('RUNTIME_KIT', $packages[1]['key']);
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function studlyCases(): iterable
    {
        yield 'one word' => ['kit', 'Kit'];
        yield 'dashes' => ['shared-kernel', 'SharedKernel'];
        yield 'underscores' => ['admin_grid', 'AdminGrid'];
    }

    #[DataProvider('studlyCases')]
    public function testStudly(string $dir, string $expected): void
    {
        self::assertSame($expected, studly($dir));
    }

    /**
     * Every rule of validate(): the change that breaks it and the message that must appear.
     *
     * @return iterable<string, array{0: callable(array<string, mixed>): array<string, mixed>, 1: string}>
     */
    public static function brokenPackages(): iterable
    {
        yield 'wrong name' => [static fn (array $p): array => ['name' => 'prestashopheaven/kit'] + $p, 'name must be prestashopheaven/runtime-kit'];
        yield 'version field' => [static fn (array $p): array => ['version' => '1.2.0'] + $p, 'no "version"'];
        yield 'license' => [static fn (array $p): array => ['license' => 'AFL-3.0'] + $p, 'license must be MIT'];
        yield 'namespace' => [static function (array $p): array {
            $p['autoload']['psr-4'] = ['PrestaShopHeaven\\ModuleKit\\' => 'src/'];

            return $p;
        }, 'autoload psr-4 must map PrestaShopHeaven\\Runtime\\Kit\\'];
        yield 'branch alias' => [static function (array $p): array {
            $p['extra']['branch-alias']['dev-main'] = '2.x-dev';

            return $p;
        }, 'branch-alias.dev-main must be 1.x-dev'];
        yield 'kind' => [static function (array $p): array {
            $p['extra']['prestashopheaven']['kind'] = 'build';

            return $p;
        }, 'kind must be runtime'];
        yield 'use-when' => [static function (array $p): array {
            $p['extra']['prestashopheaven']['use-when'] = ' ';

            return $p;
        }, 'use-when is empty'];
        yield 'keywords' => [static function (array $p): array {
            $p['extra']['prestashopheaven']['keywords'] = [];

            return $p;
        }, 'keywords is empty'];
        yield 'description' => [static fn (array $p): array => ['description' => ''] + $p, 'description is empty'];
        yield 'internal constraint' => [static function (array $p): array {
            $p['require']['prestashopheaven/runtime-cqrs'] = '^1.0';

            return $p;
        }, 'require it as ^1.2'];
        yield 'runtime requires build' => [static function (array $p): array {
            $p['require']['prestashopheaven/build-testing-php'] = '^1.0';

            return $p;
        }, 'may never require a build package'];
    }

    /**
     * @param callable(array<string, mixed>): array<string, mixed> $break
     */
    #[DataProvider('brokenPackages')]
    public function testEveryRuleReportsItsProblem(callable $break, string $message): void
    {
        $file = $this->root . '/packages/kit/composer.json';
        file_put_contents($file, json_encode($break(readJson($file))));

        self::assertStringContainsString($message, implode("\n", validate($this->root)));
    }

    public function testMissingPackageFilesAreReported(): void
    {
        unlink($this->root . '/packages/kit/README.md');

        self::assertContains('packages/kit/README.md is missing.', validate($this->root));
    }

    public function testRootRules(): void
    {
        $this->writeRoot(['name' => 'prestashopheaven/runtime-core', 'version' => '1.2.0', 'extra' => ['prestashopheaven' => ['monorepo' => ['kind' => 'runtime']]]]);
        self::assertStringContainsString('name must be prestashopheaven/mono-runtime-<category>', implode("\n", validate($this->root)));

        $this->writeRoot(['name' => 'prestashopheaven/mono-runtime-core', 'version' => '1.2', 'extra' => ['prestashopheaven' => ['monorepo' => ['kind' => 'runtime']]]]);
        self::assertStringContainsString('"version" must be X.Y.Z', implode("\n", validate($this->root)));
    }

    public function testThePathRepositoryMustGiveEveryPackageTheOneVersion(): void
    {
        $composer = readJson($this->root . '/composer.json');
        $composer['repositories'][0]['options']['versions']['prestashopheaven/runtime-kit'] = '1.1.0';
        $this->writeRoot($composer);
        self::assertStringContainsString('path repository packages/* must list every package with version 1.2.0', implode("\n", validate($this->root)));

        unset($composer['repositories']);
        $this->writeRoot($composer);
        self::assertStringContainsString('path repository packages/*', implode("\n", validate($this->root)));
    }

    public function testNoPackagesIsAProblem(): void
    {
        $this->remove($this->root . '/packages');
        mkdir($this->root . '/packages');

        self::assertContains('No packages in packages/*/composer.json.', validate($this->root));
    }

    public function testUnknownKindThrows(): void
    {
        $this->writeRoot(['name' => 'prestashopheaven/mono-runtime-core', 'version' => '1.2.0']);

        $this->expectException(\RuntimeException::class);
        kind($this->root);
    }

    public function testMissingComposerJsonThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        readJson($this->root . '/nope.json');
    }

    public function testReleaseNeedsTheSameVersionAChangelogSectionAndAFreeTag(): void
    {
        self::assertSame([], checkRelease($this->root, '1.2.0', false));

        $problems = implode("\n", checkRelease($this->root, '1.3.0', true));
        self::assertStringContainsString('"version" is "1.2.0", the release is 1.3.0', $problems);
        self::assertStringContainsString('CHANGELOG.md has no section "## 1.3.0"', $problems);
        self::assertStringContainsString('Tag v1.3.0 already exists.', $problems);
    }

    public function testAReleaseRefusesLinkedPathRepositories(): void
    {
        $composer = readJson($this->root . '/composer.json');
        $composer['repositories'][] = ['type' => 'path', 'url' => '../../libraries/mono-build-tools/packages/*'];
        file_put_contents($this->root . '/composer.json', json_encode($composer));

        self::assertSame([], validate($this->root), 'linking is fine while working');
        self::assertStringContainsString('linked path repository ../../libraries/mono-build-tools/packages/*', implode("\n", checkRelease($this->root, '1.2.0', false)));
    }

    public function testSetVersionUpdatesRootAliasesAndInternalConstraints(): void
    {
        setVersion($this->root, '2.0.0');

        self::assertSame('2.0.0', readJson($this->root . '/composer.json')['version']);
        $kit = readJson($this->root . '/packages/kit/composer.json');
        self::assertSame('^2.0', $kit['require']['prestashopheaven/runtime-cqrs']);
        self::assertSame('>=8.1', $kit['require']['php']);
        self::assertSame('2.x-dev', $kit['extra']['branch-alias']['dev-main']);
        $repositories = readJson($this->root . '/composer.json')['repositories'];
        self::assertCount(1, $repositories);
        self::assertSame(['prestashopheaven/runtime-cqrs' => '2.0.0', 'prestashopheaven/runtime-kit' => '2.0.0'], $repositories[0]['options']['versions']);
        self::assertSame([], validate($this->root));
    }

    public function testSetVersionAlsoMovesTheFixtureModules(): void
    {
        mkdir($this->root . '/tests/fixture/heavenfoo', 0777, true);
        file_put_contents($this->root . '/tests/fixture/heavenfoo/composer.json', json_encode([
            'repositories' => [['type' => 'path', 'url' => '../../../packages/*', 'options' => ['symlink' => false, 'versions' => ['prestashopheaven/runtime-kit' => '1.2.0']]]],
            'require' => ['php' => '>=8.1', 'prestashopheaven/runtime-kit' => '1.2.0', 'other/package' => '^3.0'],
        ]));

        setVersion($this->root, '1.3.0');

        $fixture = readJson($this->root . '/tests/fixture/heavenfoo/composer.json');
        self::assertSame('1.3.0', $fixture['require']['prestashopheaven/runtime-kit']);
        self::assertSame('^3.0', $fixture['require']['other/package']);
        self::assertSame(['prestashopheaven/runtime-cqrs' => '1.3.0', 'prestashopheaven/runtime-kit' => '1.3.0'], $fixture['repositories'][0]['options']['versions']);
    }

    public function testThePhpstanConfigListsTheSourcesAndUnitTestsOfEveryPackage(): void
    {
        mkdir($this->root . '/packages/kit/tests/Unit', 0777, true);
        putenv('PS_CACHE_DIR=/ps-cache');
        try {
            writePhpstanConfig($this->root, $this->root . '/.cache/phpstan');
        } finally {
            putenv('PS_CACHE_DIR');
        }

        $config = readJson($this->root . '/.cache/phpstan/config.json');
        self::assertSame(['packages/cqrs/src', 'packages/kit/src', 'packages/kit/tests/Unit'], $config['paths']);
        self::assertSame(['8.1.0', '9.2.0'], $config['versions']);
        self::assertSame('/ps-cache', $config['cache_dir']);
        $neon = (string) file_get_contents($this->root . '/.cache/phpstan/phpstan.neon');
        self::assertStringContainsString('ps-module-extension.neon', $neon, 'runtime packages get the PrestaShop extension');
        self::assertStringContainsString('level: 6', $neon);
    }

    public function testThePhpunitConfigHasOneSuitePerPackageWithoutIntegrationTests(): void
    {
        mkdir($this->root . '/packages/kit/tests/Integration', 0777, true);
        file_put_contents($this->root . '/packages/kit/tests/bootstrap.php', "<?php\n");
        mkdir($this->root . '/.cache');

        writePhpunitConfig($this->root, $this->root . '/.cache');

        $xml = (string) file_get_contents($this->root . '/.cache/phpunit.xml');
        self::assertStringContainsString('<testsuite name="prestashopheaven/runtime-kit">', $xml);
        self::assertStringContainsString('<exclude>' . $this->root . '/packages/kit/tests/Integration</exclude>', $xml);
        self::assertStringNotContainsString('runtime-cqrs', $xml, 'a package without tests/ has no suite');
        self::assertStringContainsString('<directory>' . $this->root . '/packages/cqrs/src</directory>', $xml);
        self::assertStringContainsString('packages/kit/tests/bootstrap.php', (string) file_get_contents($this->root . '/.cache/bootstrap.php'));
    }

    public function testTheCoverageGateCountsLinesPerPackage(): void
    {
        $kit = str_replace('\\', '/', (string) realpath($this->root . '/packages/kit/src'));
        $cqrs = str_replace('\\', '/', (string) realpath($this->root . '/packages/cqrs/src'));
        $clover = $this->root . '/clover.xml';
        file_put_contents($clover, '<?xml version="1.0"?><coverage><project>'
            . '<file name="' . $kit . '/Full.php"><metrics statements="10" coveredstatements="10"/></file>'
            . '<file name="' . $cqrs . '/Half.php"><metrics statements="4" coveredstatements="2"/></file>'
            . '</project></coverage>');

        ob_start();
        $problems = coverageProblems($this->root, $clover);
        ob_end_clean();

        self::assertCount(1, $problems);
        self::assertStringContainsString('prestashopheaven/runtime-cqrs: coverage 50 % is below 100 %; not fully covered: Half.php (2/4)', $problems[0]);
        self::assertSame(['No coverage report at ' . $this->root . '/none.xml.'], coverageProblems($this->root, $this->root . '/none.xml'));
    }

    public function testTheGuardExitAndIntegrationTestedFilesDoNotCount(): void
    {
        $src = $this->root . '/packages/kit/src';
        file_put_contents("$src/Guarded.php", "<?php\nif (!defined('_PS_VERSION_')) {\n    exit;\n}\n\$a = 1;\n");
        file_put_contents("$src/Shop.php", "<?php\n\$b = 2;\n");
        mkdir($this->root . '/packages/kit/tests/Integration', 0777, true);
        file_put_contents($this->root . '/packages/kit/tests/Integration/ShopTest.php', "<?php\n");
        $file = $this->root . '/packages/kit/composer.json';
        $kit = readJson($file);
        $kit['extra']['prestashopheaven']['integration-tested'] = ['src/Shop.php'];
        file_put_contents($file, json_encode($kit));
        $real = str_replace('\\', '/', (string) realpath($src));
        $clover = $this->root . '/clover.xml';
        file_put_contents($clover, '<?xml version="1.0"?><coverage><project>'
            . '<file name="' . $real . '/Guarded.php"><line num="2" type="stmt" count="1"/><line num="3" type="stmt" count="0"/><line num="5" type="stmt" count="1"/></file>'
            . '<file name="' . $real . '/Shop.php"><line num="2" type="stmt" count="0"/></file>'
            . '</project></coverage>');

        ob_start();
        $problems = coverageProblems($this->root, $clover);
        $output = (string) ob_get_clean();

        self::assertSame([], $problems);
        self::assertStringContainsString('prestashopheaven/runtime-kit: 100 % of 2 lines', $output);
        self::assertSame([], validate($this->root));
        self::assertTrue(isGuardExit(['<?php', "if (!defined('_PS_VERSION_')) {", '    exit;'], 3));
        self::assertFalse(isGuardExit(['<?php', 'if ($x) {', '    exit;'], 3));
        self::assertFalse(isGuardExit(['<?php', '$a = 1;'], 2));
    }

    public function testAnIntegrationTestedFileNeedsItsIntegrationTest(): void
    {
        file_put_contents($this->root . '/packages/kit/src/Shop.php', "<?php\n");
        $file = $this->root . '/packages/kit/composer.json';
        $kit = readJson($file);
        $kit['extra']['prestashopheaven']['integration-tested'] = ['src/Shop.php', 'src/Missing.php'];
        file_put_contents($file, json_encode($kit));

        $problems = implode("\n", validate($this->root));
        self::assertStringContainsString('src/Shop.php is integration-tested but has no tests/Integration/ShopTest.php', $problems);
        self::assertStringContainsString('integration-tested lists src/Missing.php, which is not a file in src/', $problems);
    }

    public function testFixtureTestsCopiesTheIntegrationTestsOfEveryPackage(): void
    {
        mkdir($this->root . '/packages/kit/tests/Integration/Install', 0777, true);
        file_put_contents($this->root . '/packages/kit/tests/Integration/Install/InstallerTest.php', "<?php\n");
        $fixture = $this->root . '/tests/fixture/heavenfoo';
        mkdir($fixture . '/tests/Packages/old', 0777, true);
        file_put_contents($fixture . '/tests/Packages/old/StaleTest.php', "<?php\n");

        self::assertSame(1, fixtureTests($fixture));
        self::assertFileExists($fixture . '/tests/Packages/kit/Install/InstallerTest.php');
        self::assertDirectoryDoesNotExist($fixture . '/tests/Packages/old', 'stale copies are removed');
        self::assertDirectoryDoesNotExist($fixture . '/tests/Packages/cqrs', 'a package without integration tests');

        $this->expectException(\RuntimeException::class);
        fixtureTests(sys_get_temp_dir());
    }

    public function testALowerCoverageGateNeedsAReason(): void
    {
        $file = $this->root . '/packages/kit/composer.json';
        $kit = readJson($file);
        $kit['extra']['prestashopheaven']['coverage-min'] = 50;
        file_put_contents($file, json_encode($kit));

        self::assertStringContainsString('coverage-min lowers the coverage gate (PKG-08) and needs a coverage-reason', implode("\n", validate($this->root)));
    }

    public function testEveryPackageMustBeRequiredByTheRoot(): void
    {
        $composer = readJson($this->root . '/composer.json');
        unset($composer['require']['prestashopheaven/runtime-kit']);
        file_put_contents($this->root . '/composer.json', json_encode($composer));

        self::assertContains('composer.json: require prestashopheaven/runtime-kit ("*") so the monorepo installs and tests it.', validate($this->root));
    }

    public function testNewMonorepoAndNewPackageFollowTheRules(): void
    {
        $libraries = $this->root . '/libraries';
        mkdir($libraries);

        $dir = newMonorepo($libraries, 'build', 'demo', 'A demo monorepo.');
        self::assertFileExists("$dir/.github/workflows/release.yml");
        self::assertSame('prestashopheaven/mono-build-demo', readJson("$dir/composer.json")['name']);

        $package = newPackage($dir, 'nice-tool', 'A nice tool.', 'When you need a nice tool.', ['nice', 'tool']);
        self::assertSame("$dir/packages/nice-tool", $package);
        self::assertSame([], validate($dir));
        self::assertStringContainsString('## Use when', (string) file_get_contents("$package/README.md"));
        self::assertSame('src/', readJson("$package/composer.json")['autoload']['psr-4']['PrestaShopHeaven\\Build\\NiceTool\\']);

        $this->expectException(\RuntimeException::class);
        newPackage($dir, 'nice-tool', 'Again.', 'Again.', ['again']);
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: string, 3: list<string>}>
     */
    public static function wrongNewPackages(): iterable
    {
        yield 'capital in the folder' => ['Tool', 'D.', 'U.', ['k']];
        yield 'no description' => ['tool', ' ', 'U.', ['k']];
        yield 'no use-when' => ['tool', 'D.', '', ['k']];
        yield 'no keywords' => ['tool', 'D.', 'U.', []];
    }

    /**
     * @param list<string> $keywords
     */
    #[DataProvider('wrongNewPackages')]
    public function testANewPackageNeedsAFolderNameTextsAndKeywords(string $dir, string $description, string $useWhen, array $keywords): void
    {
        $this->expectException(\InvalidArgumentException::class);
        newPackage($this->root, $dir, $description, $useWhen, $keywords);
    }

    public function testANewMonorepoNeedsAKindACategoryAndADescription(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        newMonorepo($this->root, 'shipped', 'core', 'Wrong kind.');
    }

    public function testANewMonorepoNeverOverwritesAFolder(): void
    {
        mkdir($this->root . '/mono-build-taken');

        $this->expectException(\RuntimeException::class);
        newMonorepo($this->root, 'build', 'taken', 'Taken.');
    }

    public function testLeadingVariablesBecomeTheEnvironmentOnWindows(): void
    {
        self::assertSame('phpunit -c x', envForWindows("XDEBUG_MODE=coverage PS_PHPSTAN_DIR='/a b' phpunit -c x"));
        self::assertSame('coverage', getenv('XDEBUG_MODE'));
        self::assertSame('/a b', getenv('PS_PHPSTAN_DIR'));
        putenv('XDEBUG_MODE');
        putenv('PS_PHPSTAN_DIR');
        self::assertSame('php -v', envForWindows('php -v'));
    }

    public function testTheEnvironmentFileIsReadWithoutComments(): void
    {
        file_put_contents($this->root . '/.env', "# comment\nPACKAGIST_TOKEN=\"secret\"\nEMPTY=\n");

        self::assertSame(['PACKAGIST_TOKEN' => 'secret', 'EMPTY' => ''], envValues($this->root));
        self::assertSame([], envValues($this->root . '/nowhere'));
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: string}>
     */
    public static function repoDescriptions(): iterable
    {
        yield 'short stays' => ['The core.', '', 'The core.'];
        yield 'suffix added' => ['The core.', ' (split)', 'The core. (split)'];
        yield 'percent spelled out' => ['100 % coverage', '', '100 percent coverage'];
        yield 'whitespace collapsed' => ["two\n  lines", '', 'two lines'];
        yield 'empty' => ['', '', ''];
    }

    #[DataProvider('repoDescriptions')]
    public function testRepoDescriptionIsSafeForGithubAndTheShell(string $text, string $suffix, string $expected): void
    {
        self::assertSame($expected, repoDescription($text, $suffix));
    }

    public function testALongRepoDescriptionIsCutOnAWordAndKeepsItsSuffix(): void
    {
        $description = repoDescription(str_repeat('word ', 100), ' (read-only split of prestashopheaven/mono-x)');

        self::assertLessThanOrEqual(350, mb_strlen($description));
        self::assertStringEndsWith('word… (read-only split of prestashopheaven/mono-x)', $description);
    }

    public function testRegisterInDryRunOnlyPrintsWhatItWouldDo(): void
    {
        mkdir($this->root . '/libraries');
        mkdir($this->root . '/modules');
        ob_start();
        $code = register($this->root, true);
        $output = (string) ob_get_clean();

        self::assertSame(0, $code);
        self::assertStringContainsString('[dry-run]', $output);
        self::assertStringContainsString('Registered prestashopheaven/', $output);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function wrongVersions(): iterable
    {
        yield 'empty' => [''];
        yield 'two parts' => ['1.2'];
        yield 'prefix' => ['v1.2.0'];
        yield 'suffix' => ['1.2.0-beta'];
    }

    #[DataProvider('wrongVersions')]
    public function testWrongVersionsAreRefused(string $version): void
    {
        $this->expectException(\InvalidArgumentException::class);
        requireVersion($version);
    }

    public function testCommandLine(): void
    {
        ob_start();
        $validate = main(['validate', '--root=' . $this->root]);
        $matrix = main(['matrix', '--root=' . $this->root]);
        $output = (string) ob_get_clean();

        self::assertSame(0, $validate);
        self::assertSame(0, $matrix);
        self::assertStringContainsString('"key":"RUNTIME_CQRS"', $output);
        self::assertSame(2, main(['unknown']));
        self::assertSame(2, main(['set-version', 'x', '--root=' . $this->root]));
        self::assertSame(1, main(['validate', '--root=' . $this->root . '/missing']));
    }

    /**
     * @param array<string, mixed> $data
     */
    private function writeRoot(array $data): void
    {
        file_put_contents($this->root . '/composer.json', json_encode($data));
    }

    /**
     * @param array<string, string> $require
     */
    private function writePackage(string $dir, array $require): void
    {
        $path = $this->root . '/packages/' . $dir;
        mkdir($path . '/src', 0777, true);
        foreach (['README.md', 'CHANGELOG.md', 'LICENSE.md', '.gitattributes'] as $file) {
            file_put_contents("$path/$file", "x\n");
        }
        file_put_contents("$path/composer.json", json_encode([
            'name' => "prestashopheaven/runtime-$dir",
            'description' => "The $dir package.",
            'license' => 'MIT',
            'require' => $require ?: new \stdClass(),
            'autoload' => ['psr-4' => ['PrestaShopHeaven\\Runtime\\' . studly($dir) . '\\' => 'src/']],
            'extra' => [
                'branch-alias' => ['dev-main' => '1.x-dev'],
                'prestashopheaven' => ['kind' => 'runtime', 'use-when' => 'Testing.', 'keywords' => ['test']],
            ],
        ]));
    }

    private function remove(string $path): void
    {
        if (!is_dir($path)) {
            @unlink($path);

            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->remove("$path/$entry");
            }
        }
        rmdir($path);
    }
}
