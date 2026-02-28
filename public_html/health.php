<?php

/**
 * TPIX TRADE - Health Check Endpoint
 * Developed by Xman Studio.
 *
 * Simple health check without bootstrapping Laravel
 * Use for load balancer / uptime monitoring
 *
 * Returns JSON with health status
 */
header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('X-Robots-Tag: noindex');

$health = [
    'status' => 'healthy',
    'timestamp' => date('c'),
    'app' => 'TPIX TRADE',
    'checks' => [],
];

$allHealthy = true;

// Check 1: PHP Version
$phpVersion = PHP_VERSION;
$phpHealthy = version_compare($phpVersion, '8.2.0', '>=');
$health['checks']['php'] = [
    'status' => $phpHealthy ? 'ok' : 'warning',
    'version' => $phpVersion,
    'required' => '8.2.0',
];
if (! $phpHealthy) {
    $allHealthy = false;
}

// Check 2: Storage writable
$storageWritable = is_writable(__DIR__.'/../storage');
$health['checks']['storage'] = [
    'status' => $storageWritable ? 'ok' : 'error',
    'writable' => $storageWritable,
];
if (! $storageWritable) {
    $allHealthy = false;
}

// Check 3: Cache writable
$cacheWritable = is_writable(__DIR__.'/../bootstrap/cache');
$health['checks']['cache'] = [
    'status' => $cacheWritable ? 'ok' : 'error',
    'writable' => $cacheWritable,
];
if (! $cacheWritable) {
    $allHealthy = false;
}

// Check 4: Database connection
try {
    $envFile = __DIR__.'/../.env';
    if (file_exists($envFile)) {
        $env = parse_ini_file($envFile);
        $dbConnection = $env['DB_CONNECTION'] ?? 'sqlite';

        if ($dbConnection === 'sqlite') {
            $dbPath = __DIR__.'/../database/database.sqlite';
            if (file_exists($dbPath)) {
                $pdo = new PDO('sqlite:'.$dbPath);
                $pdo->query('SELECT 1');
                $health['checks']['database'] = ['status' => 'ok', 'driver' => 'sqlite'];
            } else {
                $health['checks']['database'] = ['status' => 'warning', 'message' => 'SQLite file not found'];
            }
        } else {
            // For MySQL/PostgreSQL, just mark as unchecked (requires full Laravel)
            $health['checks']['database'] = ['status' => 'unchecked', 'driver' => $dbConnection];
        }
    }
} catch (Exception $e) {
    $health['checks']['database'] = ['status' => 'error', 'message' => 'Connection failed'];
    $allHealthy = false;
}

// Check 5: Vendor directory exists
$vendorExists = is_dir(__DIR__.'/../vendor');
$health['checks']['vendor'] = [
    'status' => $vendorExists ? 'ok' : 'error',
    'exists' => $vendorExists,
];
if (! $vendorExists) {
    $allHealthy = false;
}

// Check 6: .env file exists
$envExists = file_exists(__DIR__.'/../.env');
$health['checks']['env'] = [
    'status' => $envExists ? 'ok' : 'warning',
    'exists' => $envExists,
];

// Check 7: Version info
$versionFile = __DIR__.'/../version.json';
if (file_exists($versionFile)) {
    $versionData = json_decode(file_get_contents($versionFile), true);
    $health['version'] = $versionData['version'] ?? 'unknown';
    $health['build'] = $versionData['build'] ?? 0;
}

// Check 8: Disk space
$freeSpace = disk_free_space(__DIR__);
$totalSpace = disk_total_space(__DIR__);
$usedPercent = round((1 - $freeSpace / $totalSpace) * 100, 1);
$diskHealthy = $usedPercent < 90;
$health['checks']['disk'] = [
    'status' => $diskHealthy ? 'ok' : 'warning',
    'used_percent' => $usedPercent,
    'free_gb' => round($freeSpace / 1024 / 1024 / 1024, 2),
];
if (! $diskHealthy) {
    $allHealthy = false;
}

// Check 9: Memory
$health['checks']['memory'] = [
    'status' => 'ok',
    'limit' => ini_get('memory_limit'),
    'current_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
];

// Final status
$health['status'] = $allHealthy ? 'healthy' : 'degraded';

// Set appropriate HTTP status
http_response_code($allHealthy ? 200 : 503);

echo json_encode($health, JSON_PRETTY_PRINT);
