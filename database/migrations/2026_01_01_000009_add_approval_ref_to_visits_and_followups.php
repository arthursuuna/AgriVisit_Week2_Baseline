<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A written row points back at the decision that authorised it.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['visits', 'followups'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->string('approval_ref')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        foreach (['visits', 'followups'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropIndex(['approval_ref']);
                $table->dropColumn('approval_ref');
            });
        }
    }
};
