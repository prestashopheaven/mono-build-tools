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
 * The shared shop instances of the Module-Factory (workspace docs/factory/queue.md, part B): one instance per pool
 * ("shops" for the shop tests, "docs" for the docs screenshots), each one Docker network `psh-<pool>` with one MySQL
 * server `psh-<pool>-db`, and shop slots `psh-<pool>-s<n>`. A slot is a PrestaShop container made from a template:
 *
 *   psh-tpl:<version>   PrestaShop <version> installed once (committed image) + <workspace>/.cache/instances/
 *                       templates/tpl_<version>.sql (its database); made the first time a slot of that version is needed
 *
 * A slot gets its own database s<n> in the shared MySQL (imported from the template dump), its own port
 * (shops 8300 + n, docs 8400 + n) and the domain localhost:<port>. So every module run has its own fresh shop, but there
 * is one instance per pool and no PrestaShop installer runs per job.
 *
 * Used by ps-test.php (class Shop) when the pool is enabled (instance_enabled(): `ps-module instance enable`, or
 * PS_TEST_INSTANCE); otherwise every module keeps its own compose stacks. Heavy commands also wait for their turn in the
 * queue of the pool (queue.php, queue_lease()).
 *
 *   ps-module instance status [--pool=X]      the instance, its slots and the templates
 *   ps-module instance template <version>     make (or check) the template of a PrestaShop version
 *   ps-module instance down [--pool=X]        remove the slots, the MySQL server and the network (templates stay)
 *   ps-module instance enable|disable [--pool=X]  use the shared instances (and the queue) or not
 */

declare(strict_types=1);

if (!function_exists('ps_lock')) {
    require_once __DIR__ . '/lock.php';
}

/** The first port of each pool's slots (slot n answers on base + n). */
const INSTANCE_PORT_BASE = ['shops' => 8300, 'docs' => 8400];

/** The most slots one instance holds. */
const INSTANCE_MAX_SLOTS = 16;

/** The MySQL image of the shared database server. */
const INSTANCE_DB_IMAGE = 'mysql:8.0';

/** The environment every PrestaShop container of an instance gets (the same admin as the per-module test shops). */
const INSTANCE_PS_ENV = [
    'DB_USER' => 'root', 'DB_PASSWD' => 'prestashop', 'DB_PREFIX' => 'ps_', 'PS_FOLDER_ADMIN' => 'admin-dev',
    'PS_FOLDER_INSTALL' => 'install-dev', 'PS_DEV_MODE' => '1', 'PS_LANGUAGE' => 'en', 'PS_COUNTRY' => 'NL',
    'ADMIN_MAIL' => 'demo@prestashop.com', 'ADMIN_PASSWD' => 'prestashop_demo',
];

/**
 * Whether the shared instance of a pool is used. In order: PS_TEST_INSTANCE (off|0 = never; shops, docs, a list or
 * all = those pools), then CI (never), then the switch file <workspace>/.cache/instances/enabled.json written by
 * `ps-module instance enable|disable`. Off by default, so a repo outside the workspace keeps its own stacks.
 */
function instance_enabled(string $pool, string $from): bool
{
    if (!isset(INSTANCE_PORT_BASE[$pool])) {
        return false;
    }
    $env = strtolower(trim((string) getenv('PS_TEST_INSTANCE')));
    if ($env !== '') {
        if (in_array($env, ['off', '0', 'no', 'false'], true)) {
            return false;
        }
        $list = preg_split('/[\s,]+/', $env) ?: [];

        return in_array('all', $list, true) || in_array('1', $list, true) || in_array($pool, $list, true);
    }
    if ((string) getenv('CI') !== '' || (string) getenv('GITHUB_ACTIONS') !== '') {
        return false;
    }
    $switch = json_decode((string) @file_get_contents(instance_dir($from) . '/enabled.json'), true);

    return is_array($switch) && !empty($switch[$pool]);
}

/**
 * Turns the shared instance of pools on or off (the switch file of instance_enabled()).
 *
 * @param list<string> $pools
 */
function instance_switch(string $dir, array $pools, bool $on): void
{
    @mkdir($dir, 0775, true);
    $switch = json_decode((string) @file_get_contents("$dir/enabled.json"), true);
    $switch = is_array($switch) ? $switch : [];
    foreach ($pools as $pool) {
        $switch[$pool] = $on;
    }
    file_put_contents("$dir/enabled.json", json_encode($switch, JSON_PRETTY_PRINT) . "\n");
}

