<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('intelligence_suspended_at')->nullable()->index();
        });

        Schema::create('abuse_reports', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 40)->unique();
            $table->foreignId('reporter_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('category', 32)->index();
            $table->text('reporter_email')->nullable();
            $table->boolean('contact_consent')->default(false);
            $table->text('description');
            $table->text('evidence_reference')->nullable();
            $table->string('subject_reference_hash', 64)->nullable()->index();
            $table->string('status', 24)->default('received')->index();
            $table->foreignId('assigned_operator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('retained_until');
            $table->timestamps();
        });

        Schema::create('moderation_domain_blocks', function (Blueprint $table): void {
            $table->id();
            $table->string('domain_hash', 64)->unique();
            $table->string('category', 32);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('blocked_at');
            $table->timestamp('released_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('moderation_audit_events', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 40)->unique();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('action', 40)->index();
            $table->string('subject_type', 32);
            $table->string('subject_hash', 64)->index();
            $table->json('safe_metadata')->nullable();
            $table->timestamp('occurred_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('moderation_audit_events');
        Schema::dropIfExists('moderation_domain_blocks');
        Schema::dropIfExists('abuse_reports');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('intelligence_suspended_at'));
    }
};
