<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usage_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('monitored_website_id')->constrained()->cascadeOnDelete();
            $table->string('status')->index();
            $table->unsignedBigInteger('disk_bytes')->default(0);
            $table->unsignedBigInteger('disk_allocated_bytes')->nullable();
            $table->unsignedBigInteger('database_bytes')->default(0);
            $table->unsignedBigInteger('project_bytes')->default(0);
            $table->decimal('usage_percent', 8, 2)->default(0);
            $table->text('error')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('checked_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_snapshots');
    }
};
