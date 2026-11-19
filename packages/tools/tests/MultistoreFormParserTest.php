<?php
/**
 * NOTICE OF LICENSE
 *
 * This source file is subject to the MIT License
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/MIT
 *
 * @author    George van Engers | PrestaHaven.com
 * @copyright Since 2026 George van Engers
 * @license   https://opensource.org/licenses/MIT MIT License
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/ps-multistore.php';

/**
 * msParseFormPage() reads the settings form of a configuration page like a browser sends it: the field after a
 * multistore checkbox is a field of its own (it was swallowed up to the next </select>), disabled fields are not sent,
 * a textarea and a select give their content, and only checked checkboxes and radios are sent.
 */
final class MultistoreFormParserTest extends TestCase
{
    private const PAGE = 'http://localhost:33000/admin-dev/index.php/modules/heavenfoo/configuration?_token=abc';

    public function testTheFieldAfterATickedMultistoreCheckboxIsSentWithItsValue(): void
    {
        $page = msParseFormPage($this->form(
            '<input type="checkbox" id="form_multistore_limit" name="form[multistore_limit]" class="multistore-checkbox" value="1" checked="checked" />'
            . '<div class="input-group"><input type="text" id="form_limit" name="form[limit]" inputmode="numeric" value="72" /></div>'
            . '<input type="checkbox" name="form[multistore_mode]" value="1" />'
            . '<select id="form_mode" name="form[mode]" disabled="disabled"><option value="a">A</option><option value="b" selected="selected">B</option></select>'
        ), self::PAGE);

        self::assertIsArray($page);
        self::assertSame('1', $page['fields']['form[multistore_limit]'] ?? null);
        self::assertSame('72', $page['fields']['form[limit]'] ?? null);
        self::assertSame(['form[limit]'], $page['text']);
        self::assertSame(['form[multistore_limit]' => '1', 'form[multistore_mode]' => '1'], $page['checkboxes']);
        self::assertArrayNotHasKey('form[multistore_mode]', $page['fields'], 'An unticked checkbox is not sent.');
        self::assertArrayNotHasKey('form[mode]', $page['fields'], 'A disabled field is not sent.');
    }

    public function testADisabledTextFieldIsListedButNotSent(): void
    {
        $page = msParseFormPage($this->form(
            '<input type="checkbox" name="form[multistore_limit]" value="1" />'
            . '<input type="text" name="form[limit]" disabled="disabled" value="60" />'
            . '<input name="form[note]" value="x" />'
        ), self::PAGE);

        self::assertIsArray($page);
        self::assertSame(['form[limit]', 'form[note]'], $page['text'], 'An input without type is a text input.');
        self::assertSame(['form[note]' => 'x', 'form[_token]' => 't'], $page['fields']);
    }

    public function testATextareaASelectAndRadiosGiveWhatABrowserSends(): void
    {
        $page = msParseFormPage($this->form(
            '<textarea name="form[words]" rows="3">  red &amp; blue  </textarea>'
            . '<select name="form[hour]"><option value="1">01:00</option><option selected value="6">06:00</option></select>'
            . '<select name="form[first]"><option value="x">X</option></select>'
            . '<input type="radio" name="form[side]" value="left" />'
            . '<input type="radio" name="form[side]" value="right" checked />'
            . '<input type="text" name="form[after]" value="still read" />'
        ), self::PAGE);

        self::assertIsArray($page);
        self::assertSame('red & blue', $page['fields']['form[words]'] ?? null);
        self::assertSame('6', $page['fields']['form[hour]'] ?? null);
        self::assertSame('', $page['fields']['form[first]'] ?? null, 'No selected option: nothing chosen.');
        self::assertSame('right', $page['fields']['form[side]'] ?? null);
        self::assertSame('still read', $page['fields']['form[after]'] ?? null);
    }

    public function testTheActionBecomesAnAbsoluteUrlAndOtherFormsAreSkipped(): void
    {
        $html = '<form action="/admin-dev/search"><input type="text" name="bo_query" value="" /></form>'
            . '<form method="post" action="/admin-dev/reset"><input type="hidden" name="_token" value="r" /></form>'
            . $this->form('<input type="text" name="form[limit]" value="3" />');

        $page = msParseFormPage($html, self::PAGE);

        self::assertIsArray($page);
        self::assertSame('http://localhost:33000/admin-dev/index.php/modules/heavenfoo/configuration/save?a=1&b=2', $page['action']);
        self::assertSame(['form[limit]'], $page['text']);
    }

