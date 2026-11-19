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

require_once __DIR__ . '/../src/verbose.php';
require_once __DIR__ . '/../src/test-check.php';

/**
 * src/test-check.php: TST-07 counts only real dispatches in code (new X() or a named constructor X::forY()), never a
 * comment; acceptance ids count only in a test method's docblock or name (Bing IndexNow retro, proposals 2 and 3).
 */
final class TestCheckTest extends TestCase
{
    private string $dir = '';

    /**
     * An empty module folder per test.
     */
    protected function setUp(): void
    {
        $this->dir = str_replace('\\', '/', sys_get_temp_dir()) . '/test-check-' . bin2hex(random_bytes(4)) . '/heavenfoo';
        mkdir($this->dir . '/src/Application/Foo/Command/DoIt', 0777, true);
        mkdir($this->dir . '/tests/Unit/Application/Foo/Command/DoIt', 0777, true);
        mkdir($this->dir . '/tests/Integration', 0777, true);
        file_put_contents($this->dir . '/heavenfoo.php', "<?php\n");
        file_put_contents($this->dir . '/src/Application/Foo/Command/DoIt/DoItCommand.php', "<?php\nfinal class DoItCommand\n{\n    public static function forShops(): self\n    {\n        return new self();\n    }\n}\n");
        file_put_contents($this->dir . '/tests/Unit/Application/Foo/Command/DoIt/DoItCommandTest.php', "<?php\nfinal class DoItCommandTest\n{\n    public function testIt(): void\n    {\n        DoItCommand::forShops();\n    }\n}\n");
    }

    /**
     * Removes the module folder.
     */
    protected function tearDown(): void
    {
        $root = dirname($this->dir);
        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($root);
    }

    /**
     * The TST-07 lines of checkTests() for the module.
     *
     * @return string[]
     */
    private function tst07(string $integrationTest): array
    {
        file_put_contents($this->dir . '/tests/Integration/BusTest.php', $integrationTest);

        return array_values(array_filter(checkTests($this->dir), static fn (string $l): bool => str_contains($l, 'TST-07')));
    }

    public function testACommandOnlyNamedInADocblockIsNotDispatched(): void
    {
        self::assertCount(1, $this->tst07("<?php\n/** Dispatches new DoItCommand( through the bus. */\nfinal class BusTest {}\n"));
        self::assertCount(1, $this->tst07("<?php\n// DoItCommand::forShops( is the one\nfinal class BusTest {}\n"));
    }

    public function testNewAndANamedConstructorCountAsDispatch(): void
    {
        self::assertSame([], $this->tst07("<?php\nfinal class BusTest { public function testA(): void { \$bus->handle(new DoItCommand()); } }\n"));
        self::assertSame([], $this->tst07("<?php\nfinal class BusTest { public function testA(): void { \$bus->handle(DoItCommand::forShops()); } }\n"));
        self::assertCount(1, $this->tst07("<?php\nfinal class BusTest { public function testA(): void { \$x = DoItCommand::class; } }\n"), '::class is no dispatch');
    }

    public function testWithoutCommentsKeepsTheCode(): void
    {
        self::assertSame("<?php\n \n\$a = 1;  \n", testWithoutComments("<?php\n/** doc */\n\$a = 1; // note\n"));
    }

    public function testAcceptanceIdsCountOnlyOnTestMethods(): void
    {
        file_put_contents($this->dir . '/tests/Integration/AcceptanceTest.php', <<<'PHP'
            <?php
            /**
             * Covers A1, A2 and A3.
             */
            final class AcceptanceTest
            {
                /**
                 * A4: the key file is written.
                 */
                public function testKeyFile(): void
                {
                }

                public function testA5SendsTheQueue(): void
                {
                }

                /**
                 * A6 and A7 with a data provider.
                 */
                #[DataProvider('cases')]
                public function testProvided(string $x): void
                {
                }

                public function testA8x(): void
                {
                }
            }
            PHP);

        $ids = array_keys(testAcceptanceIds($this->dir . '/tests'));
        sort($ids);
        self::assertSame(['A4', 'A5', 'A6', 'A7'], $ids, 'class docblock ids and testA8x (no word boundary) do not count');
        self::assertSame([], testAcceptanceIds($this->dir . '/nope'));
    }
}
