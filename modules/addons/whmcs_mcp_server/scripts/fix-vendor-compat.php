<?php

/**
 * Fix de compatibilidade do vendor (pós composer install/update).
 *
 * O mcp/sdk v0.7 declara suporte a psr/container ^1.0 || ^2.0 no composer,
 * mas implementa a interface com assinaturas da v2.x (tipadas). Como o
 * WHMCS fornece psr/container 1.x (interface sem tipos), o PHP fatala.
 * Este script rebaixa as assinaturas para compatibilidade com 1.x.
 *
 * Uso: composer run post-install-cmd (automático) ou php scripts/fix-vendor-compat.php
 */

$file = __DIR__ . '/../vendor/mcp/sdk/src/Capability/Registry/Container.php';

if (!is_file($file)) {
    fwrite(STDERR, "[fix-vendor] Container.php não encontrado — nada a fazer.\n");
    exit(0);
}

$content = file_get_contents($file);

$replacements = [
    'public function get(string $id): mixed' => 'public function get($id)',
    'public function has(string $id): bool' => 'public function has($id)',
];

$changed = 0;
foreach ($replacements as $from => $to) {
    if (str_contains($content, $from)) {
        $content = str_replace($from, $to, $content);
        $changed++;
    }
}

if ($changed > 0) {
    file_put_contents($file, $content);
    echo "[fix-vendor] " . $changed . " assinatura(s) ajustada(s) em Container.php (compat psr/container 1.x).\n";
} else {
    echo "[fix-vendor] Container.php já compatível — nada a fazer.\n";
}

exit(0);
