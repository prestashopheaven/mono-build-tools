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
 * ps-module factory: the state and the gates of the Module-Factory pipeline for one module repository
 * (workspace docs/factory/, decisions F1-F9).
 *
 *   ps-module factory init [--change="<request>"]    start a run: <repo>/.factory/state.json (a new module, or a change)
 *   ps-module factory status [--json]                 every stage: passed, stale (its inputs changed), open; the next one
 *   ps-module factory gate <stage>                    run the checks of a stage; on success stamp it
 *   ps-module factory approve --by="<name>" --ref="<message>"   record the final approval of the current spec (intake)
 *   ps-module factory review <stage> --verdict=approved|changes [--findings=FILE]
 *                                                     record the reviewer agent's verdict on the current design or code
 *   ps-module factory wait-package <package> <version> [--timeout=SECONDS]
 *                                                     wait until Packagist has a version of one of our packages (F2)
 *
 * A gate passes only when every earlier gate passed on the current inputs, so the order is enforced. A stamp holds the
 * hash of its inputs: when the spec, the design or the code changes, the stamp and every later one become stale. There
 * is no command to stamp a gate by hand.
 *
 * Exit code 0 = ok, 1 = a gate failed or is open, 2 = wrong usage.
 */

declare(strict_types=1);

if (!function_exists('dod_status')) {
    require_once __DIR__ . '/dod.php';
}

/** The stages of the pipeline, in order (the same as build-rules-core Stages::ORDER). */
const FACTORY_STAGES = ['intake', 'research', 'design', 'build', 'content', 'verify', 'release', 'retro'];

/** The folder of the factory files in a module repository. */
const FACTORY_DIR = '.factory';

/** The fields every design.json needs. */
const FACTORY_DESIGN_FIELDS = ['contexts', 'commands', 'queries', 'hooks', 'configuration', 'tables', 'covers'];

/**
 * The path of a file in the factory folder of $repoDir.
 */
function factory_path(string $repoDir, string $file = ''): string
{
    return rtrim($repoDir, '/') . '/' . FACTORY_DIR . ($file === '' ? '' : '/' . $file);
}

/**
 * The state of the run, or null when the repository has no factory run.
 *
 * @return array<string, mixed>|null
 */
function factory_state(string $repoDir): ?array
{
    $file = factory_path($repoDir, 'state.json');
    if (!is_file($file)) {
        return null;
    }
    $state = json_decode((string) file_get_contents($file), true);

    return is_array($state) ? $state : null;
}

/**
 * Writes the state of the run.
 *
 * @param array<string, mixed> $state
 */
