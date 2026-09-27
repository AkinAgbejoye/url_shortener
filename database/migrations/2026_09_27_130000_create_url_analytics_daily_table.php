<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('url_analytics_daily', function (Blueprint $table) {
            $table->id();
            $table->foreignId('url_id')->constrained('urls')->cascadeOnDelete();
            $table->date('date');
            $table->unsignedBigInteger('redirect_count')->default(0);
            $table->timestamps();

            $table->unique(['url_id', 'date']);
            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('url_analytics_daily');
    }
};
