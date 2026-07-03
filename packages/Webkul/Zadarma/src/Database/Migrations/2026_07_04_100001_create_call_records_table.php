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
        Schema::create('call_records', function (Blueprint $table) {
            $table->increments('id');
            $table->string('external_id')->unique();
            $table->string('direction');
            $table->string('from_number');
            $table->string('to_number');
            $table->unsignedInteger('duration')->default(0);
            $table->string('disposition')->nullable();
            $table->string('recording_url')->nullable();
            $table->unsignedInteger('person_id')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamps();

            $table->foreign('person_id')->references('id')->on('persons')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('call_records');
    }
};
