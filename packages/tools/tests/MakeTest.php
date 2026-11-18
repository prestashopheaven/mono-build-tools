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

require_once __DIR__ . '/../src/make.php';

/**
 * src/make.php: `make cqrs` writes a command, a query and their handlers from design.json, tags the handlers for the
 * module's bus once, never overwrites a file, and refuses a wrong design.
 */
final class MakeTest extends TestCase
{
    private string $repo = '';

    /**
     * A module repository with module.spec.json, a design and config/common.yml.
     */
    protected function setUp(): void
    {
        $this->repo = str_replace('\\', '/', sys_get_temp_dir()) . '/make-test-' . bin2hex(random_bytes(4));
        mkdir("$this->repo/.factory", 0777, true);
        mkdir("$this->repo/heavenfoo/config", 0777, true);
        file_put_contents("$this->repo/module.spec.json", json_encode(['namespace' => 'PrestaShopHeaven\\Foo', 'license_headers' => ['php' => "/**\n * HEADER\n */"]]));
        file_put_contents("$this->repo/heavenfoo/config/common.yml", "services:\n  _defaults:\n    public: true\n");
        file_put_contents("$this->repo/.factory/design.json", json_encode([
            'commands' => [['name' => 'CheckVatNumber', 'context' => 'Vat', 'fields' => [['name' => 'vatNumber', 'type' => 'string'], ['name' => 'shopId', 'type' => '?int']]], ['name' => 'ClearChecks', 'context' => 'Vat', 'returns' => 'Result']],
            'queries' => [['name' => 'GetVatStatus', 'context' => 'Vat', 'description' => 'Asks for the status.']],
        ]));
    }

    /**
     * Removes the repository.
     */
    protected function tearDown(): void
    {
        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->repo, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->repo);
    }

    /**
     * Runs make_main and returns [exit code, output].
     *
     * @param list<string> $args
     *
     * @return array{0: int, 1: string}
     */
    private function make(array $args): array
    {
        ob_start();
        $code = @make_main($this->repo, "$this->repo/heavenfoo", $args);

        return [$code, (string) ob_get_clean()];
    }

    public function testCqrsWritesMessagesHandlersAndTagsOnce(): void
    {
        [$code, $out] = $this->make(['cqrs']);
        self::assertSame(0, $code);
        self::assertStringContainsString('Wrote src/Application/Vat/Command/CheckVatNumber/CheckVatNumberCommand.php', $out);

        $command = (string) file_get_contents("$this->repo/heavenfoo/src/Application/Vat/Command/CheckVatNumber/CheckVatNumberCommand.php");
        self::assertStringStartsWith("<?php\n/**\n * HEADER\n */\n\ndeclare(strict_types=1);\n\nnamespace PrestaShopHeaven\\Foo\\Application\\Vat\\Command\\CheckVatNumber;", $command);
        self::assertStringContainsString('private readonly ?int $shopId,', $command);
        self::assertStringContainsString('public function getVatNumber(): string', $command);
        self::assertStringContainsString(' * Asks to check vat number.', $command);

        $handler = (string) file_get_contents("$this->repo/heavenfoo/src/Application/Vat/Command/CheckVatNumber/CheckVatNumberHandler.php");
        self::assertStringContainsString('public function handle(CheckVatNumberCommand $message): void', $handler);
        self::assertStringContainsString('a command: the only place this change happens', $handler);

        self::assertStringContainsString('public function handle(ClearChecksCommand $message): array', (string) file_get_contents("$this->repo/heavenfoo/src/Application/Vat/Command/ClearChecks/ClearChecksHandler.php"));
        $query = (string) file_get_contents("$this->repo/heavenfoo/src/Application/Vat/Query/GetVatStatus/GetVatStatusQuery.php");
        self::assertStringContainsString(' * Asks for the status.', $query);
        self::assertStringNotContainsString('__construct', $query, 'no fields, no constructor');
        self::assertStringContainsString('a query: reads, never writes', (string) file_get_contents("$this->repo/heavenfoo/src/Application/Vat/Query/GetVatStatus/GetVatStatusHandler.php"));

        $yaml = (string) file_get_contents("$this->repo/heavenfoo/config/common.yml");
        self::assertStringContainsString("  PrestaShopHeaven\\Foo\\Application\\Vat\\Command\\CheckVatNumber\\CheckVatNumberHandler:\n    tags:\n      - { name: 'prestashopheaven.heavenfoo.handler', command: 'PrestaShopHeaven\\Foo\\Application\\Vat\\Command\\CheckVatNumber\\CheckVatNumberCommand' }", $yaml);

        [$again, $second] = $this->make(['cqrs']);
        self::assertSame(0, $again);
        self::assertSame("Nothing to generate: every command and query of the design exists.\n", $second);
        self::assertSame($yaml, file_get_contents("$this->repo/heavenfoo/config/common.yml"));
    }

    public function testAnEditedFileIsNeverOverwritten(): void
    {
        $this->make(['cqrs']);
        $file = "$this->repo/heavenfoo/src/Application/Vat/Command/CheckVatNumber/CheckVatNumberHandler.php";
        file_put_contents($file, '<?php // built by hand');

        $this->make(['cqrs']);

        self::assertSame('<?php // built by hand', file_get_contents($file));
    }

    public function testDryRunWritesNothing(): void
    {
        [$code, $out] = $this->make(['cqrs', '--dry-run']);

        self::assertSame(0, $code);
        self::assertStringContainsString('Would write src/Application/Vat/Query/GetVatStatus/GetVatStatusHandler.php', $out);
        self::assertStringContainsString('Would add handler tags to config/common.yml', $out);
        self::assertDirectoryDoesNotExist("$this->repo/heavenfoo/src");
    }

    public function testWrongUsageAndMissingFilesFail(): void
    {
        self::assertSame(2, $this->make(['grid'])[0]);
        unlink("$this->repo/.factory/design.json");
        self::assertSame(1, $this->make(['cqrs'])[0]);
    }

    public function testAWrongDesignIsRefused(): void
    {
        foreach ([
            ['commands' => [['name' => 'check', 'context' => 'Vat']]],
            ['commands' => [['name' => 'Check']]],
            ['commands' => [42]],
            ['queries' => [['name' => 'Get', 'context' => 'Vat', 'fields' => [['name' => 'x', 'type' => 'Money']]]]],
            ['queries' => [['name' => 'Get', 'context' => 'Vat', 'fields' => [['name' => 'X', 'type' => 'int']]]]],
        ] as $i => $design) {
            file_put_contents("$this->repo/.factory/design.json", json_encode($design));
            self::assertSame(1, $this->make(['cqrs'])[0], "design $i");
        }
    }

    public function testAStringNameIsAMessageWithoutFieldsButNeedsAContext(): void
    {
        $this->expectException(InvalidArgumentException::class);

        make_cqrs_plan(['commands' => ['CheckVatNumber']], 'X', 'heavenfoo', '/** */', $this->repo);
    }

    public function testWords(): void
    {
        self::assertSame('check vat number', make_words('CheckVatNumber'));
    }
}
