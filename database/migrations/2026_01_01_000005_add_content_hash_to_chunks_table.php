<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * sha256 of the chunk text at the time it was embedded. Indexing re-embeds a
 * chunk only when its vector is missing or this hash no longer matches, so a
 * re-ingest that leaves the text unchanged costs no API calls.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chunks', function (Blueprint $table) {
            $table->string('content_hash', 64)->nullable()->index()->after('embedding_model');
        });
    }

    public function down(): void
    {
        Schema::table('chunks', function (Blueprint $table) {
            $table->dropIndex(['content_hash']);
            $table->dropColumn('content_hash');
        });
    }
};
