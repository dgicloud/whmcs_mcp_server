<?php

declare(strict_types=1);

$autoload = getenv('TEST_VENDOR_AUTOLOAD');
if ($autoload === false || !is_file($autoload)) {
    fwrite(STDERR, "Defina TEST_VENDOR_AUTOLOAD para o autoload com illuminate/database.\n");
    exit(2);
}

require $autoload;
require __DIR__ . '/../modules/addons/whmcs_mcp_server/lib/RateLimiter.php';

use HadCloud\Mcp\RateLimiter;
use Illuminate\Database\Capsule\Manager as Capsule;

$capsule = new Capsule();
$capsule->addConnection([
    'driver' => 'sqlite',
    'database' => ':memory:',
    'prefix' => '',
]);
$capsule->setAsGlobal();

RateLimiter::table();

for ($request = 1; $request <= 300; $request++) {
    $result = RateLimiter::endpoint('203.0.113.10');
    if (!$result['allowed']) {
        fwrite(STDERR, "Limite geral bloqueou antes da requisição 301.\n");
        exit(1);
    }
}

$blockedEndpoint = RateLimiter::endpoint('203.0.113.10');
if ($blockedEndpoint['allowed'] || $blockedEndpoint['retry_after'] < 1) {
    fwrite(STDERR, "Limite geral não bloqueou a requisição 301.\n");
    exit(1);
}

for ($failure = 1; $failure <= 20; $failure++) {
    $result = RateLimiter::authFailure('203.0.113.20');
    if (!$result['allowed']) {
        fwrite(STDERR, "Limite de autenticação bloqueou antes da falha 21.\n");
        exit(1);
    }
}

$blockedAuth = RateLimiter::authFailure('203.0.113.20');
if ($blockedAuth['allowed'] || $blockedAuth['retry_after'] < 1) {
    fwrite(STDERR, "Limite de autenticação não bloqueou a falha 21.\n");
    exit(1);
}

$otherIp = RateLimiter::authFailure('203.0.113.21');
if (!$otherIp['allowed']) {
    fwrite(STDERR, "Um IP não deve consumir o limite de outro IP.\n");
    exit(1);
}

echo "rate_limiter_integration: OK\n";
