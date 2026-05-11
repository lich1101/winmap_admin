<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitored_websites', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('domain')->unique();
            $table->text('usage_endpoint_url');
            $table->text('api_key')->nullable();
            $table->unsignedBigInteger('quota_bytes')->default(0);
            $table->boolean('enabled')->default(true)->index();
            $table->string('last_status')->default('pending')->index();
            $table->text('last_error')->nullable();
            $table->unsignedBigInteger('last_disk_bytes')->default(0);
            $table->unsignedBigInteger('last_database_bytes')->default(0);
            $table->unsignedBigInteger('last_project_bytes')->default(0);
            $table->decimal('last_usage_percent', 8, 2)->default(0);
            $table->timestamp('last_checked_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitored_websites');
    }
};
