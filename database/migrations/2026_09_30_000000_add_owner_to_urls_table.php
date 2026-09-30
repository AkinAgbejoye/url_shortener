<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('urls', function (Blueprint $table) {
            $table->foreignId('owner_id')
                ->nullable()
                ->after('id')
                ->index()
                ->constrained('users')
                ->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('urls', function (Blueprint $table) {
            if (DB::connection()->getDriverName() === 'sqlite') {
                $table->dropForeign(['owner_id']);
                $table->dropIndex(['owner_id']);
                $table->dropColumn('owner_id');

                return;
            }

            $table->dropConstrainedForeignId('owner_id');
        });
    }
};
