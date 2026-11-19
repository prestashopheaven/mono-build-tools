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

/**
 * The PHP error output in a page of the smoke tests (ps-test.php), or null. It matches the way PHP and Symfony print an
 * error ("Fatal error:" with its colon, also as "<b>Fatal error</b>:", an uncaught exception, a stack trace, the
 * back-office "Invalid token" page), not the words in a normal text: a module that writes "a fatal error" in a help
 * text is not broken (Bing IndexNow retro, proposal 6).
 *
 * @param bool $backOffice also the back-office only signs (stack trace, invalid token)
 *
 * @return array{0: string, 1: int}|null what was found and where
 */
function smokeError(string $body, bool $backOffice): ?array
{
    $patterns = [
        '/(?:<b>\s*)?\b(Fatal error|Parse error|Warning|Notice|Deprecated)(?:\s*<\/b>)?\s*:\s/' => null,
        '/\bUncaught\s+(?:[A-Z][\w\\\\]*)(?:Exception|Error)\b/' => 'Uncaught',
    ];
    if ($backOffice) {
        $patterns['/\bStack trace:|>\s*Stack Traces?\s*</'] = 'Stack trace';
        $patterns['/\bInvalid token\b/'] = 'Invalid token';
    }
    $first = null;
    foreach ($patterns as $pattern => $label) {
        if (preg_match($pattern, $body, $m, PREG_OFFSET_CAPTURE) === 1 && ($first === null || $m[0][1] < $first[1])) {
            $first = [$label ?? $m[1][0], (int) $m[0][1]];
        }
    }

    return $first;
}
