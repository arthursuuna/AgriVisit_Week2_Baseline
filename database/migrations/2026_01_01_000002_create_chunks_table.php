<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per retrievable passage.
 *
 * The embedding column stays null through Phase 1. It is filled in Phase 2 by
 * the indexing command, which is why the schema carries it from the start.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->string('chunk_ref')->unique();    // C-0001, the ID the model cites
            $table->string('section')->nullable();    // heading the chunk sits under
            $table->unsignedInteger('position');      // order within the document
            $table->longText('text');
            $table->unsignedInteger('word_count');
            $table->longText('embedding')->nullable();        // JSON array, Phase 2
            $table->string('embedding_model')->nullable();    // Phase 2
            $table->timestamps();

            $table->index(['document_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chunks');
    }
};
