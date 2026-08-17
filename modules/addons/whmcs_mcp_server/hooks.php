<?php

/**
 * WHMCS MCP Server — Hooks
 *
 * @package HadCloud\WhmcsMcp
 */

if (!defined('WHMCS')) {
    die('Direct Access Denied');
}

// Hook de exemplo: registra chamadas MCP no log de atividades do WHMCS.
// Descomente para habilitar:
//
// add_hook('AdminAreaFooterOutput', 1, function (array $vars) {
//     return '';
// });