    public function testWithoutAnActionTheFormGoesToThePageItself(): void
    {
        $page = msParseFormPage('<form method="post"><input type="hidden" name="form[_token]" value="t" /></form>', self::PAGE);

        self::assertIsArray($page);
        self::assertSame(self::PAGE, $page['action']);
    }

    public function testAPageWithoutASymfonyFormSaysSo(): void
    {
        self::assertSame('no Symfony form found', msParseFormPage('<form action="/x"><input name="_token" value="t" /></form>', self::PAGE));
        self::assertSame('no Symfony form found', msParseFormPage('', self::PAGE));
    }

    /**
     * A Symfony settings form with the given fields and its CSRF field.
     */
    private function form(string $fields): string
    {
        return '<form name="form" method="post" action="/admin-dev/index.php/modules/heavenfoo/configuration/save?a=1&amp;b=2">'
            . $fields . '<input type="hidden" id="form__token" name="form[_token]" value="t" /></form>';
    }

    public function testPickTextFieldTakesTheFirstFieldTheFormAccepts(): void
    {
        $tried = [];
        $picked = msPickTextField(['form[emails]', 'form[medium]', 'form[days]'], static function (string $field) use (&$tried): ?string {
            $tried[] = $field;

            return $field === 'form[days]' ? null : 'refused ' . $field;
        });

        self::assertSame('form[days]', $picked['field']);
        self::assertNull($picked['error']);
        self::assertSame(['form[emails]' => 'refused form[emails]', 'form[medium]' => 'refused form[medium]'], $picked['refused']);
        self::assertSame(['form[emails]', 'form[medium]', 'form[days]'], $tried);
    }

    public function testPickTextFieldStopsAtTheFirstSuccess(): void
    {
        $tried = [];
        $picked = msPickTextField(['form[a]', 'form[b]'], static function (string $field) use (&$tried): ?string {
            $tried[] = $field;

            return null;
        });

        self::assertSame(['field' => 'form[a]', 'error' => null, 'refused' => []], $picked);
        self::assertSame(['form[a]'], $tried, 'A field that saved is the test field: no other field is changed.');
    }

    public function testPickTextFieldReportsTheLastErrorWhenEveryFieldIsRefused(): void
    {
        $picked = msPickTextField(['form[a]', 'form[b]'], static fn (string $field): ?string => 'no ' . $field);

        self::assertNull($picked['field']);
        self::assertSame('no form[b]', $picked['error']);
        self::assertSame(['form[a]', 'form[b]'], array_keys($picked['refused']));
        self::assertSame(['field' => null, 'error' => null, 'refused' => []], msPickTextField([], static fn (string $field): ?string => 'x'));
    }

    public function testANumberInputIsATextFieldCandidateInPageOrder(): void
    {
        $page = msParseFormPage($this->form(
            '<input type="number" name="form[medium]" value="30" />'
            . '<input type="text" name="form[emails]" value="" />'
            . '<input type="number" name="form[days]" value="365" />'
        ), self::PAGE);

        self::assertIsArray($page);
        self::assertSame(['form[medium]', 'form[emails]', 'form[days]'], $page['text']);
        self::assertSame('365', $page['fields']['form[days]']);
    }

    public function testAnEmailInputIsACandidateWithAnEmailMarker(): void
    {
        $page = msParseFormPage($this->form(
            '<input type="email" name="form[alert_email]" value="" />'
            . '<input type="text" name="form[name]" value="x" />'
        ), self::PAGE);

        self::assertIsArray($page);
        self::assertSame(['form[alert_email]', 'form[name]'], $page['text']);
        self::assertSame(['form[alert_email]'], $page['email']);
        self::assertSame('ms-bo-all@example.com', msFormMarker('all', false, 'all', true));
        self::assertSame('ms-bo-s2@example.com', msFormMarker('shop', false, 's2', true));
        self::assertSame('ms-bo-s2.', msFormMarker('shop', false, 's2'));
        self::assertTrue(msSameValue('ms-bo-s2@example.com', msFormMarker('shop', false, 's2', true), false));
    }
}
