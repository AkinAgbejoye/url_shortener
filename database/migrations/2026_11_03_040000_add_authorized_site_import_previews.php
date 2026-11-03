<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_imports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('domain_verification_id')->constrained('domain_verifications')->restrictOnDelete();
            $table->string('public_id', 40)->unique();
            $table->string('state', 24)->index();
            $table->text('rights_attestation');
            $table->text('declared_licenses');
            $table->timestamp('rights_attested_at');
            $table->timestamp('rights_expires_at')->index();
            $table->json('policy');
            $table->longText('sanitized_html')->nullable();
            $table->longText('editable_representation')->nullable();
            $table->json('sanitation_summary')->nullable();
            $table->json('provenance')->nullable();
            $table->string('safe_failure_category', 40)->nullable();
            $table->timestamp('cancel_requested_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('takedown_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamp('deleted_at')->nullable()->index();
            $table->timestamps();
            $table->index(['owner_id', 'state']);
        });

        Schema::create('site_import_assets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_import_id')->constrained('site_imports')->cascadeOnDelete();
            $table->string('public_id', 40)->unique();
            $table->string('source_path_hash', 64);
            $table->string('media_type', 80);
            $table->unsignedBigInteger('bytes');
            $table->string('content_hash', 64);
            $table->longText('content');
            $table->json('scan_result');
            $table->timestamps();
            $table->unique(['site_import_id', 'source_path_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_import_assets');
        Schema::dropIfExists('site_imports');
    }
};
