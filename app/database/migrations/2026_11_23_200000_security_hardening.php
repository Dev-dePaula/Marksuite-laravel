<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Endurecimento: bloqueio de contas, 2FA (TOTP), bloqueio de IPs e registo de eventos de segurança. */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->text('totpSecret')->nullable();          // cifrado com APP_KEY
            $t->boolean('totpEnabled')->default(false);
            $t->text('totpRecovery')->nullable();        // hashes dos códigos de recuperação
            $t->unsignedBigInteger('totpLastStep')->default(0); // anti-replay
            $t->unsignedInteger('failedLogins')->default(0);
            $t->timestamp('lockedUntil')->nullable();
            $t->timestamp('passwordChangedAt')->nullable();
        });
        Schema::create('security_blocks', function (Blueprint $t) {
            $t->id();
            $t->string('ip', 64)->unique();
            $t->string('reason', 160);
            $t->unsignedInteger('hits')->default(1);
            $t->timestamp('until')->nullable()->index();
            $t->timestamp('createdAt')->nullable();
            $t->timestamp('updatedAt')->nullable();
        });
        Schema::create('security_events', function (Blueprint $t) {
            $t->id();
            $t->string('type', 30)->index();
            $t->string('ip', 64)->nullable()->index();
            $t->string('username', 190)->nullable();
            $t->string('detail', 300)->nullable();
            $t->timestamp('createdAt')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_events');
        Schema::dropIfExists('security_blocks');
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['totpSecret', 'totpEnabled', 'totpRecovery', 'totpLastStep', 'failedLogins', 'lockedUntil', 'passwordChangedAt']));
    }
};
