<?php

declare(strict_types=1);

use App\Controllers\AuthController;
use App\Controllers\DashboardController;
use App\Controllers\ServerController;
use App\Controllers\MediaUserController;
use App\Controllers\AutomationController;
use App\Controllers\LogController;
use App\Controllers\SettingsController;
use App\Controllers\BillingController;
use App\Controllers\StatsController;
use App\Controllers\TicketController;
use App\Controllers\IntegrationController;
use App\Controllers\UpdaterController;
use App\Controllers\PluginController;
use App\Controllers\ImportController;
use App\Controllers\TenantController;
use App\Controllers\StreamController;
use App\Controllers\DiagnosticsController;
use App\Controllers\BackupController;
use App\Controllers\DocsController;
use App\Controllers\OAuthController;
use App\Controllers\CustomerController;
use App\Controllers\WebhookController;
use App\Controllers\PrivacyController;
use App\Controllers\LocaleController;
use App\Controllers\MetricsController;
use App\Controllers\RoleController;
use App\Controllers\ApiKeyController;
use App\Controllers\InvoiceController;
use App\Controllers\SecurityController;
use App\Controllers\Portal\PortalController;
use App\Controllers\Portal\PortalTicketController;
use App\Controllers\Portal\PortalPaymentController;
use App\Middleware\AuthMiddleware;
use App\Middleware\PortalAuthMiddleware;
use App\Middleware\CsrfMiddleware;
use Core\Application;

$router = Application::getInstance()->router();

// Public routes
$router->get('/', fn () => redirect('/dashboard'));
$router->get('/login', [AuthController::class, 'showLogin'], 'login');
$router->post('/login', [AuthController::class, 'login'], null, [CsrfMiddleware::class]);
$router->get('/auth/oauth/{provider}', [OAuthController::class, 'redirect'], 'oauth.redirect');
$router->get('/auth/oauth/{provider}/callback', [OAuthController::class, 'callback'], 'oauth.callback');
$router->get('/locale/{locale}', [LocaleController::class, 'switch'], 'locale.switch');
$router->get('/metrics', [MetricsController::class, 'index'], 'metrics');
$router->post('/logout', [AuthController::class, 'logout'], 'logout', [AuthMiddleware::class, CsrfMiddleware::class]);

// API docs (public)
$router->get('/api/docs', [DocsController::class, 'swagger'], 'docs.swagger');
$router->get('/api/docs/openapi.json', [DocsController::class, 'openapi'], 'docs.openapi');

// Payment webhooks (public)
$router->post('/webhooks/payment/{gateway}', [PortalPaymentController::class, 'webhook']);

// Portal (client self-service)
$router->get('/portal/login', [PortalController::class, 'showLogin'], 'portal.login');
$router->post('/portal/login', [PortalController::class, 'login'], null, [CsrfMiddleware::class]);
$router->group(['prefix' => '/portal', 'middleware' => [PortalAuthMiddleware::class]], function ($router) {
    $router->get('', [PortalController::class, 'dashboard'], 'portal.dashboard');
    $router->post('/logout', [PortalController::class, 'logout'], 'portal.logout', [CsrfMiddleware::class]);
    $router->get('/subscription', [PortalController::class, 'subscription'], 'portal.subscription');
    $router->get('/profile', [PortalController::class, 'profile'], 'portal.profile');
    $router->post('/profile', [PortalController::class, 'updateProfile'], 'portal.profile.update', [CsrfMiddleware::class]);
    $router->post('/payment/checkout', [PortalPaymentController::class, 'checkout'], 'portal.payment.checkout', [CsrfMiddleware::class]);
    $router->get('/payment/success', [PortalPaymentController::class, 'success'], 'portal.payment.success');
    // Portal tickets
    $router->get('/tickets', [PortalTicketController::class, 'index'], 'portal.tickets');
    $router->get('/tickets/create', [PortalTicketController::class, 'create'], 'portal.tickets.create');
    $router->post('/tickets', [PortalTicketController::class, 'store'], 'portal.tickets.store', [CsrfMiddleware::class]);
    $router->get('/tickets/{uuid}', [PortalTicketController::class, 'show'], 'portal.tickets.show');
});