/**
 * The pool of the shared instance this process uses, or null for the module's own compose stacks: the pool is
 * PS_TEST_POOL (docs sets "docs" for its shop) or "shops", when that pool is enabled (instance_enabled()).
 */
function instance_pool(string $from): ?string
{
    $pool = (string) getenv('PS_TEST_POOL');
    $pool = $pool === '' ? 'shops' : $pool;

    return instance_enabled($pool, $from) ? $pool : null;
}
/**
 * The folder for the instance's state and templates: <workspace>/.cache/instances (the workspace above $from).
 */
function instance_dir(string $from): string
{
    $dir = rtrim(str_replace('\\', '/', (string) (realpath($from) ?: $from)), '/');
    for ($d = $dir, $i = 0; $i < 8 && $d !== dirname($d); ++$i, $d = dirname($d)) {
        if (is_dir("$d/libraries") && is_dir("$d/modules")) {
            return "$d/.cache/instances";
        }
    }

    return "$dir/.cache/instances";
}

/**
 * A safe tag of a PrestaShop version for names (9.2.1 => 921, 9.2-8.4 => 92-84).
 */
function instance_tag(string $version): string
{
    return str_replace('.', '', (string) preg_replace('/[^0-9.\-]/', '', $version));
}

/**
 * Runs a command (a list of arguments); returns [exit code, output].
 *
 * @param list<string> $command
 *
 * @return array{0: int, 1: string}
 */
function instance_run(array $command): array
{
    // An argument list (no shell): on Windows escapeshellarg() would turn quotes and % into spaces.
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes);
    if (!is_resource($process)) {
        return [1, 'cannot start ' . $command[0]];
    }
    fclose($pipes[0]);
    $out = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $code = proc_close($process);

    return [$code, trim($out, " \n\r\t\v\0")];
}

/**
 * The docker run arguments for the environment of a PrestaShop container.
 *
 * @param array<string, string> $extra
 *
 * @return list<string>
 */
function instance_env_args(string $pool, array $extra): array
{
    $args = [];
    foreach (INSTANCE_PS_ENV + ['DB_SERVER' => "psh-$pool-db"] + $extra as $name => $value) {
        array_push($args, '-e', "$name=$value");
    }

    return $args;
}

/**
 * Starts the network and the MySQL server of a pool when they are not there; waits until MySQL answers.
 *
 * @throws RuntimeException when MySQL does not come up
 */
function instance_up(string $pool): void
{
    if (instance_run(['docker', 'network', 'inspect', "psh-$pool"])[0] !== 0) {
        instance_run(['docker', 'network', 'create', "psh-$pool"]);
    }
    $db = "psh-$pool-db";
    [$code, $running] = instance_run(['docker', 'inspect', '-f', '{{.State.Running}}', $db]);
    if ($code !== 0) {
        instance_run(['docker', 'run', '-d', '--name', $db, '--network', "psh-$pool", '-e', 'MYSQL_ROOT_PASSWORD=prestashop', '--tmpfs', '/var/lib/mysql', INSTANCE_DB_IMAGE]);
    } elseif ($running !== 'true') {
        instance_run(['docker', 'start', $db]);
    }
    for ($i = 0; $i < 90; ++$i) {
        if (instance_run(['docker', 'exec', $db, 'mysqladmin', 'ping', '-h127.0.0.1', '-uroot', '-pprestashop'])[0] === 0) {
            return;
        }
        sleep(2);
    }

    throw new RuntimeException("The MySQL server $db did not start.");
}

/**
 * Runs SQL in the shared MySQL of a pool.
 *
 * @throws RuntimeException when it fails
 */
function instance_sql(string $pool, string $sql, string $database = ''): void
{
    $command = ['docker', 'exec', "psh-$pool-db", 'mysql', '-uroot', '-pprestashop'];
    if ($database !== '') {
        $command[] = $database;
    }
    array_push($command, '-e', $sql);
    [$code, $out] = instance_run($command);
    if ($code !== 0) {
        throw new RuntimeException("MySQL in psh-$pool-db failed: " . preg_replace('/^mysql: \[Warning\].*\n?/m', '', $out));
    }
}

