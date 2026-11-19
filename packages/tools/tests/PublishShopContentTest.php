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

require_once __DIR__ . '/../src/verbose.php';
require_once __DIR__ . '/../src/publish.php';

/**
 * src/publish-shop-content.php without a shop: the parts option, the customer changelog from CHANGELOG.md, the short
 * reference, the PrestaShop versions, the features, the description split, the category and the manual link.
 */
final class PublishShopContentTest extends TestCase
{
    public function testParts(): void
    {
        self::assertSame(PUBLISH_SHOP_PARTS, publish_shop_parts(['--update']));
        self::assertSame(['images', 'changelog'], publish_shop_parts(['--only=Images, changelog,images']));
    }

    public function testAnUnknownPartIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not: price');

        publish_shop_parts(['--only=texts,price']);
    }

    public function testAnEmptyOnlyIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        publish_shop_parts(['--only=']);
    }

    public function testTheChangelogTakesTheEnglishSummaryOfEveryVersion(): void
    {
        $markdown = <<<'MD'
            # Changelog

            Alle noemenswaardige wijzigingen.

            ## [1.4.0] - 2026-11-08

            Built on the shared **PrestaHaven** packages.
            - Faster settings page, see [docs](https://example.org).

            ### Gewijzigd
            - Gebouwd op de gedeelde packages.

            ## [1.3.0] - 2026-10-26
            ### Toegevoegd
            - Alleen ontwikkelaarsnotities.

            ## 1.0.0 (2026-10-21)

            First release with `cron`.
            MD;

        self::assertSame([
            ['version' => '1.4.0', 'date' => '2026-11-08', 'text' => "Built on the shared PrestaHaven packages.\nFaster settings page, see docs."],
            ['version' => '1.0.0', 'date' => '2026-10-21', 'text' => 'First release with cron.'],
        ], publish_shop_changelog(str_replace("\n            ", "\n", $markdown)));
        self::assertSame([], publish_shop_changelog(''));
    }

    public function testTheReferenceIsShortAndUnique(): void
    {
        self::assertSame('HPDA', publish_shop_reference('heaven-price-drop-alert', []));
        self::assertSame('HTMS', publish_shop_reference('heaven-test-module-shop', ['HPDA']));
        self::assertSame('HPDAL', publish_shop_reference('heaven-price-drop-alert', ['hpda']), 'taken: one more letter of the last word');
        self::assertSame('HPDA', publish_shop_reference('heaven-price-drop-alert', ['HPDA'], 'HPDA'), 'its own reference may stay');
        self::assertSame('HM', publish_shop_reference('heaven', []));
        self::assertSame('HX2', publish_shop_reference('heaven-x', ['HX']), 'no letters left: a number');
    }

    public function testThePrestaShopVersionsRunFromTheMinimumToTheNewestTested(): void
    {
        self::assertSame(['8.1', '8.2', '9.0', '9.1', '9.2'], publish_shop_ps_versions('8.1.0', '9.2.1'));
        self::assertSame(['9.0'], publish_shop_ps_versions('9.0.0', '9.0.3'));
        self::assertSame(['8.1'], publish_shop_ps_versions('', ''));
    }

    public function testTheManagedFeatures(): void
    {
        $dir = str_replace('\\', '/', sys_get_temp_dir()) . '/shop-content-' . bin2hex(random_bytes(4));
        mkdir("$dir/override/classes", 0777, true);
        $ctx = ['languages' => ['en', 'nl', 'de'], 'ps_min' => '8.1.0', 'ps_tested' => '9.0.1', 'module_dir' => $dir];
        try {
            self::assertSame([
                ['name' => 'PrestaShop versions', 'values' => ['8.1', '8.2', '9.0']],
                ['name' => 'Multistore compatible', 'values' => ['yes']],
                ['name' => 'Supported languages', 'values' => ['DE', 'EN', 'NL']],
                ['name' => 'Has overrides', 'values' => ['no']],
            ], publish_shop_managed_features($ctx, ''), 'an override folder without PHP files has no overrides');
            file_put_contents("$dir/override/classes/Product.php", '<?php');
            $features = publish_shop_managed_features($ctx, 'https://demo.example/start/');
            self::assertSame(['yes'], $features[3]['values']);
            self::assertSame(['name' => 'Demo backend', 'values' => ['https://demo.example/start/']], $features[5]);
        } finally {
            unlink("$dir/override/classes/Product.php");
            rmdir("$dir/override/classes");
            rmdir("$dir/override");
            rmdir($dir);
        }
    }

    public function testTheDescriptionSplitsIntoParagraphsAndTheList(): void
    {
        self::assertSame(["<p>A.</p>\n<p>B.</p>\n", "<ul>\n<li>x</li>\n</ul>\n"], publish_shop_split_description("<p>A.</p>\n<ul>\n<li>x</li>\n</ul>\n<p>B.</p>\n"));
        self::assertSame(['<p>Only text.</p>', ''], publish_shop_split_description('<p>Only text.</p>'));
    }

    public function testTheCategory(): void
    {
        $categories = [5 => 'Catalog & Products', 6 => 'Sales & Conversion', 10 => 'SEO & Content', 11 => 'Invoices & Accounting'];
        $map = ['Store Management › Invoicing & Accounting' => 'Invoices & Accounting', 'Traffic & Sales Channels' => 'SEO & Content', 'Store Design' => 'Catalog & Products'];

        self::assertSame(10, publish_shop_suggest_category(['marketing' => ['category' => 'Traffic & Sales Channels › SEO']], $categories, $map));
        self::assertSame(11, publish_shop_suggest_category(['marketing' => ['category' => 'Store Management › Invoicing & Accounting']], $categories, $map));
        self::assertSame(6, publish_shop_suggest_category(['sales' => ['category' => 6], 'marketing' => ['category' => 'Store Design › Wishlist']], $categories, $map), 'docs.json > sales.category wins');
        self::assertSame(5, publish_shop_suggest_category(['sales' => ['category' => 'catalog & products']], $categories, $map));
        self::assertNull(publish_shop_suggest_category(['sales' => ['category' => '99']], $categories, $map), 'an id that is not below All modules');
        self::assertNull(publish_shop_suggest_category(['marketing' => ['category' => 'Payment › Other']], $categories, $map));
        self::assertNull(publish_shop_suggest_category([], $categories, $map));
        self::assertSame(10, publish_shop_suggest_category(['marketing' => ['category' => 'Traffic & Sales Channels › SEO']], $categories), 'the map of resources/publish/shop-categories.json');
    }

    public function testTheDocumentationUrl(): void
    {
        self::assertSame('https://docs.example/heavenfoo/index.php?lang=nl', publish_shop_documentation_url('https://docs.example/', 'heavenfoo', 'nl'));
        self::assertSame('', publish_shop_documentation_url('', 'heavenfoo', 'nl'));
    }

    public function testTheExtraFieldValuesPerShopLanguage(): void
    {
        $plan = ['source' => 'en', 'description' => ['en' => "<p>A</p>\n<ul>\n<li>x</li>\n</ul>\n", 'nl' => "<p>B</p>\n"]];
        $languages = [1 => ['iso' => 'en', 'name' => 'English', 'module' => 'en'], 2 => ['iso' => 'nl', 'name' => 'Nederlands', 'module' => 'nl']];

        self::assertSame([1 => "<ul>\n<li>x</li>\n</ul>"], publish_shop_extra_features($plan, $languages), 'a language without its own list gets nothing');
        self::assertSame(['en' => "<p>A</p>\n", 'nl' => "<p>B</p>\n"], publish_shop_plan_without_list($plan)['description']);
        putenv('DOCS_SITE_URL=https://docs.example');
        try {
            self::assertSame([1 => 'https://docs.example/heavenfoo/index.php?lang=en', 2 => 'https://docs.example/heavenfoo/index.php?lang=nl'], publish_shop_extra_documentation(['repo' => sys_get_temp_dir(), 'module' => 'heavenfoo'], $languages));
        } finally {
            putenv('DOCS_SITE_URL');
        }
    }
}
