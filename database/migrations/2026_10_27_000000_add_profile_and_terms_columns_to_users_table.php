<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('name', 150)->change();
            $table->string('username', 50)->unique()->after('name');

            // Nullable to preserve existing accounts without inventing consent.
            $table->timestampTz('terms_accepted_at')->nullable()->after('password');
            $table->string('terms_version', 40)->nullable()->after('terms_accepted_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['username']);
            $table->dropColumn(['username', 'terms_accepted_at', 'terms_version']);
            $table->string('name', 255)->change();
        });
    }
};