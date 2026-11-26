<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sections', function (Blueprint $table) {
            $table->string('format', 16)->default('workshop')->after('name');
            $table->string('join_code', 16)->nullable()->unique()->after('timezone');
            $table->unsignedSmallInteger('capacity')->nullable()->after('join_code');
            $table->boolean('auto_run')->default(false)->after('capacity');
        });

        // Who is in a class. Enrolment is its own record, so a paused or unplaced participant still has one.
        Schema::create('enrolments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('section_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status', 16)->default('active');
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->unique(['section_id', 'user_id']);
        });

        // The class calendar: when each decision year opens and closes, in UTC (RO3).
        Schema::create('section_years', function (Blueprint $table) {
            $table->id();
            $table->foreignId('section_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->timestamp('opens_at')->nullable();
            $table->timestamp('closes_at')->nullable();
            $table->timestamps();
            $table->unique(['section_id', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('section_years');
        Schema::dropIfExists('enrolments');
        Schema::table('sections', function (Blueprint $table) {
            $table->dropUnique(['join_code']);
            $table->dropColumn(['format', 'join_code', 'capacity', 'auto_run']);
        });
    }
};
