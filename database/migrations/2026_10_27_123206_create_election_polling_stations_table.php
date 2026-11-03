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
        Schema::create('election_polling_stations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('election_id')->constrained()->cascadeOnDelete();
            $table->foreignId('polling_station_id')->constrained()->restrictOnDelete();
            $table->boolean('eligible')->default(true);
            $table->string('status', 32)->default('active');
            $table->timestamps();

            $table->unique(['election_id', 'polling_station_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('election_polling_stations');
    }
};
