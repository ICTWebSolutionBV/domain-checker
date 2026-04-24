<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('ip_lookups');
    }

    public function down(): void
    {
        Schema::create('ip_lookups', function (Blueprint $table): void {
            $table->id();
            $table->string('ip', 45)->index();
            $table->json('data');
            $table->timestamp('looked_up_at')->index();
            $table->timestamps();
        });
    }
};
