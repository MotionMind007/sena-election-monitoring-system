<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vote_submissions', function (Blueprint $table) {
            $table->index(['status', 'id'], 'vote_submissions_queue_index');
            $table->index(['election_id', 'election_contest_id', 'election_polling_station_id', 'id'], 'vote_submissions_dashboard_index');
        });

        Schema::table('verified_results', function (Blueprint $table) {
            $table->index(['election_id', 'election_contest_id', 'active', 'verified_at'], 'verified_results_dashboard_index');
        });

        Schema::table('election_polling_stations', function (Blueprint $table) {
            $table->index(['election_id', 'eligible', 'status'], 'event_tps_scope_index');
        });
    }

    public function down(): void
    {
        Schema::table('vote_submissions', function (Blueprint $table) {
            $table->dropIndex('vote_submissions_queue_index');
            $table->dropIndex('vote_submissions_dashboard_index');
        });

        Schema::table('verified_results', function (Blueprint $table) {
            $table->dropIndex('verified_results_dashboard_index');
        });

        Schema::table('election_polling_stations', function (Blueprint $table) {
            $table->dropIndex('event_tps_scope_index');
        });
    }
};