// Admin protected routes
$router->group(['middleware' => [AuthMiddleware::class]], function ($router) {
    $router->get('/dashboard', [DashboardController::class, 'index'], 'dashboard');

    // Servers
    $router->get('/servers', [ServerController::class, 'index'], 'servers.index');
    $router->get('/servers/create', [ServerController::class, 'create'], 'servers.create');
    $router->post('/servers', [ServerController::class, 'store'], 'servers.store', [CsrfMiddleware::class]);
    $router->get('/servers/{uuid}', [ServerController::class, 'show'], 'servers.show');
    $router->post('/servers/{uuid}/sync', [ServerController::class, 'sync'], 'servers.sync', [CsrfMiddleware::class]);
    $router->post('/servers/{uuid}/test', [ServerController::class, 'test'], 'servers.test', [CsrfMiddleware::class]);
    $router->delete('/servers/{uuid}', [ServerController::class, 'destroy'], 'servers.destroy', [CsrfMiddleware::class]);

    // Media Users
    $router->get('/media-users', [MediaUserController::class, 'index'], 'media_users.index');
    $router->get('/media-users/create', [MediaUserController::class, 'create'], 'media_users.create');
    $router->post('/media-users', [MediaUserController::class, 'store'], 'media_users.store', [CsrfMiddleware::class]);
    $router->post('/media-users/{uuid}/suspend', [MediaUserController::class, 'suspend'], 'media_users.suspend', [CsrfMiddleware::class]);
    $router->post('/media-users/{uuid}/activate', [MediaUserController::class, 'activate'], 'media_users.activate', [CsrfMiddleware::class]);
    $router->delete('/media-users/{uuid}', [MediaUserController::class, 'destroy'], 'media_users.destroy', [CsrfMiddleware::class]);

    // Stats
    $router->get('/stats', [StatsController::class, 'index'], 'stats.index');
    $router->get('/stats/api', [StatsController::class, 'api'], 'stats.api');
    $router->get('/stats/export', [StatsController::class, 'export'], 'stats.export');

    // Tickets
    $router->get('/tickets', [TicketController::class, 'index'], 'tickets.index');
    $router->post('/tickets', [TicketController::class, 'store'], 'tickets.store', [CsrfMiddleware::class]);
    $router->get('/tickets/{uuid}', [TicketController::class, 'show'], 'tickets.show');
    $router->post('/tickets/{uuid}/reply', [TicketController::class, 'reply'], 'tickets.reply', [CsrfMiddleware::class]);
    $router->post('/tickets/{uuid}/close', [TicketController::class, 'close'], 'tickets.close', [CsrfMiddleware::class]);

    // Integrations
    $router->get('/integrations', [IntegrationController::class, 'index'], 'integrations.index');
    $router->post('/integrations', [IntegrationController::class, 'store'], 'integrations.store', [CsrfMiddleware::class]);
    $router->post('/integrations/{id}/test', [IntegrationController::class, 'test'], 'integrations.test', [CsrfMiddleware::class]);
    $router->get('/integrations/{id}/stats', [IntegrationController::class, 'stats'], 'integrations.stats');
    $router->delete('/integrations/{id}', [IntegrationController::class, 'destroy'], 'integrations.destroy', [CsrfMiddleware::class]);

    // Automation
    $router->get('/automation', [AutomationController::class, 'index'], 'automation.index');
    $router->get('/automation/create', [AutomationController::class, 'create'], 'automation.create');
    $router->post('/automation', [AutomationController::class, 'store'], 'automation.store', [CsrfMiddleware::class]);
    $router->post('/automation/run', [AutomationController::class, 'run'], 'automation.run', [CsrfMiddleware::class]);
    $router->post('/automation/{id}/toggle', [AutomationController::class, 'toggle'], 'automation.toggle', [CsrfMiddleware::class]);
    $router->delete('/automation/{id}', [AutomationController::class, 'destroy'], 'automation.destroy', [CsrfMiddleware::class]);

    // Logs
    $router->get('/logs', [LogController::class, 'index'], 'logs.index');
    $router->get('/logs/export', [LogController::class, 'export'], 'logs.export');

    // Settings
    $router->get('/settings', [SettingsController::class, 'index'], 'settings.index');
    $router->post('/settings', [SettingsController::class, 'update'], 'settings.update', [CsrfMiddleware::class]);
    $router->post('/settings/2fa/enable', [SettingsController::class, 'enable2fa'], 'settings.2fa.enable', [CsrfMiddleware::class]);
    $router->post('/settings/2fa/confirm', [SettingsController::class, 'confirm2fa'], 'settings.2fa.confirm', [CsrfMiddleware::class]);

    // Billing
    $router->get('/billing', [BillingController::class, 'index'], 'billing.index');
    $router->post('/billing/plans', [BillingController::class, 'createPlan'], 'billing.plans.store', [CsrfMiddleware::class]);
    $router->post('/billing/subscriptions/{id}/pay', [BillingController::class, 'markPaid'], 'billing.pay', [CsrfMiddleware::class]);

    // CRM Customers
    $router->get('/customers', [CustomerController::class, 'index'], 'customers.index');
    $router->get('/customers/create', [CustomerController::class, 'create'], 'customers.create');
    $router->post('/customers', [CustomerController::class, 'store'], 'customers.store', [CsrfMiddleware::class]);
    $router->get('/customers/{uuid}', [CustomerController::class, 'show'], 'customers.show');
    $router->put('/customers/{uuid}', [CustomerController::class, 'update'], 'customers.update', [CsrfMiddleware::class]);

    // Webhooks
    $router->get('/webhooks', [WebhookController::class, 'index'], 'webhooks.index');
    $router->post('/webhooks', [WebhookController::class, 'store'], 'webhooks.store', [CsrfMiddleware::class]);
    $router->post('/webhooks/{id}/test', [WebhookController::class, 'test'], 'webhooks.test', [CsrfMiddleware::class]);
    $router->delete('/webhooks/{id}', [WebhookController::class, 'destroy'], 'webhooks.destroy', [CsrfMiddleware::class]);

    // Privacy / GDPR
    $router->get('/privacy', [PrivacyController::class, 'index'], 'privacy.index');
    $router->post('/privacy/export', [PrivacyController::class, 'export'], 'privacy.export', [CsrfMiddleware::class]);
    $router->post('/privacy/delete', [PrivacyController::class, 'delete'], 'privacy.delete', [CsrfMiddleware::class]);
    $router->get('/privacy/{id}/download', [PrivacyController::class, 'download'], 'privacy.download');

    // Invoices
    $router->get('/invoices', [InvoiceController::class, 'index'], 'invoices.index');
    $router->get('/invoices/{id}', [InvoiceController::class, 'show'], 'invoices.show');
    $router->get('/invoices/{id}/download', [InvoiceController::class, 'download'], 'invoices.download');

    // Roles & API Keys
    $router->get('/roles', [RoleController::class, 'index'], 'roles.index');
    $router->get('/roles/{id}/edit', [RoleController::class, 'edit'], 'roles.edit');
    $router->put('/roles/{id}', [RoleController::class, 'update'], 'roles.update', [CsrfMiddleware::class]);
    $router->get('/api-keys', [ApiKeyController::class, 'index'], 'api_keys.index');
    $router->post('/api-keys', [ApiKeyController::class, 'store'], 'api_keys.store', [CsrfMiddleware::class]);
    $router->delete('/api-keys/{id}', [ApiKeyController::class, 'destroy'], 'api_keys.destroy', [CsrfMiddleware::class]);

    // Security
    $router->get('/security', [SecurityController::class, 'index'], 'security.index');
    $router->post('/security/block', [SecurityController::class, 'block'], 'security.block', [CsrfMiddleware::class]);
    $router->post('/security/{id}/unblock', [SecurityController::class, 'unblock'], 'security.unblock', [CsrfMiddleware::class]);

    // Updater
    $router->get('/updater', [UpdaterController::class, 'index'], 'updater.index');
    $router->post('/updater/run', [UpdaterController::class, 'run'], 'updater.run', [CsrfMiddleware::class]);

    // Plugins
    $router->get('/plugins', [PluginController::class, 'index'], 'plugins.index');
    $router->post('/plugins/{slug}/install', [PluginController::class, 'install'], 'plugins.install', [CsrfMiddleware::class]);
    $router->post('/plugins/{slug}/deactivate', [PluginController::class, 'deactivate'], 'plugins.deactivate', [CsrfMiddleware::class]);

    // Import/Export
    $router->get('/import', [ImportController::class, 'show'], 'import.index');
    $router->post('/import', [ImportController::class, 'upload'], 'import.upload', [CsrfMiddleware::class]);
    $router->get('/import/template', [ImportController::class, 'template'], 'import.template');

    // Multi-tenant
    $router->get('/tenants', [TenantController::class, 'index'], 'tenants.index');
    $router->post('/tenants/{id}/switch', [TenantController::class, 'switch'], 'tenants.switch', [CsrfMiddleware::class]);

    // Real-time SSE
    $router->get('/stream/events', [StreamController::class, 'events'], 'stream.events');

    // Backups
    $router->get('/backups', [BackupController::class, 'index'], 'backups.index');
    $router->post('/backups', [BackupController::class, 'create'], 'backups.create', [CsrfMiddleware::class]);
    $router->post('/backups/incremental', [BackupController::class, 'incremental'], 'backups.incremental', [CsrfMiddleware::class]);
    $router->get('/backups/{id}/download', [BackupController::class, 'download'], 'backups.download');
    $router->delete('/backups/{id}', [BackupController::class, 'destroy'], 'backups.destroy', [CsrfMiddleware::class]);

    // Diagnostics & License
    $router->get('/diagnostics', [DiagnosticsController::class, 'index'], 'diagnostics.index');
    $router->get('/diagnostics/run', [DiagnosticsController::class, 'run'], 'diagnostics.run');
    $router->post('/diagnostics/license', [DiagnosticsController::class, 'license'], 'diagnostics.license', [CsrfMiddleware::class]);
});
