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
 * The job queue for the heavy work of module threads (workspace docs/factory/queue.md), used with the shared
 * instances (instance.php): heavy commands take a lease with queue_lease() and wait for their turn; work can also be
 * handed off as a job (docs builds and shop tests). One queue per pool ("docs", "shops"), one worker process per pool that
 * runs at most `max` jobs at once (default 3), never two jobs of the same module at once, and an exclusive job alone.
 * Module threads submit a job and then wait: they ask the queue for the status and fetch the result when it is done.
 *
 *   ps-module queue submit --pool=docs|shops [--exclusive] [--thread=<label>] [--priority=N] -- <command ...>
 *                                     add a job (run in the module folder); prints the job id and the queue position.
 *                                     The same command of the same module while one is queued gives the same job.
 *   ps-module queue status <id> [--json]    queued (position n) / running (since) / done / failed / cancelled
 *   ps-module queue wait <id> [--timeout=S] wait until it ends or S seconds pass (default 540, under the 10-minute
 *                                     tool limit); exit 0 done, 1 failed or cancelled, 3 still waiting
 *   ps-module queue result <id> [--lines=N]  exit code, times and the last N lines of the log; marks it as read
 *   ps-module queue list [--json] [--pool=X] every job: state, module, thread, position, age; the coordinator's view
 *   ps-module queue cancel <id>              a queued job is cancelled, a running one is stopped
 *   ps-module queue set --pool=X --max=N     change the limit while running (raise: starts at once; lower: running jobs finish)
 *   ps-module queue pools                    per pool: the limit, running and queued jobs
 *   ps-module queue work --pool=X [--once]   the worker (started by submit when none runs; --once: one pass, tests)
 *
 * Storage: <workspace>/.cache/queue/<pool>/<id>.json per job, <id>.log for its output; every change under the
 * lock queue-<pool> (lock.php). Settings: <workspace>/.cache/queue/pools.json {"docs": {"max": 3}, ...}.
 */

declare(strict_types=1);

if (!function_exists('ps_lock')) {
    require_once __DIR__ . '/lock.php';
}

/** The pools and their defaults: at most `max` jobs at once. */
const QUEUE_POOLS = ['docs' => ['max' => 3], 'shops' => ['max' => 3]];

/** The states of a job. */
const QUEUE_OPEN = ['queued', 'running'];

/**
 * The queue folder of the workspace above $from (the folder with libraries/ and modules/), or $from/.cache/queue.
 */
function queue_dir(string $from): string
{
    $dir = rtrim(str_replace('\\', '/', (string) (realpath($from) ?: $from)), '/');
    for ($d = $dir, $i = 0; $i < 8 && $d !== dirname($d); ++$i, $d = dirname($d)) {
        if (is_dir("$d/libraries") && is_dir("$d/modules")) {
            return "$d/.cache/queue";
        }
    }

    return "$dir/.cache/queue";
}

/**
 * The settings of a pool (pools.json over the defaults).
 *
 * @return array{max: int}
 *
 * @throws InvalidArgumentException for an unknown pool
 */
function queue_pool(string $dir, string $pool): array
{
    if (!isset(QUEUE_POOLS[$pool])) {
        throw new InvalidArgumentException("Unknown pool $pool (known: " . implode(', ', array_keys(QUEUE_POOLS)) . ').');
    }
    $settings = json_decode((string) @file_get_contents("$dir/pools.json"), true);
    $max = (int) ($settings[$pool]['max'] ?? QUEUE_POOLS[$pool]['max']);

    return ['max' => max(1, $max)];
}

/**
 * Changes the limit of a pool while the queue runs. The worker reads it on every pass (every 2 seconds): a higher
 * limit starts waiting jobs at once; a lower one lets the running jobs finish and starts nothing new until the pool is
 * below it.
 *
 * @throws InvalidArgumentException for an unknown pool or a limit below 1
 */
function queue_set_max(string $dir, string $pool, int $max): void
{
    queue_pool($dir, $pool);
    if ($max < 1 || $max > 16) {
        throw new InvalidArgumentException('The limit of a pool is 1 to 16.');
    }
    @mkdir($dir, 0775, true);
    queue_locked($dir, $pool, static function () use ($dir, $pool, $max): void {
        $settings = json_decode((string) @file_get_contents("$dir/pools.json"), true);
        $settings = is_array($settings) ? $settings : [];
        $settings[$pool]['max'] = $max;
        file_put_contents("$dir/pools.json.tmp", json_encode($settings, JSON_PRETTY_PRINT) . "\n");
        rename("$dir/pools.json.tmp", "$dir/pools.json");
    });
}

