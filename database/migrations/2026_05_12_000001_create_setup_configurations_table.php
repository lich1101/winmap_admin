<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('setup_configurations', function (Blueprint $table) {
            $table->id();
            $table->boolean('is_completed')->default(false)->index();
            $table->string('server_host')->nullable();
            $table->unsignedInteger('server_port')->default(22);
            $table->string('server_username')->nullable();
            $table->text('server_password')->nullable();
            $table->string('drupal_project_path')->nullable();
            $table->string('drupal_site_scheme')->default('https');
            $table->string('auth_site_domain')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('setup_configurations');
    }
};