/**
 * Makes the template of a PrestaShop version when it is missing: a fresh install in the pool's instance, committed
 * as image psh-tpl:<tag>, and its database dumped to templates/tpl_<tag>.sql. Locked, so two jobs never build the same
 * template. Returns the dump file.
 *
 * @throws RuntimeException when the install fails
 */
function instance_template(string $dir, string $pool, string $version, ?callable $say = null): string
{
    $say ??= static function (string $m): void {
        fwrite(STDERR, $m . "\n");
    };
    $tag = instance_tag($version);
    $dump = "$dir/templates/tpl_$tag.sql";
    $image = "psh-tpl:$tag";
    $ready = static fn (): bool => is_file($dump) && filesize($dump) > 0 && instance_run(['docker', 'image', 'inspect', $image])[0] === 0;
    if ($ready()) {
        return $dump;
    }
    $held = ps_lock_held("template-$tag");
    ps_lock_at("$dir/locks", "template-$tag", "making the template of PrestaShop $version", 1800);
    try {
        if ($ready()) {
            return $dump;
        }
        @mkdir("$dir/templates", 0775, true);
        instance_up($pool);
        $build = "psh-build-$tag";
        $port = (string) (INSTANCE_PORT_BASE[$pool] + 99);
        $say("Making the template of PrestaShop $version (once; later slots start from it)...");
        instance_run(['docker', 'rm', '-f', $build]);
        instance_sql($pool, "DROP DATABASE IF EXISTS tpl_$tag; CREATE DATABASE tpl_$tag");
        [$code, $out] = instance_run(array_merge(['docker', 'run', '-d', '--name', $build, '--network', "psh-$pool", '-p', "$port:80"],
            instance_env_args($pool, ['DB_NAME' => "tpl_$tag", 'PS_INSTALL_AUTO' => '1', 'PS_DOMAIN' => "localhost:$port"]), ["prestashop/prestashop:$version"]));
        if ($code !== 0) {
            throw new RuntimeException("Starting PrestaShop $version failed: $out");
        }
        $deadline = time() + 1200;
        while (true) {
            [, $state] = instance_run(['docker', 'inspect', '-f', '{{.State.Running}}/{{.State.ExitCode}}', $build]);
            if ($state === 'false/0') {
                instance_run(['docker', 'start', $build]); // the 9.2 image stops once after a successful install
            } elseif (str_starts_with($state, 'false/')) {
                throw new RuntimeException("The install of PrestaShop $version failed: " . instance_run(['docker', 'logs', '--tail', '5', $build])[1]);
            }
            if (instance_http("http://localhost:$port/") === 200 && instance_run(['docker', 'exec', $build, 'test', '-f', '/var/www/html/app/config/parameters.php'])[0] === 0) {
                break;
            }
            if (time() > $deadline) {
                throw new RuntimeException("PrestaShop $version did not finish its install in 20 minutes.");
            }
            sleep(3);
        }
        instance_run(['docker', 'exec', $build, 'sh', '-c', 'rm -rf /var/www/html/var/cache/*']);
        [$code, $out] = instance_run(['docker', 'commit', $build, $image]);
        if ($code !== 0) {
            throw new RuntimeException("docker commit failed: $out");
        }
        instance_run(['docker', 'exec', "psh-$pool-db", 'sh', '-c', "mysqldump -uroot -pprestashop --single-transaction tpl_$tag > /tmp/tpl_$tag.sql"]);
        [$code, $out] = instance_run(['docker', 'cp', "psh-$pool-db:/tmp/tpl_$tag.sql", "$dump.tmp"]);
        if ($code !== 0 || !is_file("$dump.tmp")) {
            throw new RuntimeException("Copying the template dump failed: $out");
        }
        rename("$dump.tmp", $dump);
        instance_run(['docker', 'rm', '-f', $build]);
        instance_sql($pool, "DROP DATABASE IF EXISTS tpl_$tag");
        $say("Template psh-tpl:$tag is ready.");

        return $dump;
    } finally {
        if (!$held) {
            ps_unlock("template-$tag");
        }
    }
}

/**
 * Whether a template (image psh-tpl:<tag> and its dump) is there.
 */
function instance_template_ready(string $dir, string $tag): bool
{
    $dump = "$dir/templates/tpl_$tag.sql";

    return is_file($dump) && filesize($dump) > 0 && instance_run(['docker', 'image', 'inspect', "psh-tpl:$tag"])[0] === 0;
}

