<?php

use App\Http\Controllers\Api\{AnalyticsController, AuthController, CrmController, DocumentController, FinanceController, MonitorController, OpsController, ProspectController, StrategyController};
use Illuminate\Support\Facades\Route;

Route::get('/status', fn () => ['status' => 'online', 'timestamp' => now()]);

// ---------- público ----------
Route::post('/auth/register', [AuthController::class, 'register'])->middleware('throttle:10,60');
Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:20,15');
Route::get('/public/doc/{token}', [DocumentController::class, 'publicDoc'])->middleware('throttle:public');

// ---------- autenticado + isolado por empresa-cliente ----------
Route::middleware(['auth:sanctum', 'throttle:api', 'tenant', \App\Http\Middleware\TrackActivity::class])->group(function () {
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::post('/auth/logout-others', [AuthController::class, 'logoutOthers']);
    Route::post('/auth/password', [AuthController::class, 'changePassword'])->middleware('throttle:6,15');
    Route::post('/auth/2fa/setup', [AuthController::class, 'twoFactorSetup'])->middleware('throttle:10,15');
    Route::post('/auth/2fa/enable', [AuthController::class, 'twoFactorEnable'])->middleware('throttle:10,15');
    Route::post('/auth/2fa/disable', [AuthController::class, 'twoFactorDisable'])->middleware('throttle:6,15');

    // utilizadores da empresa
    Route::get('/users', [AuthController::class, 'users']);
    Route::post('/users', [AuthController::class, 'createUser'])->middleware('canwrite:users');
    Route::put('/users/{id}', [AuthController::class, 'updateUser'])->middleware('canwrite:users');

    Route::get('/dashboard', [StrategyController::class, 'dashboard']);

    // empresas
    Route::get('/companies', [CrmController::class, 'companies']);
    Route::post('/companies', [CrmController::class, 'storeCompany'])->middleware('canwrite:companies');
    Route::put('/companies/{id}', [CrmController::class, 'updateCompany'])->middleware('canwrite:companies');
    Route::delete('/companies/{id}', [CrmController::class, 'destroyCompany'])->middleware('canwrite:companies');
    Route::put('/companies/{id}/fiscal', [DocumentController::class, 'companyFiscal'])->middleware('canwrite:billing');

    // CRM
    Route::get('/opportunities', [CrmController::class, 'opportunities']);
    Route::get('/opportunities/funnel', [CrmController::class, 'funnel']);
    Route::post('/opportunities', [CrmController::class, 'storeOpportunity'])->middleware('canwrite:opportunities');
    Route::put('/opportunities/{id}', [CrmController::class, 'updateOpportunity'])->middleware('canwrite:opportunities');
    Route::delete('/opportunities/{id}', [CrmController::class, 'destroyOpportunity'])->middleware('canwrite:opportunities');
    Route::get('/opportunities/{id}/contacts', [CrmController::class, 'oppContacts']);
    Route::post('/opportunities/{id}/contacts', [CrmController::class, 'storeOppContact'])->middleware('canwrite:opportunities');
    Route::delete('/opportunities/{id}/contacts/{cid}', [CrmController::class, 'destroyOppContact'])->middleware('canwrite:opportunities');
    Route::post('/opportunities/{id}/draft', [CrmController::class, 'draft'])->middleware('canwrite:opportunities');
    Route::post('/opportunities/{id}/advance', [CrmController::class, 'advance'])->middleware('canwrite:opportunities');
    Route::post('/opportunities/{id}/lose', [CrmController::class, 'lose'])->middleware('canwrite:opportunities');

    // prospecção por IA
    Route::get('/prospect/config', [ProspectController::class, 'showConfig']);
    Route::put('/prospect/config', [ProspectController::class, 'saveConfig']);
    Route::post('/prospect/profile', [ProspectController::class, 'profile'])->middleware(['canwrite:opportunities', 'throttle:ai']);
    Route::post('/prospect/runs', [ProspectController::class, 'startRun'])->middleware(['canwrite:opportunities', 'throttle:ai']);
    Route::get('/prospect/runs', [ProspectController::class, 'runs']);
    Route::get('/prospect/runs/{id}', [ProspectController::class, 'run']);
    Route::get('/prospect/{id}/pdf', [ProspectController::class, 'pdfView']);
    Route::put('/prospect/{id}', [ProspectController::class, 'update'])->middleware('canwrite:opportunities');
    Route::post('/prospect/{id}/send', [ProspectController::class, 'sendProposal'])->middleware(['canwrite:opportunities', 'throttle:30,60']);
    Route::post('/prospect/{id}/reply', [ProspectController::class, 'reply'])->middleware('canwrite:opportunities');
    Route::post('/prospect/{id}/optout', [ProspectController::class, 'optout'])->middleware('canwrite:opportunities');

    // projectos
    Route::get('/projects', [OpsController::class, 'projects']);
    Route::post('/projects', [OpsController::class, 'storeProject'])->middleware('canwrite:projects');
    Route::put('/projects/{id}', [OpsController::class, 'updateProject'])->middleware('canwrite:projects');
    Route::delete('/projects/{id}', [OpsController::class, 'destroyProject'])->middleware('canwrite:projects');

    // pessoas / operações / universidade
    Route::get('/employees', [OpsController::class, 'employees']);
    Route::post('/employees', [OpsController::class, 'storeEmployee'])->middleware('canwrite:employees');
    Route::put('/employees/{id}', [OpsController::class, 'updateEmployee'])->middleware('canwrite:employees');
    Route::delete('/employees/{id}', [OpsController::class, 'destroyEmployee'])->middleware('canwrite:employees');
    Route::get('/processes', [OpsController::class, 'processes']);
    Route::post('/processes', [OpsController::class, 'storeProcess'])->middleware('canwrite:processes');
    Route::post('/processes/{id}/improve', [OpsController::class, 'improve'])->middleware('canwrite:processes');
    Route::get('/process-actions', [OpsController::class, 'processActions']);
    Route::post('/process-actions/{id}/done', [OpsController::class, 'actionDone'])->middleware('canwrite:processes');
    Route::get('/courses', [OpsController::class, 'courses']);
    Route::post('/courses', [OpsController::class, 'storeCourse'])->middleware('canwrite:courses');
    Route::post('/courses/{id}/enroll', [OpsController::class, 'enroll'])->middleware('canwrite:courses');

    // financeiro
    Route::get('/invoices', [FinanceController::class, 'invoices']);
    Route::post('/invoices', [FinanceController::class, 'storeInvoice'])->middleware('canwrite:billing');
    Route::post('/invoices/{id}/pay', [FinanceController::class, 'payLegacy'])->middleware('canwrite:billing');
    Route::post('/invoices/{id}/notify', [FinanceController::class, 'notifyLegacy'])->middleware('canwrite:invoices');
    Route::post('/invoices/{id}/receipts', [FinanceController::class, 'receipt'])->middleware('canwrite:billing');
    Route::get('/invoices/{id}/receipts', [FinanceController::class, 'invoiceReceipts']);
    Route::post('/invoices/{id}/cancel', [FinanceController::class, 'cancel'])->middleware('canwrite:billing');
    Route::get('/receipts', [FinanceController::class, 'receipts']);
    Route::get('/movements', [FinanceController::class, 'movements']);
    Route::post('/movements', [FinanceController::class, 'storeMovement'])->middleware('canwrite:movements');
    Route::get('/suppliers', [FinanceController::class, 'suppliers']);
    Route::post('/suppliers', [FinanceController::class, 'storeSupplier'])->middleware('canwrite:billing');
    Route::put('/suppliers/{id}', [FinanceController::class, 'updateSupplier'])->middleware('canwrite:billing');
    Route::delete('/suppliers/{id}', [FinanceController::class, 'destroySupplier'])->middleware('canwrite:billing');
    Route::get('/expenses', [FinanceController::class, 'expenses']);
    Route::post('/expenses', [FinanceController::class, 'storeExpense'])->middleware('canwrite:billing');
    Route::patch('/expenses/{id}/approve', [FinanceController::class, 'approveExpense'])->middleware('canwrite:approveExpense');
    Route::patch('/expenses/{id}/reject', [FinanceController::class, 'rejectExpense'])->middleware('canwrite:approveExpense');
    Route::patch('/expenses/{id}/pay', [FinanceController::class, 'payExpense'])->middleware('canwrite:billing');
    Route::delete('/expenses/{id}', [FinanceController::class, 'destroyExpense'])->middleware('canwrite:billing');

    // contabilidade
    Route::prefix('accounting')->group(function () {
        Route::get('/accounts', [FinanceController::class, 'accounts']);
        Route::post('/accounts', [FinanceController::class, 'storeAccount'])->middleware('canwrite:accounting');
        Route::put('/accounts/{id}', [FinanceController::class, 'updateAccount'])->middleware('canwrite:accounting');
        Route::delete('/accounts/{id}', [FinanceController::class, 'destroyAccount'])->middleware('canwrite:accounting');
        Route::get('/entries', [FinanceController::class, 'entries']);
        Route::get('/entries/{id}', [FinanceController::class, 'entry']);
        Route::post('/entries', [FinanceController::class, 'storeEntry'])->middleware('canwrite:accounting');
        Route::post('/entries/{id}/reverse', [FinanceController::class, 'reverseEntry'])->middleware('canwrite:accounting');
        Route::get('/ledger', [FinanceController::class, 'ledger']);
        Route::get('/balancete', [FinanceController::class, 'balancete']);
        Route::get('/dre', [FinanceController::class, 'dre']);
        Route::get('/balanco', [FinanceController::class, 'balanco']);
        Route::get('/summary', [FinanceController::class, 'summary']);
        Route::get('/taxes', [FinanceController::class, 'taxes']);
        Route::put('/tax-settings', [FinanceController::class, 'taxSettings'])->middleware('canwrite:accounting');
    });

    // documentos de facturação
    Route::get('/documents', [DocumentController::class, 'index']);
    Route::post('/documents', [DocumentController::class, 'store'])->middleware('canwrite:billing');
    Route::post('/documents/bulk', [DocumentController::class, 'bulk'])->middleware('canwrite:billing');
    Route::get('/documents/{id}', [DocumentController::class, 'show']);
    Route::put('/documents/{id}', [DocumentController::class, 'update'])->middleware('canwrite:billing');
    Route::delete('/documents/{id}', [DocumentController::class, 'destroy'])->middleware('canwrite:billing');
    Route::post('/documents/{id}/emit', [DocumentController::class, 'emit'])->middleware('canwrite:billing');
    Route::post('/documents/{id}/cancel', [DocumentController::class, 'cancel'])->middleware('canwrite:billing');
    Route::post('/documents/{id}/printed', [DocumentController::class, 'printed']);
    Route::post('/documents/{id}/convert', [DocumentController::class, 'convert'])->middleware('canwrite:billing');
    Route::post('/documents/{id}/notify', [DocumentController::class, 'notify'])->middleware('canwrite:billing');
    Route::get('/documents/{id}/notifications', [DocumentController::class, 'docNotifications']);
    Route::get('/notifications', [DocumentController::class, 'notifications']);
    Route::get('/notifications/config', [DocumentController::class, 'notifyConfig']);
    Route::put('/notifications/config', [DocumentController::class, 'saveNotifyConfig'])->middleware('canwrite:billing');
    Route::post('/notifications/run', [DocumentController::class, 'runNotifications'])->middleware('canwrite:billing');
    Route::post('/notifications/test', [DocumentController::class, 'testNotify'])->middleware('canwrite:billing');
    Route::get('/contacts', [DocumentController::class, 'contacts']);
    Route::post('/contacts', [DocumentController::class, 'storeContact'])->middleware('canwrite:billing');
    Route::put('/contacts/{id}', [DocumentController::class, 'updateContact'])->middleware('canwrite:billing');
    Route::delete('/contacts/{id}', [DocumentController::class, 'destroyContact'])->middleware('canwrite:billing');
    Route::get('/items', [DocumentController::class, 'items']);
    Route::post('/items', [DocumentController::class, 'storeItem'])->middleware('canwrite:billing');
    Route::put('/items/{id}', [DocumentController::class, 'updateItem'])->middleware('canwrite:billing');
    Route::delete('/items/{id}', [DocumentController::class, 'destroyItem'])->middleware('canwrite:billing');
    Route::get('/compliance', [DocumentController::class, 'compliance']);
    Route::get('/compliance/tamper/{id}', [DocumentController::class, 'tamper']);
    Route::get('/iva', [DocumentController::class, 'iva']);
    Route::get('/late/config', [DocumentController::class, 'lateConfig']);
    Route::put('/late/config', [DocumentController::class, 'saveLateConfig'])->middleware('canwrite:billing');
    Route::get('/billing-meta', [DocumentController::class, 'meta']);

    // decisões / memória / OKR / automações / definições / tarefas / IA
    Route::get('/decisions', [StrategyController::class, 'decisions']);
    Route::post('/decisions', [StrategyController::class, 'storeDecision'])->middleware('canwrite:decisions');
    Route::post('/decisions/{id}/decide', [StrategyController::class, 'decide'])->middleware('canwrite:decisions');
    Route::get('/memory', [StrategyController::class, 'memory']);
    Route::put('/memory/{id}/result', [StrategyController::class, 'memoryResult'])->middleware('canwrite:decisions');
    Route::get('/okrs', [StrategyController::class, 'okrs']);
    Route::post('/okrs', [StrategyController::class, 'storeOkr'])->middleware('canwrite:okrs');
    Route::put('/key-results/{id}', [StrategyController::class, 'updateKr'])->middleware('canwrite:okrs');
    Route::delete('/okrs/{id}', [StrategyController::class, 'destroyOkr'])->middleware('canwrite:okrs');
    Route::get('/automations', [StrategyController::class, 'automations']);
    Route::put('/automations/{id}', [StrategyController::class, 'toggleAutomation'])->middleware('canwrite:automations');
    Route::get('/settings', [StrategyController::class, 'settings']);
    Route::put('/settings', [StrategyController::class, 'saveSettings'])->middleware('canwrite:settings');
    Route::get('/tasks', [StrategyController::class, 'tasks']);
    Route::post('/tasks', [StrategyController::class, 'storeTask'])->middleware('canwrite:tasks');
    Route::post('/ai/ask', [StrategyController::class, 'ask'])->middleware('throttle:15,1');

    // Central de Análise e Monitorização
    Route::prefix('analytics')->group(function () {
        Route::get('/overview', [AnalyticsController::class, 'overview']);
        Route::get('/breakdown', [AnalyticsController::class, 'breakdown']);
        Route::get('/alerts', [AnalyticsController::class, 'alerts']);
        Route::get('/history', [AnalyticsController::class, 'history']);
        Route::get('/insights', [AnalyticsController::class, 'insights']);
        Route::post('/insights', [AnalyticsController::class, 'generate']);
    });
    Route::prefix('monitor')->group(function () {
        Route::post('/client-error', [MonitorController::class, 'clientError']);
        Route::get('/summary', [MonitorController::class, 'summary']);
        Route::get('/activity', [MonitorController::class, 'activity']);
        Route::get('/errors', [MonitorController::class, 'errors']);
        Route::get('/daily', [MonitorController::class, 'daily']);
    });
});
