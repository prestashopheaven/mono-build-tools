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
 * ps-module make: generators of the Module-Factory (phase P7). They write the mechanical part of the design, so the
 * build stage writes only the logic, and every module has the same shape.
 *
 *   ps-module make cqrs [--dry-run]   from <repo>/.factory/design.json > commands and queries:
 *       src/Application/<Context>/Command/<Name>/<Name>Command.php and <Name>Handler.php (queries: Query/<Name>/...Query.php
 *       and ...Handler.php), the message with its typed, read-only fields and getters, the handler with its handle()
 *       method, and the handler's tag for the module's command bus in config/common.yml. Existing files are never
 *       overwritten. The handler body and every test are the build stage's work: the gates (test-check, coverage,
 *       the reviewer) refuse a handler that does nothing or has no test.
 *
 * design.json: "commands"/"queries": [{"name": "CheckVatNumber", "context": "Vat",
 *     "fields": [{"name": "vatNumber", "type": "string"}, {"name": "shopId", "type": "int"}], "returns": "void"}]
 *
 * Exit code 0 = ok, 1 = failed, 2 = wrong usage.
 */

declare(strict_types=1);

/** The field types a generated message may have. */
const MAKE_TYPES = ['int', 'string', 'bool', 'float', 'array', '?int', '?string', '?bool', '?float'];

/**
 * The files and the service lines `make cqrs` would write for $design in a module: [path => content], plus the
 * service block for config/common.yml (empty when nothing is new).
 *
 * @param array<string, mixed> $design
 *
 * @return array{files: array<string, string>, services: string}
 *
 * @throws InvalidArgumentException when a command or query of the design is wrong
 */
function make_cqrs_plan(array $design, string $namespace, string $module, string $header, string $moduleDir): array
{
    $files = [];
    $services = '';
    foreach (['commands' => 'Command', 'queries' => 'Query'] as $key => $kind) {
        foreach ((array) ($design[$key] ?? []) as $i => $message) {
            $message = is_string($message) ? ['name' => $message] : $message;
            if (!is_array($message)) {
                throw new InvalidArgumentException(sprintf('design.json: %s[%d] must be an object or a name.', $key, $i));
            }
            $name = (string) ($message['name'] ?? '');
            $context = (string) ($message['context'] ?? '');
            if (preg_match('/^[A-Z][A-Za-z0-9]+$/', $name) !== 1 || preg_match('/^[A-Z][A-Za-z0-9]+$/', $context) !== 1) {
                throw new InvalidArgumentException(sprintf('design.json: %s[%d] needs a name and a context in StudlyCase.', $key, $i));
            }
            $fields = [];
            foreach ((array) ($message['fields'] ?? []) as $field) {
                $fieldName = is_array($field) ? (string) ($field['name'] ?? '') : '';
                $type = is_array($field) ? (string) ($field['type'] ?? '') : '';
                if (preg_match('/^[a-z][A-Za-z0-9]*$/', $fieldName) !== 1 || !in_array($type, MAKE_TYPES, true)) {
                    throw new InvalidArgumentException("design.json: a field of $name needs a camelCase name and a type (" . implode(', ', MAKE_TYPES) . ').');
                }
                $fields[$fieldName] = $type;
            }
            $returns = $kind === 'Query' ? 'array' : ((string) ($message['returns'] ?? 'void') === 'void' ? 'void' : 'array');
            $folder = "src/Application/$context/$kind/$name";
            $classNamespace = "$namespace\\Application\\$context\\$kind\\$name";
            $messageClass = $name . $kind;
            $handlerClass = $name . 'Handler';
            foreach ([
                "$folder/$messageClass.php" => make_message_class($header, $classNamespace, $messageClass, $fields, (string) ($message['description'] ?? '')),
                "$folder/$handlerClass.php" => make_handler_class($header, $classNamespace, $handlerClass, $messageClass, $kind, $returns),
            ] as $path => $content) {
                if (!is_file("$moduleDir/$path")) {
                    $files[$path] = $content;
                }
            }
            $handlerFqcn = "$classNamespace\\$handlerClass";
            $services .= "  $handlerFqcn:\n    tags:\n      - { name: 'prestashopheaven.$module.handler', command: '$classNamespace\\$messageClass' }\n";
        }
    }

    return ['files' => $files, 'services' => $services];
}

/**
 * The PHP source of a command or query: typed read-only fields, set in the constructor, each with a getter.
 *
 * @param array<string, string> $fields
 */
