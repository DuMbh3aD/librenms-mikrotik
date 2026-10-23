<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mikrotik_topology_positions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('group_id');
            $table->unsignedInteger('device_id');
            $table->integer('x')->default(0);
            $table->integer('y')->default(0);
            $table->timestamps();

            $table->unique(
                ['group_id', 'device_id'],
                'mikrotik_topology_group_device_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mikrotik_topology_positions');
    }
};
