<?php

declare(strict_types=1);

namespace Tests\Unit\Scripts;

use App\Scripts\WriteConfirmationMode;
use App\Scripts\WriteConfirmationResolver;
use PHPUnit\Framework\TestCase;

final class WriteConfirmationResolverTest extends TestCase
{
    private WriteConfirmationResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new WriteConfirmationResolver;
    }

    public function test_item_alone_resolves_to_single_with_that_id(): void
    {
        $params = ['item' => '123456789012'];

        $this->assertSame(WriteConfirmationMode::Single, $this->resolver->resolve($params));
        $this->assertSame('123456789012', $this->resolver->singleItemId($params));
    }

    public function test_items_with_one_entry_resolves_to_single_with_that_entry(): void
    {
        $params = ['items' => '123456789012'];

        $this->assertSame(WriteConfirmationMode::Single, $this->resolver->resolve($params));
        $this->assertSame('123456789012', $this->resolver->singleItemId($params));
    }

    public function test_items_with_two_entries_resolves_to_bulk(): void
    {
        $params = ['items' => '111,222'];

        $this->assertSame(WriteConfirmationMode::Bulk, $this->resolver->resolve($params));
    }

    public function test_items_with_stray_whitespace_and_commas_still_counts_correctly(): void
    {
        $params = ['items' => ' 111 , , 222 '];

        $this->assertSame(WriteConfirmationMode::Bulk, $this->resolver->resolve($params));
    }

    public function test_offset_or_limit_resolves_to_bulk(): void
    {
        $this->assertSame(WriteConfirmationMode::Bulk, $this->resolver->resolve(['offset' => '10']));
        $this->assertSame(WriteConfirmationMode::Bulk, $this->resolver->resolve(['limit' => '50']));
    }

    public function test_no_selection_param_at_all_resolves_to_bulk(): void
    {
        $this->assertSame(WriteConfirmationMode::Bulk, $this->resolver->resolve(['account' => 'dows']));
    }

    public function test_item_takes_priority_over_items(): void
    {
        $params = ['item' => '999', 'items' => '111,222,333'];

        $this->assertSame(WriteConfirmationMode::Single, $this->resolver->resolve($params));
        $this->assertSame('999', $this->resolver->singleItemId($params));
    }

    public function test_single_item_id_returns_null_in_bulk_mode(): void
    {
        $this->assertNull($this->resolver->singleItemId(['items' => '111,222']));
    }
}
