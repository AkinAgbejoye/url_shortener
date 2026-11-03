<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analysis_batches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->string('public_id', 40)->unique();
            $table->string('state', 24)->index();
            $table->string('format', 8);
            $table->text('manifest_rows');
            $table->unsignedSmallInteger('total_rows');
            $table->unsignedSmallInteger('processed_rows')->default(0);
            $table->unsignedSmallInteger('successful_rows')->default(0);
            $table->unsignedSmallInteger('failed_rows')->default(0);
            $table->timestamp('cancel_requested_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->index(['owner_id', 'state']);
        });

        Schema::create('analysis_batch_rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('analysis_batch_id')->constrained('analysis_batches')->cascadeOnDelete();
            $table->unsignedSmallInteger('row_number');
            $table->string('status', 24)->index();
            $table->string('safe_error', 40)->nullable();
            $table->foreignId('analysis_job_id')->nullable()->constrained('analysis_jobs')->nullOnDelete();
            $table->timestamps();
            $table->unique(['analysis_batch_id', 'row_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analysis_batch_rows');
        Schema::dropIfExists('analysis_batches');
    }
};
