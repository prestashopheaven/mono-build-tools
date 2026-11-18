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

/*
 * The CPU guard of the Module-Factory (decision F9: the developer's PC never goes above 80 % CPU, or it heats up and
 * shuts down). Heavy work (a Docker test-shop stack, PHPStan, a docs build) starts only while the whole PC is below the
 * start limit, and parallel tools get a limited number of worker processes.
 *
 *   ps_cpu_wait('PrestaShop 9.2.0 test shop');   // waits (message every minute) until the load is below the start limit
 *
 * Settings (environment or the workspace .env):
 *   PS_MODULE_CPU_LIMIT    the hard limit in percent (default 80); reported when a sample is above it
 *   PS_MODULE_CPU_START    start heavy work only below this load (default 60, at most the limit)
 *   PS_MODULE_CPU_WORKERS  worker processes for PHPStan and PHP-CS-Fixer (default a quarter of the cores, at least 1)
 *   PS_MODULE_SHOP_CPUS    CPUs per PrestaShop container (default 2; the database container gets 1)
 *   PS_MODULE_CPU_GUARD=0  switches the waiting off (CI runners)
 */

declare(strict_types=1);

if (!function_exists('ps_verbose')) {
    require_once __DIR__ . '/verbose.php';
}

/**
 * The current CPU load of the whole machine in percent (0-100), or null when it cannot be measured here.
 */
function ps_cpu_load(): ?int
{
    if (PHP_OS_FAMILY === 'Windows') {
        $out = @shell_exec('powershell -NoProfile -NonInteractive -Command "(Get-CimInstance Win32_Processor | Measure-Object -Property LoadPercentage -Average).Average"');

        return ps_cpu_parse_percent(is_string($out) ? $out : '');
    }
    if (is_readable('/proc/stat')) {
        $first = ps_cpu_proc_stat((string) @file_get_contents('/proc/stat'));
        usleep(500000);
        $second = ps_cpu_proc_stat((string) @file_get_contents('/proc/stat'));

        return $first === null || $second === null ? null : ps_cpu_busy_percent($first, $second);
    }
    $load = function_exists('sys_getloadavg') ? sys_getloadavg() : false;
    $cores = ps_cpu_cores();
    if ($load === false || $cores < 1) {
        return null;
    }

    return max(0, min(100, (int) round($load[0] / $cores * 100)));
}

/**
 * A whole percentage from the output of a command ("37", "37.5"); null when there is none.
 */
function ps_cpu_parse_percent(string $output): ?int
{
    if (preg_match('/^\s*(\d+(?:[.,]\d+)?)\s*$/', $output, $m) !== 1) {
        return null;
    }

    return max(0, min(100, (int) round((float) str_replace(',', '.', $m[1]))));
}

/**
 * The busy and total jiffies of the "cpu" line of /proc/stat, or null when the line is missing.
 *
 * @return array{busy: int, total: int}|null
 */
function ps_cpu_proc_stat(string $stat): ?array
{
    if (preg_match('/^cpu\s+(.+)$/m', $stat, $m) !== 1) {
        return null;
    }
    $values = array_map('intval', preg_split('/\s+/', trim($m[1], " \n\r\t\v\0")) ?: []);
    if (count($values) < 4) {
        return null;
    }
    $idle = $values[3] + ($values[4] ?? 0);
    $total = array_sum($values);

    return ['busy' => $total - $idle, 'total' => $total];
}

/**
 * The load in percent between two /proc/stat samples (0 when no time passed).
 *
 * @param array{busy: int, total: int} $first
 * @param array{busy: int, total: int} $second
 */
function ps_cpu_busy_percent(array $first, array $second): int
{
    $total = $second['total'] - $first['total'];
    if ($total <= 0) {
        return 0;
    }

    return max(0, min(100, (int) round(($second['busy'] - $first['busy']) / $total * 100)));
}

/**
 * The number of logical cores (at least 1).
 */
