<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vote_submissions', function (Blueprint $table) {
            $table->foreignId('correction_of_submission_id')->nullable()->after('election_polling_station_id')->constrained('vote_submissions')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('vote_submissions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('correction_of_submission_id');
        });
    }
};
