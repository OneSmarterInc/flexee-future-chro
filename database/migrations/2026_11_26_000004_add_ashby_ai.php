<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Per-class AI switches the instructor controls (AI2), and the debrief text.
        Schema::table('sections', function (Blueprint $table) {
            $table->json('ai_off')->nullable()->after('auto_run');
            $table->text('debrief')->nullable()->after('ai_off');
            $table->string('debrief_note', 255)->nullable()->after('debrief');
        });

        // Where each table answer came from, and why an AI answer was not shown (for the instructor only).
        Schema::table('table_messages', function (Blueprint $table) {
            $table->string('source', 8)->default('fixed')->after('answer');
            $table->string('dropped_reason', 255)->nullable()->after('source');
            $table->unsignedInteger('input_tokens')->default(0)->after('dropped_reason');
            $table->unsignedInteger('output_tokens')->default(0)->after('input_tokens');
            $table->string('model', 64)->nullable()->after('output_tokens');
        });

        // The reply to a team's paragraph (AI3).
        Schema::table('team_years', function (Blueprint $table) {
            $table->text('feedback')->nullable()->after('paragraph');
            $table->string('feedback_note', 255)->nullable()->after('feedback');
        });

        // The class reading for a closed year (AI4).
        Schema::table('section_years', function (Blueprint $table) {
            $table->text('reading')->nullable()->after('closes_at');
            $table->string('reading_note', 255)->nullable()->after('reading');
        });
    }

    public function down(): void
    {
        Schema::table('section_years', fn (Blueprint $t) => $t->dropColumn(['reading', 'reading_note']));
        Schema::table('team_years', fn (Blueprint $t) => $t->dropColumn(['feedback', 'feedback_note']));
        Schema::table('table_messages', fn (Blueprint $t) => $t->dropColumn(['source', 'dropped_reason', 'input_tokens', 'output_tokens', 'model']));
        Schema::table('sections', fn (Blueprint $t) => $t->dropColumn(['ai_off', 'debrief', 'debrief_note']));
    }
};
