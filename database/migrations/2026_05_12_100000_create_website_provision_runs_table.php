<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('website_provision_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('subdomain');
            $table->string('parent_domain');
            $table->string('full_domain')->index();
            $table->string('www_root');
            $table->string('system_user');
            $table->string('source_database');
            $table->string('mysql_password_file')->default('/root/.mysql_pass');
            $table->string('ssl_registration_email');
            $table->string('website_username')->nullable();
            $table->text('website_password')->nullable();
            $table->string('status')->default('pending')->index();
            $table->string('current_step')->nullable()->index();
            $table->longText('steps_payload');
            $table->text('last_error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('website_provision_runs');
    }
};