/**
 * Saves a running slot as a derived template <tag> (image + dump), for a state that is slow to make and the same
 * every time, such as a version with the languages of the docs installed. Later slots start from it with
 * instance_slot_up(..., $template).
 *
 * @throws RuntimeException when a step fails
 */
function instance_template_save(string $dir, string $pool, int $index, string $tag): void
{
    if (preg_match('/^[a-z0-9][a-z0-9_.-]*$/', $tag) !== 1) {
        throw new InvalidArgumentException("invalid template tag: $tag");
    }
    @mkdir("$dir/templates", 0775, true);
    $name = "psh-$pool-s$index";
    $dump = "$dir/templates/tpl_$tag.sql";
    instance_run(['docker', 'exec', '-u', 'root', $name, 'sh', '-c', 'rm -rf /var/www/html/var/cache/*']);
    [$code, $out] = instance_run(['docker', 'commit', $name, "psh-tpl:$tag"]);
    if ($code !== 0) {
        throw new RuntimeException("docker commit failed: $out");
    }
    [$code, $out] = instance_run(['docker', 'exec', "psh-$pool-db", 'sh', '-c', "mysqldump -uroot -pprestashop --single-transaction s$index > /tmp/tpl_$tag.sql"]);
    if ($code !== 0) {
        throw new RuntimeException("Dumping the database of $name failed: $out");
    }
    [$code, $out] = instance_run(['docker', 'cp', "psh-$pool-db:/tmp/tpl_$tag.sql", "$dump.tmp"]);
    if ($code !== 0 || !is_file("$dump.tmp")) {
        throw new RuntimeException("Copying the template dump failed: $out");
    }
    rename("$dump.tmp", $dump);
}

/**
 * The HTTP status of a URL (0 when it does not answer); no redirects followed.
 */
function instance_http(string $url): int
{
    $ch = curl_init($url);
    if ($ch === false) {
        return 0;
    }
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_FOLLOWLOCATION => false]);
    curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

    return $status;
}

/**
 * The reserved slots of a pool: index => {module, version, pid, since}.
 *
 * @return array<int, array{module: string, version: string, pid: int, since: int}>
 */
function instance_slots(string $dir, string $pool): array
{
    $data = json_decode((string) @file_get_contents("$dir/$pool-slots.json"), true);

    return is_array($data) ? array_map(static fn (array $s): array => $s, array_filter($data, 'is_array')) : [];
}

/**
 * The slot a module and version hold in a pool, or null (without reserving one).
 */
function instance_slot_of(string $dir, string $pool, string $module, string $version): ?int
{
    foreach (instance_slots($dir, $pool) as $index => $slot) {
        if ($slot['module'] === $module && $slot['version'] === $version) {
            return (int) $index;
        }
    }

    return null;
}

/**
 * The slot of a module and version in a pool: the one it already has, else the lowest free index (a slot whose
 * container is gone and that is older than 10 minutes is free again). Reserved under the lock instance-<pool>.
 *
 * @param callable(string): bool $containerExists tests pass their own
 *
 * @throws RuntimeException when all slots are taken
 */
function instance_reserve(string $dir, string $pool, string $module, string $version, ?callable $containerExists = null, ?int $now = null): int
{
    $containerExists ??= static fn (string $name): bool => instance_run(['docker', 'inspect', $name])[0] === 0;
    @mkdir($dir, 0775, true);
    $held = ps_lock_held("instance-$pool");
    ps_lock_at("$dir/locks", "instance-$pool", 'slot reservation', 120);
    try {
        $time = $now ?? time();
        $slots = instance_slots($dir, $pool);
        foreach ($slots as $index => $slot) {
            if ($slot['module'] === $module && $slot['version'] === $version) {
                return (int) $index;
            }
        }
        for ($index = 1; $index <= INSTANCE_MAX_SLOTS; ++$index) {
            $slot = $slots[$index] ?? null;
            $stale = $slot !== null && $time - (int) $slot['since'] > 600 && !$containerExists("psh-$pool-s$index");
            if ($slot === null || $stale) {
                $slots[$index] = ['module' => $module, 'version' => $version, 'pid' => getmypid(), 'since' => $time];
                ksort($slots);
                file_put_contents("$dir/$pool-slots.json", json_encode($slots, JSON_PRETTY_PRINT));

                return $index;
            }
        }

        throw new RuntimeException("All " . INSTANCE_MAX_SLOTS . " slots of the $pool instance are taken (ps-module instance status).");
    } finally {
        if (!$held) {
            ps_unlock("instance-$pool");
        }
    }
}

