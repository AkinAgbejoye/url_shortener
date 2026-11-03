<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analysis_findings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('analysis_report_id')->constrained('analysis_reports')->cascadeOnDelete();
            $table->string('public_id', 40)->unique();
            $table->string('epistemic_type', 24)->index();
            $table->string('category', 40)->index();
            $table->text('statement');
            $table->string('confidence', 16);
            $table->json('evidence_ids');
            $table->json('details')->nullable();
            $table->timestamps();
            $table->index(['analysis_report_id', 'epistemic_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analysis_findings');
    }
};
