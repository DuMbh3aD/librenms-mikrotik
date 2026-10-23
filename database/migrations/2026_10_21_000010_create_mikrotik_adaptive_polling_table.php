<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('mikrotik_adaptive_polling', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('connection_key', 64)->unique();
            $table->string('last_status', 20)->default('unknown');
            $table->timestamp('down_since')->nullable();
            $table->timestamp('last_check_at')->nullable();
            $table->timestamp('last_dispatch_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mikrotik_adaptive_polling');
    }
};
