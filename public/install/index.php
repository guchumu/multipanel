<?php

declare(strict_types=1);

/**
 * MultiPanel ERP - Web Installer
 *
 * Access via /install/ when .env is not configured or APP_INSTALLED=false
 */

session_start();

$root = dirname(__DIR__, 2);

require_once $root . '/vendor/autoload.php';
require_once $root . '/core/helpers.php';

use Dotenv\Dotenv;

$step = (int) ($_GET['step'] ?? 1);
$errors = [];
$success = false;

if (file_exists($root . '/.env')) {
    $dotenv = Dotenv::createImmutable($root);
    $dotenv->safeLoad();
}

if (env('APP_INSTALLED', false) || file_exists($root . '/storage/.installed')) {
    header('Location: /');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    match ($step) {
        1 => handleRequirements($errors),
        2 => handleDatabase($errors),
        3 => handleAdmin($errors, $success),
        4 => handleFinish($errors, $success),
        default => null,
    };
}

function handleRequirements(array &$errors): void
{
    global $root;

    $required = [
        'PHP 8.3+' => version_compare(PHP_VERSION, '8.3.0', '>='),
        'PDO MySQL' => extension_loaded('pdo_mysql'),
        'OpenSSL' => extension_loaded('openssl'),
        'Mbstring' => extension_loaded('mbstring'),
        'JSON' => extension_loaded('json'),
        'cURL' => extension_loaded('curl'),
        'vendor/autoload.php' => file_exists($root . '/vendor/autoload.php'),
    ];

    foreach ($required as $name => $ok) {
        if (!$ok) {
            $errors[] = "Requisito no cumplido: {$name}";
        }
    }

    if (empty($errors)) {
        header('Location: ?step=2');
        exit;
    }
}

function handleDatabase(array &$errors): void
{
    global $root;

    $host = $_POST['db_host'] ?? '127.0.0.1';
    $port = $_POST['db_port'] ?? '3306';
    $name = $_POST['db_name'] ?? 'multipanel';
    $user = $_POST['db_user'] ?? 'root';
    $pass = $_POST['db_pass'] ?? '';

    try {
        $pdo = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);

        $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("USE `{$name}`");

        $schemaFile = $root . '/database/schema.sql';
        $schema = is_file($schemaFile) ? file_get_contents($schemaFile) : false;
        if ($schema) {
            $pdo->exec($schema);
        }

        $seedFile = $root . '/database/seeds/default.sql';
        if (is_file($seedFile)) {
            $pdo->exec((string) file_get_contents($seedFile));
        }

        writeEnv([
            'DB_HOST' => $host,
            'DB_PORT' => $port,
            'DB_DATABASE' => $name,
            'DB_USERNAME' => $user,
            'DB_PASSWORD' => $pass,
            'APP_URL' => installAppUrl(),
        ]);

        $_SESSION['install_db'] = true;
        header('Location: ?step=3');
        exit;
    } catch (PDOException $e) {
        $errors[] = 'Error de base de datos: ' . $e->getMessage();
    }
}

function handleAdmin(array &$errors, bool &$success): void
{
    global $root;

    $email = $_POST['email'] ?? '';
    $username = $_POST['username'] ?? 'admin';
    $password = $_POST['password'] ?? '';
    $passwordConfirm = $_POST['password_confirmation'] ?? '';

    if (strlen($password) < 8) {
        $errors[] = 'La contraseña debe tener al menos 8 caracteres.';
        return;
    }

    if ($password !== $passwordConfirm) {
        $errors[] = 'Las contraseñas no coinciden.';
        return;
    }

    try {
        $db = Core\Database::getInstance();
        $hash = password_hash($password, PASSWORD_ARGON2ID);

        $db->insert('users', [
            'uuid' => Ramsey\Uuid\Uuid::uuid4()->toString(),
            'tenant_id' => 1,
            'role_id' => 2,
            'email' => $email,
            'username' => $username,
            'password' => $hash,
            'first_name' => 'Admin',
            'status' => 'active',
            'email_verified_at' => date('Y-m-d H:i:s'),
        ]);

        header('Location: ?step=4');
        exit;
    } catch (Throwable $e) {
        $errors[] = 'Error creando administrador: ' . $e->getMessage();
    }
}

function handleFinish(array &$errors, bool &$success): void
{
    global $root;

    appendEnv('APP_INSTALLED', 'true');
    appendEnv('APP_KEY', bin2hex(random_bytes(32)));
    appendEnv('JWT_SECRET', bin2hex(random_bytes(32)));
    file_put_contents($root . '/storage/.installed', date('c'));
    $success = true;
}

