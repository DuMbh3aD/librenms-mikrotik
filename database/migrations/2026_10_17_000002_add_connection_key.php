<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('mikrotik_connections', function (Blueprint $table) {
            $table->string('connection_key', 64)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('mikrotik_connections', function (Blueprint $table) {
            $table->dropUnique(['connection_key']);
            $table->dropColumn('connection_key');
        });
    }
};
