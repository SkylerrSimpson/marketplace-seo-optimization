<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use App\Scripts\ScriptRegistry;
use PHPUnit\Framework\TestCase;

/**
 * The one place today's real config/scripts/ebay.php content gets checked — a typo
 * here should fail this test, not surface as a 500 once routes exist. Deliberately
 * separate from ScriptRegistryTest: that suite tests the parser and shouldn't change
 * every time a new script gets registered; this one tests today's data.
 */
final class RealEbayRegistryIntegrityTest extends TestCase
{
    public function test_the_real_ebay_config_parses_without_throwing(): void
    {
        $configDir = dirname(__DIR__, 3).'/config/scripts';

        $registry = new ScriptRegistry($configDir);

        $this->assertCount(37, $registry->forMarketplace('ebay'));
    }
}
