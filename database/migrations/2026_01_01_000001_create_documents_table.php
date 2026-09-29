<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per source document in the controlled corpus.
 *
 * Every column below exists to answer a provenance question: where did this
 * come from, who published it, may we use it, and what did ingestion change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->string('doc_ref')->unique();      // DOC-001, cited in every chunk
            $table->string('title');
            $table->string('publisher');
            $table->text('source_url');
            $table->string('licence')->nullable();
            $table->date('retrieved_on');
            $table->json('crops')->nullable();        // ["maize","beans"]
            $table->string('file_name');
            $table->unsignedInteger('page_count')->default(0);
            $table->unsignedInteger('word_count')->default(0);
            $table->unsignedInteger('sections_removed')->default(0);
            $table->json('removed_headings')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
