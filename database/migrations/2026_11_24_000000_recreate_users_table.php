<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// users を docs/design/data.md の 2-1 の形に作り直す。
// Laravel 10 は dbal が無く ->change() で型を直せないので、drop → create にする（users はまだ空）。
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('users');

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name', 30);
            $table->string('name_normalized', 30)->collation('utf8mb4_bin')->unique();
            $table->string('email', 254);
            $table->string('email_normalized', 254)->collation('utf8mb4_bin')->unique();
            $table->string('password');
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });
    }
};
