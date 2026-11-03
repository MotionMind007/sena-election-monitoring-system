<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contest_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('election_contest_id')->constrained()->cascadeOnDelete();
            $table->string('ballot_number', 20);
            $table->string('display_name', 200);
            $table->string('photo_path')->nullable();
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['election_contest_id', 'ballot_number']);
        });

        Schema::create('vote_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('election_id')->constrained()->restrictOnDelete();
            $table->foreignId('election_contest_id')->constrained()->restrictOnDelete();
            $table->foreignId('election_polling_station_id')->constrained()->restrictOnDelete();
            $table->foreignId('submitter_id')->constrained('users')->restrictOnDelete();
            $table->string('status', 32)->index();
            $table->string('entry_mode', 32);
            $table->string('source_name')->nullable();
            $table->string('source_method', 32)->nullable();
            $table->text('source_notes')->nullable();
            $table->uuid('idempotency_key');
            $table->unsignedInteger('version');
            $table->timestampTz('submitted_at')->nullable();
            $table->timestamps();
            $table->unique(['submitter_id', 'idempotency_key']);
            $table->unique(['election_id', 'election_contest_id', 'election_polling_station_id', 'version'], 'vote_submission_version_unique');
        });

        Schema::create('vote_submission_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vote_submission_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contest_participant_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('votes');
            $table->string('ballot_dimension', 32)->default('candidate');
            $table->unique(['vote_submission_id', 'contest_participant_id', 'ballot_dimension'], 'vote_submission_item_unique');
        });

        Schema::create('vote_submission_totals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vote_submission_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('valid_votes');
            $table->unsignedInteger('invalid_votes');
            $table->unsignedInteger('total_votes');
            $table->json('extra_fields')->nullable();
        });

        Schema::create('submission_evidence', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vote_submission_id')->constrained()->cascadeOnDelete();
            $table->string('disk', 32)->default('local');
            $table->string('storage_key');
            $table->string('original_name');
            $table->string('sha256', 64);
            $table->string('media_type', 100);
            $table->unsignedBigInteger('size_bytes');
            $table->timestampTz('uploaded_at');
            $table->timestamps();
            $table->unique(['vote_submission_id', 'sha256']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('submission_evidence');
        Schema::dropIfExists('vote_submission_totals');
        Schema::dropIfExists('vote_submission_items');
        Schema::dropIfExists('vote_submissions');
        Schema::dropIfExists('contest_participants');
    }
};
