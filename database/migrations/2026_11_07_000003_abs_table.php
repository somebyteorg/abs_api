<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// This migration belongs only to emos_abs_api. Never use the abs connection here.
return new class extends Migration {
    public function up(): void
    {
        Schema::create('abs_authors', function (Blueprint $table) {
            $table->id()->startingValue(1000);
            $table->foreignId('user_id')->nullable()->constrained();
            $table->string('name');
            $table->text('description')->nullable();
            $table->uuid('cover_file_id')->nullable()->constrained('files');
            $table->timestamps();
            $table->softDeletes();
            $table->index(['deleted_at', 'name', 'id'], 'abs_authors_listing_index');
            $table->index('cover_file_id');
            $table->rawIndex('"name" gin_trgm_ops', 'abs_authors_name_search_index')->algorithm('gin');
        });
        Schema::create('abs_libraries', function (Blueprint $table) {
            $table->id()->startingValue(1000);
            $table->foreignId('user_id')->nullable()->constrained();
            $table->string('name');
            $table->enum('type', ['book', 'podcast']);
            $table->uuid('abs_id')->unique();
            $table->uuid('abs_folder_id')->unique();
            $table->boolean('need_sync')->default(true);
            $table->timestamp('sync_enqueued_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['need_sync', 'updated_at', 'id'], 'abs_libraries_sync_outbox_index');
        });
        Schema::create('abs_lists', function (Blueprint $table) {
            $table->id()->startingValue(1000);
            $table->foreignId('user_id')->index()->constrained();
            $table->foreignId('abs_library_id')->constrained('abs_libraries');
            $table->enum('type', ['book', 'podcast']);
            $table->string('name');
            $table->string('tagline')->nullable();
            $table->text('description')->nullable();
            $table->uuid('cover_file_id')->nullable()->constrained('files');
            $table->date('date_air')->nullable();
            $table->boolean('is_adult')->default(false);
            $table->uuid('abs_id')->unique();
            $table->uuid('abs_media_id')->unique();
            $table->unsignedBigInteger('revision')->default(0);
            $table->boolean('need_sync')->default(true);
            $table->string('sync_status', 20)->default('pending');
            $table->string('sync_error')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamp('sync_enqueued_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            // Match latest-first listings, with either a type or category filter.
            $table->index(['updated_at', 'id'], 'abs_lists_recent_index');
            $table->index(['type', 'updated_at', 'id'], 'abs_lists_type_recent_index');
            $table->index(['abs_library_id', 'updated_at', 'id'], 'abs_lists_library_recent_index');
            $table->index(['need_sync', 'updated_at', 'id'], 'abs_lists_sync_outbox_index');
            $table->index('cover_file_id');
            $table->rawIndex('"name" gin_trgm_ops', 'abs_lists_name_search_index')->algorithm('gin');
            $table->rawIndex('"tagline" gin_trgm_ops', 'abs_lists_tagline_search_index')->algorithm('gin');
        });
        Schema::create('abs_work_authors', function (Blueprint $table) {
            $table->foreignId('work_id')->constrained('abs_lists');
            $table->foreignId('author_id')->constrained('abs_authors');
            $table->uuid('abs_id')->unique();
            $table->timestamps();
            $table->primary(['work_id', 'author_id']);
            $table->index(['author_id', 'work_id'], 'abs_work_authors_author_work_index');
        });
        Schema::create('abs_author_libraries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('author_id')->constrained('abs_authors');
            $table->foreignId('abs_library_id')->constrained('abs_libraries');
            $table->timestamps();
            $table->unique(['abs_library_id', 'author_id']);
        });
        Schema::create('abs_items', function (Blueprint $table) {
            $table->id()->startingValue(1000);
            $table->foreignId('user_id')->constrained();
            $table->foreignId('abs_list_id')->constrained('abs_lists');
            $table->unsignedInteger('season_number')->default(1);
            $table->unsignedInteger('episode_number');
            $table->unsignedInteger('index');
            $table->string('title');
            $table->uuid('abs_id')->unique();
            $table->unsignedBigInteger('primary_media_id')->nullable()->index();
            $table->unsignedBigInteger('merged_into_id')->nullable();
            $table->boolean('slot_active')->nullable()->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['abs_list_id', 'season_number', 'episode_number', 'slot_active'], 'abs_items_live_slot_unique');
            $table->index(['abs_list_id', 'deleted_at', 'index', 'id'], 'abs_items_work_order_index');
        });
        Schema::create('abs_media', function (Blueprint $table) {
            $table->id()->startingValue(1000);
            $table->foreignUuid('file_id')->index()->constrained('files');
            $table->foreignId('abs_list_id')->constrained('abs_lists');
            $table->foreignId('abs_item_id')->constrained('abs_items');
            $table->foreignId('user_id')->index()->constrained();
            $table->string('name');
            $table->unsignedBigInteger('file_size');
            $table->double('file_second');
            $table->jsonb('file_metadata');
            $table->boolean('is_reference')->default(false);
            $table->index(['abs_item_id', 'deleted_at']);
            $table->index(['abs_list_id', 'deleted_at'], 'abs_media_work_active_index');
            $table->text('delete_reason')->nullable();
            $table->timestamps();
            $table->softDeletes();

        });
        Schema::table('abs_items', fn(Blueprint $table) => $table->foreign('primary_media_id')->references('id')->on('abs_media'));
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id()->startingValue(1000);
            $table->foreignId('user_id')->constrained();
            $table->unsignedBigInteger('work_id')->nullable();
            $table->string('action', 40);
            $table->string('subject');
            $table->jsonb('details');
            $table->timestamp('created_at');
            $table->index(['work_id', 'id'], 'activity_logs_work_history_index');
            $table->index(['user_id', 'id'], 'activity_logs_user_history_index');
            $table->index(['created_at', 'id'], 'activity_logs_date_history_index');
        });
        Schema::create('inbox_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->unsignedBigInteger('work_id')->nullable();
            $table->text('message');
            $table->timestamp('read_at')->nullable();
            $table->timestamp('created_at');
            $table->index(['user_id', 'id'], 'inbox_messages_user_history_index');
        });
    }

    public function down(): void
    {
        Schema::table('abs_items', fn(Blueprint $table) => $table->dropForeign(['primary_media_id']));
        foreach (['inbox_messages', 'activity_logs', 'abs_media', 'abs_items', 'abs_work_authors', 'abs_author_libraries', 'abs_lists', 'abs_libraries', 'abs_authors'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
