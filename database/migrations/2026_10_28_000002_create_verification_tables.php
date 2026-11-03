<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coordinator_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vote_submission_id')->constrained()->restrictOnDelete();
            $table->foreignId('reviewer_id')->constrained('users')->restrictOnDelete();
            $table->string('action', 32);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['vote_submission_id', 'reviewer_id']);
        });
        Schema::create('approval_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vote_submission_id')->constrained()->restrictOnDelete();
            $table->foreignId('admin_id')->constrained('users')->restrictOnDelete();
            $table->string('action', 32);
            $table->text('reason')->nullable();
            $table->timestamps();
        });
        Schema::create('verified_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('election_id')->constrained()->restrictOnDelete();
            $table->foreignId('election_contest_id')->constrained()->restrictOnDelete();
            $table->foreignId('election_polling_station_id')->constrained()->restrictOnDelete();
            $table->foreignId('source_submission_id')->unique()->constrained('vote_submissions')->restrictOnDelete();
            $table->boolean('active')->default(true);
            $table->timestampTz('verified_at');
            $table->foreignId('verified_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('supersedes_id')->nullable()->constrained('verified_results')->nullOnDelete();
            $table->timestamps();
        });
        DB::statement('CREATE UNIQUE INDEX verified_result_active_unique ON verified_results (election_id, election_contest_id, election_polling_station_id) WHERE active = true');
        Schema::create('verified_result_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('verified_result_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contest_participant_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('votes');
            $table->string('ballot_dimension', 32)->default('candidate');
            $table->unique(['verified_result_id', 'contest_participant_id', 'ballot_dimension'], 'verified_result_item_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('verified_result_items');
        Schema::dropIfExists('verified_results');
        Schema::dropIfExists('approval_actions');
        Schema::dropIfExists('coordinator_reviews');
    }
};
