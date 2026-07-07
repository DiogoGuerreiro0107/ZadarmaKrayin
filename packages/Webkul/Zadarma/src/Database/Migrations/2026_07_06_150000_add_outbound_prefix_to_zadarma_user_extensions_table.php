<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('zadarma_user_extensions', function (Blueprint $table) {
            $table->string('outbound_prefix')->nullable()->after('extension');

            // Both fields are now independently optional (a user might only
            // want to override one of the two), so extension can no longer
            // be required at the DB level either.
            $table->string('extension')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('zadarma_user_extensions', function (Blueprint $table) {
            $table->dropColumn('outbound_prefix');

            $table->string('extension')->nullable(false)->change();
        });
    }
};
