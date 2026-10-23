<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mikrotik_device_models', function (Blueprint $table) {
            $table->id();
            $table->string('model', 191)->unique();
            $table->string('manufacturer', 100)->default('MikroTik');
            $table->string('device_type', 50)->nullable();
            $table->string('body_shape', 50)->default('generic');
            $table->string('source_url', 2048)->nullable();
            $table->longText('svg')->nullable();
            $table->json('ports')->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->boolean('manually_edited')->default(false);
            $table->boolean('verified')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mikrotik_device_models');
    }
};
