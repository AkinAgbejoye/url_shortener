<?php

namespace Tests\Unit;

use App\Models\Url;
use App\Rules\CustomAlias as CustomAliasRule;
use App\Support\CustomAlias;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CustomAliasTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('validAliases')]
    public function test_it_canonicalizes_and_accepts_safe_aliases(string $input, string $canonical): void
    {
        $validator = Validator::make(['custom_alias' => $input], [
            'custom_alias' => [new CustomAliasRule],
        ]);

        $this->assertFalse($validator->fails());
        $this->assertSame($canonical, CustomAlias::canonicalize($input));
    }

    /** @return array<string, array{string, string}> */
    public static function validAliases(): array
    {
        return [
            'lowercase' => ['launch', 'launch'],
            'mixed case and whitespace' => ['  Product-Launch-2026  ', 'product-launch-2026'],
            'numbers' => ['release-42', 'release-42'],
        ];
    }

    #[DataProvider('invalidAliases')]
    public function test_it_rejects_unsafe_aliases(mixed $input, string $message): void
    {
        $validator = Validator::make(['custom_alias' => $input], [
            'custom_alias' => [new CustomAliasRule],
        ]);

        $this->assertTrue($validator->fails());
        $this->assertSame($message, $validator->errors()->first('custom_alias'));
    }

    /** @return array<string, array{mixed, string}> */
    public static function invalidAliases(): array
    {
        return [
            'non-string' => [42, 'The custom alias must be a string.'],
            'too short' => ['ab', 'The custom alias must be between 3 and 48 characters.'],
            'too long' => [str_repeat('a', 49), 'The custom alias must be between 3 and 48 characters.'],
            'reserved case insensitive' => ['API', 'The custom alias is reserved and cannot be used.'],
            'leading hyphen' => ['-launch', 'The custom alias may contain letters, numbers, and single hyphens between groups.'],
            'trailing hyphen' => ['launch-', 'The custom alias may contain letters, numbers, and single hyphens between groups.'],
            'consecutive hyphens' => ['product--launch', 'The custom alias may contain letters, numbers, and single hyphens between groups.'],
            'slash' => ['product/launch', 'The custom alias may contain letters, numbers, and single hyphens between groups.'],
            'encoded separator' => ['product%2flaunch', 'The custom alias may contain letters, numbers, and single hyphens between groups.'],
            'control character' => ["product\nlaunch", 'The custom alias may contain letters, numbers, and single hyphens between groups.'],
            'unicode' => ['café', 'The custom alias may contain letters, numbers, and single hyphens between groups.'],
            'underscore' => ['product_launch', 'The custom alias may contain letters, numbers, and single hyphens between groups.'],
        ];
    }

    public function test_length_bounds_are_configurable_and_never_invert(): void
    {
        config()->set('url_shortener.aliases.min_length', 5);
        config()->set('url_shortener.aliases.max_length', 4);

        $validator = Validator::make(['custom_alias' => 'four'], [
            'custom_alias' => [new CustomAliasRule],
        ]);

        $this->assertSame(
            'The custom alias must be between 5 and 5 characters.',
            $validator->errors()->first('custom_alias'),
        );
    }

    public function test_reserved_aliases_are_configurable(): void
    {
        config()->set('url_shortener.aliases.reserved', ['campaign']);

        $validator = Validator::make(['custom_alias' => 'Campaign'], [
            'custom_alias' => [new CustomAliasRule],
        ]);

        $this->assertSame(
            'The custom alias is reserved and cannot be used.',
            $validator->errors()->first('custom_alias'),
        );
    }

    public function test_existing_and_custom_urls_have_typed_origin_metadata(): void
    {
        $this->assertTrue(Schema::hasColumn('urls', 'is_custom'));
        $this->assertTrue(Schema::hasIndex('urls', ['is_custom']));

        $generated = Url::create([
            'short_code' => '1',
            'long_url' => 'https://generated.example',
        ]);
        $custom = Url::create([
            'short_code' => CustomAlias::canonicalize('Product-Launch'),
            'long_url' => 'https://custom.example',
            'is_custom' => true,
        ]);

        $this->assertFalse($generated->fresh()->is_custom);
        $this->assertSame('1', $generated->fresh()->short_code);
        $this->assertTrue($custom->fresh()->is_custom);
        $this->assertSame('product-launch', $custom->fresh()->short_code);
    }
}