function factory_save(string $repoDir, array $state): void
{
    @mkdir(factory_path($repoDir), 0775, true);
    file_put_contents(factory_path($repoDir, 'state.json'), json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
}

/**
 * Starts a run in $repoDir: a new module, or a change to an existing one (the short path, F1).
 *
 * @return array<string, mixed> the new state
 *
 * @throws RuntimeException when a run exists already
 */
function factory_init(string $repoDir, ?string $change, ?int $now = null): array
{
    if (factory_state($repoDir) !== null) {
        throw new RuntimeException('A factory run exists already (' . factory_path($repoDir, 'state.json') . ').');
    }
    $state = [
        'format' => 1,
        'path' => $change === null ? 'new' : 'change',
        'change' => $change,
        'started' => gmdate('c', $now ?? time()),
        'gates' => [],
        'waiting' => null,
    ];
    factory_save($repoDir, $state);

    return $state;
}

/**
 * A short hash of files and texts (missing files count as empty), line endings normalised.
 *
 * @param list<string> $parts file paths, or texts prefixed with "text:"
 */
function factory_hash(array $parts): string
{
    $context = hash_init('sha256');
    foreach ($parts as $part) {
        $content = str_starts_with($part, 'text:') ? substr($part, 5) : (is_file($part) ? (string) file_get_contents($part) : '');
        hash_update($context, $part . "\0" . str_replace("\r\n", "\n", $content) . "\0");
    }

    return substr(hash_final($context), 0, 16);
}

/**
 * The hash of the inputs of a stage: what its stamp is valid for.
 */
function factory_inputs_hash(string $repoDir, string $stage): string
{
    $spec = factory_path($repoDir, 'spec.json');

    return match ($stage) {
        'intake' => factory_hash([$spec, factory_path($repoDir, 'approval.json')]),
        'research' => factory_hash([$spec, factory_path($repoDir, 'research.md')]),
        'design' => factory_hash([$spec, factory_path($repoDir, 'design.json'), factory_path($repoDir, 'reviews/design.json')]),
        'build' => factory_hash(['text:' . dod_fingerprint($repoDir)]),
        'verify' => factory_hash(['text:' . dod_fingerprint($repoDir), factory_path($repoDir, 'reviews/verify.json')]),
        'content' => factory_hash(['text:' . dod_fingerprint($repoDir), 'text:' . dod_docs_hash($repoDir), rtrim($repoDir, '/') . '/test-site/setup.php']),
        'release' => factory_hash(['text:' . factory_module_version($repoDir)]),
        'retro' => factory_hash([factory_path($repoDir, 'retro.md')]),
        default => throw new InvalidArgumentException("Unknown stage $stage (known: " . implode(', ', FACTORY_STAGES) . ').'),
    };
}

/**
 * The version of the module ($this->version in its main file), or '' when it cannot be read.
 */
function factory_module_version(string $repoDir): string
{
    $moduleDir = dod_module_dir($repoDir);
    if ($moduleDir === null) {
        return '';
    }
    $main = $moduleDir . '/' . basename($moduleDir) . '.php';
    $source = is_file($main) ? (string) file_get_contents($main) : '';

    return preg_match('/\$this->version\s*=\s*\'([^\']+)\'/', $source, $m) === 1 ? $m[1] : '';
}

/**
 * The JSON object in a factory file, or null when it is missing or no object.
 *
 * @return array<string, mixed>|null
 */
function factory_json(string $repoDir, string $file): ?array
{
    $path = factory_path($repoDir, $file);
    $data = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

    return is_array($data) ? $data : null;
}

/**
 * Problems with the fields of a JSON document: every field must be there and not empty, and no text in it may still be
 * a placeholder (TODO, TBD, ?, ...).
 *
 * @param array<string, mixed> $data
 * @param list<string> $fields
 *
 * @return list<string>
 */
function factory_required_fields(array $data, array $fields, string $file): array
{
    $problems = [];
    foreach ($fields as $field) {
        if (!array_key_exists($field, $data) || $data[$field] === '' || $data[$field] === [] || $data[$field] === null) {
            $problems[] = "$file: $field is missing or empty.";
        }
    }
    array_walk_recursive($data, static function (mixed $value, int|string $key) use (&$problems, $file): void {
        if (is_string($value) && preg_match('/^\s*(?:\?+|\.\.\.|…)\s*$|\b(?:TODO|TBD|FIXME)\b/u', $value) === 1) {
            $problems[] = "$file: \"$key\" still holds a placeholder: $value";
        }
    });

    return $problems;
}

/**
 * The JSON schema of a factory document shipped with build-tools (resources/factory/<name>.schema.json).
 *
 * @return array<string, mixed>
 *
 * @throws RuntimeException when it is missing or broken
 */
function factory_schema(string $name): array
{
    $file = dirname(__DIR__) . "/resources/factory/$name.schema.json";
    $schema = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
    if (!is_array($schema)) {
        throw new RuntimeException("The schema $file is missing or broken.");
    }

    return $schema;
}

/**
 * Problems of $value against a JSON schema (the subset the factory schemas use: type, required, properties, items,
 * enum, pattern, minLength, maxLength, minItems, minimum).
 *
 * @param array<string, mixed> $schema
 *
 * @return list<string>
 */
function factory_schema_problems(mixed $value, array $schema, string $path): array
{
    $problems = [];
    if (isset($schema['type'])) {
        $types = (array) $schema['type'];
        $actual = match (true) {
            is_array($value) && ($value === [] || array_is_list($value)) => 'array',
            is_array($value) => 'object',
            is_string($value) => 'string',
            is_bool($value) => 'boolean',
            is_int($value) || is_float($value) => 'number',
            default => 'null',
        };
        $fits = in_array($actual, $types, true) || ($actual === 'array' && $value === [] && in_array('object', $types, true));
        if (!$fits) {
            return ["$path must be " . implode(' or ', $types) . ", not $actual."];
        }
    }
    if (isset($schema['enum']) && !in_array($value, (array) $schema['enum'], true)) {
        $problems[] = "$path must be one of " . implode(', ', array_map(static fn (mixed $v): string => (string) json_encode($v), (array) $schema['enum'])) . '.';
    }
    if (is_string($value)) {
        if (isset($schema['minLength']) && mb_strlen(trim($value, " \n\r\t\v\0")) < $schema['minLength']) {
            $problems[] = "$path is too short (at least {$schema['minLength']} characters).";
        }
        if (isset($schema['maxLength']) && mb_strlen($value) > $schema['maxLength']) {
            $problems[] = "$path is too long (at most {$schema['maxLength']} characters).";
        }
        if (isset($schema['pattern']) && preg_match('/' . str_replace('/', '\/', (string) $schema['pattern']) . '/u', $value) !== 1) {
            $problems[] = "$path does not match {$schema['pattern']}.";
        }
    }
    if ((is_int($value) || is_float($value)) && isset($schema['minimum']) && $value < $schema['minimum']) {
        $problems[] = "$path must be at least {$schema['minimum']}.";
    }
    if (is_array($value) && ($value === [] || array_is_list($value)) && in_array('array', (array) ($schema['type'] ?? []), true)) {
        if (isset($schema['minItems']) && count($value) < $schema['minItems']) {
            $problems[] = "$path needs at least {$schema['minItems']} item(s).";
        }
        foreach ($value as $i => $item) {
            if (isset($schema['items']) && is_array($schema['items'])) {
                array_push($problems, ...factory_schema_problems($item, $schema['items'], "{$path}[$i]"));
            }
        }
    } elseif (is_array($value)) {
        foreach ((array) ($schema['required'] ?? []) as $field) {
            if (!array_key_exists($field, $value)) {
                $problems[] = "$path.$field is missing.";
            }
        }
        foreach ((array) ($schema['properties'] ?? []) as $field => $sub) {
            if (array_key_exists($field, $value) && is_array($sub)) {
                array_push($problems, ...factory_schema_problems($value[$field], $sub, "$path.$field"));
            }
        }
    }

    return $problems;
}

/**
 * The IDs of the needs in the spec (needs: [{id, text}, ...]).
 *
 * @param array<string, mixed> $spec
 *
 * @return list<string>
 */
function factory_need_ids(array $spec): array
{
    $ids = [];
    foreach (is_array($spec['needs'] ?? null) ? $spec['needs'] : [] as $need) {
        if (is_array($need) && is_string($need['id'] ?? null) && $need['id'] !== '') {
            $ids[] = $need['id'];
        }
    }

    return $ids;
}

/**
 * The checks of one stage (without the order and the stamps): the list of problems, empty when the stage is done.
 *
 * @return list<string>
 */
function factory_stage_problems(string $repoDir, string $stage): array
{
    $spec = factory_json($repoDir, 'spec.json');
    switch ($stage) {
        case 'intake':
            if ($spec === null) {
                return ['.factory/spec.json is missing or no JSON object: run the intake interview first.'];
            }
            // The schema (resources/factory/spec.schema.json) says what the run needs to go on without asking again;
            // placeholders (TODO, TBD, ?) mean a question was not answered.
            $problems = array_merge(
                factory_schema_problems($spec, factory_schema('spec'), 'spec.json'),
                factory_required_fields($spec, [], 'spec.json'),
            );
            $ids = factory_need_ids($spec);
            if (count(array_unique($ids)) !== count($ids)) {
                $problems[] = 'spec.json: a need id is used twice.';
            }
            $needsWithAcceptance = [];
            foreach (is_array($spec['acceptance'] ?? null) ? $spec['acceptance'] : [] as $case) {
                if (is_array($case) && is_string($case['need'] ?? null)) {
                    $needsWithAcceptance[$case['need']] = true;
                }
            }
            foreach ($ids as $id) {
                if (!isset($needsWithAcceptance[$id])) {
                    $problems[] = "spec.json: need $id has no acceptance scenario (acceptance[].need = \"$id\").";
                }
            }
            $approval = factory_json($repoDir, 'approval.json');
            if ($approval === null) {
                $problems[] = 'The final approval is missing: after the interview, ask for it and record it with ps-module factory approve.';
            } elseif (($approval['spec'] ?? '') !== factory_hash([factory_path($repoDir, 'spec.json')])) {
                $problems[] = 'The spec changed after the approval: ask for the approval of the new spec.';
            }

            return $problems;
        case 'research':
            $research = factory_path($repoDir, 'research.md');
            if (!is_file($research)) {
                return ['.factory/research.md is missing: search our packages, PrestaShop and external packages for every need (PKG-02).'];
            }
            $text = (string) file_get_contents($research);
            $problems = [];
            foreach (factory_need_ids($spec ?? []) as $id) {
                if (preg_match('/^\|\s*' . preg_quote($id, '/') . '\s*\|/m', $text) !== 1) {
                    $problems[] = "research.md: need $id has no row in the reuse table (| $id | ... |).";
                }
            }

            return $problems;
        case 'design':
            $design = factory_json($repoDir, 'design.json');
            if ($design === null) {
                return ['.factory/design.json is missing or no JSON object.'];
            }
            // Every field must be there (an empty list is a decision: "this change adds no table"); what must not be
            // empty: covers always, and the bounded contexts of a new module. A change (a CSS fix) may add no
            // command, query, hook, setting or table.
            $isChange = (factory_state($repoDir)['path'] ?? 'new') === 'change';
            $problems = factory_required_fields($design, $isChange ? ['covers'] : ['contexts', 'covers'], 'design.json');
            foreach (FACTORY_DESIGN_FIELDS as $field) {
                if (!array_key_exists($field, $design)) {
                    $problems[] = "design.json: $field is missing (write [] when the design adds none).";
                } elseif (!is_array($design[$field])) {
                    $problems[] = "design.json: $field must be a list or an object.";
                }
            }
            $problems = array_values(array_unique($problems));
            $covered = is_array($design['covers'] ?? null) ? array_keys($design['covers']) : [];
            foreach (factory_need_ids($spec ?? []) as $id) {
                if (!in_array($id, $covered, true)) {
                    $problems[] = "design.json: need $id is not covered (covers.$id names the commands, queries, hooks or screens).";
                }
            }
            $review = factory_json($repoDir, 'reviews/design.json');
            if ($review === null || ($review['verdict'] ?? '') !== 'approved') {
                $problems[] = 'The reviewer has not approved the design: run the reviewer agent and record its verdict (ps-module factory review design).';
            } elseif (($review['hash'] ?? '') !== factory_hash([factory_path($repoDir, 'design.json')])) {
                $problems[] = 'The design changed after the review: review it again.';
            }

            return $problems;
        case 'build':
            $step = dod_status($repoDir)['steps']['check'] ?? null;

            return ($step['state'] ?? '') === 'ok' ? [] : ['composer check has not passed on the current code (ps-module check).'];
        case 'verify':
            $dod = dod_status($repoDir);
            $problems = [];
            foreach ($dod['steps'] as $name => $step) {
                if (!in_array($step['state'], ['ok', 'skipped'], true)) {
                    $problems[] = "Definition of done: $name is {$step['state']} (" . dod_command($name, $dod['module']) . ').';
                }
            }
            $review = factory_json($repoDir, 'reviews/verify.json');
            if ($review === null || ($review['verdict'] ?? '') !== 'approved') {
                $problems[] = 'The reviewer has not approved the code: run the reviewer agent and record its verdict (ps-module factory review verify).';
            } elseif (($review['hash'] ?? '') !== $dod['fingerprint']) {
                $problems[] = 'The code changed after the review: review it again.';
            }

            return $problems;
        case 'content':
            $problems = [];
            if (!is_file(rtrim($repoDir, '/') . '/test-site/setup.php')) {
                $problems[] = 'test-site/setup.php is missing (TS-01).';
            }
            $docs = dod_status($repoDir)['steps']['docs'] ?? null;
            if ($docs !== null && $docs['state'] !== 'ok') {
                $problems[] = 'The docs are not built on the current code and texts (ps-module docs build).';
            }

            return $problems;
        case 'release':
            $version = factory_module_version($repoDir);
            if ($version === '') {
                return ['The module version cannot be read.'];
            }
            $tag = trim((string) @shell_exec('git -C ' . escapeshellarg($repoDir) . ' ls-remote --tags origin ' . escapeshellarg("refs/tags/v$version") . ' 2>' . (PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null')), " \n\r\t\v\0");

            if ($tag === '') {
                return ["Release v$version is not on GitHub yet: merge the release (ps-module git pr --release $version --publish)."];
            }
            $moduleDir = dod_module_dir($repoDir);

            return factory_release_problems(factory_release_status($moduleDir === null ? '' : basename($moduleDir), $version), factory_json($repoDir, 'spec.json') ?? []);
        case 'retro':
            return is_file(factory_path($repoDir, 'retro.md')) ? [] : ['.factory/retro.md is missing: what went wrong, and the proposed rule, check, generator or pitfall.'];
        default:
            throw new InvalidArgumentException("Unknown stage $stage (known: " . implode(', ', FACTORY_STAGES) . ').');
    }
}

/**
 * Asks the update server (UPDATE_SERVER_URL, default https://updates.prestahaven.com) for the status of a release:
 * accepted or not, and the sales-sync jobs (decision F8). The request is signed with the release signing key
 * (UPDATE_SIGNING_KEY), so no extra secret is needed. Returns the decoded answer, or an array with "error".
 *
 * @param callable(string, list<string>): (string|false) $fetch GETs a URL with headers (tests pass their own)
 * @param callable(): string $secretKey the raw ed25519 secret key (update_signing_key() by default)
 *
 * @return array<string, mixed>
 */
function factory_release_status(string $module, string $version, ?callable $fetch = null, ?callable $secretKey = null, ?int $now = null): array
{
    if (preg_match('/^[a-z][a-z0-9_]{1,63}$/', $module) !== 1) {
        return ['error' => "The module name \"$module\" cannot be read."];
    }
    $base = rtrim((string) (getenv('UPDATE_SERVER_URL') ?: 'https://updates.prestahaven.com'), '/');
    $path = "/internal/factory/v1/releases/$module/$version";
    $timestamp = (string) ($now ?? time());
    try {
        if ($secretKey === null) {
            require_once __DIR__ . '/update-sign.php';
            $secretKey = 'update_signing_key';
        }
        $signature = base64_encode(sodium_crypto_sign_detached("factory-v1\nGET\n$path\n$timestamp", $secretKey()));
    } catch (Throwable $e) {
        return ['error' => 'The release cannot be checked on the update server: ' . $e->getMessage()];
    }
    $fetch ??= static function (string $url, array $headers): string|false {
        $context = stream_context_create(['http' => ['method' => 'GET', 'header' => implode("\r\n", $headers), 'timeout' => 20, 'ignore_errors' => true]]);

        return @file_get_contents($url, false, $context);
    };
    $body = $fetch($base . $path, ["X-Factory-Timestamp: $timestamp", "X-Factory-Signature: $signature", 'Accept: application/json']);
    $data = is_string($body) ? json_decode($body, true) : null;
    if (!is_array($data)) {
        return ['error' => "The update server did not answer for $module $version."];
    }
    if (isset($data['error'])) {
        return ['error' => "The update server refused the status request for $module $version (" . (string) $data['error'] . '): check UPDATE_SIGNING_KEY.'];
    }

    return $data;
}

/**
 * The problems of a release status (factory_release_status()) for the release gate: the server must have accepted
 * the version, and when the spec publishes to the sales shop, its sales-sync job must be done.
 *
 * @param array<string, mixed> $status
 * @param array<string, mixed> $spec
 *
 * @return list<string>
 */
function factory_release_problems(array $status, array $spec): array
{
    if (isset($status['error'])) {
        return [(string) $status['error']];
    }
    $label = ($status['module'] ?? '?') . ' ' . ($status['version'] ?? '?');
    $release = is_array($status['release'] ?? null) ? $status['release'] : null;
    if ($release === null) {
        return ["The update server has no release $label yet: the GitHub webhook may still be on its way (try again in a few minutes)."];
    }
    if (($release['status'] ?? '') !== 'accepted') {
        return ["The update server has $label as \"" . ($release['status'] ?? '?') . '"' . (isset($release['reason']) && $release['reason'] !== null ? " ({$release['reason']})" : '') . ': it must be accepted (Update-* trailers of the release commit, UPD-06).'];
    }
    if (($spec['publish']['sales_shop'] ?? false) !== true) {
        return [];
    }
    if (($status['sales']['product'] ?? false) !== true) {
        return ["The sales shop has no product for $label yet: run ps-module publish shop (factory-release step 3)."];
    }
    $jobs = is_array($status['sales']['jobs'] ?? null) ? $status['sales']['jobs'] : [];
    foreach ($jobs as $job) {
        if (is_array($job) && ($job['status'] ?? '') === 'done') {
            return [];
        }
    }
    $states = array_map(static fn (mixed $job): string => is_array($job) ? (string) ($job['status'] ?? '?') : '?', $jobs);

    return ["The sales shop has not received the zip of $label yet (sales-sync jobs: " . ($states === [] ? 'none' : implode(', ', $states)) . '); heavensalessync polls every 15 minutes.'];
}

/**
 * The state of every stage: passed (stamp on the current inputs), stale (stamp on older inputs) or open, and the next
 * stage to work on (the first one that is not passed; null when all passed).
 *
 * @param array<string, mixed> $state
 *
 * @return array{stages: array<string, string>, next: string|null}
 */
function factory_overview(string $repoDir, array $state): array
{
    $stages = [];
    $next = null;
    foreach (FACTORY_STAGES as $stage) {
        $gate = $state['gates'][$stage] ?? null;
        if (!is_array($gate)) {
            $stages[$stage] = 'open';
        } else {
            $stages[$stage] = ($gate['hash'] ?? '') === factory_inputs_hash($repoDir, $stage) ? 'passed' : 'stale';
        }
        if ($next === null && $stages[$stage] !== 'passed') {
            $next = $stage;
        }
    }

    return ['stages' => $stages, 'next' => $next];
}

/**
 * Runs the gate of $stage: every earlier stage must be passed on its current inputs, then the stage's own checks.
 * On success the stamp is written. Returns the problems (empty = passed).
 *
 * @return list<string>
 *
 * @throws RuntimeException when there is no factory run
 * @throws InvalidArgumentException when $stage is unknown
 */
function factory_gate(string $repoDir, string $stage, ?int $now = null): array
{
    if (!in_array($stage, FACTORY_STAGES, true)) {
        throw new InvalidArgumentException("Unknown stage $stage (known: " . implode(', ', FACTORY_STAGES) . ').');
    }
    $state = factory_state($repoDir);
    if ($state === null) {
        throw new RuntimeException('No factory run in this repository: start one with ps-module factory init.');
    }
    $overview = factory_overview($repoDir, $state);
    foreach (FACTORY_STAGES as $earlier) {
        if ($earlier === $stage) {
            break;
        }
        if ($overview['stages'][$earlier] !== 'passed') {
            return ["The gate of $earlier is {$overview['stages'][$earlier]}: pass it first (ps-module factory gate $earlier)."];
        }
    }
    $problems = factory_stage_problems($repoDir, $stage);
    if ($problems === []) {
        $state['gates'][$stage] = ['passed' => gmdate('c', $now ?? time()), 'hash' => factory_inputs_hash($repoDir, $stage)];
        factory_save($repoDir, $state);
    }

    return $problems;
}

/**
 * Records the final approval of the current spec (decision F6): by whom, and where (the message that approved it).
 *
 * @throws RuntimeException when there is no spec
 * @throws InvalidArgumentException when $by or $ref is empty
 */
function factory_approve(string $repoDir, string $by, string $ref, ?int $now = null): void
{
    if (trim($by, " \n\r\t\v\0") === '' || trim($ref, " \n\r\t\v\0") === '') {
        throw new InvalidArgumentException('Usage: ps-module factory approve --by="<name>" --ref="<the message that approved it>"');
    }
    if (!is_file(factory_path($repoDir, 'spec.json'))) {
        throw new RuntimeException('There is no .factory/spec.json to approve.');
    }
    file_put_contents(factory_path($repoDir, 'approval.json'), json_encode([
        'by' => $by,
        'ref' => $ref,
        'at' => gmdate('c', $now ?? time()),
        'spec' => factory_hash([factory_path($repoDir, 'spec.json')]),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
}

/**
 * Records the reviewer agent's verdict on the design or the code (decision F3: the reviewer decides, in a fresh
 * context). The verdict is tied to the current design (design) or the current code fingerprint (verify).
 *
 * @param list<string> $findings
 *
 * @throws InvalidArgumentException on a wrong stage or verdict
 */
function factory_review(string $repoDir, string $stage, string $verdict, array $findings, ?int $now = null): void
{
    if (!in_array($stage, ['design', 'verify'], true) || !in_array($verdict, ['approved', 'changes'], true)) {
        throw new InvalidArgumentException('Usage: ps-module factory review design|verify --verdict=approved|changes [--findings=FILE]');
    }
    if ($verdict === 'changes' && $findings === []) {
        throw new InvalidArgumentException('A verdict "changes" needs the findings (--findings=FILE, one per line).');
    }
    @mkdir(factory_path($repoDir, 'reviews'), 0775, true);
    file_put_contents(factory_path($repoDir, "reviews/$stage.json"), json_encode([
        'verdict' => $verdict,
        'findings' => $findings,
        'at' => gmdate('c', $now ?? time()),
        'hash' => $stage === 'design' ? factory_hash([factory_path($repoDir, 'design.json')]) : dod_fingerprint($repoDir),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
}

/**
 * Whether Packagist lists $version of $package (prestashopheaven/...), read from the Composer v2 metadata.
 *
 * @param callable(string): (string|false) $fetch reads a URL (file_get_contents by default; tests pass their own)
 */
function factory_packagist_has(string $package, string $version, ?callable $fetch = null): bool
{
    $fetch ??= static fn (string $url): string|false => @file_get_contents($url);
    $json = $fetch('https://repo.packagist.org/p2/' . $package . '.json?t=' . time());
    $data = is_string($json) ? json_decode($json, true) : null;
    foreach (is_array($data) ? ($data['packages'][$package] ?? []) : [] as $release) {
        if (is_array($release) && in_array($release['version'] ?? '', [$version, "v$version"], true)) {
            return true;
        }
    }

    return false;
}

/**
 * Waits until Packagist has $version of $package (decision F2: a module run that changed a package waits for its
 * release before it goes on). Polls with growing pauses (30 s, then up to 5 minutes); records the wait in the state.
 *
 * @param callable(string): (string|false) $fetch
 * @param callable(int): void $sleep
 *
 * @throws RuntimeException when the version is not there after $timeout seconds
 */
function factory_wait_package(string $repoDir, string $package, string $version, int $timeout, ?callable $fetch = null, ?callable $sleep = null, ?callable $say = null): void
{
    if (preg_match('/^prestashopheaven\/[a-z0-9-]+$/', $package) !== 1 || preg_match('/^\d+\.\d+\.\d+$/', $version) !== 1) {
        throw new InvalidArgumentException('Usage: ps-module factory wait-package prestashopheaven/<package> <X.Y.Z> [--timeout=SECONDS]');
    }
    $sleep ??= static function (int $seconds): void {
        sleep($seconds);
    };
    $say ??= static function (string $message): void {
        fwrite(STDERR, $message . "\n");
    };
    $state = factory_state($repoDir);
    if ($state !== null) {
        $state['waiting'] = ['package' => $package, 'version' => $version, 'since' => gmdate('c')];
        factory_save($repoDir, $state);
    }
    $waited = 0;
    $pause = 30;
    while (!factory_packagist_has($package, $version, $fetch)) {
        if ($waited >= $timeout) {
            throw new RuntimeException("$package $version is not on Packagist after {$waited}s: check the release workflow of its monorepo.");
        }
        $say("Waiting for $package $version on Packagist ({$waited}s)...");
        $sleep($pause);
        $waited += $pause;
        $pause = min(300, $pause * 2);
    }
    if ($state !== null) {
        $state['waiting'] = null;
        factory_save($repoDir, $state);
    }
    $say("$package $version is on Packagist.");
}

/**
 * The command line of ps-module factory. Returns the exit code.
 *
 * @param list<string> $args
 */
function factory_main(string $repoDir, array $args): int
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
    try {
        switch ($rest[0] ?? '') {
            case 'init':
                $state = factory_init($repoDir, $options['change'] ?? null);
                echo 'Factory run started (' . $state['path'] . "). Next: the intake interview, then ps-module factory gate intake.\n";

                return 0;
            case 'status':
                $state = factory_state($repoDir);
                if ($state === null) {
                    echo "No factory run in this repository (ps-module factory init).\n";

                    return 0;
                }
                $overview = factory_overview($repoDir, $state);
                if (isset($options['json'])) {
                    echo json_encode($overview + ['path' => $state['path'], 'waiting' => $state['waiting'] ?? null], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

                    return 0;
                }
                foreach ($overview['stages'] as $stage => $stageState) {
                    printf("  %-9s %s\n", $stage, $stageState);
                }
                if (is_array($state['waiting'] ?? null)) {
                    echo "Waiting for {$state['waiting']['package']} {$state['waiting']['version']} (since {$state['waiting']['since']}).\n";
                }
                echo $overview['next'] === null ? "Every gate passed.\n" : "Next: {$overview['next']}.\n";

                return 0;
            case 'gate':
                $stage = $rest[1] ?? '';
                $problems = factory_gate($repoDir, $stage);
                if ($problems === []) {
                    echo "Gate $stage passed.\n";

                    return 0;
                }
                fwrite(STDERR, "Gate $stage is not passed:\n  - " . implode("\n  - ", $problems) . "\n");

                return 1;
            case 'approve':
                factory_approve($repoDir, $options['by'] ?? '', $options['ref'] ?? '');
                echo "Approval of the current spec recorded.\n";

                return 0;
            case 'review':
                $findings = [];
                if (isset($options['findings'])) {
                    $findings = array_values(array_filter(array_map(static fn (string $line): string => trim($line, " \n\r\t\v\0"), file($options['findings']) ?: []), static fn (string $line): bool => $line !== ''));
                }
                factory_review($repoDir, $rest[1] ?? '', $options['verdict'] ?? '', $findings);
                echo 'Review of ' . ($rest[1] ?? '') . ' recorded: ' . ($options['verdict'] ?? '') . ".\n";

                return 0;
            case 'wait-package':
                factory_wait_package($repoDir, $rest[1] ?? '', $rest[2] ?? '', (int) ($options['timeout'] ?? 3600));

                return 0;
            default:
                fwrite(STDERR, "Usage: ps-module factory init [--change=\"<request>\"] | status [--json] | gate <stage> | approve --by=<name> --ref=<message> | review design|verify --verdict=approved|changes [--findings=FILE] | wait-package <package> <X.Y.Z>\n");

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

/**
 * The repository of the factory run for the command line: --repo=DIR, else the nearest folder at or above $start
 * with a .factory folder or a module.spec.json, else $start.
 */
function factory_repo_dir(string $start, ?string $option): string
{
    if ($option !== null && $option !== '') {
        return rtrim(str_replace('\\', '/', $option), '/');
    }
    $dir = rtrim(str_replace('\\', '/', (string) (realpath($start) ?: $start)), '/');
    for ($i = 0; $i < 4 && $dir !== '' && $dir !== dirname($dir); ++$i, $dir = dirname($dir)) {
        if (is_dir("$dir/" . FACTORY_DIR) || is_file("$dir/module.spec.json")) {
            return $dir;
        }
    }

    return rtrim(str_replace('\\', '/', $start), '/');
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {
    $factoryArgs = array_slice($argv, 1);
    $factoryRepo = null;
    foreach ($factoryArgs as $i => $arg) {
        if (str_starts_with($arg, '--repo=')) {
            $factoryRepo = substr($arg, 7);
            unset($factoryArgs[$i]);
        }
    }
    exit(factory_main(factory_repo_dir((string) getcwd(), $factoryRepo), array_values($factoryArgs)));
}
