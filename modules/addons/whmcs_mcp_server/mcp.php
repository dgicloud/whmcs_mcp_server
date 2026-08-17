<?php

/**
 * WHMCS MCP Server — Endpoint MCP (Streamable HTTP)
 *
 * URL: /modules/addons/whmcs_mcp_server/mcp.php
 * Auth: Authorization: Bearer <api_key>
 *
 * @package HadCloud\WhmcsMcp
 */

// Carrega o WHMCS (localAPI, Capsule, Setting) PRIMEIRO: o WHMCS usa
// psr/container 1.x globalmente; o SDK foi ajustado (scripts/fix-vendor-compat.php)
// para ser compatível com essa versão, então a ordem não conflita.
$initCandidates = [
    __DIR__ . '/../../../init.php',     // via docroot padrão
    __DIR__ . '/../../init.php',        // fallback
];

$initLoaded = false;
foreach ($initCandidates as $candidate) {
    if (file_exists($candidate)) {
        require $candidate;
        $initLoaded = true;
        break;
    }
}

if (!$initLoaded) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'init.php do WHMCS não encontrado']);
    exit;
}

// Autoload do addon (SDK mcp/sdk + classes HadCloud\Mcp)
require __DIR__ . '/vendor/autoload.php';

use HadCloud\Mcp\McpApplication;

$app = new McpApplication();
$app->handle();
