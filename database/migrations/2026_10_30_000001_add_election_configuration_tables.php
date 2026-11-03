<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parties', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150)->unique();
            $table->string('abbreviation', 30)->unique();
            $table->string('logo_path')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('election_parties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('election_id')->constrained()->cascadeOnDelete();
            $table->foreignId('party_id')->constrained()->restrictOnDelete();
            $table->string('ballot_number', 16);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['election_id', 'party_id']);
            $table->unique(['election_id', 'ballot_number']);
        });

        Schema::create('electoral_districts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('election_id')->constrained()->cascadeOnDelete();
            $table->string('name', 150);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['election_id', 'name']);
        });

        Schema::create('electoral_district_regions', function (Blueprint $table) {
            $table->foreignId('electoral_district_id')->constrained()->cascadeOnDelete();
            $table->foreignId('region_id')->constrained()->restrictOnDelete();
            $table->primary(['electoral_district_id', 'region_id']);
        });

        Schema::create('electoral_district_parties', function (Blueprint $table) {
            $table->foreignId('electoral_district_id')->constrained()->cascadeOnDelete();
            $table->foreignId('election_party_id')->constrained()->cascadeOnDelete();
            $table->primary(['electoral_district_id', 'election_party_id'], 'electoral_district_party_primary');
        });

        Schema::table('contest_participants', function (Blueprint $table) {
            $table->dropUnique(['election_contest_id', 'ballot_number']);
            $table->string('participant_type', 24)->default('pair')->after('election_contest_id');
            $table->foreignId('election_party_id')->nullable()->after('photo_path')->constrained()->restrictOnDelete();
            $table->foreignId('electoral_district_id')->nullable()->after('election_party_id')->constrained()->restrictOnDelete();
            $table->unique(
                ['election_contest_id', 'participant_type', 'electoral_district_id', 'election_party_id', 'ballot_number'],
                'contest_participant_ballot_unique'
            );
        });

        Schema::create('participant_supporting_parties', function (Blueprint $table) {
            $table->foreignId('contest_participant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('party_id')->constrained()->restrictOnDelete();
            $table->primary(['contest_participant_id', 'party_id'], 'participant_supporting_party_primary');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('participant_supporting_parties');
        Schema::table('contest_participants', function (Blueprint $table) {
            $table->dropUnique('contest_participant_ballot_unique');
            $table->dropConstrainedForeignId('electoral_district_id');
            $table->dropConstrainedForeignId('election_party_id');
            $table->dropColumn('participant_type');
            $table->unique(['election_contest_id', 'ballot_number']);
        });
        Schema::dropIfExists('electoral_district_parties');
        Schema::dropIfExists('electoral_district_regions');
        Schema::dropIfExists('electoral_districts');
        Schema::dropIfExists('election_parties');
        Schema::dropIfExists('parties');
    }
};
