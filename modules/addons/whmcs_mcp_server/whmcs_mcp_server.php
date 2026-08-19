<?php

/**
 * WHMCS MCP Server — Addon principal
 *
 * Expõe o WHMCS como servidor MCP (Model Context Protocol) via
 * Streamable HTTP, rodando in-process com localAPI().
 *
 * @package     HadCloud\WhmcsMcp
 * @author      HAD Cloud
 * @version     1.3.1
 */

use HadCloud\Mcp\AdminPanel;
use HadCloud\Mcp\Alerts;
use HadCloud\Mcp\ApiKeys;
use HadCloud\Mcp\Audit;
use HadCloud\Mcp\RateLimiter;
use HadCloud\Mcp\Settings;
use HadCloud\Mcp\ToolState;
use HadCloud\Mcp\Tools;

use Illuminate\Database\Capsule\Manager as Capsule;

if (!defined('WHMCS')) {
    die('Direct Access Denied');
}

// Autoload manual das classes usadas no admin (o composer autoload
// só é carregado no mcp.php para evitar conflito com o vendor do WHMCS).
require_once __DIR__ . '/lib/Settings.php';
require_once __DIR__ . '/lib/Tools.php';
require_once __DIR__ . '/lib/ToolState.php';
require_once __DIR__ . '/lib/ApiKeys.php';
require_once __DIR__ . '/lib/Audit.php';
require_once __DIR__ . '/lib/Alerts.php';
require_once __DIR__ . '/lib/RateLimiter.php';
require_once __DIR__ . '/lib/AdminPanel.php';

/**
 * Configuração do addon (metadados exibidos em Setup > Addon Modules).
 */
function whmcs_mcp_server_config(): array
{
    return [
        'name' => 'WHMCS MCP Server',
        'description' => 'Servidor MCP (Model Context Protocol) rodando dentro do próprio WHMCS. Expõe clientes, faturas, tickets e pedidos via Streamable HTTP para assistentes de IA (Claude, Cursor, etc).',
        'version' => AdminPanel::version(),
        'author' => 'HAD Cloud',
        'language' => 'english',
        'fields' => [],
    ];
}

/**
 * Ativação: cria storage, tabelas e gera a primeira API key.
 */
function whmcs_mcp_server_activate(): array
{
    $base = __DIR__;

    // Diretório de sessões (persistência do protocolo MCP)
    $sessionDir = $base . '/storage/sessions';
    if (!is_dir($sessionDir)) {
        mkdir($sessionDir, 0755, true);
    }

    // Bloqueia acesso web ao storage
    $htaccess = $base . '/storage/.htaccess';
    if (!file_exists($htaccess)) {
        file_put_contents($htaccess, "Require all denied\n");
    }

    // Estrutura de dados (tabelas + sync do catálogo)
    ToolState::table();
    ToolState::sync();
    ApiKeys::table();
    Audit::table();
    Alerts::table();
    RateLimiter::table();

    // Migra a chave única legada (v1.0.0) para a tabela de chaves
    ApiKeys::migrateLegacy();

    // Sem nenhuma chave? Gera a primeira
    if (count(ApiKeys::all()) === 0) {
        ApiKeys::create('Default');
    }

    // Tools de escrita desativadas por padrão (segurança)
    if (Settings::get('allow_write_tools') === null) {
        Settings::set('allow_write_tools', '0');
    }

    if (Settings::get('session_ttl') === null) {
        Settings::set('session_ttl', '7200');
    }

    return [
        'status' => 'success',
        'description' => 'Addon ativado. Configure o acesso em Addons > WHMCS MCP Server.',
    ];
}

/**
 * Desativação: mantém dados (chaves e sessões) para reativação.
 */
function whmcs_mcp_server_deactivate(): array
{
    return [
        'status' => 'success',
        'description' => 'Addon desativado. Os dados foram preservados.',
    ];
}

/**
 * Upgrade: migra instalações antigas (v1.0.0 → v1.1.0 → ...).
 */
function whmcs_mcp_server_upgrade(array $vars): void
{
    ToolState::table();
    ToolState::sync();
    ApiKeys::table();
    ApiKeys::migrateLegacy();
    Audit::table();
    Alerts::table();
    RateLimiter::table();

    if (Settings::get('session_ttl') === null) {
        Settings::set('session_ttl', '7200');
    }

    // v1.3.0: defaults de alerta + coluna notified na auditoria
    foreach ([
        'alerts_enabled' => '0',
        'alerts_on_error' => '1',
        'alerts_on_auth_fail' => '1',
        'alerts_email_enabled' => '1',
        'alerts_email_to' => '',
        'alerts_telegram_enabled' => '0',
        'alerts_telegram_token' => '',
        'alerts_telegram_chat' => '',
        'alerts_telegram_api_base' => 'https://api.telegram.org',
        'alerts_rate_minutes' => '5',
    ] as $key => $default) {
        if (Settings::get($key) === null) {
            Settings::set($key, $default);
        }
    }

    if (!Capsule::schema()->hasColumn('mod_whmcs_mcp_audit', 'notified')) {
        Capsule::schema()->table('mod_whmcs_mcp_audit', function ($table): void {
            $table->tinyInteger('notified')->default(0);
        });
    }
}

/**
 * Painel administrativo do addon.
 */
