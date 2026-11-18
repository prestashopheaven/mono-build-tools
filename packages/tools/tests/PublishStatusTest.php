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

/**
 * `ps-module publish status` (publish_cmd_status()): the checklist has the docs site, the demo shop and the sales shop;
 * the Addons upload and the Cloudflare purge are manual steps outside the tool (George, 2026-11-18).
 */
final class PublishStatusTest extends TestCase
{
    /**
     * The checklist lists docs, demo and shop as open, and no Addons or Cloudflare step.
     */
    public function testChecklistHasDocsDemoAndShopOnly(): void
    {
        $repo = sys_get_temp_dir() . '/ps-module-status-' . getmypid() . '-' . uniqid();
        mkdir("$repo/heavenfoo", 0777, true);
        $ctx = publish_context_without_module($repo);
        $ctx['module_dir'] = "$repo/heavenfoo";
        $ctx['module'] = 'heavenfoo';
        $ctx['version'] = '1.0.0';

        ob_start();
        try {
            $code = publish_cmd_status($ctx, false);
        } finally {
            $output = (string) ob_get_clean();
            rmdir("$repo/heavenfoo");
            rmdir($repo);
        }

        $this->assertSame(1, $code);
        $this->assertMatchesRegularExpression('#Docs site \(PDFs\)\s+open#', $output);
        $this->assertMatchesRegularExpression('#Demo shop\s+open#', $output);
        $this->assertMatchesRegularExpression('#Sales shop\s+open#', $output);
        $this->assertStringContainsString('3 step(s) open.', $output);
        $this->assertStringNotContainsStringIgnoringCase('addons', $output);
        $this->assertStringNotContainsStringIgnoringCase('cloudflare', $output);
    }

    /**
     * The version per shop: the test shop from its module table, the demo from the recorded publish steps, the sales
     * shop from the update server's sales sync, each with a clear text when it cannot be read.
     */
    public function testVersionsPerShop(): void
    {
        $repo = sys_get_temp_dir() . '/ps-module-versions-' . getmypid() . '-' . uniqid();
        mkdir("$repo/.cache/publish", 0777, true);
        file_put_contents("$repo/.cache/publish/1.3.0.json", '{"demo":{"time":"t","note":"version 1.3.0"}}');
        file_put_contents("$repo/.cache/publish/1.4.0.json", '{"docs":{"time":"t","note":""}}');
        file_put_contents("$repo/.cache/publish/1.2.0.json", '{"demo":{"time":"t","note":"version 1.2.0"}}');
        $ctx = ['module' => 'heavenfoo', 'version' => '1.4.0', 'repo' => $repo];
        $exec = static fn (array $command): array => $command[1] === 'ps' ? [0, "abc123\n"] : [0, 'V=1.4.0'];
        $done = static fn (string $m, string $v): array => ['sales' => ['product' => true, 'jobs' => [['status' => 'done']]]];

        try {
            $this->assertSame([
                'Internal test shop' => '1.4.0',
                'Demo shop' => '1.3.0 (last publish demo)',
                'Sales shop' => '1.4.0 (zip delivered by the sales sync)',
            ], publish_versions_per_shop($ctx, $exec, $done));

            $stopped = static fn (array $command): array => [0, ''];
            $failed = static fn (string $m, string $v): array => ['error' => 'no answer'];
            $versions = publish_versions_per_shop(['repo' => "$repo/none"] + $ctx, $stopped, $failed);
            $this->assertSame('not running', $versions['Internal test shop']);
            $this->assertSame('not published from this repo', $versions['Demo shop']);
            $this->assertSame('unknown: no answer', $versions['Sales shop']);

            $missing = static fn (array $command): array => $command[1] === 'ps' ? [0, 'abc'] : [0, 'V='];
            $queued = static fn (string $m, string $v): array => ['sales' => ['product' => true, 'jobs' => [['status' => 'queued']]]];
            $versions = publish_versions_per_shop($ctx, $missing, $queued);
            $this->assertSame('not installed', $versions['Internal test shop']);
            $this->assertSame('not 1.4.0 yet (sales sync not done)', $versions['Sales shop']);
            $this->assertSame('no product', publish_versions_per_shop($ctx, $missing, static fn (string $m, string $v): array => ['sales' => ['product' => false]])['Sales shop']);
        } finally {
            foreach (glob("$repo/.cache/publish/*.json") ?: [] as $file) {
                unlink($file);
            }
            rmdir("$repo/.cache/publish");
            rmdir("$repo/.cache");
            rmdir($repo);
        }
    }

    /**
     * Only the sales-shop zip is a manual step the tool records; "done addons" is refused.
     */
    public function testOnlyTheShopIsAManualStep(): void
    {
        $this->assertSame(['docs', 'demo', 'shop'], PUBLISH_STEPS);
        $ctx = ['module' => 'heavenfoo', 'version' => '1.0.0'];
        $err = fopen('php://memory', 'w+');
        $this->assertIsResource($err);

        $this->assertSame(2, @publish_cmd_done($ctx, 'addons'));
    }
}
