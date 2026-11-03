<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('domain_verifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->string('public_id', 40)->unique();
            $table->text('domain');
            $table->string('domain_hash', 64)->index();
            $table->string('method', 24);
            $table->text('challenge_token');
            $table->string('challenge_token_hash', 64);
            $table->timestamp('challenge_expires_at')->index();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('verified_until')->nullable()->index();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['owner_id', 'domain_hash']);
        });

        Schema::create('crawl_jobs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('domain_verification_id')->constrained('domain_verifications')->restrictOnDelete();
            $table->string('public_id', 40)->unique();
            $table->string('state', 24)->index();
            $table->json('policy');
            $table->json('robots_policy')->nullable();
            $table->json('summary')->nullable();
            $table->timestamp('cancel_requested_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->index(['owner_id', 'state']);
        });

        Schema::create('crawl_pages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('crawl_job_id')->constrained('crawl_jobs')->cascadeOnDelete();
            $table->string('public_id', 40)->unique();
            $table->text('url');
            $table->string('url_hash', 64);
            $table->string('canonical_hash', 64)->nullable();
            $table->unsignedSmallInteger('depth');
            $table->unsignedSmallInteger('status_code');
            $table->unsignedBigInteger('bytes');
            $table->string('content_hash', 64);
            $table->string('outcome', 24)->index();
            $table->timestamp('fetched_at');
            $table->timestamps();
            $table->unique(['crawl_job_id', 'url_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crawl_pages');
        Schema::dropIfExists('crawl_jobs');
        Schema::dropIfExists('domain_verifications');
    }
};
