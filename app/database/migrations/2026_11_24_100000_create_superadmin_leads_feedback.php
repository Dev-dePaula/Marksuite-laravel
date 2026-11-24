<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Super admin da plataforma + captura de leads e feedbacks dos clientes. */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('super_admins', function (Blueprint $t) {
            $t->id();
            $t->string('name', 120);
            $t->string('email', 190)->unique();
            $t->string('passwordHash', 100);
            $t->unsignedInteger('failedLogins')->default(0);
            $t->timestamp('lockedUntil')->nullable();
            $t->timestamp('lastLoginAt')->nullable();
            $t->string('lastLoginIp', 64)->nullable();
            $t->timestamps();
        });
        Schema::create('leads', function (Blueprint $t) {
            $t->id();
            $t->string('name', 120);
            $t->string('email', 190)->nullable()->index();
            $t->string('phone', 50)->nullable();
            $t->string('company', 120)->nullable();
            $t->string('message', 1000)->nullable();
            $t->string('source', 30)->default('site');          // site | registo
            $t->string('status', 20)->default('NOVO')->index();  // NOVO | CONTACTADO | CONVERTIDO | PERDIDO
            $t->string('notes', 2000)->nullable();
            $t->string('ip', 64)->nullable();
            $t->timestamps();
        });
        Schema::create('feedbacks', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenantId')->nullable()->index();
            $t->unsignedBigInteger('userId')->nullable();
            $t->string('name', 120)->nullable();
            $t->string('email', 190)->nullable();
            $t->string('type', 20)->default('SUGESTAO');        // ELOGIO | SUGESTAO | PROBLEMA | OUTRO
            $t->unsignedTinyInteger('rating')->nullable();       // 1..5
            $t->string('message', 3000);
            $t->string('status', 20)->default('NOVO')->index();  // NOVO | LIDO | RESOLVIDO
            $t->string('ip', 64)->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feedbacks');
        Schema::dropIfExists('leads');
        Schema::dropIfExists('super_admins');
    }
};
