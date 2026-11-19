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

require_once __DIR__ . '/../src/smoke-error.php';

/**
 * src/smoke-error.php: PHP error output fails a smoke test, the same words in a normal text do not.
 */
final class SmokeErrorTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: bool, 2: ?string}>
     */
    public static function bodies(): array
    {
        return [
            'php html fatal' => ['<br /><b>Fatal error</b>:  Uncaught Error: x in /var/www/a.php', false, 'Fatal error'],
            'php text warning' => ['Warning: Undefined variable $x in /var/www/a.php on line 3', false, 'Warning'],
            'deprecated' => ['<b>Deprecated</b>: Creation of dynamic property', false, 'Deprecated'],
            'uncaught alone' => ['PHP message: Uncaught TypeError in x', false, 'Uncaught'],
            'help text with the words' => ['<p>Hooks mode can cause a fatal error. A warning is shown. Notice the cron.</p>', true, null],
            'warning without colon' => ['<div class="alert">Warning</div>', true, null],
            'stack trace in the back office' => ['<h3>Stack Trace</h3>', true, 'Stack trace'],
            'stack trace only counts in the back office' => ['Stack trace: #0', false, null],
            'invalid token page' => ['<h1>Invalid token</h1>', true, 'Invalid token'],
            'clean page' => ['<html><body>Shop</body></html>', true, null],
        ];
    }

    #[PHPUnit\Framework\Attributes\DataProvider('bodies')]
    public function testFindsOnlyRealErrorOutput(string $body, bool $backOffice, ?string $expected): void
    {
        $found = smokeError($body, $backOffice);

        self::assertSame($expected, $found[0] ?? null);
    }

    public function testReportsTheFirstErrorAndItsPosition(): void
    {
        self::assertSame(['Notice', 3], smokeError('ab Notice: x <b>Fatal error</b>: y', false));
    }
}
