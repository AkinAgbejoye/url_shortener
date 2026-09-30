<?php

namespace Tests\Unit;

use App\Contracts\UrlOwnership;
use App\Models\Url;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class UrlOwnershipTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_urls_remain_anonymous_after_the_owner_migration(): void
    {
        $url = Url::create([
            'short_code' => 'legacy-row',
            'long_url' => 'https://legacy.example',
            'management_token_hash' => hash('sha256', 'legacy-management-token'),
        ]);

        $this->assertTrue(Schema::hasColumn('urls', 'owner_id'));
        $this->assertTrue(Schema::hasIndex('urls', ['owner_id']));
        $this->assertNull($url->fresh()->owner_id);
        $this->assertSame('legacy-row', $url->fresh()->short_code);
        $this->assertSame('https://legacy.example', $url->fresh()->long_url);
        $this->assertSame(hash('sha256', 'legacy-management-token'), $url->fresh()->management_token_hash);
        $this->assertTrue($url->fresh()->isAnonymous());
    }

    public function test_owned_urls_have_typed_relationships_and_are_queryable_by_owner(): void
    {
        $owner = User::factory()->create();
        $otherOwner = User::factory()->create();
        $ownedUrl = $this->createUrl('owned', ['owner_id' => $owner->id]);
        $this->createUrl('other-owned', ['owner_id' => $otherOwner->id]);
        $anonymousUrl = $this->createUrl('anonymous');

        $this->assertTrue($ownedUrl->fresh()->owner->is($owner));
        $this->assertTrue($owner->urls()->firstOrFail()->is($ownedUrl));
        $this->assertEqualsCanonicalizing([$ownedUrl->id], Url::owned($owner)->pluck('id')->all());
        $this->assertEqualsCanonicalizing([$ownedUrl->id], Url::owned($owner->id)->pluck('id')->all());
        $this->assertEqualsCanonicalizing(
            [$ownedUrl->id, $otherOwner->urls()->firstOrFail()->id, $anonymousUrl->id],
            Url::active()->pluck('id')->all(),
        );
    }

    public function test_anonymous_and_owned_scopes_are_reusable_without_changing_active_links(): void
    {
        $owner = User::factory()->create();
        $ownedUrl = $this->createUrl('owned-scope', ['owner_id' => $owner->id]);
        $anonymousUrl = $this->createUrl('anonymous-scope');

        $this->assertEqualsCanonicalizing([$ownedUrl->id], Url::owned()->pluck('id')->all());
        $this->assertEqualsCanonicalizing([$anonymousUrl->id], Url::anonymous()->pluck('id')->all());
        $this->assertEqualsCanonicalizing([$ownedUrl->id, $anonymousUrl->id], Url::active()->pluck('id')->all());
    }

    public function test_users_with_owned_urls_cannot_be_deleted_silently(): void
    {
        $owner = User::factory()->create();
        $this->createUrl('delete-restricted', ['owner_id' => $owner->id]);

        $this->expectException(QueryException::class);

        $owner->delete();
    }

    public function test_ownership_contract_exposes_facts_without_authorizing_access(): void
    {
        $owner = User::factory()->create();
        $ownedUrl = $this->createUrl('contract-owned', ['owner_id' => $owner->id])->fresh();
        $anonymousUrl = $this->createUrl('contract-anonymous')->fresh();

        $this->assertInstanceOf(UrlOwnership::class, $ownedUrl);
        $this->assertSame($owner->id, $ownedUrl->ownerId());
        $this->assertTrue($ownedUrl->isOwned());
        $this->assertFalse($ownedUrl->isAnonymous());
        $this->assertNull($anonymousUrl->ownerId());
        $this->assertFalse($anonymousUrl->isOwned());
        $this->assertTrue($anonymousUrl->isAnonymous());
    }

    public function test_owner_migration_rolls_back_and_reapplies_on_sqlite(): void
    {
        $this->assertTrue(Schema::hasColumn('urls', 'owner_id'));

        Artisan::call('migrate:rollback', ['--step' => 1]);
        $this->assertFalse(Schema::hasColumn('urls', 'owner_id'));

        Artisan::call('migrate');
        $this->assertTrue(Schema::hasColumn('urls', 'owner_id'));
    }

    /** @param array<string, mixed> $attributes */
    private function createUrl(string $shortCode, array $attributes = []): Url
    {
        return Url::create([
            'short_code' => $shortCode,
            'long_url' => "https://{$shortCode}.example",
            ...$attributes,
        ]);
    }
}
