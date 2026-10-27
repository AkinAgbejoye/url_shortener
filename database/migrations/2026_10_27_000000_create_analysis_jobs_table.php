<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analysis_jobs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->string('public_id', 40)->unique();
            $table->text('target_url');
            $table->string('submitted_origin', 255);
            $table->string('origin_hash', 64)->index();
            $table->string('domain_hash', 64)->index();
            $table->string('state', 32)->index();
            $table->string('safe_failure_category', 40)->nullable();
            $table->string('idempotency_key_hash', 64)->nullable();
            $table->string('intent_hash', 64)->nullable();
            $table->json('extraction')->nullable();
            $table->json('provenance')->nullable();
            $table->timestamp('cancel_requested_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->unique(['owner_id', 'idempotency_key_hash']);
            $table->index(['owner_id', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analysis_jobs');
    }
};
