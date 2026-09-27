<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('urls', function (Blueprint $table) {
            $table->boolean('is_custom')->default(false)->index()->after('short_code');
        });
    }

    public function down(): void
    {
        Schema::table('urls', function (Blueprint $table) {
            $table->dropIndex(['is_custom']);
            $table->dropColumn('is_custom');
        });
    }
};
