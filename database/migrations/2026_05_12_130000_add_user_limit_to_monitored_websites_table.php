<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitored_websites', function (Blueprint $table) {
            $table->unsignedInteger('user_limit')->default(0)->after('quota_bytes');
            $table->unsignedInteger('last_user_count')->default(0)->after('last_project_bytes');
        });
    }

    public function down(): void
    {
        Schema::table('monitored_websites', function (Blueprint $table) {
            $table->dropColumn(['user_limit', 'last_user_count']);
        });
    }
};
