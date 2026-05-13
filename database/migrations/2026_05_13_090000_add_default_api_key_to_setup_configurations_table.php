<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('setup_configurations', function (Blueprint $table) {
            $table->text('default_api_key')->nullable()->after('default_website_password');
        });
    }

    public function down(): void
    {
        Schema::table('setup_configurations', function (Blueprint $table) {
            $table->dropColumn('default_api_key');
        });
    }
};
