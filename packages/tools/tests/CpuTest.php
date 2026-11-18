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

require_once __DIR__ . '/../src/cpu.php';

/**
 * src/cpu.php: the CPU guard waits while the load is at or above the start limit, starts below it, gives up after the
 * timeout, can be switched off, and reads its settings and the load samples strictly.
 */
final class CpuTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $saved = [];

    /**
     * Saves the settings the tests change.
     */
    protected function setUp(): void
    {
        foreach (['PS_MODULE_CPU_LIMIT', 'PS_MODULE_CPU_START', 'PS_MODULE_CPU_WORKERS', 'PS_MODULE_SHOP_CPUS', 'PS_MODULE_CPU_GUARD', 'NUMBER_OF_PROCESSORS'] as $name) {
            $this->saved[$name] = getenv($name);
            putenv($name);
        }
    }

    /**
     * Restores the settings.
     */
    protected function tearDown(): void
    {
        foreach ($this->saved as $name => $value) {
            putenv($value === false ? $name : "$name=$value");
        }
    }

    public function testStartsAtOnceBelowTheStartLimit(): void
    {
        $messages = [];
        $load = ps_cpu_wait('a test shop', 60, static fn (): int => 40, static function (int $s): void {
            self::fail('no wait expected');
        }, static function (string $m) use (&$messages): void {
            $messages[] = $m;
        });

        self::assertSame(40, $load);
        self::assertSame([], $messages);
    }

    public function testWaitsWhileTheLoadIsHighThenStarts(): void
    {
        $samples = [85, 70, 61, 59];
        $slept = 0;
        $messages = [];
        $load = ps_cpu_wait('PHPStan', 600, static function () use (&$samples): int {
            return (int) array_shift($samples);
        }, static function (int $s) use (&$slept): void {
            $slept += $s;
        }, static function (string $m) use (&$messages): void {
            $messages[] = $m;
        });

        self::assertSame(59, $load);
        self::assertSame(45, $slept);
        self::assertSame('CPU at 85 % (limit 80 %, start below 60 %): waiting before PHPStan.', $messages[0]);
        self::assertSame('CPU at 59 %: starting PHPStan (waited 45s).', end($messages));
        self::assertCount(2, $messages, 'one waiting message per minute');
    }

    public function testGivesUpAfterTheTimeout(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The CPU stayed at or above 60 % for 30s; docs did not start.');

        ps_cpu_wait('docs', 30, static fn (): int => 95, static function (int $s): void {
        }, static function (string $m): void {
        });
    }

    public function testDoesNotWaitWhenTheLoadCannotBeMeasured(): void
    {
        self::assertNull(ps_cpu_wait('x', 60, static fn (): ?int => null));
    }

    public function testCanBeSwitchedOffForCi(): void
    {
        putenv('PS_MODULE_CPU_GUARD=0');

        self::assertNull(ps_cpu_wait('x', 60, static function (): int {
            self::fail('no measurement expected');
        }));
    }

    public function testSettingsHaveDefaultsAndBounds(): void
    {
        self::assertSame(80, ps_cpu_limit());
        self::assertSame(60, ps_cpu_start_limit());
        self::assertSame(2, ps_cpu_shop_cpus());

        putenv('PS_MODULE_CPU_LIMIT=150');
        putenv('PS_MODULE_CPU_START=95');
        self::assertSame(100, ps_cpu_limit());
        self::assertSame(95, ps_cpu_start_limit());

        putenv('PS_MODULE_CPU_LIMIT=70');
        self::assertSame(70, ps_cpu_start_limit(), 'the start limit is never above the limit');

        putenv('PS_MODULE_SHOP_CPUS=abc');
        self::assertSame(2, ps_cpu_shop_cpus(), 'a wrong value falls back to the default');
        putenv('PS_MODULE_SHOP_CPUS=0');
        self::assertSame(2, ps_cpu_shop_cpus(), 'zero falls back to the default');
        putenv('PS_MODULE_SHOP_CPUS=3');
        self::assertSame(3, ps_cpu_shop_cpus());
    }

    public function testWorkersAreAQuarterOfTheCoresAtLeastOne(): void
    {
        self::assertGreaterThanOrEqual(1, ps_cpu_cores());
        self::assertSame(max(1, intdiv(ps_cpu_cores(), 4)), ps_cpu_workers());
        self::assertSame(8, ps_cpu_workers(32));
        self::assertSame(1, ps_cpu_workers(2));

        putenv('PS_MODULE_CPU_WORKERS=5');
        self::assertSame(5, ps_cpu_workers(32));
    }

    public function testParsePercentAcceptsOnlyANumber(): void
    {
        self::assertSame(37, ps_cpu_parse_percent("37\r\n"));
        self::assertSame(38, ps_cpu_parse_percent('37,5'));
        self::assertSame(100, ps_cpu_parse_percent('140'));
        self::assertNull(ps_cpu_parse_percent(''));
        self::assertNull(ps_cpu_parse_percent('Get-CimInstance: access denied'));
    }

    public function testProcStatSamplesGiveTheBusyShare(): void
    {
        $first = ps_cpu_proc_stat("cpu  100 0 100 700 100 0 0 0 0 0\ncpu0 1 2 3 4\n");
        $second = ps_cpu_proc_stat("cpu  200 0 200 800 100 0 0 0 0 0\n");

        self::assertSame(['busy' => 200, 'total' => 1000], $first);
        self::assertNotNull($second);
        self::assertSame(67, ps_cpu_busy_percent($first, $second));
        self::assertSame(0, ps_cpu_busy_percent($second, $second), 'no time passed');
        self::assertNull(ps_cpu_proc_stat('intr 1 2 3'));
        self::assertNull(ps_cpu_proc_stat("cpu  1 2\n"));
    }
}