function ps_cpu_cores(): int
{
    $env = (int) getenv('NUMBER_OF_PROCESSORS');
    if ($env > 0) {
        return $env;
    }
    if (is_readable('/proc/cpuinfo')) {
        $count = substr_count((string) @file_get_contents('/proc/cpuinfo'), "\nprocessor") + (str_starts_with((string) @file_get_contents('/proc/cpuinfo'), 'processor') ? 1 : 0);
        if ($count > 0) {
            return $count;
        }
    }
    $sysctl = @shell_exec('sysctl -n hw.ncpu 2>/dev/null');

    return max(1, (int) (is_string($sysctl) ? trim($sysctl, " \n\r\t\v\0") : 1));
}

/**
 * An integer setting from the environment, or $default when it is not set or not a positive number.
 */
function ps_cpu_setting(string $name, int $default): int
{
    $value = getenv($name);

    return is_string($value) && preg_match('/^\d+$/', $value) === 1 && (int) $value > 0 ? (int) $value : $default;
}

/**
 * The hard CPU limit in percent (PS_MODULE_CPU_LIMIT, default 80, at most 100).
 */
function ps_cpu_limit(): int
{
    return min(100, ps_cpu_setting('PS_MODULE_CPU_LIMIT', 80));
}

/**
 * The load below which heavy work may start (PS_MODULE_CPU_START, default 60, never above the limit).
 */
function ps_cpu_start_limit(): int
{
    return min(ps_cpu_limit(), ps_cpu_setting('PS_MODULE_CPU_START', 60));
}

/**
 * Worker processes for parallel tools (PS_MODULE_CPU_WORKERS, default a quarter of the cores, at least 1).
 *
 * @param int|null $cores the number of cores (ps_cpu_cores() by default)
 */
function ps_cpu_workers(?int $cores = null): int
{
    return ps_cpu_setting('PS_MODULE_CPU_WORKERS', max(1, intdiv($cores ?? ps_cpu_cores(), 4)));
}

/**
 * CPUs per PrestaShop container for docker compose (PS_MODULE_SHOP_CPUS, default 2).
 */
function ps_cpu_shop_cpus(): int
{
    return ps_cpu_setting('PS_MODULE_SHOP_CPUS', 2);
}

/**
 * Waits until the CPU load is below the start limit, then returns the last measured load (null = not measurable, then
 * it does not wait). Prints a message when it has to wait and every minute after that; gives up after $timeout seconds
 * with a RuntimeException. PS_MODULE_CPU_GUARD=0 switches it off.
 *
 * @param callable(): ?int $measure the load measurement (ps_cpu_load by default; tests pass their own)
 * @param callable(int): void $sleep waits a number of seconds (sleep by default)
 * @param callable(string): void $say prints a message (STDERR by default)
 *
 * @throws RuntimeException when the load stays too high for $timeout seconds
 */
function ps_cpu_wait(string $purpose, int $timeout = 3600, ?callable $measure = null, ?callable $sleep = null, ?callable $say = null): ?int
{
    if (getenv('PS_MODULE_CPU_GUARD') === '0') {
        ps_vlog("cpu guard: off (PS_MODULE_CPU_GUARD=0), starting $purpose");

        return null;
    }
    $measure ??= 'ps_cpu_load';
    $sleep ??= static function (int $seconds): void {
        sleep($seconds);
    };
    $say ??= static function (string $message): void {
        fwrite(STDERR, $message . "\n");
    };
    $start = ps_cpu_start_limit();
    $waited = 0;
    $lastMessage = -60;
    while (true) {
        $load = $measure();
        if ($load === null) {
            ps_vlog("cpu guard: load not measurable here, starting $purpose");

            return null;
        }
        if ($load < $start) {
            if ($waited > 0) {
                $say("CPU at $load %: starting $purpose (waited {$waited}s).");
            }
            ps_vlog("cpu guard: load $load % < $start %, starting $purpose");

            return $load;
        }
        if ($waited >= $timeout) {
            throw new RuntimeException("The CPU stayed at or above $start % for {$waited}s; $purpose did not start. Stop other heavy work and try again.");
        }
        if ($waited - $lastMessage >= 60) {
            $say("CPU at $load % (limit " . ps_cpu_limit() . " %, start below $start %): waiting before $purpose.");
            $lastMessage = $waited;
        }
        $sleep(15);
        $waited += 15;
    }
}
