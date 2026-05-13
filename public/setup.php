<?php
/**
 * Nawafez — One-time setup script for shared hosting.
 * Visit: https://nwafiz.creativealphat.com/setup.php
 * DELETE THIS FILE after running successfully.
 */

// Simple security token — change this before uploading
define('SETUP_TOKEN', 'nawafez-setup-2025');

if (($_GET['token'] ?? '') !== SETUP_TOKEN) {
    http_response_code(403);
    die('403 Forbidden — pass ?token=nawafez-setup-2025');
}

$root = dirname(__DIR__);

// ─── 1. Create storage directories ───────────────────────────────────────────
$dirs = [
    'storage/framework/cache/data',
    'storage/framework/sessions',
    'storage/framework/views',
    'storage/app/public',
    'storage/logs',
    'bootstrap/cache',
];

echo "<h2>1. Creating storage directories</h2><pre>";
foreach ($dirs as $dir) {
    $path = $root . '/' . $dir;
    if (!is_dir($path)) {
        mkdir($path, 0755, true);
        echo "✅ Created: $dir\n";
    } else {
        echo "ℹ️  Exists:  $dir\n";
    }
}
echo "</pre>";

// ─── 2. Check .env ───────────────────────────────────────────────────────────
echo "<h2>2. .env status</h2><pre>";
$envPath = $root . '/.env';
if (file_exists($envPath)) {
    echo "✅ .env file exists\n";
    // Show APP_URL and DB_DATABASE only (safe)
    $env = file_get_contents($envPath);
    preg_match('/APP_URL=(.+)/', $env, $appUrl);
    preg_match('/DB_DATABASE=(.+)/', $env, $dbName);
    echo "   APP_URL:     " . trim($appUrl[1] ?? 'not set') . "\n";
    echo "   DB_DATABASE: " . trim($dbName[1] ?? 'not set') . "\n";
} else {
    echo "❌ .env file NOT found — copy .env.example to .env and fill in credentials\n";
}
echo "</pre>";

// ─── 3. Check vendor/ ────────────────────────────────────────────────────────
echo "<h2>3. Composer vendor</h2><pre>";
if (is_dir($root . '/vendor')) {
    echo "✅ vendor/ exists\n";
} else {
    echo "❌ vendor/ NOT found — run: composer install --no-dev --optimize-autoloader\n";
    echo "   On shared hosting: upload vendor/ via FTP after running composer locally\n";
}
echo "</pre>";

// ─── 4. Bootstrap Laravel and run migrations ─────────────────────────────────
echo "<h2>4. Laravel boot + migrate</h2><pre>";
if (!is_dir($root . '/vendor')) {
    echo "⚠️  Skipping — vendor/ not found\n";
} elseif (!file_exists($envPath)) {
    echo "⚠️  Skipping — .env not found\n";
} else {
    try {
        require $root . '/vendor/autoload.php';
        $app = require_once $root . '/bootstrap/app.php';

        $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);

        // Run migrations fresh (drops all tables and recreates)
        $kernel->call('migrate:fresh', ['--force' => true]);
        echo "✅ migrate:fresh: " . $kernel->output();

        // Seed (creates admin user)
        $kernel->call('db:seed', ['--force' => true]);
        echo "✅ db:seed: " . $kernel->output();

        // Cache
        $kernel->call('config:cache');
        echo "✅ config:cache\n";

        $kernel->call('route:cache');
        echo "✅ route:cache\n";

        // Storage link (may fail on some shared hosts)
        try {
            $kernel->call('storage:link');
            echo "✅ storage:link\n";
        } catch (\Throwable $e) {
            echo "⚠️  storage:link failed (manual symlink may be needed): " . $e->getMessage() . "\n";
        }

    } catch (\Throwable $e) {
        echo "❌ Error: " . $e->getMessage() . "\n";
        echo "   File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    }
}
echo "</pre>";

echo "<hr><p><strong>✅ Done. DELETE this file: <code>public/setup.php</code></strong></p>";
