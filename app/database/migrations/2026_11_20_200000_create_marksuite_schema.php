<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Schema do MarkSuite multi-empresa. Base de dados única; todas as tabelas de negócio têm `tenantId`
 * (a empresa-cliente que subscreve). Os nomes das colunas ficam em camelCase para manter o JSON
 * da API idêntico ao do backend Node original (o frontend React funciona sem alterações).
 */
return new class extends Migration {
    private function money(Blueprint $t, string $c, bool $nullable = false, $default = null): void
    {
        $col = $t->decimal($c, 18, 2);
        if ($nullable) $col->nullable();
        if ($default !== null) $col->default($default);
    }

    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $t) {
            $t->id();
            $t->string('name', 120);
            $t->string('slug', 80)->unique();
            $t->string('plan', 20)->default('teste');
            $t->boolean('active')->default(true);
            $t->timestamp('trialEndsAt')->nullable();
            $t->timestamps();
        });

        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenantId')->index();
            $t->string('username', 60)->unique();
            $t->string('email', 190)->unique();
            $t->string('name', 120);
            $t->string('passwordHash', 100);
            $t->string('role', 30)->default('DIRECTOR_GERAL');
            $t->boolean('active')->default(true);
            $t->boolean('isOwner')->default(false);
            $t->timestamps();
        });

        $tables = [
            'companies' => function (Blueprint $t) {
                $t->string('name', 120); $t->string('abbr', 4); $t->string('color', 9)->default('#64748B');
                foreach (['Comercial', 'Financeiro', 'Projectos', 'Pessoas', 'Clientes', 'Operacoes'] as $s) $t->integer("score$s")->default(80);
                $t->string('nif', 50)->nullable(); $t->string('address', 255)->nullable(); $t->string('email', 255)->nullable();
                $t->string('phone', 50)->nullable(); $t->string('website', 120)->nullable(); $t->string('iban', 60)->nullable();
                $t->string('ivaRegime', 20)->default('geral');
                $t->unique(['tenantId', 'name']);
            },
            'health_snapshots' => function (Blueprint $t) {
                $t->unsignedBigInteger('companyId'); $t->date('month'); $t->integer('score');
                $t->unique(['companyId', 'month']);
            },
            'opportunities' => function (Blueprint $t) {
                $t->string('client', 120); $t->string('title', 160); $t->unsignedBigInteger('companyId');
                $this->money($t, 'value'); $t->string('stage', 20)->default('LEADS'); $t->integer('probability')->default(20);
                $t->date('lastContact'); $t->string('outcome', 10)->default('ABERTA'); $t->date('closedAt')->nullable();
                $t->string('pain', 255)->nullable(); $t->string('decisionMaker', 120)->nullable(); $t->string('email', 255)->nullable();
                $t->string('trigger', 160)->nullable(); $t->date('triggerDate')->nullable(); $t->date('firstContact')->nullable();
                $t->date('nextFollowup')->nullable(); $t->string('nextAction', 255)->nullable(); $t->boolean('replied')->default(false);
                $t->date('meetingAt')->nullable(); $t->date('diagnosedAt')->nullable(); $t->integer('infoLevel')->default(0);
                foreach (['sPain', 'sAccess', 'sTiming', 'sBudget', 'sFit', 'sMeeting', 'aCap', 'aCoh', 'aProof', 'aDigital', 'aConv'] as $s) $t->integer($s)->default(0);
                $t->string('offer', 40)->nullable();
            },
            'opp_contacts' => function (Blueprint $t) {
                $t->unsignedBigInteger('opportunityId')->index(); $t->string('name', 120); $t->string('role', 20);
                $t->string('area', 80)->nullable(); $t->string('email', 255)->nullable(); $t->string('notes', 255)->nullable();
            },
            'employees' => function (Blueprint $t) {
                $t->string('name', 120); $t->string('jobTitle', 120); $t->unsignedBigInteger('companyId');
                $t->integer('performance')->default(80); $t->integer('capacity')->default(90);
            },
            'projects' => function (Blueprint $t) {
                $t->string('name', 160); $t->unsignedBigInteger('companyId'); $t->string('client', 120)->default('Interno');
                $t->unsignedBigInteger('ownerId'); $t->date('startDate'); $t->date('dueDate'); $t->integer('progress')->default(0);
                $t->integer('delayedDeps')->default(0); $t->date('completedAt')->nullable();
            },
            'contacts' => function (Blueprint $t) {
                $t->unsignedBigInteger('companyId'); $t->string('name', 255); $t->string('nif', 50)->nullable();
                $t->string('email', 255)->nullable(); $t->string('phone', 50)->nullable(); $t->string('whatsapp', 50)->nullable();
                $t->string('address', 500)->nullable(); $t->string('kind', 30)->default('Cliente');
                $this->money($t, 'cativoPct', false, 0); $t->boolean('notifyEmail')->default(true);
                $t->boolean('notifyWhatsapp')->default(true); $t->boolean('active')->default(true);
            },
            'items' => function (Blueprint $t) {
                $t->unsignedBigInteger('companyId'); $t->string('ref', 40); $t->string('description', 255);
                $t->string('kind', 20)->default('Serviço'); $t->string('unit', 20)->default('un');
                $this->money($t, 'price', false, 0); $t->decimal('ivaRate', 5, 2)->default(14); $t->boolean('active')->default(true);
                $t->unique(['companyId', 'ref']);
            },
            'invoices' => function (Blueprint $t) {
                $t->string('kind', 10); $t->string('counterparty', 120); $t->string('docNumber', 40);
                $t->unsignedBigInteger('companyId'); $t->date('dueDate'); $this->money($t, 'amount');
                $t->date('paidAt')->nullable(); $t->timestamp('lastNotifiedAt')->nullable();
                $t->string('clientNif', 50)->nullable(); $t->text('description')->nullable(); $t->date('issueDate')->nullable();
                $this->money($t, 'subtotal', true); $t->decimal('ivaRate', 5, 2)->default(0);
                $this->money($t, 'ivaAmount', false, 0); $this->money($t, 'paidAmount', false, 0); $t->timestamp('cancelledAt')->nullable();
                $t->string('docType', 4)->default('FT'); $t->string('serie', 10)->nullable(); $t->boolean('draft')->default(false);
                $t->boolean('archived')->default(false); $t->unsignedBigInteger('contactId')->nullable()->index();
                $t->string('terms', 30)->nullable(); $t->string('vref', 60)->nullable(); $t->string('place', 120)->nullable(); $t->text('notes')->nullable();
                $t->decimal('gdiscPct', 5, 2)->default(0); $t->decimal('retPct', 5, 2)->default(0); $this->money($t, 'retAmount', false, 0);
                $t->decimal('cativoPct', 5, 2)->default(0); $this->money($t, 'cativoAmount', false, 0); $this->money($t, 'grossTotal', true);
                $t->string('refNumber', 40)->nullable(); $t->unsignedBigInteger('originId')->nullable(); $t->string('convertedTo', 40)->nullable();
                $t->string('hash', 64)->nullable(); $t->string('prevHash', 64)->nullable(); $t->text('signature')->nullable();
                $t->string('signKeyVersion', 10)->nullable(); $t->integer('eseq')->nullable(); $t->integer('printCount')->default(0);
                $t->json('transport')->nullable(); $t->json('delivery')->nullable(); $t->string('createdBy', 120)->nullable();
                $t->index(['docType', 'companyId']);
            },
            'invoice_lines' => function (Blueprint $t) {
                $t->unsignedBigInteger('invoiceId')->index(); $t->integer('pos')->default(0); $t->string('code', 40)->nullable();
                $t->string('description', 500); $t->decimal('qty', 18, 4)->default(1); $this->money($t, 'price');
                $t->decimal('discPct', 5, 2)->default(0); $t->decimal('ivaRate', 5, 2)->default(0);
                $this->money($t, 'net', false, 0); $this->money($t, 'tax', false, 0); $t->unsignedBigInteger('itemId')->nullable();
            },
            'notifications' => function (Blueprint $t) {
                $t->unsignedBigInteger('invoiceId'); $t->string('channel', 12); $t->string('event', 20); $t->string('toAddress', 255);
                $t->string('status', 12)->default('PENDENTE'); $t->string('error', 500)->nullable(); $t->string('providerId', 120)->nullable();
                $t->text('body'); $t->boolean('manual')->default(false); $t->timestamp('sentAt')->nullable();
                $t->index(['invoiceId', 'event', 'channel']);
            },
            'receipts' => function (Blueprint $t) {
                $t->string('docNumber', 30); $t->unsignedBigInteger('invoiceId')->index(); $this->money($t, 'amount');
                $t->date('paymentDate'); $t->string('paymentMethod', 30)->default('transferencia'); $this->money($t, 'lateAmount', false, 0);
                $t->unique(['tenantId', 'docNumber']);
            },
            'suppliers' => function (Blueprint $t) {
                $t->unsignedBigInteger('companyId'); $t->string('name', 255); $t->string('nif', 50)->nullable(); $t->string('email', 255)->nullable();
                $t->string('phone', 50)->nullable(); $t->string('category', 100)->nullable(); $t->text('address')->nullable(); $t->boolean('active')->default(true);
            },
            'expenses' => function (Blueprint $t) {
                $t->unsignedBigInteger('companyId'); $t->unsignedBigInteger('supplierId')->nullable(); $t->string('description', 255);
                $t->string('category', 100)->nullable(); $this->money($t, 'subtotal'); $t->decimal('ivaRate', 5, 2)->default(0);
                $this->money($t, 'ivaAmount', false, 0); $this->money($t, 'amount'); $t->date('dueDate')->nullable();
                $t->string('status', 12)->default('PENDENTE'); $t->string('requestedBy', 120); $t->text('approvalNote')->nullable();
                $t->timestamp('decidedAt')->nullable(); $t->timestamp('paidAt')->nullable();
            },
            'accounts' => function (Blueprint $t) {
                $t->unsignedBigInteger('companyId'); $t->string('code', 20); $t->string('name', 255); $t->string('class', 12);
                $t->boolean('synthetic')->default(false); $t->unsignedBigInteger('parentId')->nullable();
                $t->boolean('isSystem')->default(false); $t->boolean('active')->default(true);
                $t->unique(['companyId', 'code']);
            },
            'journal_entries' => function (Blueprint $t) {
                $t->string('docNumber', 30); $t->unsignedBigInteger('companyId'); $t->date('entryDate'); $t->text('description');
                $t->string('source', 30)->default('manual'); $t->string('sourceType', 30)->nullable(); $t->unsignedBigInteger('sourceId')->nullable();
                $t->unsignedBigInteger('reversedOfId')->nullable()->unique(); $t->boolean('reversed')->default(false); $t->string('createdBy', 120);
                $t->unique(['tenantId', 'docNumber']); $t->index(['companyId', 'entryDate']); $t->index(['sourceType', 'sourceId']);
            },
            'journal_lines' => function (Blueprint $t) {
                $t->unsignedBigInteger('entryId')->index(); $t->unsignedBigInteger('accountId');
                $this->money($t, 'debit', false, 0); $this->money($t, 'credit', false, 0); $t->string('memo', 255)->nullable();
            },
            'doc_counters' => function (Blueprint $t) {
                $t->string('key', 30); $t->integer('nextSeq')->default(1); $t->unique(['tenantId', 'key']);
            },
            'movements' => function (Blueprint $t) {
                $t->date('date')->index(); $t->string('description', 200); $t->unsignedBigInteger('companyId')->nullable();
                $t->string('category', 40); $this->money($t, 'amount');
            },
            'processes' => function (Blueprint $t) {
                $t->string('name', 160); $t->unsignedBigInteger('companyId')->nullable(); $t->unsignedBigInteger('ownerId');
                $t->string('sla', 40); $t->integer('execution')->default(80);
            },
            'process_actions' => function (Blueprint $t) {
                $t->unsignedBigInteger('processId')->index(); $t->string('action', 200); $t->unsignedBigInteger('ownerId');
                $t->date('dueDate'); $t->boolean('done')->default(false);
            },
            'decisions' => function (Blueprint $t) {
                $t->string('title', 160); $t->string('subtitle', 200); $this->money($t, 'amount', true); $t->string('area', 12);
                $t->text('context'); $t->string('status', 10)->default('PENDENTE'); $t->date('postponedUntil')->nullable();
                $t->unsignedBigInteger('decidedById')->nullable(); $t->timestamp('decidedAt')->nullable(); $t->text('justification')->nullable();
            },
            'memory_entries' => function (Blueprint $t) {
                $t->string('title', 200); $t->text('context'); $t->string('responsible', 120); $t->string('approvedBy', 120);
                $t->date('date'); $t->text('reason'); $t->text('expected'); $t->text('actual')->nullable();
                $t->string('outcome', 14)->default('EM_AVALIACAO'); $t->string('area', 12); $t->unsignedBigInteger('decisionId')->nullable()->unique();
            },
            'objectives' => function (Blueprint $t) { $t->string('title', 200); },
            'key_results' => function (Blueprint $t) {
                $t->unsignedBigInteger('objectiveId')->index(); $t->string('title', 200); $t->integer('progress')->default(0);
            },
            'courses' => function (Blueprint $t) {
                $t->string('name', 160); $t->integer('avgCompletion')->default(0); $t->unique(['tenantId', 'name']);
            },
            'enrollments' => function (Blueprint $t) {
                $t->unsignedBigInteger('courseId'); $t->unsignedBigInteger('employeeId'); $t->unique(['courseId', 'employeeId']);
            },
            'automations' => function (Blueprint $t) {
                $t->string('code', 40); $t->string('label', 200); $t->boolean('enabled')->default(false); $t->unique(['tenantId', 'code']);
            },
            'tasks' => function (Blueprint $t) {
                $t->string('title', 200); $t->text('notes')->nullable(); $t->unsignedBigInteger('assigneeId'); $t->unsignedBigInteger('createdById');
                $t->date('dueDate')->nullable(); $t->string('source', 40); $t->boolean('done')->default(false);
            },
            'settings' => function (Blueprint $t) {
                $t->string('key', 40); $t->string('value', 200); $t->unique(['tenantId', 'key']);
            },
            'kpi_snapshots' => function (Blueprint $t) {
                $t->date('day'); $t->unsignedBigInteger('companyId')->default(0); $t->string('key', 30); $t->double('value');
                $t->unique(['tenantId', 'day', 'companyId', 'key']);
            },
            'ai_insights' => function (Blueprint $t) {
                $t->integer('days'); $t->text('content'); $t->unsignedBigInteger('createdById')->nullable();
            },
            'activity_logs' => function (Blueprint $t) {
                $t->string('type', 12); $t->unsignedBigInteger('userId')->nullable(); $t->string('username', 60); $t->string('method', 10)->nullable();
                $t->string('path', 200)->nullable(); $t->string('ip', 64)->nullable(); $t->string('userAgent', 255)->nullable();
                $t->index(['username', 'createdAt']); $t->index(['type', 'createdAt']);
            },
            'error_logs' => function (Blueprint $t) {
                $t->string('source', 10); $t->unsignedBigInteger('userId')->nullable(); $t->string('username', 60)->nullable();
                $t->string('method', 10)->nullable(); $t->string('path', 200)->nullable(); $t->integer('status')->nullable();
                $t->string('message', 500); $t->text('stack')->nullable(); $t->string('ip', 64)->nullable(); $t->string('userAgent', 255)->nullable();
            },
        ];

        foreach ($tables as $name => $cols) {
            Schema::create($name, function (Blueprint $t) use ($cols) {
                $t->id();
                $t->unsignedBigInteger('tenantId')->index();
                $cols($t);
                $t->timestamp('createdAt')->nullable();
                $t->timestamp('updatedAt')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('tenants');
    }
};
