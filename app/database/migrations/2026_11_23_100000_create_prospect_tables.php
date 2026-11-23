<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Prospecção por IA (porta de ProspectRun/Prospect do projeto-angola), isolada por tenant. */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('prospect_runs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenantId')->index();
            $t->unsignedBigInteger('companyId');
            $t->string('niche', 160);
            $t->string('location', 160);
            $t->string('status', 20)->default('A_CORRER');
            $t->text('summary')->nullable();
            $t->string('error', 255)->nullable();
            $t->timestamp('createdAt')->nullable();
            $t->timestamp('updatedAt')->nullable();
            $t->index(['companyId', 'createdAt']);
        });
        Schema::create('prospects', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenantId')->index();
            $t->unsignedBigInteger('runId')->index();
            $t->string('name', 160);
            $t->string('phone', 60)->nullable();
            $t->string('email', 255)->nullable()->index();
            $t->string('website', 255)->nullable();
            $t->string('city', 120)->nullable();
            $t->string('sourceUrl', 500)->nullable();
            $t->text('pains')->nullable();
            $t->text('solutions')->nullable();
            $t->text('proposal')->nullable();
            $t->string('status', 20)->default('NOVO');
            $t->boolean('approved')->default(false);
            $t->timestamp('sentAt')->nullable();
            $t->timestamp('repliedAt')->nullable();
            $t->boolean('optOut')->default(false);
            $t->unsignedBigInteger('opportunityId')->nullable();
            $t->timestamp('createdAt')->nullable();
            $t->timestamp('updatedAt')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prospects');
        Schema::dropIfExists('prospect_runs');
    }
};
