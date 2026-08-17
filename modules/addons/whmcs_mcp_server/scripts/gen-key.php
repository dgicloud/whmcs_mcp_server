<?php
/**
 * Gera uma nova API key do addon MCP e imprime a chave crua (uma única vez).
 * Uso: php gen-key.php <label>
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}

$label = $argv[1] ?? 'Hermes N1';
$base = '/home/developehad/public_html';

require $base . '/init.php';
require $base . '/modules/addons/whmcs_mcp_server/vendor/autoload.php';

use HadCloud\Mcp\ApiKeys;

$raw = ApiKeys::create($label);

echo "KEY_CREATED\n";
echo "label: {$label}\n";
echo "key:   {$raw}\n";
