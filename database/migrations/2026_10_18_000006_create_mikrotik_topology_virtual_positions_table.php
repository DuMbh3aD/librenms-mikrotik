<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mikrotik_topology_virtual_positions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('group_id');
            $table->string('virtual_id', 191);
            $table->string('label', 191);
            $table->double('x');
            $table->double('y');
            $table->timestamps();

            $table->unique(['group_id', 'virtual_id'], 'mt_virtual_position_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mikrotik_topology_virtual_positions');
    }
};
