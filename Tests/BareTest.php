<?php

namespace Omnifood\JustEat\Tests;

use Omnifood\JustEat\JustEatPlatformFactory;
use Omnifood\Testing\BareTestCase;

/** omnifood/justeat's classes load with omnisense/omnifood, omnisense/contracts and Symfony's HTTP client contracts alone. */
final class BareTest extends BareTestCase
{
    protected static function entryClass(): string
    {
        return JustEatPlatformFactory::class;
    }

    protected static function requiredPackages(): array
    {
        return ['symfony/http-client-contracts', 'symfony/deprecation-contracts', 'psr/container', 'symfony/polyfill-mbstring'];
    }
}
