<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crawl_pages', function (Blueprint $table): void {
            $table->json('evidence_snapshot')->nullable()->after('content_hash');
        });
        Schema::create('monitor_schedules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('domain_verification_id')->constrained('domain_verifications')->restrictOnDelete();
            $table->string('public_id', 40)->unique();
            $table->string('state', 24)->index();
            $table->unsignedSmallInteger('frequency_hours');
            $table->unsignedSmallInteger('monthly_run_budget');
            $table->json('crawl_policy');
            $table->json('thresholds');
            $table->json('notification_preferences');
            $table->string('parser_version', 40);
            $table->string('model_version', 40);
            $table->timestamp('next_run_at')->index();
            $table->timestamp('last_run_at')->nullable();
            $table->unsignedInteger('missed_runs')->default(0);
            $table->timestamp('paused_at')->nullable();
            $table->timestamp('deleted_at')->nullable()->index();
            $table->timestamps();
            $table->index(['owner_id', 'state']);
            $table->index(['owner_id', 'domain_verification_id']);
        });
        Schema::create('monitor_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('monitor_schedule_id')->constrained('monitor_schedules')->cascadeOnDelete();
            $table->foreignId('crawl_job_id')->nullable()->constrained('crawl_jobs')->nullOnDelete();
            $table->string('public_id', 40)->unique();
            $table->string('state', 24)->index();
            $table->timestamp('scheduled_for');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancel_requested_at')->nullable();
            $table->string('safe_failure_category', 40)->nullable();
            $table->timestamp('notification_failed_at')->nullable();
            $table->timestamps();
            $table->unique(['monitor_schedule_id', 'scheduled_for']);
        });
        Schema::create('monitor_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('monitor_schedule_id')->constrained('monitor_schedules')->cascadeOnDelete();
            $table->foreignId('monitor_run_id')->constrained('monitor_runs')->cascadeOnDelete();
            $table->string('public_id', 40)->unique();
            $table->unsignedInteger('sequence');
            $table->json('source_snapshot');
            $table->json('evidence_references');
            $table->string('parser_version', 40);
            $table->string('model_version', 40);
            $table->timestamp('captured_at');
            $table->timestamps();
            $table->unique(['monitor_schedule_id', 'sequence']);
        });
        Schema::create('monitor_comparisons', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('monitor_schedule_id')->constrained('monitor_schedules')->cascadeOnDelete();
            $table->foreignId('previous_version_id')->constrained('monitor_versions')->cascadeOnDelete();
            $table->foreignId('current_version_id')->constrained('monitor_versions')->cascadeOnDelete();
            $table->boolean('material');
            $table->json('source_changes');
            $table->json('version_drift');
            $table->json('evidence_citations');
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();
            $table->unique(['previous_version_id', 'current_version_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitor_comparisons');
        Schema::dropIfExists('monitor_versions');
        Schema::dropIfExists('monitor_runs');
        Schema::dropIfExists('monitor_schedules');
        Schema::table('crawl_pages', fn (Blueprint $table) => $table->dropColumn('evidence_snapshot'));
    }
};
