<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('mikrotik_connection_sources', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('connection_id');
            $table->string('source_type', 50);
            $table->string('source_key', 255);

            $table->json('source_data')->nullable();
            $table->timestamp('last_seen_at')->nullable();

            $table->timestamps();

            $table->foreign('connection_id')
                ->references('id')
                ->on('mikrotik_connections')
                ->cascadeOnDelete();

            $table->unique(['source_type', 'source_key']);
            $table->index('connection_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mikrotik_connection_sources');
    }
};
