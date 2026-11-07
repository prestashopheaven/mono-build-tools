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

/**
 * The host port of a module's test shop for one PrestaShop version: base + 100 * major + 10 * minor + patch.
 *
 * The base is testing.json > base_port (8000 when missing), so the shops of different modules never share a port on
 * one machine. PS_TEST_BASE_PORT overrides it: CI sets it below 32768, because the 5xxxx ports of the modules fall in
 * the Linux ephemeral range of the GitHub runners, where another process can already hold the port.
 *
 * @param array<string, mixed> $testing the module's testing.json
 *
 * @throws InvalidArgumentException when PS_TEST_BASE_PORT is not a port number that leaves room for the versions
 */
function shop_port(array $testing, string $version): int
{
    $base = (int) ($testing['base_port'] ?? 8000);
    $override = trim((string) getenv('PS_TEST_BASE_PORT'));
    if ($override !== '') {
        if (!ctype_digit($override) || (int) $override < 1 || (int) $override > 64000) {
            throw new InvalidArgumentException("PS_TEST_BASE_PORT must be a port number from 1 to 64000, '$override' given.");
        }
        $base = (int) $override;
    }
    $parts = array_map('intval', explode('.', $version));

    return $base + $parts[0] * 100 + ($parts[1] ?? 0) * 10 + ($parts[2] ?? 0);
}
