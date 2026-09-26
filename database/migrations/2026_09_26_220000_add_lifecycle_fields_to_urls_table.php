<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('urls', function (Blueprint $table) {
            $table->timestamp('expires_at')->nullable()->after('long_url');
            $table->timestamp('disabled_at')->nullable()->after('expires_at');
            $table->softDeletes();

            $table->index(['disabled_at', 'expires_at']);
            $table->index('deleted_at');
        });
    }

    public function down(): void
    {
        Schema::table('urls', function (Blueprint $table) {
            $table->dropIndex(['disabled_at', 'expires_at']);
            $table->dropIndex(['deleted_at']);
            $table->dropSoftDeletes();
            $table->dropColumn(['expires_at', 'disabled_at']);
        });
    }
};
