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

/*
 * Bootstrap of the package's own tests: Composer's autoloader (PHPUnit) and the scripts under test. The scripts are
 * plain function files; publish.php only runs its command line when it is the script PHP started.
 */

declare(strict_types=1);

// In the monorepo the autoloader is the root one (ps-module mono check loads it first); standalone it is the package's.
if (is_file(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}
require_once __DIR__ . '/../src/publish.php';