/**
 * Frees the slot of a module and version (the container and its database are removed by instance_slot_down()).
 */
function instance_release(string $dir, string $pool, int $index): void
{
    $held = ps_lock_held("instance-$pool");
    ps_lock_at("$dir/locks", "instance-$pool", 'slot release', 120);
    try {
        $slots = instance_slots($dir, $pool);
        unset($slots[$index]);
        file_put_contents("$dir/$pool-slots.json", json_encode($slots, JSON_PRETTY_PRINT));
    } finally {
        if (!$held) {
            ps_unlock("instance-$pool");
        }
    }
}

/**
 * Starts slot $index from the template of $version: a fresh database s<index>, the slot's domain, the container from
 * the template image pointed at that database. Waits until the front page answers.
 *
 * @throws RuntimeException when a step fails
 */
function instance_slot_up(string $dir, string $pool, int $index, string $version, ?callable $say = null, ?string $template = null): void
{
    instance_up($pool);
    $dump = instance_template($dir, $pool, $version, $say);
    $tag = instance_tag($version);
    // A derived template (instance_template_save(), e.g. the version with the docs languages) when it is there.
    if ($template !== null && $template !== '' && instance_template_ready($dir, $template)) {
        $tag = $template;
        $dump = "$dir/templates/tpl_$template.sql";
    }
    $name = "psh-$pool-s$index";
    $port = INSTANCE_PORT_BASE[$pool] + $index;
    $domain = "localhost:$port";
    $database = "s$index";
    instance_run(['docker', 'rm', '-f', $name]);
    instance_sql($pool, "DROP DATABASE IF EXISTS $database; CREATE DATABASE $database");
    [$code, $out] = instance_run(['docker', 'cp', $dump, "psh-$pool-db:/tmp/tpl_$tag.sql"]);
    if ($code !== 0) {
        throw new RuntimeException("Copying the template dump into MySQL failed: $out");
    }
    [$code, $out] = instance_run(['docker', 'exec', "psh-$pool-db", 'sh', '-c', "mysql -uroot -pprestashop $database < /tmp/tpl_$tag.sql"]);
    if ($code !== 0) {
        throw new RuntimeException("Importing the template failed: $out");
    }
    instance_sql($pool, "UPDATE ps_shop_url SET domain='$domain', domain_ssl='$domain'; UPDATE ps_configuration SET value='$domain' WHERE name IN ('PS_SHOP_DOMAIN', 'PS_SHOP_DOMAIN_SSL')", $database);
    [$code, $out] = instance_run(array_merge(['docker', 'run', '-d', '--name', $name, '--network', "psh-$pool", '--network-alias', $name, '-p', "$port:80"],
        instance_env_args($pool, ['DB_NAME' => $database, 'PS_INSTALL_AUTO' => '0', 'PS_DOMAIN' => $domain]), ["psh-tpl:$tag"]));
    if ($code !== 0) {
        throw new RuntimeException("Starting the slot $name failed: $out");
    }
    $fix = "\$f = '/var/www/html/app/config/parameters.php'; \$t = file_get_contents(\$f); \$t = preg_replace(\"/'database_name' => '[^']*'/\", \"'database_name' => '$database'\", \$t); file_put_contents(\$f, \$t);";
    [$code, $out] = instance_run(['docker', 'exec', '-u', 'root', $name, 'php', '-r', $fix]);
    if ($code !== 0) {
        throw new RuntimeException("Pointing the slot at its database failed: $out");
    }
    instance_run(['docker', 'exec', '-u', 'root', $name, 'sh', '-c', 'rm -rf /var/www/html/var/cache/* && chown -R www-data:www-data /var/www/html/var']);
    for ($i = 0; $i < 120; ++$i) {
        if (in_array(instance_http("http://$domain/"), [200, 301, 302], true)) {
            return;
        }
        sleep(1);
    }

    throw new RuntimeException("The slot $name does not answer on http://$domain/.");
}

/**
 * Removes a slot's container and database.
 */
