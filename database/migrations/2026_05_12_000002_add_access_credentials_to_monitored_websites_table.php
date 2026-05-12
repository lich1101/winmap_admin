<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitored_websites', function (Blueprint $table) {
            $table->string('website_username')->nullable()->after('api_key');
            $table->text('website_password')->nullable()->after('website_username');
        });
    }

    public function down(): void
    {
        Schema::table('monitored_websites', function (Blueprint $table) {
            $table->dropColumn([
                'website_username',
                'website_password',
            ]);
        });
    }
};
