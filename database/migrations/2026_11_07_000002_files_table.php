<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('files', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('abs_list_id')->nullable();
            $table->unsignedBigInteger('author_id')->nullable();
            $table->string('file_type', 10);
            $table->string('file_name');
            $table->unsignedBigInteger('file_size');
            $table->string('file_path');
            $table->string('mime_type');
            $table->char('sha256', 64);
            $table->char('source_sha256', 64)->nullable()->index();
            $table->index('sha256');
            $table->foreignId('user_id')->constrained();
            $table->timestamps();
            $table->softDeletes();

            // Upload history includes soft-deleted files and orders UUID ties ascending.
            $table->rawIndex('"user_id", "created_at" desc, "id"', 'files_user_uploads_index');
            $table->rawIndex('"created_at" desc, "id"', 'files_uploads_index');
        });

        Schema::create('upload_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained();
            $table->unsignedBigInteger('abs_list_id')->nullable();
            $table->unsignedBigInteger('author_id')->nullable();
            $table->string('kind', 10);
            $table->string('file_name');
            $table->unsignedBigInteger('file_size');
            $table->char('sha256', 64)->nullable();
            $table->unsignedInteger('chunk_size');
            $table->unsignedInteger('chunk_count');
            $table->unsignedInteger('season_number')->default(1);
            $table->unsignedInteger('episode_number')->default(1);
            $table->string('title')->nullable();
            $table->unsignedBigInteger('chapter_id')->nullable();
            $table->boolean('allow_alternative')->default(false);
            $table->string('primary_choice', 10)->nullable();
            $table->string('status', 20)->default('uploading');
            $table->uuid('file_id')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->index(['status', 'expires_at', 'id'], 'upload_sessions_expiry_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('upload_sessions');
        Schema::dropIfExists('files');
    }
};