function instance_slot_down(string $pool, int $index): void
{
    instance_run(['docker', 'rm', '-f', "psh-$pool-s$index"]);
    if (instance_run(['docker', 'inspect', "psh-$pool-db"])[0] === 0) {
        instance_run(['docker', 'exec', "psh-$pool-db", 'mysql', '-uroot', '-pprestashop', '-e', "DROP DATABASE IF EXISTS s$index"]);
    }
}

/**
 * Turns a docker compose command of the Shop class (ps-test.php) into one for the slot container: exec, cp and ps.
 * Returns null for a command that has no meaning for a slot (up and down are handled by the Shop itself).
 */
function instance_translate(string $args, string $container): ?string
{
    if (preg_match('/^exec -T (.*?)\bprestashop (.*)$/s', $args, $m) === 1) {
        return 'docker exec ' . $m[1] . escapeshellarg($container) . ' ' . $m[2];
    }
    if (str_starts_with($args, 'cp ')) {
        return 'docker ' . preg_replace('/(["\']?)prestashop:/', '$1' . $container . ':', $args, 1);
    }
    if (trim($args, " \n\r\t\v\0") === 'ps -q') {
        return 'docker ps -q --filter ' . escapeshellarg('name=^' . $container . '$');
    }

    return null;
}

/**
 * The command line of ps-module instance. Returns the exit code.
 *
 * @param list<string> $args
 */
function instance_main(string $cwd, array $args): int
{
    $options = [];
    $rest = [];
    foreach ($args as $arg) {
        if (preg_match('/^--([a-z-]+)(?:=(.*))?$/s', $arg, $m) === 1) {
            $options[$m[1]] = $m[2] ?? '1';
        } else {
            $rest[] = $arg;
        }
    }
    $dir = $options['instance-dir'] ?? instance_dir($cwd);
    $pools = isset($options['pool']) ? [(string) $options['pool']] : array_keys(INSTANCE_PORT_BASE);
    try {
        switch ($rest[0] ?? '') {
            case 'status':
                foreach ($pools as $pool) {
                    [$code, $state] = instance_run(['docker', 'inspect', '-f', '{{.State.Running}}', "psh-$pool-db"]);
                    echo "Instance psh-$pool (" . (instance_enabled($pool, $dir) ? 'on' : 'off') . "): MySQL " . ($code === 0 && $state === 'true' ? 'running' : 'not running') . "\n";
                    foreach (instance_slots($dir, $pool) as $index => $slot) {
                        printf("  slot %-2d port %d  %-26s PrestaShop %-8s since %s\n", $index, INSTANCE_PORT_BASE[$pool] + $index, $slot['module'], $slot['version'], date('H:i', (int) $slot['since']));
                    }
                }
                foreach (glob("$dir/templates/tpl_*.sql") ?: [] as $dump) {
                    echo 'Template ' . basename($dump, '.sql') . ' (' . round(filesize($dump) / 1024) . " KB)\n";
                }

                return 0;
            case 'enable':
            case 'disable':
                instance_switch($dir, $pools, $rest[0] === 'enable');
                echo 'Shared instance ' . implode(', ', $pools) . ': ' . ($rest[0] === 'enable' ? 'on' : 'off') . ($rest[0] === 'enable' && (string) getenv('CI') !== '' ? ' (not in CI)' : '') . "
";

                return 0;
            case 'template':
                instance_template($dir, $pools[0], (string) ($rest[1] ?? ''));

                return 0;
            case 'down':
                foreach ($pools as $pool) {
                    foreach (array_keys(instance_slots($dir, $pool)) as $index) {
                        instance_slot_down($pool, (int) $index);
                        instance_release($dir, $pool, (int) $index);
                    }
                    instance_run(['docker', 'rm', '-f', "psh-$pool-db"]);
                    instance_run(['docker', 'network', 'rm', "psh-$pool"]);
                    echo "Instance psh-$pool removed (templates kept).\n";
                }

                return 0;
            default:
                fwrite(STDERR, "Usage: ps-module instance status [--pool=shops|docs] | enable|disable [--pool=X] | template <ps-version> [--pool=X] | down [--pool=X]\n");

                return 2;
        }
    } catch (RuntimeException $e) {
        fwrite(STDERR, $e->getMessage() . "\n");

        return 1;
    }
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {
    exit(instance_main((string) getcwd(), array_slice($argv, 1)));
}
