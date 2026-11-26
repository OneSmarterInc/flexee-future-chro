<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Which welcome tours a person has finished or skipped (S1, A5).
        Schema::table('users', function (Blueprint $table) {
            $table->json('tours_done')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('tours_done'));
    }
};
