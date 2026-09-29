<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records how many mix-ratio sentences were removed before chunking, alongside
 * the section and chunk counts, so every removal layer is visible in the register.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->unsignedInteger('sentences_removed')->default(0)->after('removed_headings');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn('sentences_removed');
        });
    }
};
