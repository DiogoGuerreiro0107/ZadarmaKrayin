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
        Schema::create('zadarma_accounts', function (Blueprint $table) {
            $table->increments('id');
            $table->text('api_key')->nullable();
            $table->text('api_secret')->nullable();
            $table->string('caller_extension')->nullable();
            $table->string('sync_mode')->default('polling');
            $table->boolean('active')->default(false);
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('zadarma_accounts');
    }
};
