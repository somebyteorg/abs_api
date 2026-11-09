<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::ensureExtensionExists('pg_trgm');

        Schema::create('users', function (Blueprint $table) {
            $table->id()->startingValue(1000);
            $table->string('emos_id')->unique();
            $table->string('username');
            $table->text('avatar')->nullable();
            $table->string('pseudonym', 60)->nullable()->unique();
            $table->boolean('adult_mode')->default(false);
            $table->unsignedBigInteger('size_upload')->default(0);
            $table->enum('role', ['admin', 'edit', 'user'])->nullable();
            $table->string('notice_version')->nullable();
            $table->timestamp('notice_accepted_at')->nullable();
            $table->rememberToken();
            $table->timestamps();

            // Member searches use ILIKE '%keyword%'; ranking uses mixed sort directions.
            $table->rawIndex('"username" gin_trgm_ops', 'users_username_search_index')->algorithm('gin');
            $table->rawIndex('"pseudonym" gin_trgm_ops', 'users_pseudonym_search_index')->algorithm('gin');
            $table->rawIndex('"size_upload" desc, "id"', 'users_upload_ranking_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
