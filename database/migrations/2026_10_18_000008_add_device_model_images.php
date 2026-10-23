<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mikrotik_device_models', function (Blueprint $table) {
            $table->string('image_url', 2048)->nullable();
            $table->json('image_candidates')->nullable();
            $table->string('image_status', 30)->default('pending');
        });
    }

    public function down(): void
    {
        Schema::table('mikrotik_device_models', function (Blueprint $table) {
            $table->dropColumn([
                'image_url',
                'image_candidates',
                'image_status',
            ]);
        });
    }
};