function writeEnv(array $vars): void
{
    global $root;

    $example = $root . '/.env.example';
    $env = $root . '/.env';

    $content = file_exists($example) ? file_get_contents($example) : '';

    foreach ($vars as $key => $value) {
        $content = preg_replace("/^{$key}=.*$/m", "{$key}={$value}", $content);
        if (!str_contains($content, "{$key}=")) {
            $content .= "\n{$key}={$value}";
        }
    }

    file_put_contents($env, $content);
}

function appendEnv(string $key, string $value): void
{
    global $root;

    $env = $root . '/.env';
    $content = file_get_contents($env);
    if (!str_contains($content, "{$key}=")) {
        file_put_contents($env, $content . "\n{$key}={$value}");
    }
}

function installAppUrl(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

    return $scheme . '://' . $host;
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Instalador - MultiPanel ERP</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="card shadow">
                <div class="card-header bg-primary text-white">
                    <h4 class="mb-0">MultiPanel ERP - Instalador</h4>
                </div>
                <div class="card-body p-4">
                    <div class="progress mb-4" style="height: 8px;">
                        <div class="progress-bar" style="width: <?= $step * 25 ?>%"></div>
                    </div>

                    <?php foreach ($errors as $error): ?>
                    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
                    <?php endforeach; ?>

                    <?php if ($success): ?>
                    <div class="alert alert-success">
                        <h5>¡Instalación completada!</h5>
                        <p>MultiPanel ERP está listo para usar.</p>
                        <a href="/" class="btn btn-primary">Ir al panel</a>
                    </div>
                    <?php elseif ($step === 1): ?>
                    <h5>Paso 1: Requisitos del sistema</h5>
                    <ul class="list-group mb-3">
                        <?php
                        $checks = [
                            'PHP ' . PHP_VERSION . ' (8.3+)' => version_compare(PHP_VERSION, '8.3.0', '>='),
                            'PDO MySQL' => extension_loaded('pdo_mysql'),
                            'OpenSSL' => extension_loaded('openssl'),
                            'vendor/autoload.php' => file_exists($root . '/vendor/autoload.php'),
                            'storage/ writable' => is_writable($root . '/storage'),
                        ];
                        foreach ($checks as $label => $ok): ?>
                        <li class="list-group-item d-flex justify-content-between">
                            <?= htmlspecialchars($label) ?>
                            <span class="badge bg-<?= $ok ? 'success' : 'danger' ?>"><?= $ok ? 'OK' : 'FAIL' ?></span>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <form method="POST"><button class="btn btn-primary">Continuar</button></form>

                    <?php elseif ($step === 2): ?>
                    <h5>Paso 2: Base de datos</h5>
                    <form method="POST">
                        <div class="mb-3"><label class="form-label">Host</label><input name="db_host" class="form-control" value="127.0.0.1"></div>
                        <div class="mb-3"><label class="form-label">Puerto</label><input name="db_port" class="form-control" value="3306"></div>
                        <div class="mb-3"><label class="form-label">Base de datos</label><input name="db_name" class="form-control" value="multipanel"></div>
                        <div class="mb-3"><label class="form-label">Usuario</label><input name="db_user" class="form-control" value="multipanel"></div>
                        <div class="mb-3"><label class="form-label">Contraseña</label><input name="db_pass" type="password" class="form-control"></div>
                        <button class="btn btn-primary">Instalar base de datos</button>
                    </form>

                    <?php elseif ($step === 3): ?>
                    <h5>Paso 3: Administrador</h5>
                    <form method="POST">
                        <div class="mb-3"><label class="form-label">Email</label><input name="email" type="email" class="form-control" required></div>
                        <div class="mb-3"><label class="form-label">Username</label><input name="username" class="form-control" value="admin" required></div>
                        <div class="mb-3"><label class="form-label">Contraseña</label><input name="password" type="password" class="form-control" required minlength="8"></div>
                        <div class="mb-3"><label class="form-label">Confirmar contraseña</label><input name="password_confirmation" type="password" class="form-control" required></div>
                        <button class="btn btn-primary">Crear administrador</button>
                    </form>

                    <?php elseif ($step === 4): ?>
                    <h5>Paso 4: Finalizar</h5>
                    <p>Se generarán las claves de aplicación y JWT.</p>
                    <form method="POST"><button class="btn btn-success">Completar instalación</button></form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
