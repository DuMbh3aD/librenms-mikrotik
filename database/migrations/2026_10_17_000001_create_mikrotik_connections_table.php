<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('mikrotik_connections', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('connection_type', 50);
            $table->string('connection_layer', 30);

            $table->unsignedInteger('local_device_id')->nullable();
            $table->string('local_interface', 255)->nullable();

            $table->unsignedInteger('remote_device_id')->nullable();
            $table->string('remote_interface', 255)->nullable();

            $table->string('remote_address', 255)->nullable();
            $table->string('source', 50);

            $table->string('status', 30)->default('unknown');
            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->index(['connection_type', 'connection_layer']);
            $table->index('local_device_id');
            $table->index('remote_device_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mikrotik_connections');
    }
};
