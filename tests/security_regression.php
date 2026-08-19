<?php

declare(strict_types=1);

require __DIR__ . '/../modules/addons/whmcs_mcp_server/lib/RateLimiter.php';

use HadCloud\Mcp\RateLimiter;

function assertSameValue(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . PHP_EOL);
        fwrite(STDERR, 'Expected: ' . var_export($expected, true) . PHP_EOL);
        fwrite(STDERR, 'Actual:   ' . var_export($actual, true) . PHP_EOL);
        exit(1);
    }
}

$method = new ReflectionMethod(RateLimiter::class, 'evaluateWindow');
$method->setAccessible(true);

$first = $method->invoke(null, 0, 1000, 1000, 3, 60);
assertSameValue(true, $first['allowed'], 'A primeira requisição deve ser aceita');
assertSameValue(1, $first['hits'], 'A primeira requisição deve iniciar a contagem');
assertSameValue(2, $first['remaining'], 'O saldo da janela está incorreto');

$lastAllowed = $method->invoke(null, 2, 1000, 1020, 3, 60);
assertSameValue(true, $lastAllowed['allowed'], 'A requisição no limite deve ser aceita');
assertSameValue(0, $lastAllowed['remaining'], 'O saldo no limite deve ser zero');

$blocked = $method->invoke(null, 3, 1000, 1030, 3, 60);
assertSameValue(false, $blocked['allowed'], 'A requisição acima do limite deve ser bloqueada');
assertSameValue(30, $blocked['retry_after'], 'Retry-After deve refletir o restante da janela');

$reset = $method->invoke(null, 4, 1000, 1060, 3, 60);
assertSameValue(true, $reset['allowed'], 'Uma janela expirada deve aceitar nova requisição');
assertSameValue(1, $reset['hits'], 'Uma janela expirada deve reiniciar a contagem');
assertSameValue(1060, $reset['window_started_at'], 'A nova janela deve começar no horário atual');

$application = file_get_contents(
    __DIR__ . '/../modules/addons/whmcs_mcp_server/lib/McpApplication.php'
);

if ($application === false) {
    fwrite(STDERR, "Não foi possível ler McpApplication.php\n");
    exit(1);
}

foreach (['Access-Control-Allow-Origin', 'CorsMiddleware', "'Internal error: ' ."] as $forbidden) {
    if (str_contains($application, $forbidden)) {
        fwrite(STDERR, "Controle inseguro ainda presente: {$forbidden}\n");
        exit(1);
    }
}

foreach (['RateLimiter::endpoint', 'RateLimiter::authFailure', "'message' => 'Internal error'"] as $required) {
    if (!str_contains($application, $required)) {
        fwrite(STDERR, "Controle de segurança ausente: {$required}\n");
        exit(1);
    }
}

$adminPanel = file_get_contents(
    __DIR__ . '/../modules/addons/whmcs_mcp_server/lib/AdminPanel.php'
);

if ($adminPanel === false
    || !str_contains($adminPanel, "rtrim(\$systemUrl, '/') . '/modules/addons/whmcs_mcp_server/mcp.php'")) {
    fwrite(STDERR, "A URL do endpoint não garante a barra antes de modules.\n");
    exit(1);
}

echo "security_regression: OK\n";