function make_message_class(string $header, string $namespace, string $class, array $fields, string $description): string
{
    $params = [];
    $getters = '';
    foreach ($fields as $name => $type) {
        $params[] = "        private readonly $type \$$name,";
        $getters .= "\n    /**\n     * Returns the $name.\n     */\n    public function get" . ucfirst($name) . "(): $type\n    {\n        return \$this->$name;\n    }\n";
    }
    $summary = $description !== '' ? $description : (str_ends_with($class, 'Query') ? 'Asks for ' : 'Asks to ') . make_words(preg_replace('/(Command|Query)$/', '', $class) ?? $class) . '.';
    $constructor = $params === []
        ? ''
        : "    /**\n     * The values of the message; they are checked by the value objects of the handler.\n     */\n    public function __construct(\n" . implode("\n", $params) . "\n    ) {\n    }\n";

    return "<?php\n$header\n\ndeclare(strict_types=1);\n\nnamespace $namespace;\n\nif (!defined('_PS_VERSION_')) {\n    exit;\n}\n\n/**\n * $summary\n */\nfinal class $class\n{\n$constructor$getters}\n";
}

/**
 * The PHP source of a handler: handle() takes the message; a command returns nothing, a query (or a command with a
 * result) returns an array view.
 */
function make_handler_class(string $header, string $namespace, string $class, string $message, string $kind, string $returns): string
{
    $doc = $returns === 'void' ? '' : "     *\n     * @return array<string, mixed>\n";
    $body = $returns === 'void' ? '' : "        return [];\n";

    return "<?php\n$header\n\ndeclare(strict_types=1);\n\nnamespace $namespace;\n\nif (!defined('_PS_VERSION_')) {\n    exit;\n}\n\n/**\n * Handles $message ("
        . ($kind === 'Query' ? 'a query: reads, never writes' : 'a command: the only place this change happens') . ").\n */\nfinal class $class\n{\n"
        . "    /**\n     * Handles the message.\n$doc     */\n    public function handle($message \$message): $returns\n    {\n$body    }\n}\n";
}

/**
 * "CheckVatNumber" -> "check vat number".
 */
function make_words(string $studly): string
{
    return strtolower(trim((string) preg_replace('/(?<!^)[A-Z]/', ' $0', $studly), " \n\r\t\v\0"));
}

/**
 * Adds the service lines to config/common.yml below "services:" when the handler is not registered yet. Returns the
 * new content (unchanged when everything is there).
 */
function make_services_yaml(string $yaml, string $services): string
{
    $new = '';
    foreach (preg_split('/(?=^  \S)/m', $services, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $block) {
        $id = trim((string) strtok($block, ':'), " \n\r\t\v\0");
        if (!str_contains($yaml, "  $id:")) {
            $new .= $block;
        }
    }
    if ($new === '') {
        return $yaml;
    }

    return rtrim($yaml, "\n") . "\n\n  # Command and query handlers (ps-module make cqrs).\n" . $new;
}

/**
 * The command line: ps-module make cqrs [--dry-run]. Returns the exit code.
 *
 * @param list<string> $args
 */
function make_main(string $repoDir, string $moduleDir, array $args): int
{
    if (($args[0] ?? '') !== 'cqrs') {
        fwrite(STDERR, "Usage: ps-module make cqrs [--dry-run]\n");

        return 2;
    }
    $design = json_decode((string) @file_get_contents("$repoDir/.factory/design.json"), true);
    $spec = json_decode((string) @file_get_contents("$repoDir/module.spec.json"), true);
    if (!is_array($design) || !is_array($spec) || !is_string($spec['namespace'] ?? null) || !is_string($spec['license_headers']['php'] ?? null)) {
        fwrite(STDERR, "ps-module make: needs .factory/design.json and module.spec.json (namespace, license_headers) in $repoDir.\n");

        return 1;
    }
    try {
        $plan = make_cqrs_plan($design, $spec['namespace'], basename($moduleDir), $spec['license_headers']['php'], $moduleDir);
    } catch (InvalidArgumentException $e) {
        fwrite(STDERR, $e->getMessage() . "\n");

        return 1;
    }
    $dryRun = in_array('--dry-run', $args, true);
    foreach ($plan['files'] as $path => $content) {
        echo ($dryRun ? 'Would write ' : 'Wrote ') . "$path\n";
        if (!$dryRun) {
            @mkdir(dirname("$moduleDir/$path"), 0775, true);
            file_put_contents("$moduleDir/$path", $content);
        }
    }
    $yamlFile = "$moduleDir/config/common.yml";
    $yaml = (string) @file_get_contents($yamlFile);
    $updated = make_services_yaml($yaml, $plan['services']);
    if ($updated !== $yaml) {
        echo ($dryRun ? 'Would add' : 'Added') . " handler tags to config/common.yml\n";
        if (!$dryRun) {
            file_put_contents($yamlFile, $updated);
        }
    }
    if ($plan['files'] === [] && $updated === $yaml) {
        echo "Nothing to generate: every command and query of the design exists.\n";
    } elseif (!$dryRun) {
        echo "Next: vendor/bin/ps-module index (index.php in the new folders), then the handler logic with its tests first.\n";
    }

    return 0;
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {
    exit(make_main((string) ($argv[1] ?? ''), (string) ($argv[2] ?? ''), array_slice($argv, 3)));
}
