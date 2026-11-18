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

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/instance.php';

/**
 * src/instance.php without Docker: the on/off switch, the slot reservations and the translation of the compose
 * commands of the Shop class to a slot container.
 */
final class InstanceTest extends TestCase
{
    private string $workspace = '';
    private string $dir = '';

    /**
     * An empty workspace (libraries/ + modules/) per test, without the switch variables of the caller.
     */
    protected function setUp(): void
    {
        $this->workspace = str_replace('\\', '/', sys_get_temp_dir()) . '/instance-test-' . bin2hex(random_bytes(4));
        mkdir($this->workspace . '/libraries', 0777, true);
        mkdir($this->workspace . '/modules/repo', 0777, true);
        $this->dir = instance_dir($this->workspace . '/modules/repo');
        foreach (['PS_TEST_INSTANCE', 'PS_TEST_POOL', 'CI', 'GITHUB_ACTIONS'] as $name) {
            putenv($name);
        }
    }

    /**
     * Removes the workspace.
     */
    protected function tearDown(): void
    {
        foreach (['PS_TEST_INSTANCE', 'PS_TEST_POOL', 'CI', 'GITHUB_ACTIONS'] as $name) {
            putenv($name);
        }
        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->workspace, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($this->workspace);
    }

    public function testTheStateLivesInTheWorkspace(): void
    {
        self::assertSame($this->workspace . '/.cache/instances', $this->dir);
    }

    public function testTheSwitch(): void
    {
        $repo = $this->workspace . '/modules/repo';
        self::assertNull(instance_pool($repo), 'off by default');

        instance_switch($this->dir, ['shops'], true);
        self::assertSame('shops', instance_pool($repo));
        self::assertFalse(instance_enabled('docs', $repo));
        putenv('PS_TEST_POOL=docs');
        self::assertNull(instance_pool($repo), 'the docs pool is still off');
        instance_switch($this->dir, ['docs'], true);
        self::assertSame('docs', instance_pool($repo));
        putenv('PS_TEST_POOL');

        putenv('CI=true');
        self::assertNull(instance_pool($repo), 'never in CI');
        putenv('PS_TEST_INSTANCE=shops');
        self::assertSame('shops', instance_pool($repo), 'the variable wins over CI');
        putenv('PS_TEST_INSTANCE=off');
        putenv('CI');
        self::assertNull(instance_pool($repo), 'off wins over the switch file');
        putenv('PS_TEST_INSTANCE=docs,shops');
        self::assertTrue(instance_enabled('docs', $repo) && instance_enabled('shops', $repo));
        putenv('PS_TEST_INSTANCE=all');
        self::assertFalse(instance_enabled('nope', $repo), 'only known pools');
        putenv('PS_TEST_INSTANCE');

        instance_switch($this->dir, ['shops', 'docs'], false);
        self::assertNull(instance_pool($repo));
    }

    public function testSlotsAreReservedPerModuleAndVersion(): void
    {
        $exists = static fn (string $name): bool => true;
        $a = instance_reserve($this->dir, 'shops', 'heavena', '9.2.1', $exists, 1000);
        $b = instance_reserve($this->dir, 'shops', 'heavenb', '9.2.1', $exists, 1000);
        self::assertSame([1, 2], [$a, $b]);
        self::assertSame(1, instance_reserve($this->dir, 'shops', 'heavena', '9.2.1', $exists, 1000), 'the same slot again');
        self::assertSame(3, instance_reserve($this->dir, 'shops', 'heavena', '8.1.0', $exists, 1000), 'another version, another slot');
        self::assertSame(2, instance_slot_of($this->dir, 'shops', 'heavenb', '9.2.1'));
        self::assertNull(instance_slot_of($this->dir, 'docs', 'heavenb', '9.2.1'), 'pools are apart');

        instance_release($this->dir, 'shops', 1);
        self::assertNull(instance_slot_of($this->dir, 'shops', 'heavena', '9.2.1'));
        self::assertSame(1, instance_reserve($this->dir, 'shops', 'heavenc', '9.2.1', $exists, 1000), 'the lowest free slot');

        // A slot whose container is gone is free again after 10 minutes, not before.
        $gone = static fn (string $name): bool => $name !== 'psh-shops-s2';
        self::assertSame(4, instance_reserve($this->dir, 'shops', 'heavend', '9.2.1', $gone, 1500));
        self::assertSame(2, instance_reserve($this->dir, 'shops', 'heavene', '9.2.1', $gone, 1700));
        self::assertNull(instance_slot_of($this->dir, 'shops', 'heavenb', '9.2.1'));
    }

    public function testComposeCommandsBecomeSlotCommands(): void
    {
        $c = 'psh-shops-s3';
        self::assertSame("docker exec -u www-data 'psh-shops-s3' php bin/console", str_replace('"', "'", (string) instance_translate('exec -T -u www-data prestashop php bin/console', $c)));
        self::assertSame("docker cp '/tmp/a' 'psh-shops-s3:/var/www/html/modules/a'", str_replace('"', "'", (string) instance_translate("cp '/tmp/a' 'prestashop:/var/www/html/modules/a'", $c)));
        self::assertStringStartsWith('docker ps -q --filter ', (string) instance_translate('ps -q', $c));
        self::assertNull(instance_translate('up -d', $c));
        self::assertNull(instance_translate('down -v --remove-orphans', $c));
    }
}
