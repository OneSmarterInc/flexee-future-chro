<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The slice (2027, 2029, 2031 in two-year jumps) or the full format (every year 2027 to 2034), FF-D5.
        Schema::table('sections', function (Blueprint $table) {
            $table->string('length', 8)->default('slice')->after('format');
        });
    }

    public function down(): void
    {
        Schema::table('sections', fn (Blueprint $t) => $t->dropColumn('length'));
    }
};
