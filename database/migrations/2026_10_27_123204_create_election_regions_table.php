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
        Schema::create('election_regions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('election_id')->constrained()->cascadeOnDelete();
            $table->foreignId('region_id')->constrained()->restrictOnDelete();
            $table->string('code_snapshot', 20);
            $table->string('name_snapshot');
            $table->string('level_snapshot', 24);
            $table->string('source_snapshot', 32);
            $table->string('source_version_snapshot')->nullable();
            $table->timestamps();

            $table->unique(['election_id', 'region_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('election_regions');
    }
};
