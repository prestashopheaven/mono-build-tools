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