/**
 * Every job of a pool, oldest first.
 *
 * @return list<array<string, mixed>>
 */
function queue_jobs(string $dir, string $pool): array
{
    $jobs = [];
    foreach (glob("$dir/$pool/*.json") ?: [] as $file) {
        $job = json_decode((string) file_get_contents($file), true);
        if (is_array($job) && isset($job['id'])) {
            $jobs[] = $job;
        }
    }
    usort($jobs, static fn (array $a, array $b): int => [$b['priority'] ?? 0, $a['created'] ?? 0, $a['id']] <=> [$a['priority'] ?? 0, $b['created'] ?? 0, $b['id']]);

    return $jobs;
}

/**
 * One job by id (any pool), or null.
 *
 * @return array<string, mixed>|null
 */
function queue_job(string $dir, string $id): ?array
{
    foreach (array_keys(QUEUE_POOLS) as $pool) {
        $file = "$dir/$pool/$id.json";
        if (is_file($file)) {
            $job = json_decode((string) file_get_contents($file), true);

            return is_array($job) ? $job : null;
        }
    }

    return null;
}

/**
 * Writes a job (atomically: temp file, then rename).
 *
 * @param array<string, mixed> $job
 */
function queue_save(string $dir, array $job): void
{
    @mkdir("$dir/{$job['pool']}", 0775, true);
    $file = "$dir/{$job['pool']}/{$job['id']}.json";
    file_put_contents("$file.tmp", json_encode($job, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    rename("$file.tmp", $file);
}

/**
 * Runs $change with the pool lock held (no two processes change a pool at the same time).
 *
 * @template T
 *
 * @param callable(): T $change
 *
 * @return T
 */
function queue_locked(string $dir, string $pool, callable $change): mixed
{
    $held = ps_lock_held("queue-$pool");
    ps_lock_at("$dir/locks", "queue-$pool", 'queue change', 120);
    try {
        return $change();
    } finally {
        if (!$held) {
            ps_unlock("queue-$pool");
        }
    }
}

/**
 * Adds a job; the same command of the same module that is still queued returns that job instead.
 *
 * @param list<string> $command
 *
 * @return array<string, mixed> the job
 */
function queue_submit(string $dir, string $pool, string $module, string $cwd, array $command, bool $exclusive = false, string $thread = '', int $priority = 0, ?float $now = null): array
{
    queue_pool($dir, $pool);
    if ($command === [] || preg_match('/^[a-z0-9_-]+$/', $module) !== 1) {
        throw new InvalidArgumentException('A job needs a module name and a command.');
    }

    return queue_locked($dir, $pool, static function () use ($dir, $pool, $module, $cwd, $command, $exclusive, $thread, $priority, $now): array {
        foreach (queue_jobs($dir, $pool) as $job) {
            if ($job['state'] === 'queued' && $job['module'] === $module && $job['command'] === $command) {
                return $job;
            }
        }
        $time = $now ?? microtime(true);
        $job = [
            'id' => $pool . '-' . gmdate('Ymd-His', (int) $time) . '-' . bin2hex(random_bytes(3)),
            'pool' => $pool,
            'module' => $module,
            'cwd' => str_replace('\\', '/', $cwd),
            'command' => $command,
            'exclusive' => $exclusive,
            'thread' => $thread,
            'priority' => $priority,
            'state' => 'queued',
            'created' => $time,
            'started' => null,
            'finished' => null,
            'exit' => null,
            'pid' => null,
            'result_read' => false,
            'attempts' => 0,
        ];
        queue_save($dir, $job);

        return $job;
    });
}

/**
 * The 1-based position of a queued job in its pool (0 when it is not queued).
 */
function queue_position(string $dir, array $job): int
{
    if ($job['state'] !== 'queued') {
        return 0;
    }
    $position = 0;
    foreach (queue_jobs($dir, $job['pool']) as $other) {
        if ($other['state'] === 'queued') {
            ++$position;
            if ($other['id'] === $job['id']) {
                return $position;
            }
        }
    }

    return 0;
}

/**
 * The jobs that may start now, in order: a free slot (max), not a module that already runs in the pool, an exclusive
 * job only when nothing runs, and nothing else while an exclusive job runs.
 *
 * @param list<array<string, mixed>> $jobs
 *
 * @return list<array<string, mixed>>
 */
function queue_startable(array $jobs, int $max): array
{
    $running = array_values(array_filter($jobs, static fn (array $j): bool => $j['state'] === 'running'));
    foreach ($running as $job) {
        if (!empty($job['exclusive'])) {
            return [];
        }
    }
    $busyModules = array_flip(array_map(static fn (array $j): string => $j['module'], $running));
    $free = $max - count($running);
    $start = [];
    foreach ($jobs as $job) {
        if ($free <= 0 || $job['state'] !== 'queued' || isset($busyModules[$job['module']])) {
            continue;
        }
        if (!empty($job['exclusive'])) {
            if ($running === [] && $start === []) {
                return [$job];
            }
            break; // an exclusive job waits for the pool to empty; later jobs wait behind it (no starvation)
        }
        $start[] = $job;
        $busyModules[$job['module']] = true;
        --$free;
    }

    return $start;
}

/**
 * One pass of the worker: finished processes are recorded, then the startable jobs are started. Returns the number
 * of jobs that are still open (queued or running).
 *
 * @param array<string, array{process: resource, log: resource}> $processes the running processes of this worker, by job id
 * @param callable(array<string, mixed>, string): array{process: resource, log: resource}|null $spawn starts a job (tests pass their own)
 */
function queue_tick(string $dir, string $pool, array &$processes, ?callable $spawn = null, ?float $now = null): int
{
    $spawn ??= 'queue_spawn';

    return queue_locked($dir, $pool, static function () use ($dir, $pool, &$processes, $spawn, $now): int {
        $time = $now ?? microtime(true);
        $jobs = queue_jobs($dir, $pool);
        foreach ($jobs as &$job) {
            // A lease is started and ended by its own holder (queue_lease()), never by the worker.
            if ($job['state'] !== 'running' || !empty($job['lease'])) {
                continue;
            }
            $own = $processes[$job['id']] ?? null;
            if ($own !== null) {
                $status = proc_get_status($own['process']);
                if (!$status['running']) {
                    $exit = $status['exitcode'];
                    proc_close($own['process']);
                    fclose($own['log']);
                    unset($processes[$job['id']]);
                    $job['state'] = $exit === 0 ? 'done' : 'failed';
                    $job['exit'] = $exit;
                    $job['finished'] = $time;
                    queue_save($dir, $job);
                }
            } elseif (($job['worker'] ?? '') !== queue_worker_id()) {
                // A job of a worker that is gone (crash, reboot): back in the queue once, then failed.
                $job['state'] = ++$job['attempts'] >= 2 ? 'failed' : 'queued';
                $job['exit'] = $job['state'] === 'failed' ? -1 : null;
                $job['finished'] = $job['state'] === 'failed' ? $time : null;
                queue_save($dir, $job);
            }
        }
        unset($job);
        foreach (queue_startable($jobs, queue_pool($dir, $pool)['max']) as $job) {
            if (!empty($job['lease'])) {
                continue;
            }
            $log = "$dir/$pool/{$job['id']}.log";
            $started = $spawn($job, $log);
            if ($started === null) {
                $job['state'] = 'failed';
                $job['exit'] = -1;
                $job['finished'] = $time;
            } else {
                $processes[$job['id']] = $started;
                $status = proc_get_status($started['process']);
                $job['state'] = 'running';
                $job['started'] = $time;
                $job['pid'] = $status['pid'];
                $job['worker'] = queue_worker_id();
            }
            queue_save($dir, $job);
        }

        return count(array_filter(queue_jobs($dir, $pool), static fn (array $j): bool => in_array($j['state'], QUEUE_OPEN, true)));
    });
}

/**
 * Whether a process runs (a lease whose holder is gone is ended by the next one that waits).
 */
function queue_pid_alive(int $pid): bool
{
    if ($pid <= 0) {
        return false;
    }
    if (PHP_OS_FAMILY === 'Windows') {
        $out = (string) shell_exec('tasklist /FI "PID eq ' . $pid . '" /NH 2>NUL');

        return preg_match('/\s' . $pid . '\s/', $out) === 1;
    }

    return function_exists('posix_kill') ? posix_kill($pid, 0) : is_dir("/proc/$pid");
}

/**
 * A lease: this process waits for its turn in the pool and then does the heavy work itself (the module thread keeps
 * its own output). The lease counts like a job for the limit, one-per-module and exclusive rules; it starts when the
 * rules allow it and ends with queue_release() (or, when the holder dies, the next waiting process ends it). Child
 * processes see PS_MODULE_QUEUE_LEASE and do not wait again. Returns the lease id, or null when this process already
 * holds one.
 *
 * @param callable(string): void $say progress messages (every minute while waiting)
 * @param callable(int): void $sleep tests pass their own
 *
 * @throws RuntimeException when the wait takes longer than $timeout seconds
 */
function queue_lease(string $dir, string $pool, string $module, string $label, bool $exclusive = false, int $timeout = 14400, ?callable $say = null, ?callable $sleep = null): ?string
{
    if ((string) getenv('PS_MODULE_QUEUE_LEASE') !== '') {
        return null;
    }
    $say ??= static function (string $m): void {
        fwrite(STDERR, $m . "\n");
    };
    $sleep ??= static function (int $s): void {
        sleep($s);
    };
    $job = queue_locked($dir, $pool, static function () use ($dir, $pool, $module, $label, $exclusive): array {
        $time = microtime(true);
        $job = [
            'id' => $pool . '-' . gmdate('Ymd-His', (int) $time) . '-' . bin2hex(random_bytes(3)),
            'pool' => $pool, 'module' => $module, 'cwd' => '', 'command' => [], 'exclusive' => $exclusive, 'thread' => $label,
            'priority' => 0, 'state' => 'queued', 'created' => $time, 'started' => null, 'finished' => null, 'exit' => null,
            'pid' => getmypid(), 'result_read' => true, 'attempts' => 0, 'lease' => true,
        ];
        queue_save($dir, $job);

        return $job;
    });
    $waited = 0;
    $lastSaid = -60;
    while (true) {
        $state = queue_locked($dir, $pool, static function () use ($dir, $pool, $job): string {
            $jobs = queue_jobs($dir, $pool);
            foreach ($jobs as &$other) {
                if (!empty($other['lease']) && in_array($other['state'], QUEUE_OPEN, true) && $other['id'] !== $job['id'] && !queue_pid_alive((int) $other['pid'])) {
                    $other['state'] = $other['state'] === 'running' ? 'done' : 'cancelled';
                    $other['finished'] = microtime(true);
                    $other['note'] = 'holder process gone';
                    queue_save($dir, $other);
                }
            }
            unset($other);
            foreach (queue_startable($jobs, queue_pool($dir, $pool)['max']) as $start) {
                if ($start['id'] === $job['id']) {
                    $start['state'] = 'running';
                    $start['started'] = microtime(true);
                    queue_save($dir, $start);

                    return 'running';
                }
            }

            return 'queued';
        });
        if ($state === 'running') {
            putenv('PS_MODULE_QUEUE_LEASE=' . $job['id']);
            if ($waited > 0) {
                $say("Queue: your turn in the $pool pool after {$waited}s ({$job['id']}).");
            }

            return $job['id'];
        }
        if ($waited >= $timeout) {
            queue_release($dir, $job['id'], 1);
            throw new RuntimeException("Still waiting in the $pool queue after {$waited}s; try again later (ps-module queue list).");
        }
        if ($waited - $lastSaid >= 60) {
            $current = queue_job($dir, $job['id']) ?? $job;
            $say("Queue: waiting in the $pool pool, position " . queue_position($dir, $current) . ' (ps-module queue list).');
            $lastSaid = $waited;
        }
        $sleep(3);
        $waited += 3;
    }
}

/**
 * Ends a lease (or a job) with an exit code.
 */
function queue_release(string $dir, string $id, int $exit): void
{
    $job = queue_job($dir, $id);
    if ($job === null) {
        return;
    }
    queue_locked($dir, $job['pool'], static function () use ($dir, $id, $exit): void {
        $job = queue_job($dir, $id);
        if ($job !== null && in_array($job['state'], QUEUE_OPEN, true)) {
            $job['state'] = $exit === 0 ? 'done' : 'failed';
            $job['exit'] = $exit;
            $job['finished'] = microtime(true);
            queue_save($dir, $job);
        }
    });
    putenv('PS_MODULE_QUEUE_LEASE');
}

/**
 * The id of this worker process (host and pid), to recognise jobs of a worker that is gone.
 */
function queue_worker_id(): string
{
    return gethostname() . ':' . getmypid();
}

/**
 * Starts a job's command in its folder with stdout and stderr in its log.
 *
 * @param array<string, mixed> $job
 *
 * @return array{process: resource, log: resource}|null
 */
function queue_spawn(array $job, string $logFile): ?array
{
    $log = fopen($logFile, 'ab');
    if ($log === false) {
        return null;
    }
    $process = proc_open($job['command'], [0 => ['pipe', 'r'], 1 => $log, 2 => $log], $pipes, $job['cwd']);
    if (!is_resource($process)) {
        fclose($log);

        return null;
    }
    fclose($pipes[0]);

    return ['process' => $process, 'log' => $log];
}

/**
 * The worker loop of a pool: one per pool (lock queue-worker-<pool>); stops when the pool has been empty for $idle
 * seconds.
 */
function queue_work(string $dir, string $pool, int $idle = 60, bool $once = false): void
{
    ps_lock_at("$dir/locks", "queue-worker-$pool", 'queue worker', 1);
    $processes = [];
    $quiet = 0.0;
    do {
        $open = queue_tick($dir, $pool, $processes);
        if ($once) {
            return;
        }
        $quiet = $open === 0 ? $quiet + 2 : 0.0;
        sleep(2);
    } while ($quiet < $idle || $processes !== []);
}

/**
 * Starts the worker of a pool in the background when none runs (the lock queue-worker-<pool> is free).
 */
function queue_ensure_worker(string $dir, string $pool): bool
{
    $handle = @fopen("$dir/locks/queue-worker-$pool.lock", 'c');
    if ($handle !== false) {
        $free = flock($handle, LOCK_EX | LOCK_NB);
        if ($free) {
            flock($handle, LOCK_UN);
        }
        fclose($handle);
        if (!$free) {
            return false;
        }
    }
    @mkdir("$dir/locks", 0775, true);
    // A worker that was just started has not taken its lock yet: do not start a second one within 15 seconds.
    $marker = "$dir/locks/queue-worker-$pool.starting";
    if (is_file($marker) && time() - (int) filemtime($marker) < 15) {
        return false;
    }
    touch($marker);
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' work --pool=' . escapeshellarg($pool) . ' --queue-dir=' . escapeshellarg($dir);
    if (PHP_OS_FAMILY === 'Windows') {
        pclose(popen('start "queue-' . $pool . '" /B ' . $cmd . ' > NUL 2>&1', 'r'));
    } else {
        exec($cmd . ' > /dev/null 2>&1 &');
    }

    return true;
}

/**
 * The status of a job as one line, for status and wait.
 *
 * @param array<string, mixed> $job
 */
function queue_status_line(string $dir, array $job, ?float $now = null): string
{
    $time = $now ?? microtime(true);

    return match ($job['state']) {
        'queued' => 'queued, position ' . queue_position($dir, $job) . ' in ' . $job['pool'],
        'running' => 'running for ' . (int) ($time - (float) $job['started']) . 's',
        'done' => 'done (exit 0) after ' . (int) ((float) $job['finished'] - (float) $job['started']) . 's',
        'failed' => 'failed (exit ' . $job['exit'] . ')',
        default => (string) $job['state'],
    };
}

/**
 * The command line. Returns the exit code.
 *
 * @param list<string> $args
 */
function queue_main(string $cwd, array $args): int
{
    $options = [];
    $rest = [];
    $command = [];
    foreach ($args as $i => $arg) {
        if ($arg === '--') {
            $command = array_slice($args, $i + 1);
            break;
        }
        if (preg_match('/^--([a-z-]+)(?:=(.*))?$/s', $arg, $m) === 1) {
            $options[$m[1]] = $m[2] ?? '1';
        } else {
            $rest[] = $arg;
        }
    }
    $dir = $options['queue-dir'] ?? queue_dir($cwd);
    try {
        switch ($rest[0] ?? '') {
            case 'submit':
                $pool = (string) ($options['pool'] ?? '');
                $module = (string) ($options['module'] ?? basename($cwd));
                $job = queue_submit($dir, $pool, $module, $cwd, $command, isset($options['exclusive']), (string) ($options['thread'] ?? ''), (int) ($options['priority'] ?? 0));
                queue_ensure_worker($dir, $pool);
                echo "{$job['id']} " . queue_status_line($dir, $job) . "\n";

                return 0;
            case 'status':
            case 'wait':
                $job = queue_job($dir, (string) ($rest[1] ?? ''));
                if ($job === null) {
                    fwrite(STDERR, "No job {$rest[1]}.\n");

                    return 2;
                }
                $deadline = microtime(true) + (int) ($options['timeout'] ?? 540);
                while ($rest[0] === 'wait' && in_array($job['state'], QUEUE_OPEN, true) && microtime(true) < $deadline) {
                    queue_ensure_worker($dir, $job['pool']);
                    sleep(5);
                    $job = (array) queue_job($dir, $job['id']);
                }
                if (isset($options['json'])) {
                    echo json_encode($job + ['position' => queue_position($dir, $job)], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
                } else {
                    echo "{$job['id']} " . queue_status_line($dir, $job) . "\n";
                }

                return match ($job['state']) {
                    'done' => 0,
                    'queued', 'running' => $rest[0] === 'wait' ? 3 : 0,
                    default => 1,
                };
            case 'result':
                $job = queue_job($dir, (string) ($rest[1] ?? ''));
                if ($job === null) {
                    fwrite(STDERR, "No job {$rest[1]}.\n");

                    return 2;
                }
                $log = (string) @file_get_contents("$dir/{$job['pool']}/{$job['id']}.log");
                $lines = array_slice(preg_split('/\R/', rtrim($log, " \t\n\r")) ?: [], -max(1, (int) ($options['lines'] ?? 40)));
                echo "{$job['id']} " . queue_status_line($dir, $job) . "\n" . implode("\n", $lines) . "\n";
                if (!in_array($job['state'], QUEUE_OPEN, true)) {
                    queue_locked($dir, $job['pool'], static function () use ($dir, $job): void {
                        $job['result_read'] = true;
                        queue_save($dir, $job);
                    });
                }

                return $job['state'] === 'done' ? 0 : 1;
            case 'list':
                $all = [];
                foreach (isset($options['pool']) ? [$options['pool']] : array_keys(QUEUE_POOLS) as $pool) {
                    foreach (queue_jobs($dir, $pool) as $job) {
                        $all[] = $job + ['position' => queue_position($dir, $job), 'status' => queue_status_line($dir, $job)];
                    }
                }
                if (isset($options['json'])) {
                    echo json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
                } else {
                    foreach ($all as $job) {
                        printf("%-32s %-6s %-26s %-20s %s%s\n", $job['id'], $job['pool'], $job['module'], $job['thread'], $job['status'], !empty($job['result_read']) ? ' (read)' : '');
                    }
                }

                return 0;
            case 'cancel':
                $job = queue_job($dir, (string) ($rest[1] ?? ''));
                if ($job === null || !in_array($job['state'], QUEUE_OPEN, true)) {
                    fwrite(STDERR, "No open job {$rest[1]}.\n");

                    return 2;
                }
                queue_locked($dir, $job['pool'], static function () use ($dir, $job): void {
                    if ($job['state'] === 'running' && !empty($job['pid'])) {
                        exec(PHP_OS_FAMILY === 'Windows' ? 'taskkill /F /T /PID ' . (int) $job['pid'] . ' >NUL 2>&1' : 'kill ' . (int) $job['pid']);
                    }
                    $job['state'] = 'cancelled';
                    $job['finished'] = microtime(true);
                    queue_save($dir, $job);
                });
                echo "Cancelled {$job['id']}.\n";

                return 0;
            case 'set':
                $pool = (string) ($options['pool'] ?? '');
                queue_set_max($dir, $pool, (int) ($options['max'] ?? 0));
                queue_ensure_worker($dir, $pool);
                echo "Pool $pool: at most {$options['max']} job(s) at once (the worker picks it up within 2 seconds).\n";

                return 0;
            case 'pools':
                foreach (array_keys(QUEUE_POOLS) as $pool) {
                    $jobs = queue_jobs($dir, $pool);
                    $count = static fn (string $state): int => count(array_filter($jobs, static fn (array $j): bool => $j['state'] === $state));
                    printf("%-6s max %d, running %d, queued %d\n", $pool, queue_pool($dir, $pool)['max'], $count('running'), $count('queued'));
                }

                return 0;
            case 'work':
                queue_work($dir, (string) ($options['pool'] ?? ''), (int) ($options['idle'] ?? 60), isset($options['once']));

                return 0;
            default:
                fwrite(STDERR, "Usage: ps-module queue submit --pool=docs|shops [--exclusive] [--thread=<label>] -- <command> | status <id> | wait <id> [--timeout=S] | result <id> | list [--json] | cancel <id> | work --pool=X\n");

                return 2;
        }
    } catch (InvalidArgumentException $e) {
        fwrite(STDERR, $e->getMessage() . "\n");

        return 2;
    } catch (RuntimeException $e) {
        fwrite(STDERR, $e->getMessage() . "\n");

        return 1;
    }
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {
    exit(queue_main((string) getcwd(), array_slice($argv, 1)));
}