function whmcs_mcp_server_output(array $vars): void
{
    // Ações via POST (forms tradicionais + AJAX)
    if (isset($_POST['action'])) {
        check_token('whmcs');

        // Export CSV: download direto (não é JSON nem redirect)
        if ($_POST['action'] === 'audit_export') {
            $rows = Audit::export([
                'tool' => (string) ($_POST['tool'] ?? ''),
                'status' => (string) ($_POST['status'] ?? ''),
            ]);

            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="whmcs-mcp-audit-' . date('Ymd-His') . '.csv"');

            $out = fopen('php://output', 'w');
            fputcsv($out, ['id', 'created_at', 'tool', 'status', 'key_label', 'ip', 'session_id', 'duration_ms', 'args', 'message']);

            foreach ($rows as $row) {
                fputcsv($out, [
                    $row['id'],
                    $row['created_at'],
                    $row['tool'],
                    $row['status'],
                    $row['key_label'],
                    $row['ip'],
                    $row['session_id'],
                    $row['duration_ms'],
                    $row['args'],
                    $row['message'],
                ]);
            }

            fclose($out);
            exit;
        }

        $isAjax = (($_POST['ajax'] ?? '') === '1')
            || (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest');

        $result = handlePostAction($_POST['action'], $_POST);

        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode($result);
            exit;
        }

        // Flash para exibição da chave crua (única vez)
        if (!empty($result['new_key'])) {
            $_SESSION['mcp_new_key'] = $result['new_key'];
        }

        $tab = isset($_POST['tab']) ? '&tab=' . urlencode((string) $_POST['tab']) : '';
        header('Location: ' . $vars['modulelink'] . $tab);
        exit;
    }

    AdminPanel::render($vars);
}

/**
 * Roteia as ações do painel.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function handlePostAction(string $action, array $input): array
{
    switch ($action) {
        case 'create_key':
            $raw = ApiKeys::create((string) ($input['label'] ?? ''));
            logActivity('WHMCS MCP Server: API key criada');
            return ['ok' => true, 'new_key' => $raw];

        case 'revoke_key':
            $id = (int) ($input['key_id'] ?? 0);
            if ($id > 0) {
                ApiKeys::revoke($id);
                logActivity('WHMCS MCP Server: API key #' . $id . ' revogada');
            }
            return ['ok' => true];

        case 'toggle_tool':
            $name = (string) ($input['name'] ?? '');
            $enabled = ($input['enabled'] ?? '') === '1';
            if ($name === '') {
                return ['ok' => false, 'error' => 'Tool inválida'];
            }
            ToolState::set($name, $enabled);
            return ['ok' => true];

        case 'toggle_group':
            $group = (string) ($input['group'] ?? '');
            if (!isset(Tools::GROUPS[$group])) {
                return ['ok' => false, 'error' => 'Grupo inválido'];
            }
            ToolState::setGroup($group, ($input['enabled'] ?? '') === '1');
            return ['ok' => true];

        case 'toggle_column':
            $column = (string) ($input['column'] ?? '');
            $enabled = ($input['enabled'] ?? '') === '1';
            $groups = [
                'core' => ['client', 'product', 'service', 'invoice'],
                'ops'  => ['order', 'ticket', 'system'],
            ][$column] ?? null;
            if ($groups === null) {
                return ['ok' => false, 'error' => 'Coluna inválida'];
            }
            foreach ($groups as $group) {
                ToolState::setGroup($group, $enabled);
            }
            return ['ok' => true];

        case 'save_settings':
            Settings::set('allow_write_tools', (($input['allow_write_tools'] ?? '') === '1') ? '1' : '0');
            Settings::set('allowed_ips', trim((string) ($input['allowed_ips'] ?? '')));

            $ttl = (int) ($input['session_ttl'] ?? 7200);
            $ttl = max(60, min(86400, $ttl));
            Settings::set('session_ttl', (string) $ttl);

            // Alertas de auditoria
            Settings::set('alerts_enabled', (($input['alerts_enabled'] ?? '') === '1') ? '1' : '0');
            Settings::set('alerts_on_error', (($input['alerts_on_error'] ?? '') === '1') ? '1' : '0');
            Settings::set('alerts_on_auth_fail', (($input['alerts_on_auth_fail'] ?? '') === '1') ? '1' : '0');
            Settings::set('alerts_email_enabled', (($input['alerts_email_enabled'] ?? '') === '1') ? '1' : '0');
            Settings::set('alerts_email_to', trim((string) ($input['alerts_email_to'] ?? '')));
            Settings::set('alerts_telegram_enabled', (($input['alerts_telegram_enabled'] ?? '') === '1') ? '1' : '0');
            Settings::set('alerts_telegram_token', trim((string) ($input['alerts_telegram_token'] ?? '')));
            Settings::set('alerts_telegram_chat', trim((string) ($input['alerts_telegram_chat'] ?? '')));
            Settings::set('alerts_telegram_api_base', 'https://api.telegram.org'); // fixo no painel
            $rate = max(1, min(1440, (int) ($input['alerts_rate_minutes'] ?? 5)));
            Settings::set('alerts_rate_minutes', (string) $rate);

            logActivity('WHMCS MCP Server: configurações atualizadas');
            return ['ok' => true];

        case 'audit_purge':
            $daysRaw = trim((string) ($input['days'] ?? ''));

            if ($daysRaw === '') {
                $removed = Audit::purge(null);
                logActivity('WHMCS MCP Server: auditoria apagada por completo (' . $removed . ' registros)');
            } else {
                $days = max(1, (int) $daysRaw);
                $removed = Audit::purge($days);
                logActivity('WHMCS MCP Server: auditoria apagada (> ' . $days . ' dias, ' . $removed . ' registros)');
            }

            return ['ok' => true, 'removed' => $removed];

        default:
            return ['ok' => false, 'error' => 'Ação desconhecida'];
    }
}
