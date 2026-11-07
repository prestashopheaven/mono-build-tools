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

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/ps-multistore.php';

/**
 * The multistore round tests the configuration form only for a module with configuration keys: a configure page that
 * only explains (no settings) has no form to save per shop.
 */
final class MultistoreFormTestTest extends TestCase
{
    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function snapshots(): array
    {
        return [
            'configure page with settings' => [['configurable' => true, 'configuration' => ['HEAVENFOO_LIMIT']], 'form'],
            'configure page without settings' => [['configurable' => true, 'configuration' => []], 'no-settings'],
            'configure page, keys missing in the snapshot' => [['configurable' => true], 'no-settings'],
            'no configure page, with settings' => [['configurable' => false, 'configuration' => ['HEAVENFOO_LIMIT']], 'none'],
            'no configure page, no settings' => [['configurable' => false, 'configuration' => []], 'none'],
            'empty snapshot' => [[], 'none'],
        ];
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    #[DataProvider('snapshots')]
    public function testWhichFormTestTheModuleNeeds(array $snapshot, string $expected): void
    {
        self::assertSame($expected, msConfigurationFormTest($snapshot));
    }
}
