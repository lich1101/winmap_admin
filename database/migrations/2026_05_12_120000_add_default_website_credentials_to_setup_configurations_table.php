<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('setup_configurations', function (Blueprint $table) {
            $table->string('default_website_username')->nullable()->after('auth_site_domain');
            $table->text('default_website_password')->nullable()->after('default_website_username');
        });
    }

    public function down(): void
    {
        Schema::table('setup_configurations', function (Blueprint $table) {
            $table->dropColumn([
                'default_website_username',
                'default_website_password',
            ]);
        });
    }
};
