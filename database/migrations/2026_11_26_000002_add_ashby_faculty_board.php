<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The instructor chooses or draws the future before 2027 opens (FB4), and opens and closes each year (FB1).
        Schema::table('sections', function (Blueprint $table) {
            $table->string('future', 16)->nullable()->change();
            $table->unsignedSmallInteger('open_through')->default(2026)->change();
            $table->boolean('future_drawn')->default(false)->after('future');
            $table->unsignedSmallInteger('closed_through')->default(2026)->after('open_through');
        });

        // Who runs a class: its owner and any co-instructors.
        Schema::create('section_staff', function (Blueprint $table) {
            $table->id();
            $table->foreignId('section_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 16)->default('owner');
            $table->timestamps();
            $table->unique(['section_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('section_staff');
        Schema::table('sections', function (Blueprint $table) {
            $table->dropColumn(['future_drawn', 'closed_through']);
        });
    }
};
