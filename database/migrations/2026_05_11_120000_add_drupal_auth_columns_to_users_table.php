<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('auth_source')->default('local')->after('password')->index();
            $table->unsignedBigInteger('drupal_uid')->nullable()->after('auth_source');
            $table->string('drupal_site')->nullable()->after('drupal_uid');
            $table->index(['auth_source', 'drupal_site', 'drupal_uid'], 'users_drupal_identity_idx');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex('users_drupal_identity_idx');
            $table->dropColumn(['auth_source', 'drupal_uid', 'drupal_site']);
        });
    }
};
