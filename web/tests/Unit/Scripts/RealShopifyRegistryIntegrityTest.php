<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use App\Scripts\ScriptRegistry;
use PHPUnit\Framework\TestCase;

/**
 * The one place today's real config/scripts/shopify.php content gets checked — a
 * typo here should fail this test, not surface as a 500 once routes exist. See
 * RealEbayRegistryIntegrityTest's docblock for why this is separate from
 * ScriptRegistryTest.
 */
final class RealShopifyRegistryIntegrityTest extends TestCase
{
    public function test_the_real_shopify_config_parses_without_throwing(): void
    {
        $configDir = dirname(__DIR__, 3).'/config/scripts';

        $registry = new ScriptRegistry($configDir);

        $this->assertCount(37, $registry->forMarketplace('shopify'));
    }
}
