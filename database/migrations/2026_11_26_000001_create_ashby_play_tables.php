<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A class running the sim. The future is drawn once and shown to staff only until the replay.
        Schema::create('sections', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('future', 16);
            $table->string('timezone', 64)->default('America/New_York');
            $table->unsignedSmallInteger('open_through')->default(2027);
            $table->timestamps();
        });

        Schema::create('teams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('section_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();
        });

        // Who sits on a team, and in which seat.
        Schema::create('team_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('seat', 16);
            $table->timestamps();
            $table->unique(['team_id', 'user_id']);
        });

        // One row per team per decision year: the cards, the time at the table, the checks, the moment and the paragraph.
        Schema::create('team_years', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->json('cards')->nullable();
            $table->unsignedSmallInteger('minutes_used')->default(0);
            $table->unsignedSmallInteger('checking_used')->default(0);
            $table->json('checks')->nullable();
            $table->json('revealed')->nullable();
            $table->text('moment')->nullable();
            $table->text('paragraph')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['team_id', 'year']);
        });

        // What the team asked at the table, and what it was told.
        Schema::create('table_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('year');
            $table->string('person', 16);
            $table->text('question');
            $table->text('answer');
            $table->string('revealed', 32)->nullable();
            $table->timestamps();
            $table->index(['team_id', 'year', 'person']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('table_messages');
        Schema::dropIfExists('team_years');
        Schema::dropIfExists('team_members');
        Schema::dropIfExists('teams');
        Schema::dropIfExists('sections');
    }
};
