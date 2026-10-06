<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Visits booked through the schedule_visit tool (Week 4).
 *
 * farm_id is a plain string: farm profiles still live in a JSON file, so there
 * is no farms table to constrain against yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visits', function (Blueprint $table) {
            $table->id();
            $table->string('visit_ref')->unique();    // V-0001, the ID the model sees
            $table->string('farm_id')->index();
            $table->date('visit_date');
            $table->string('purpose', 200);
            $table->string('status');
            $table->string('created_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visits');
    }
};
