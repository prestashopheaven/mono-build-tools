<?php
/**
 * NOTICE OF LICENSE
 *
 * This source file is subject to the MIT License
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/MIT
 *
 * @author    George van Engers | PrestaHaven.com
 * @copyright Since 2026 George van Engers
 * @license   https://opensource.org/licenses/MIT MIT License
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/shop-port.php';

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * src/shop-port.php: the host port of a test shop, from testing.json > base_port or PS_TEST_BASE_PORT (CI).
 */
final class ShopPortTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('PS_TEST_BASE_PORT');
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: string, 2: int}>
     */
    public static function ports(): iterable
    {
        yield 'base port of the module' => [['base_port' => 55000], '8.2.8', 55828];
        yield 'default base port' => [[], '9.2.0', 8920];
        yield 'two parts' => [['base_port' => 51000], '9.1', 51910];
        yield 'numeric string' => [['base_port' => '52000'], '8.1.7', 52817];
    }

    /**
     * @param array<string, mixed> $testing
     */
    #[DataProvider('ports')]
    public function testThePortFollowsTheVersion(array $testing, string $version, int $expected): void
    {
        self::assertSame($expected, shop_port($testing, $version));
    }

    public function testTheEnvironmentOverridesTheBasePort(): void
    {
        putenv('PS_TEST_BASE_PORT=20000');

        self::assertSame(20828, shop_port(['base_port' => 55000], '8.2.8'));
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function wrongEnvironment(): iterable
    {
        yield 'text' => ['abc'];
        yield 'zero' => ['0'];
        yield 'negative' => ['-5'];
        yield 'too high' => ['65000'];
    }

    #[DataProvider('wrongEnvironment')]
    public function testAWrongEnvironmentValueIsRefused(string $value): void
    {
        putenv("PS_TEST_BASE_PORT=$value");

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('PS_TEST_BASE_PORT');
        shop_port(['base_port' => 55000], '8.2.8');
    }

    public function testAnEmptyEnvironmentValueKeepsTheBasePort(): void
    {
        putenv('PS_TEST_BASE_PORT=');

        self::assertSame(55828, shop_port(['base_port' => 55000], '8.2.8'));
    }
}
