<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('auth_otps', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('purpose', 20);
            $table->string('email');
            $table->string('code');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->dateTime('created_at');
            $table->dateTime('expires_at');
            $table->primary(['user_id', 'purpose']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auth_otps');
    }
};
