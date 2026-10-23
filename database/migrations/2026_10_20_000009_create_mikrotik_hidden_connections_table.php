<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('mikrotik_hidden_connections', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('group_id');
            $table->string('connection_key', 191);
            $table->timestamps();

            $table->unique(['group_id', 'connection_key'], 'mt_hidden_connection_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mikrotik_hidden_connections');
    }
};
