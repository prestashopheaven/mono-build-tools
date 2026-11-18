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

require_once __DIR__ . '/../src/factory.php';

/**
 * src/factory.php: a run starts once, the gates pass only in order and on their current inputs, the intake needs a
 * complete spec and the approval of exactly that spec, research and design must cover every need, the design needs the
 * reviewer's approval of exactly that design, and a package wait ends when Packagist has the version.
 */
final class FactoryTest extends TestCase
{
    private string $repo = '';

    /**
     * An empty repository folder for every test.
     */
    protected function setUp(): void
    {
        $this->repo = str_replace('\\', '/', sys_get_temp_dir()) . '/factory-test-' . bin2hex(random_bytes(4));
        mkdir($this->repo, 0777, true);
    }

    /**
     * Removes the repository folder.
     */
    protected function tearDown(): void
    {
        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->repo, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->repo);
    }

    /**
     * A complete spec with two needs.
     *
     * @return array<string, mixed>
     */
    private static function spec(): array
    {
        return [
            'module_name' => 'heavenvatchecker',
            'display_name' => 'B2B VAT Number Checker',
            'description' => 'Checks EU VAT numbers with VIES.',
            'namespace' => 'PrestaShopHeaven\\VatChecker',
            'repo_slug' => 'heaven-vat-checker',
            'tab' => 'billing_invoicing',
            'ps_min' => '8.1.0',
            'ps_max' => null,
            'php_min' => '8.1',
            'distribution' => 'addons',
            'license' => 'afl',
            'price' => 59.99,
            'languages' => ['en', 'nl'],
            'features' => ['config-form', 'action-hooks', 'external-api'],
            'needs' => [['id' => 'N1', 'text' => 'Check a VAT number with VIES', 'priority' => 'must'], ['id' => 'N2', 'text' => 'Remove VAT for valid numbers']],
            'settings' => [['key' => 'HEAVENVATCHECKER_HOME_COUNTRY', 'label' => 'Home country', 'type' => 'select', 'default' => 'NL', 'scope' => 'shop']],
            'screens' => [['id' => 'config', 'where' => 'Module configuration', 'shows' => 'The settings']],
            'mails' => [],
            'data' => [['name' => 'vat_check', 'fields' => ['vat_number', 'valid', 'checked_at'], 'retention' => '2 years']],
            'external_services' => [['name' => 'VIES', 'purpose' => 'Check numbers', 'credentials' => 'none', 'when_down' => 'Keep VAT']],
            'edge_cases' => ['VIES is down: the customer pays VAT'],
            'multistore' => 'The home country is set per shop.',
            'compatibility' => 'One-page checkouts',
            'acceptance' => [
                ['id' => 'A1', 'need' => 'N1', 'given' => 'a valid DE number', 'when' => 'the address is saved', 'then' => 'it is marked valid'],
                ['id' => 'A2', 'need' => 'N2', 'given' => 'a valid DE number', 'when' => 'the cart is shown', 'then' => 'no VAT is charged'],
            ],
            'marketing' => ['category' => 'billing', 'audience' => 'B2B shops in the EU', 'benefits' => ['No manual VAT checks']],
            'logo' => ['concept' => 'A shield with a check mark'],
            'publish' => ['addons' => true, 'docs' => true, 'demo' => true, 'sales_shop' => true],
            'out_of_scope' => ['Non-EU VAT systems'],
        ];
    }

    /**
     * Writes a factory file (JSON for arrays).
     *
     * @param array<string, mixed>|string $content
     */
    private function write(string $file, array|string $content): void
    {
        @mkdir(dirname(factory_path($this->repo, $file)), 0777, true);
        file_put_contents(factory_path($this->repo, $file), is_array($content) ? json_encode($content) : $content);
    }

    /**
     * A run with an approved spec and a passed intake gate.
     */
    private function passIntake(): void
    {
        factory_init($this->repo, null);
        $this->write('spec.json', self::spec());
        factory_approve($this->repo, 'George', 'cmsg_1');
        self::assertSame([], factory_gate($this->repo, 'intake'));
    }

    public function testInitStartsOneRunForANewModuleOrAChange(): void
    {
        $state = factory_init($this->repo, null, 0);
        self::assertSame(['format' => 1, 'path' => 'new', 'change' => null, 'started' => '1970-01-01T00:00:00+00:00', 'gates' => [], 'waiting' => null], $state);
        self::assertSame($state, factory_state($this->repo));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('A factory run exists already');
        factory_init($this->repo, 'Add a setting');
    }

    public function testAChangeRunRecordsTheRequest(): void
    {
        self::assertSame('change', factory_init($this->repo, 'Add a setting')['path']);
        self::assertSame('Add a setting', factory_state($this->repo)['change'] ?? null);
    }

    public function testWithoutARunThereIsNoStateAndNoGate(): void
    {
        self::assertNull(factory_state($this->repo));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No factory run in this repository');

        factory_gate($this->repo, 'intake');
    }

    public function testAnUnknownStageIsRefused(): void
    {
        factory_init($this->repo, null);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown stage deploy');

        factory_gate($this->repo, 'deploy');
    }

    public function testIntakeNeedsASpec(): void
    {
        factory_init($this->repo, null);

        self::assertSame(['.factory/spec.json is missing or no JSON object: run the intake interview first.'], factory_gate($this->repo, 'intake'));
    }

    public function testIntakeRefusesMissingFieldsPlaceholdersAndDoubleNeeds(): void
    {
        factory_init($this->repo, null);
        $spec = self::spec();
        unset($spec['logo']);
        $spec['description'] = 'TODO later';
        $spec['needs'][] = ['id' => 'N1', 'text' => ' '];
        $spec['needs'][] = ['id' => 'N3', 'text' => 'Log every check'];
        $spec['settings'][0]['scope'] = 'everywhere';
        $spec['edge_cases'] = [];
        $spec['languages'] = 'en';
        $this->write('spec.json', $spec);

        $problems = factory_gate($this->repo, 'intake');

        self::assertContains('spec.json.logo is missing.', $problems);
        self::assertContains('spec.json: "description" still holds a placeholder: TODO later', $problems);
        self::assertContains('spec.json.needs[2].text is too short (at least 5 characters).', $problems);
        self::assertContains('spec.json: a need id is used twice.', $problems);
        self::assertContains('spec.json: need N3 has no acceptance scenario (acceptance[].need = "N3").', $problems);
        self::assertContains('spec.json.settings[0].scope must be one of "shop", "group", "global".', $problems);
        self::assertContains('spec.json.edge_cases needs at least 1 item(s).', $problems);
        self::assertContains('spec.json.languages must be array, not string.', $problems);
        self::assertContains('The final approval is missing: after the interview, ask for it and record it with ps-module factory approve.', $problems);
    }

    public function testTheApprovalHoldsOnlyForTheSpecItApproved(): void
    {
        $this->passIntake();
        self::assertSame('passed', factory_overview($this->repo, (array) factory_state($this->repo))['stages']['intake']);

        $spec = self::spec();
        $spec['description'] = 'Checks EU VAT numbers.';
        $this->write('spec.json', $spec);

        self::assertSame('stale', factory_overview($this->repo, (array) factory_state($this->repo))['stages']['intake']);
        self::assertSame(['The spec changed after the approval: ask for the approval of the new spec.'], factory_gate($this->repo, 'intake'));
    }

    public function testApproveNeedsWhoAndWhereAndASpec(): void
    {
        factory_init($this->repo, null);
        try {
            factory_approve($this->repo, 'George', 'cmsg_1');
            self::fail('no spec');
        } catch (RuntimeException $e) {
            self::assertSame('There is no .factory/spec.json to approve.', $e->getMessage());
        }
        $this->expectException(InvalidArgumentException::class);
        factory_approve($this->repo, ' ', 'cmsg_1');
    }

    public function testGatesPassOnlyInOrder(): void
    {
        factory_init($this->repo, null);
        $this->write('research.md', "| N1 | ... |\n| N2 | ... |\n");

        self::assertSame(['The gate of intake is open: pass it first (ps-module factory gate intake).'], factory_gate($this->repo, 'research'));
    }

    public function testResearchCoversEveryNeed(): void
    {
        $this->passIntake();
        self::assertStringContainsString('research.md is missing', factory_gate($this->repo, 'research')[0]);

        $this->write('research.md', "| Need | Found |\n|---|---|\n| N1 | runtime-kit |\n");
        self::assertSame(['research.md: need N2 has no row in the reuse table (| N2 | ... |).'], factory_gate($this->repo, 'research'));

        $this->write('research.md', "| N1 | runtime-kit |\n| N2 | searched: nothing found |\n");
        self::assertSame([], factory_gate($this->repo, 'research'));
    }

    public function testDesignCoversEveryNeedAndNeedsTheReviewOfThatDesign(): void
    {
        $this->passIntake();
        $this->write('research.md', "| N1 | x |\n| N2 | y |\n");
        self::assertSame([], factory_gate($this->repo, 'research'));
        self::assertSame(['.factory/design.json is missing or no JSON object.'], factory_gate($this->repo, 'design'));

        $design = ['contexts' => ['Vat'], 'commands' => ['CheckVatNumber'], 'queries' => ['GetVatStatus'], 'hooks' => ['actionValidateCustomerAddressForm'], 'configuration' => ['HEAVENVATCHECKER_HOME_COUNTRY' => 'shop'], 'tables' => ['heavenvatchecker_check'], 'covers' => ['N1' => ['CheckVatNumber']]];
        $this->write('design.json', $design);
        $problems = factory_gate($this->repo, 'design');
        self::assertContains('design.json: need N2 is not covered (covers.N2 names the commands, queries, hooks or screens).', $problems);
        self::assertContains('The reviewer has not approved the design: run the reviewer agent and record its verdict (ps-module factory review design).', $problems);

        $design['covers']['N2'] = ['actionCartSave'];
        $this->write('design.json', $design);
        factory_review($this->repo, 'design', 'approved', []);
        self::assertSame([], factory_gate($this->repo, 'design'));

        $design['tables'][] = 'heavenvatchecker_log';
        $this->write('design.json', $design);
        self::assertSame(['The design changed after the review: review it again.'], factory_gate($this->repo, 'design'));
        self::assertSame('stale', factory_overview($this->repo, (array) factory_state($this->repo))['stages']['design']);
    }

    public function testAChangeDesignMayAddNothingButMustNameEveryField(): void
    {
        factory_init($this->repo, 'Fix the timeline CSS in RTL');
        $this->write('spec.json', self::spec());
        $design = ['contexts' => [], 'commands' => [], 'queries' => [], 'hooks' => [], 'configuration' => [], 'tables' => [], 'covers' => ['N1' => ['views/css/timeline.css'], 'N2' => ['views/css/timeline.css']]];
        $this->write('design.json', $design);
        factory_review($this->repo, 'design', 'approved', []);

        self::assertSame([], factory_stage_problems($this->repo, 'design'));

        unset($design['tables']);
        $design['hooks'] = 'none';
        $this->write('design.json', $design);
        factory_review($this->repo, 'design', 'approved', []);
        $problems = factory_stage_problems($this->repo, 'design');
        self::assertContains('design.json: tables is missing (write [] when the design adds none).', $problems);
        self::assertContains('design.json: hooks must be a list or an object.', $problems);
    }

    public function testANewModuleDesignNeedsItsContexts(): void
    {
        factory_init($this->repo, null);
        $this->write('spec.json', self::spec());
        $this->write('design.json', ['contexts' => [], 'commands' => [], 'queries' => [], 'hooks' => [], 'configuration' => [], 'tables' => [], 'covers' => ['N1' => ['x'], 'N2' => ['y']]]);
        factory_review($this->repo, 'design', 'approved', []);

        self::assertSame(['design.json: contexts is missing or empty.'], factory_stage_problems($this->repo, 'design'));
    }

    public function testReviewChangesNeedsFindingsAndOnlyDesignOrVerify(): void
    {
        factory_init($this->repo, null);
        try {
            factory_review($this->repo, 'design', 'changes', []);
            self::fail('findings needed');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('needs the findings', $e->getMessage());
        }
        try {
            factory_review($this->repo, 'build', 'approved', []);
            self::fail('only design or verify');
        } catch (InvalidArgumentException $e) {
            self::assertStringStartsWith('Usage:', $e->getMessage());
        }
        $this->write('design.json', ['x' => 1]);
        factory_review($this->repo, 'design', 'changes', ['Missing multistore scope']);
        self::assertSame(['Missing multistore scope'], factory_json($this->repo, 'reviews/design.json')['findings'] ?? null);
    }

    public function testRetroNeedsItsNotes(): void
    {
        self::assertSame(['.factory/retro.md is missing: what went wrong, and the proposed rule, check, generator or pitfall.'], factory_stage_problems($this->repo, 'retro'));
        $this->write('retro.md', "Nothing went wrong.\n");
        self::assertSame([], factory_stage_problems($this->repo, 'retro'));
    }

    public function testTheOverviewNamesTheNextStage(): void
    {
        $this->passIntake();
        $overview = factory_overview($this->repo, (array) factory_state($this->repo));

        self::assertSame('passed', $overview['stages']['intake']);
        self::assertSame('open', $overview['stages']['research']);
        self::assertSame('research', $overview['next']);
    }

    public function testRequiredFieldsFindEmptyValuesAndPlaceholdersAtAnyDepth(): void
    {
        $problems = factory_required_fields(['a' => '', 'b' => [], 'c' => null, 'd' => ['deep' => ['text' => 'later: TBD']], 'e' => '?'], ['a', 'b', 'c', 'x'], 'f.json');

        self::assertSame([
            'f.json: a is missing or empty.',
            'f.json: b is missing or empty.',
            'f.json: c is missing or empty.',
            'f.json: x is missing or empty.',
            'f.json: "text" still holds a placeholder: later: TBD',
            'f.json: "e" still holds a placeholder: ?',
        ], $problems);
    }

    public function testHashesChangeWithTheirInputs(): void
    {
        $file = $this->repo . '/a.txt';
        file_put_contents($file, "one\r\n");
        $first = factory_hash([$file, 'text:x']);
        file_put_contents($file, "one\n");
        self::assertSame($first, factory_hash([$file, 'text:x']), 'line endings do not count');
        self::assertNotSame($first, factory_hash([$file, 'text:y']));
        self::assertSame(16, strlen(factory_hash([$this->repo . '/missing'])));
    }

    public function testWaitPackagePollsPackagistWithGrowingPauses(): void
    {
        factory_init($this->repo, null);
        $answers = [
            '{"packages":{"prestashopheaven/runtime-kit":[{"version":"1.1.0"}]}}',
            false,
            '{"packages":{"prestashopheaven/runtime-kit":[{"version":"v1.2.0"},{"version":"1.1.0"}]}}',
        ];
        $slept = [];
        $said = [];
        factory_wait_package($this->repo, 'prestashopheaven/runtime-kit', '1.2.0', 600, static function (string $url) use (&$answers) {
            self::assertStringStartsWith('https://repo.packagist.org/p2/prestashopheaven/runtime-kit.json?t=', $url);

            return array_shift($answers);
        }, static function (int $s) use (&$slept): void {
            $slept[] = $s;
        }, static function (string $m) use (&$said): void {
            $said[] = $m;
        });

        self::assertSame([30, 60], $slept);
        self::assertSame('prestashopheaven/runtime-kit 1.2.0 is on Packagist.', end($said));
        $state = factory_state($this->repo);
        self::assertIsArray($state);
        self::assertNull($state['waiting']);
    }

    public function testWaitPackageGivesUpAndRecordsTheWait(): void
    {
        factory_init($this->repo, null);
        try {
            factory_wait_package($this->repo, 'prestashopheaven/runtime-kit', '9.9.9', 30, static fn (string $url): string => '{}', static function (int $s): void {
            }, static function (string $m): void {
            });
            self::fail('timeout expected');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('is not on Packagist after 30s', $e->getMessage());
        }
        self::assertSame('9.9.9', factory_state($this->repo)['waiting']['version'] ?? null);
    }

    public function testWaitPackageOnlyForOurPackagesAndRealVersions(): void
    {
        $this->expectException(InvalidArgumentException::class);

        factory_wait_package($this->repo, 'acme/kit', '1.0', 10);
    }

    public function testTheCommandLine(): void
    {
        ob_start();
        self::assertSame(0, factory_main($this->repo, ['status']));
        self::assertSame(0, factory_main($this->repo, ['init']));
        self::assertSame(0, factory_main($this->repo, ['status']));
        self::assertSame(0, factory_main($this->repo, ['status', '--json']));
        self::assertSame(1, @factory_main($this->repo, ['gate', 'intake']));
        self::assertSame(2, @factory_main($this->repo, ['gate', 'deploy']));
        self::assertSame(1, @factory_main($this->repo, ['init']));
        self::assertSame(2, @factory_main($this->repo, ['nope']));
        $this->write('spec.json', self::spec());
        self::assertSame(0, factory_main($this->repo, ['approve', '--by=George', '--ref=cmsg_1']));
        self::assertSame(0, factory_main($this->repo, ['gate', 'intake']));
        $out = (string) ob_get_clean();

        self::assertStringContainsString('No factory run in this repository', $out);
        self::assertStringContainsString('Next: intake.', $out);
        self::assertStringContainsString('"next": "intake"', $out);
        self::assertStringContainsString('Gate intake passed.', $out);
    }

    public function testTheReleaseStatusRequestIsSignedWithTheReleaseKey(): void
    {
        $pair = sodium_crypto_sign_keypair();
        $seen = [];
        $status = factory_release_status('heavenvatchecker', '1.0.0', static function (string $url, array $headers) use (&$seen): string {
            $seen = [$url, $headers];

            return '{"module":"heavenvatchecker","version":"1.0.0","release":{"status":"accepted"}}';
        }, static fn (): string => sodium_crypto_sign_secretkey($pair), 1800000000);

        self::assertSame('accepted', $status['release']['status'] ?? null);
        self::assertSame('https://updates.prestahaven.com/internal/factory/v1/releases/heavenvatchecker/1.0.0', $seen[0]);
        self::assertSame('X-Factory-Timestamp: 1800000000', $seen[1][0]);
        $signature = base64_decode(substr($seen[1][1], strlen('X-Factory-Signature: ')), true);
        self::assertIsString($signature);
        self::assertTrue(sodium_crypto_sign_verify_detached($signature, "factory-v1\nGET\n/internal/factory/v1/releases/heavenvatchecker/1.0.0\n1800000000", sodium_crypto_sign_publickey($pair)));
    }

    public function testTheReleaseStatusReportsEveryFailure(): void
    {
        $key = static fn (): string => sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
        self::assertStringContainsString('cannot be read', factory_release_status('', '1.0.0')['error'] ?? '');
        self::assertStringContainsString('did not answer', factory_release_status('heavenx', '1.0.0', static fn (string $u, array $h): bool => false, $key)['error'] ?? '');
        self::assertStringContainsString('refused the status request', factory_release_status('heavenx', '1.0.0', static fn (string $u, array $h): string => '{"error":"not_found"}', $key)['error'] ?? '');
        self::assertStringContainsString('cannot be checked', factory_release_status('heavenx', '1.0.0', static fn (string $u, array $h): string => '{}', static function (): string {
            throw new RuntimeException('UPDATE_SIGNING_KEY is not set');
        })['error'] ?? '');
    }

    public function testTheReleaseGateNeedsAcceptanceAndTheSalesShopZip(): void
    {
        $sales = ['publish' => ['sales_shop' => true]];
        $accepted = ['module' => 'heavenx', 'version' => '1.0.0', 'release' => ['status' => 'accepted', 'reason' => null], 'sales' => ['product' => true, 'jobs' => [['status' => 'queued'], ['status' => 'done']]]];

        self::assertSame([], factory_release_problems($accepted, $sales));
        self::assertSame([], factory_release_problems(['release' => ['status' => 'accepted']] + $accepted, ['publish' => ['sales_shop' => false]]));
        self::assertSame(['boom'], factory_release_problems(['error' => 'boom'], $sales));
        self::assertStringContainsString('has no release heavenx 1.0.0 yet', factory_release_problems(['release' => null] + $accepted, $sales)[0]);
        self::assertStringContainsString('as "pending" (no Update trailers)', factory_release_problems(['release' => ['status' => 'pending', 'reason' => 'no Update trailers']] + $accepted, $sales)[0]);
        self::assertStringContainsString('has no product', factory_release_problems(['sales' => ['product' => false, 'jobs' => []]] + $accepted, $sales)[0]);
        self::assertStringContainsString('(sales-sync jobs: queued, delivered)', factory_release_problems(['sales' => ['product' => true, 'jobs' => [['status' => 'queued'], ['status' => 'delivered']]]] + $accepted, $sales)[0]);
        self::assertStringContainsString('(sales-sync jobs: none)', factory_release_problems(['sales' => ['product' => true, 'jobs' => []]] + $accepted, $sales)[0]);
    }

    public function testTheRepositoryIsFoundFromASubfolderOrGiven(): void
    {
        mkdir($this->repo . '/.factory');
        mkdir($this->repo . '/heavenx/src', 0777, true);

        self::assertSame(str_replace('\\', '/', (string) realpath($this->repo)), factory_repo_dir($this->repo . '/heavenx/src', null));
        self::assertSame('C:/x/repo', factory_repo_dir($this->repo, 'C:\\x\\repo\\'));
    }
}
