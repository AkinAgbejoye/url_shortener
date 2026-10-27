<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('analysis_jobs', function (Blueprint $table): void {
            $table->text('user_context')->nullable()->after('intent_hash');
        });

        Schema::create('analysis_page_fetches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('analysis_job_id')->constrained('analysis_jobs')->cascadeOnDelete();
            $table->string('public_id', 40)->unique();
            $table->text('source_url');
            $table->unsignedSmallInteger('status_code');
            $table->string('content_type', 100);
            $table->string('content_hash', 64);
            $table->unsignedBigInteger('compressed_bytes');
            $table->unsignedBigInteger('decompressed_bytes');
            $table->timestamp('fetched_at');
            $table->timestamps();

            $table->index(['analysis_job_id', 'fetched_at']);
        });

        Schema::create('analysis_reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('analysis_job_id')->unique()->constrained('analysis_jobs')->cascadeOnDelete();
            $table->string('public_id', 40)->unique();
            $table->string('schema_version', 20)->default('1.0');
            $table->timestamp('generated_at');
            $table->timestamp('expires_at');
            $table->timestamps();
        });

        Schema::create('analysis_observations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('analysis_job_id')->constrained('analysis_jobs')->cascadeOnDelete();
            $table->foreignId('page_fetch_id')->constrained('analysis_page_fetches')->cascadeOnDelete();
            $table->string('public_id', 40)->unique();
            $table->string('evidence_public_id', 40)->unique();
            $table->string('kind', 40)->index();
            $table->string('source_location', 255);
            $table->string('content_hash', 64);
            $table->text('statement');
            $table->text('excerpt');
            $table->timestamp('observed_at');
            $table->timestamps();

            $table->index(['analysis_job_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analysis_observations');
        Schema::dropIfExists('analysis_reports');
        Schema::dropIfExists('analysis_page_fetches');
        Schema::table('analysis_jobs', function (Blueprint $table): void {
            $table->dropColumn('user_context');
        });
    }
};
