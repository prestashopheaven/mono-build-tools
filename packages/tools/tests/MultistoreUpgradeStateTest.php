<?php
/**
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License version 3.0
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/AFL-3.0
 *
 * @author    George van Engers | PrestaHaven.com
 * @copyright Since 2026 George van Engers
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/ps-multistore.php';

/**
 * The state check after a multistore upgrade (msUpgradeStateProblems): strict for a module-kit release; for a legacy
 * release (no composer.json, e.g. heavendeliverycopy 1.0.1 from Addons) the module stays in the shops of before and a
 * renamed key keeps the shop and group rows of its old key.
 */
final class MultistoreUpgradeStateTest extends TestCase
{
    private const ALL = [1, 2, 3];

    public function testAModuleKitUpgradeThatReachesEveryShopIsRight(): void
    {
        $before = ['module_shops' => self::ALL, 'configuration_scopes' => ['HEAVENFOO_A' => ['global', 's2']]];
        $after = ['module_shops' => self::ALL, 'hook_shops' => ['displayHome' => self::ALL], 'configuration_scopes' => ['HEAVENFOO_A' => ['global', 's2'], 'HEAVENFOO_B' => ['global']]];

        self::assertSame([], msUpgradeStateProblems($before, $after, self::ALL, false));
    }

    /**
     * Module-kit upgrades: every rule stays as strict as before 1.26.0.
     *
     * @return array<string, array{array<string, mixed>, array<string, mixed>, string}>
     */
    public static function moduleKitFaults(): array
    {
        return [
            'module in one shop' => [['module_shops' => [1]], ['module_shops' => [1]], 'after the upgrade the module is enabled in shops [1]'],
            'hook in one shop' => [['module_shops' => self::ALL], ['module_shops' => self::ALL, 'hook_shops' => ['displayHome' => [1]]], 'after the upgrade hook displayHome is registered for shops [1]'],
            'new key with a shop row' => [['module_shops' => self::ALL, 'configuration_scopes' => ['OLD' => ['global', 's1']]], ['module_shops' => self::ALL, 'configuration_scopes' => ['NEW' => ['global', 's1']]], 'new configuration NEW has rows ["global","s1"], expected only a global value'],
        ];
    }

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     */
    #[DataProvider('moduleKitFaults')]
    public function testAModuleKitUpgradeIsStrict(array $before, array $after, string $problem): void
    {
        self::assertSame([$problem], msUpgradeStateProblems($before, $after, self::ALL, false));
    }

    public function testALegacyUpgradeKeepsTheShopsOfTheModuleAndTheRowsOfRenamedKeys(): void
    {
        $before = ['module_shops' => [1], 'configuration_scopes' => ['HEAVEN_DELIVERY_COPY_ACCOUNT_EMAIL' => ['s1'], 'HEAVEN_DELIVERY_COPY_EMAIL_SUBJECT' => ['global', 's1', 'g2']]];
        $after = [
            'module_shops' => [1],
            'hook_shops' => ['actionOrderStatusPostUpdate' => self::ALL],
            'configuration_scopes' => ['HEAVENDELIVERYCOPY_RECIPIENTS' => ['global', 's1'], 'HEAVENDELIVERYCOPY_SUBJECT' => ['global', 's1', 'g2']],
        ];

        self::assertSame([], msUpgradeStateProblems($before, $after, self::ALL, true));
    }

    /**
     * Legacy upgrades: what the upgrade still must not do.
     *
     * @return array<string, array{array<string, mixed>, array<string, mixed>, string}>
     */
    public static function legacyFaults(): array
    {
        $before = ['module_shops' => [1], 'configuration_scopes' => ['OLD' => ['s1']]];

        return [
            'module switched on in other shops' => [$before, ['module_shops' => self::ALL], 'after the upgrade the module is enabled in shops [1,2,3], before the upgrade in [1]'],
            'module switched off' => [$before, ['module_shops' => []], 'after the upgrade the module is enabled in shops [], before the upgrade in [1]'],
            'hook not in every shop' => [$before, ['module_shops' => [1], 'hook_shops' => ['displayHome' => [1]]], 'after the upgrade hook displayHome is registered for shops [1]'],
            'a shop row no removed key had' => [$before, ['module_shops' => [1], 'configuration_scopes' => ['NEW' => ['global', 's2']]], 'new configuration NEW has rows ["global","s2"], expected a global value plus at most the shop and group rows of a removed key ["s1"]'],
            'no global value' => [$before, ['module_shops' => [1], 'configuration_scopes' => ['NEW' => ['s1']]], 'new configuration NEW has rows ["s1"], expected a global value plus at most the shop and group rows of a removed key ["s1"]'],
            'old key still there, so nothing was renamed' => [$before, ['module_shops' => [1], 'configuration_scopes' => ['OLD' => ['s1'], 'NEW' => ['global', 's1']]], 'new configuration NEW has rows ["global","s1"], expected a global value plus at most the shop and group rows of a removed key []'],
        ];
    }

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     */
    #[DataProvider('legacyFaults')]
    public function testALegacyUpgradeStillChecksTheRest(array $before, array $after, string $problem): void
    {
        self::assertSame([$problem], msUpgradeStateProblems($before, $after, self::ALL, true));
    }

    public function testEmptySnapshotsOfALegacyReleaseWithoutModuleShops(): void
    {
        self::assertSame([], msUpgradeStateProblems([], [], self::ALL, true));
        self::assertSame(['after the upgrade the module is enabled in shops []'], msUpgradeStateProblems([], [], self::ALL, false));
    }
}
