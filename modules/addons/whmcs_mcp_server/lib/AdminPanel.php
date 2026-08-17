<?php

/**
 * WHMCS MCP Server — Painel administrativo (UI)
 *
 * Três abas no estilo do painel WHMCS:
 *  - API Keys:      gestão de chaves de acesso (criar, revogar, status)
 *  - Tool Management: habilita/desabilita tools individualmente ou por grupo
 *  - Settings:      preferências globais (escrita, allowlist de IP, sessão)
 *
 * @package HadCloud\WhmcsMcp
 */

namespace HadCloud\Mcp;

final class AdminPanel
{
    /** Grupos por coluna do Tool Management. */
    private const COLUMNS = [
        'core' => ['client', 'product', 'service', 'invoice'],
        'ops'  => ['order', 'ticket', 'system'],
    ];

    private const VERSION = '1.3.0';

    public static function version(): string
    {
        return self::VERSION;
    }

    public static function render(array $vars): void
    {
        $tab = $_GET['tab'] ?? 'tools';
        $tab = in_array($tab, ['api_keys', 'tools', 'audit', 'settings'], true) ? $tab : 'tools';

        $modulelink = $vars['modulelink'];

        $endpointUrl = (string) \WHMCS\Config\Setting::getValue('SystemURL')
            . 'modules/addons/whmcs_mcp_server/mcp.php';

        echo '<style>' . self::css() . '</style>';

        echo '<div class="mcp-header">'
            . '<h2>MCP Server <span class="mcp-version">v' . self::VERSION . '</span></h2>'
            . '</div>';

        // Abas
        $tabs = [
            'api_keys' => 'API Keys',
            'tools'    => 'Tool Management',
            'audit'    => 'Audit Log',
            'settings' => 'Settings',
        ];

        echo '<ul class="nav nav-tabs mcp-tabs" role="tablist">';
        foreach ($tabs as $key => $label) {
            $active = $tab === $key ? ' class="active"' : '';
            echo '<li' . $active . '><a href="' . $modulelink . '&tab=' . $key . '">' . $label . '</a></li>';
        }
        echo '</ul>';

        echo '<div class="tab-content">';
        echo '<form method="post" id="mcp-token-form">'
            . '</form>';

        // Flash: nova API key criada
        if (isset($_SESSION['mcp_new_key'])) {
            $newKey = (string) $_SESSION['mcp_new_key'];
            unset($_SESSION['mcp_new_key']);
            echo '<div class="alert alert-success mcp-newkey" style="margin-top:15px">'
                . '<strong>API key criada!</strong> Copie agora — ela não será exibida novamente.<br>'
                . '<code style="word-break:break-all;font-size:13px">' . $newKey . '</code> '
                . '<button type="button" class="btn btn-xs btn-primary mcp-copy" data-copy="' . $newKey . '">Copiar</button>'
                . '</div>';
        }

        echo '<div class="mcp-tab-body">';
        if ($tab === 'api_keys') {
            self::renderApiKeys($modulelink);
        } elseif ($tab === 'tools') {
            self::renderTools($modulelink);
        } elseif ($tab === 'audit') {
            self::renderAudit($modulelink);
        } else {
            self::renderSettings($modulelink);
        }
        echo '</div>';

        echo '<div class="mcp-endpoint">'
            . '<strong>Endpoint:</strong> <code>' . $endpointUrl . '</code>'
            . '</div>';

        echo '</div>';
        echo '<script>' . self::js() . '</script>';
    }

    // ------------------------------------------------------------------
    // Aba: API Keys
    // ------------------------------------------------------------------
    private static function renderApiKeys(string $modulelink): void
    {
        $keys = ApiKeys::all();
        $active = array_filter($keys, fn(array $k): bool => !$k['revoked']);

        echo '<div class="alert alert-info">'
            . '<span class="glyphicon glyphicon-info-sign"></span> '
            . 'MCP API Keys. Chaves usadas para autenticar assistentes de IA no endpoint '
            . '<code>mcp.php</code> via header <code>Authorization: Bearer &lt;chave&gt;</code>. '
            . 'Chaves revogadas deixam de funcionar imediatamente.'
            . '</div>';

        echo '<div class="panel panel-default" style="margin-top:15px">'
            . '<div class="panel-heading"><strong>Nova API Key</strong></div>'
            . '<div class="panel-body">'
            . '<form method="post" action="' . $modulelink . '&tab=api_keys" class="form-inline">'
            . '<input type="hidden" name="action" value="create_key">'
            . '<div class="form-group">'
            . '<input type="text" name="label" class="form-control input-sm" style="width:280px" '
            . 'placeholder="Rótulo (ex: Claude Code — dev)" maxlength="100">'
            . '</div> '
            . '<button type="submit" class="btn btn-success btn-sm">Gerar chave</button>'
            . '</form>'
            . '<p class="text-muted"><small>A chave crua é exibida uma única vez após a criação.</small></p>'
            . '</div></div>';

        echo '<div class="panel panel-default">'
            . '<div class="panel-heading">'
            . '<strong>Chaves ativas (' . count($active) . ')</strong>'
            . '</div>'
            . '<table class="table table-striped">'
            . '<thead><tr><th>Rótulo</th><th>Chave</th><th>Criada em</th><th>Último uso</th><th></th></tr></thead>'
            . '<tbody>';

        if (count($keys) === 0) {
            echo '<tr><td colspan="5" class="text-muted">Nenhuma chave criada ainda.</td></tr>';
        }

        foreach ($keys as $key) {
            $status = $key['revoked']
                ? '<span class="label label-danger">Revogada</span>'
                : '<span class="label label-success">Ativa</span>';

            $revokeBtn = $key['revoked']
                ? ''
                : '<form method="post" action="' . $modulelink . '&tab=api_keys" style="display:inline" '
                    . 'onsubmit="return confirm(\'Revogar esta chave? Assistentes conectados com ela perderão acesso.\')">'
                    . '<input type="hidden" name="action" value="revoke_key">'
                    . '<input type="hidden" name="key_id" value="' . (int) $key['id'] . '">'
                    . '<button type="submit" class="btn btn-danger btn-xs">Revogar</button>'
                    . '</form>';

            echo '<tr>'
                . '<td>' . htmlspecialchars($key['label']) . '</td>'
                . '<td><code>' . ApiKeys::mask($key['key_prefix']) . '</code></td>'
                . '<td class="text-muted">' . htmlspecialchars($key['created_at']) . '</td>'
                . '<td class="text-muted">' . htmlspecialchars($key['last_used_at'] ?: 'nunca') . '</td>'
                . '<td class="text-right">' . $status . ' ' . $revokeBtn . '</td>'
                . '</tr>';
        }

        echo '</tbody></table></div>';
    }

    // ------------------------------------------------------------------
    // Aba: Tool Management
    // ------------------------------------------------------------------
    private static function renderTools(string $modulelink): void
    {
        $states = ToolState::all();
        $counts = ToolState::counts();
        $allowWrite = Settings::get('allow_write_tools') === '1';

        echo '<div class="alert alert-info">'
            . '<span class="glyphicon glyphicon-info-sign"></span> '
            . 'MCP Tool Management. Habilite ou desabilite tools individuais do servidor MCP. '
            . 'Tools desabilitadas são rejeitadas pela API e removidas do catálogo de capacidades '
            . '(tools/list). Use isto para restringir quais operações os assistentes de IA podem executar.'
            . '</div>';

        // Resumo de status
        echo '<p class="mcp-status-summary">'
            . '<span class="mcp-status-enabled">' . $counts['enabled'] . ' Enabled</span> '
            . '<span class="mcp-status-disabled">' . $counts['disabled'] . ' Disabled</span> '
            . '<span class="mcp-status-total">' . $counts['total'] . ' Total</span>'
            . '</p>';

        echo '<div class="row">';

        foreach (self::COLUMNS as $columnKey => $groups) {
            $columnName = $columnKey === 'core' ? 'WHMCS MCP' : 'Operations';
            $columnTools = [];
            foreach ($groups as $g) {
                foreach (Tools::byGroup()[$g] ?? [] as $def) {
                    $columnTools[] = $def;
                }
            }

            $enabledInColumn = count(array_filter(
                $columnTools,
                fn(array $def): bool => !empty($states[$def['name']])
            ));
            $totalInColumn = count($columnTools);

            echo '<div class="col-md-6">';
            echo '<div class="mcp-column">';

            // Cabeçalho da coluna (master toggle)
            echo '<div class="mcp-column-header" data-column="' . $columnKey . '">'
                . '<span class="mcp-column-title">' . $columnName . '</span>'
                . '<label class="mcp-switch">'
                . '<input type="checkbox" class="mcp-toggle-column" data-column="' . $columnKey . '" '
                . ($enabledInColumn === $totalInColumn ? 'checked' : '')
                . ($enabledInColumn > 0 && $enabledInColumn < $totalInColumn ? ' data-indeterminate="1"' : '')
                . '>'
                . '<span class="mcp-slider"></span>'
                . '</label>'
                . '<span class="mcp-column-count">' . $enabledInColumn . '/' . $totalInColumn . ' enabled</span>'
                . '</div>';

            foreach ($groups as $groupKey) {
                $groupLabel = Tools::GROUPS[$groupKey] ?? $groupKey;
                $groupDefs = Tools::byGroup()[$groupKey] ?? [];

                $enabledInGroup = count(array_filter(
                    $groupDefs,
                    fn(array $def): bool => !empty($states[$def['name']])
                ));
                $totalInGroup = count($groupDefs);

                echo '<div class="mcp-group" data-group="' . $groupKey . '">';
                echo '<div class="mcp-group-header" data-group="' . $groupKey . '">'
                    . '<span class="mcp-caret glyphicon glyphicon-chevron-down"></span>'
                    . '<span class="mcp-group-title">' . $groupLabel . '</span>'
                    . '<label class="mcp-switch mcp-switch-sm" onclick="event.stopPropagation()">'
                    . '<input type="checkbox" class="mcp-toggle-group" data-group="' . $groupKey . '" '
                    . ($enabledInGroup === $totalInGroup ? 'checked' : '')
                    . ($enabledInGroup > 0 && $enabledInGroup < $totalInGroup ? ' data-indeterminate="1"' : '')
                    . '>'
                    . '<span class="mcp-slider"></span>'
                    . '</label>'
                    . '<span class="mcp-group-count">' . $enabledInGroup . '/' . $totalInGroup . ' enabled</span>'
                    . '</div>';

                echo '<div class="mcp-group-body">';

                foreach ($groupDefs as $def) {
                    $isWrite = !empty($def['write']);
                    $enabled = !empty($states[$def['name']]);

                    // Escrita global desligada: checkbox travado (tool nunca exposta)
                    $locked = $isWrite && !$allowWrite;
                    $lockIcon = $locked
                        ? ' <span class="glyphicon glyphicon-lock mcp-lock" title="Habilite \'Tools de escrita\' em Settings para liberar"></span>'
                        : '';

                    $badge = $isWrite
                        ? ' <span class="label label-warning mcp-write-badge">escrita</span>'
                        : '';

                    echo '<label class="mcp-tool' . ($locked ? ' mcp-tool-locked' : '') . '">'
                        . '<input type="checkbox" class="mcp-toggle-tool" data-name="' . $def['name'] . '" '
                        . ($enabled ? 'checked' : '')
                        . ($locked ? ' disabled' : '')
                        . '>'
                        . '<span class="mcp-tool-name">' . htmlspecialchars($def['name']) . '</span>'
                        . '<span class="mcp-tool-label">' . htmlspecialchars($def['label']) . '</span>'
                        . $badge . $lockIcon
                        . '</label>';
                }

                echo '</div></div>'; // group-body, group
            }

            echo '</div></div>'; // column, col
        }

        echo '</div>'; // row
    }

    // ------------------------------------------------------------------
    // Aba: Audit Log
    // ------------------------------------------------------------------
    private static function renderAudit(string $modulelink): void
    {
        $stats = Audit::stats();

        $filters = [
            'tool' => (string) ($_GET['tool'] ?? ''),
            'status' => (string) ($_GET['status'] ?? ''),
            'key' => (string) ($_GET['key'] ?? ''),
            'from' => (string) ($_GET['from'] ?? ''),
            'to' => (string) ($_GET['to'] ?? ''),
        ];

        $page = max(1, (int) ($_GET['page'] ?? 1));
        $result = Audit::query($filters, $page);

        echo '<div class="alert alert-info">'
            . '<span class="glyphicon glyphicon-info-sign"></span> '
            . 'MCP Audit Log. Registra toda chamada de tool feita no endpoint '
            . '<code>mcp.php</code> (sucesso ou erro), a chave usada, o IP de origem e a duração. '
            . 'Tentativas com chave inválida também são registradas. Use os filtros para investigar '
            . 'chamadas feitas por assistentes de IA.'
            . '</div>';

        // Resumo de status
        echo '<p class="mcp-status-summary">'
            . '<span class="mcp-status-enabled">' . $stats['success'] . ' success</span> '
            . '<span class="mcp-status-disabled">' . $stats['error'] . ' errors</span> '
            . '<span class="mcp-status-authfail">' . $stats['auth_fail'] . ' auth fails</span> '
            . '<span class="mcp-status-total">' . $stats['total'] . ' total</span> '
            . '<span class="mcp-status-total">· ' . $stats['last_24h'] . ' nas últimas 24h</span>'
            . '</p>';

        // Filtros (GET)
        $qs = function (array $extra = []) use ($filters): string {
            $params = array_filter(array_merge($filters, $extra), fn($v): bool => $v !== '');
            return $modulelink . '&tab=audit' . (count($params) ? '&' . http_build_query($params) : '');
        };

        echo '<form method="get" action="' . $modulelink . '" class="form-inline mcp-audit-filters" style="margin-bottom:15px">'
            . '<input type="hidden" name="tab" value="audit">'
            . '<div class="form-group">'
            . '<input type="text" name="tool" class="form-control input-sm" placeholder="Tool (ex: GetClients)" '
            . 'value="' . htmlspecialchars($filters['tool']) . '" style="width:200px">'
            . '</div> '
            . '<div class="form-group">'
            . '<select name="status" class="form-control input-sm">'
            . '<option value="">Todos os status</option>'
            . '<option value="success"' . ($filters['status'] === 'success' ? ' selected' : '') . '>success</option>'
            . '<option value="error"' . ($filters['status'] === 'error' ? ' selected' : '') . '>error</option>'
            . '<option value="auth_fail"' . ($filters['status'] === 'auth_fail' ? ' selected' : '') . '>auth fail</option>'
            . '</select>'
            . '</div> '
            . '<div class="form-group">'
            . '<input type="text" name="key" class="form-control input-sm" placeholder="Chave (rótulo)" '
            . 'value="' . htmlspecialchars($filters['key']) . '" style="width:160px">'
            . '</div> '
            . '<div class="form-group">'
            . '<input type="date" name="from" class="form-control input-sm" value="' . htmlspecialchars($filters['from']) . '">'
            . '</div> '
            . '<div class="form-group">'
            . '<input type="date" name="to" class="form-control input-sm" value="' . htmlspecialchars($filters['to']) . '">'
            . '</div> '
            . '<button type="submit" class="btn btn-primary btn-sm">Filtrar</button> '
            . '<a href="' . $modulelink . '&tab=audit" class="btn btn-default btn-sm">Limpar</a>'
            . '</form>';

        // Ações: exportar + limpar
        echo '<div class="mcp-audit-actions" style="margin-bottom:10px">'
            . '<form method="post" action="' . $modulelink . '&tab=audit" style="display:inline">'
            . '<input type="hidden" name="action" value="audit_export">'
            . '<input type="hidden" name="tool" value="' . htmlspecialchars($filters['tool']) . '">'
            . '<input type="hidden" name="status" value="' . htmlspecialchars($filters['status']) . '">'
            . '<button type="submit" class="btn btn-default btn-xs">Exportar CSV</button>'
            . '</form> '
            . '<form method="post" action="' . $modulelink . '&tab=audit" style="display:inline" '
            . 'onsubmit="return confirm(\'Apagar registros com mais de 30 dias? Esta ação não pode ser desfeita.\')">'
            . '<input type="hidden" name="action" value="audit_purge">'
            . '<input type="hidden" name="days" value="30">'
            . '<button type="submit" class="btn btn-warning btn-xs">Limpar &gt; 30 dias</button>'
            . '</form> '
            . '<form method="post" action="' . $modulelink . '&tab=audit" style="display:inline" '
            . 'onsubmit="return confirm(\'Apagar TODOS os registros de auditoria? Esta ação não pode ser desfeita.\')">'
            . '<input type="hidden" name="action" value="audit_purge">'
            . '<input type="hidden" name="days" value="">'
            . '<button type="submit" class="btn btn-danger btn-xs">Limpar tudo</button>'
            . '</form>'
            . '</div>';

        // Tabela
        echo '<div class="panel panel-default">'
            . '<div class="panel-heading"><strong>Registros (' . $result['total'] . ')</strong></div>'
            . '<table class="table table-striped table-condensed mcp-audit-table">'
            . '<thead><tr>'
            . '<th>Data/hora</th><th>Tool</th><th>Status</th><th>Chave</th><th>IP</th>'
            . '<th>Duração</th><th>Args</th><th>Mensagem</th>'
            . '</tr></thead>'
            . '<tbody>';

        if (count($result['rows']) === 0) {
            echo '<tr><td colspan="8" class="text-muted">Nenhum registro encontrado.</td></tr>';
        }

        foreach ($result['rows'] as $row) {
            $badge = [
                'success' => '<span class="label label-success">success</span>',
                'error' => '<span class="label label-danger">error</span>',
                'auth_fail' => '<span class="label label-warning">auth fail</span>',
            ][$row['status']] ?? '<span class="label label-default">' . htmlspecialchars($row['status']) . '</span>';

            $argsShort = self::truncate($row['args'], 90);
            $messageShort = self::truncate($row['message'], 90);

            echo '<tr>'
                . '<td class="text-muted" style="white-space:nowrap">' . htmlspecialchars($row['created_at']) . '</td>'
                . '<td><code class="mcp-tool-name">' . htmlspecialchars($row['tool']) . '</code></td>'
                . '<td>' . $badge . ($row['notified'] ? ' <span class="label label-warning" title="Alerta enviado">🔔</span>' : '') . '</td>'
                . '<td>' . htmlspecialchars($row['key_label']) . '</td>'
                . '<td class="text-muted">' . htmlspecialchars($row['ip']) . '</td>'
                . '<td class="text-muted">' . $row['duration_ms'] . 'ms</td>'
                . '<td class="mcp-audit-args" title="' . htmlspecialchars($row['args']) . '">' . htmlspecialchars($argsShort) . '</td>'
                . '<td class="mcp-audit-msg" title="' . htmlspecialchars($row['message']) . '">' . htmlspecialchars($messageShort) . '</td>'
                . '</tr>';
        }

        echo '</tbody></table></div>';

        // Paginação
        if ($result['pages'] > 1) {
            echo '<nav><ul class="pagination pagination-sm">';
            for ($i = 1; $i <= $result['pages']; $i++) {
                $active = $i === $result['page'] ? ' class="active"' : '';
                echo '<li' . $active . '><a href="' . $qs(['page' => $i]) . '">' . $i . '</a></li>';
            }
            echo '</ul></nav>';
        }
    }

    /**
     * Trunca texto com reticências (para exibição em célula).
     */
    private static function truncate(string $text, int $length): string
    {
        if (mb_strlen($text) <= $length) {
            return $text;
        }

        return mb_substr($text, 0, $length - 3) . '...';
    }

    // ------------------------------------------------------------------
    // Aba: Settings
    // ------------------------------------------------------------------
    private static function renderSettings(string $modulelink): void
    {
        $allowWrite = Settings::get('allow_write_tools') === '1';
        $allowedIps = (string) Settings::get('allowed_ips', '');
        $sessionTtl = (int) Settings::get('session_ttl', 7200);

        echo '<div class="panel panel-default" style="max-width:720px">';
        echo '<div class="panel-heading"><strong>Configurações gerais</strong></div>';
        echo '<div class="panel-body">';

        echo '<form method="post" action="' . $modulelink . '&tab=settings" id="mcp-settings-form">'
            . '<input type="hidden" name="action" value="save_settings">';

        // Tools de escrita
        echo '<div class="form-group">'
            . '<label class="mcp-switch" style="margin-bottom:6px">'
            . '<input type="checkbox" name="allow_write_tools" value="1" id="mcp-write-toggle"'
            . ($allowWrite ? ' checked' : '') . '>'
            . '<span class="mcp-slider"></span>'
            . '</label> '
            . '<strong>Tools de escrita</strong>'
            . '<p class="text-muted"><small>Libera tools que alteram dados (criar/atualizar cliente, abrir ticket, '
            . 'criar fatura, aprovar/cancelar pedido). Recomendado manter desligado em produção.</small></p>'
            . '</div>';

        echo '<hr>';

        // Allowlist de IP
        echo '<div class="form-group">'
            . '<label for="mcp-allowed-ips"><strong>Allowlist de IPs</strong></label>'
            . '<p class="text-muted"><small>Somente estes IPs poderão chamar o endpoint. Vazio = qualquer IP autenticado.</small></p>'
            . '<textarea name="allowed_ips" id="mcp-allowed-ips" class="form-control" rows="3" '
            . 'placeholder="177.39.18.113, 200.198.10.5">' . htmlspecialchars($allowedIps) . '</textarea>'
            . '</div>';

        echo '<hr>';

        // TTL de sessão
        echo '<div class="form-group">'
            . '<label for="mcp-session-ttl"><strong>TTL de sessão MCP (segundos)</strong></label>'
            . '<p class="text-muted"><small>Tempo de validade das sessões do protocolo (default 7200 = 2h).</small></p>'
            . '<input type="number" name="session_ttl" id="mcp-session-ttl" class="form-control" style="max-width:200px" '
            . 'min="60" max="86400" value="' . $sessionTtl . '">'
            . '</div>';

        echo '<hr>';

        // ------------------------------------------------------------------
        // Alertas de auditoria (e-mail / Telegram)
        // ------------------------------------------------------------------
        $alertsEnabled = Settings::get('alerts_enabled') === '1';
        $alertsOnError = Settings::get('alerts_on_error') === '1';
        $alertsOnAuthFail = Settings::get('alerts_on_auth_fail') === '1';
        $alertsEmailEnabled = Settings::get('alerts_email_enabled') === '1';
        $alertsEmailTo = (string) Settings::get('alerts_email_to', '');
        $alertsTgEnabled = Settings::get('alerts_telegram_enabled') === '1';
        $alertsTgToken = (string) Settings::get('alerts_telegram_token', '');
        $alertsTgChat = (string) Settings::get('alerts_telegram_chat', '');
        $alertsRate = (int) Settings::get('alerts_rate_minutes', 5);

        echo '<div class="form-group">'
            . '<label class="mcp-switch" style="margin-bottom:6px">'
            . '<input type="checkbox" name="alerts_enabled" value="1" id="mcp-alerts-toggle"'
            . ($alertsEnabled ? ' checked' : '') . '>'
            . '<span class="mcp-slider"></span>'
            . '</label> '
            . '<strong>Alertas de auditoria</strong>'
            . '<p class="text-muted"><small>Notifica por e-mail e/ou Telegram quando a auditoria registrar '
            . 'erro em tool ou falha de autenticação.</small></p>'
            . '</div>';

        echo '<div class="form-group">'
            . '<label><strong>Notificar em</strong></label><br>'
            . '<label class="checkbox-inline"><input type="checkbox" name="alerts_on_error" value="1"'
            . ($alertsOnError ? ' checked' : '') . '> Erro de tool</label> '
            . '<label class="checkbox-inline"><input type="checkbox" name="alerts_on_auth_fail" value="1"'
            . ($alertsOnAuthFail ? ' checked' : '') . '> Falha de autenticação</label>'
            . '</div>';

        echo '<div class="form-group">'
            . '<label class="mcp-switch" style="margin-bottom:6px">'
            . '<input type="checkbox" name="alerts_email_enabled" value="1" id="mcp-alerts-email-toggle"'
            . ($alertsEmailEnabled ? ' checked' : '') . '>'
            . '<span class="mcp-slider"></span>'
            . '</label> '
            . '<strong>E-mail</strong>'
            . '<p class="text-muted"><small>Vazio no destinatário = notifica todos os administradores '
            . '(sendAdminNotification).</small></p>'
            . '<input type="email" name="alerts_email_to" class="form-control" style="max-width:360px" '
            . 'placeholder="suporte@hadcloud.com.br (opcional)" value="' . htmlspecialchars($alertsEmailTo) . '">'
            . '</div>';

        echo '<div class="form-group">'
            . '<label class="mcp-switch" style="margin-bottom:6px">'
            . '<input type="checkbox" name="alerts_telegram_enabled" value="1" id="mcp-alerts-tg-toggle"'
            . ($alertsTgEnabled ? ' checked' : '') . '>'
            . '<span class="mcp-slider"></span>'
            . '</label> '
            . '<strong>Telegram</strong>'
            . '<p class="text-muted"><small>Crie um bot com o @BotFather e informe o token e o chat_id '
            . 'de destino.</small></p>'
            . '<input type="text" name="alerts_telegram_token" class="form-control" style="max-width:360px;margin-bottom:6px" '
            . 'placeholder="123456789:AA...BotFather" value="' . htmlspecialchars($alertsTgToken) . '">'
            . '<input type="text" name="alerts_telegram_chat" class="form-control" style="max-width:360px" '
            . 'placeholder="chat_id (ex: -1001234567890)" value="' . htmlspecialchars($alertsTgChat) . '">'
            . '</div>';

        echo '<div class="form-group">'
            . '<label for="mcp-alerts-rate"><strong>Rate limit (minutos)</strong></label>'
            . '<p class="text-muted"><small>Máximo de 1 alerta por tipo a cada N minutos (evita spam '
            . 'em tentativas em massa).</small></p>'
            . '<input type="number" name="alerts_rate_minutes" id="mcp-alerts-rate" class="form-control" style="max-width:200px" '
            . 'min="1" max="1440" value="' . $alertsRate . '">'
            . '</div>';

        echo '<button type="submit" class="btn btn-primary">Salvar configurações</button>';
        echo '</form></div></div>';

        // Endpoint info
        echo '<div class="panel panel-default" style="max-width:720px">'
            . '<div class="panel-heading"><strong>Conectar</strong></div>'
            . '<div class="panel-body">'
            . '<p><strong>Claude Code:</strong></p>'
            . '<pre>claude mcp add --transport http whmcs '
            . htmlspecialchars((string) \WHMCS\Config\Setting::getValue('SystemURL'))
            . 'modules/addons/whmcs_mcp_server/mcp.php '
            . '--header "Authorization: Bearer &lt;SUA_API_KEY&gt;"</pre>'
            . '<p><strong>Claude Desktop:</strong> adicione ao <code>claude_desktop_config.json</code>:</p>'
            . '<pre>{\n  "mcpServers": {\n    "whmcs": {\n      "type": "http",\n      "url": "&lt;URL_DO_ENDPOINT&gt;",\n      "headers": { "Authorization": "Bearer &lt;SUA_API_KEY&gt;" }\n    }\n  }\n}</pre>'
            . '</div></div>';
    }

    // ------------------------------------------------------------------
    // CSS / JS
    // ------------------------------------------------------------------
    private static function css(): string
    {
        return <<<'CSS'
.mcp-header h2 { margin-top: 10px; }
.mcp-version {
    font-size: 13px; color: #fff; background: #4a90d9; border-radius: 3px;
    padding: 2px 8px; vertical-align: middle; font-weight: normal;
}
.mcp-tabs { margin-top: 15px; }
.mcp-tab-body { padding-top: 15px; }
.mcp-status-summary { margin: 12px 0 18px; font-size: 14px; }
.mcp-status-enabled { color: #2e7d32; font-weight: 600; margin-right: 18px; }
.mcp-status-disabled { color: #c62828; font-weight: 600; margin-right: 18px; }
.mcp-status-authfail { color: #e65100; font-weight: 600; margin-right: 18px; }
.mcp-status-total { color: #777; margin-right: 12px; }

.mcp-audit-table { margin-bottom: 0; }
.mcp-audit-table td { font-size: 12px; vertical-align: middle; }
.mcp-audit-table code { font-size: 12px; }
.mcp-audit-args, .mcp-audit-msg {
    max-width: 220px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
    font-family: Menlo, Consolas, monospace; color: #666; cursor: help;
}

.mcp-column {
    border: 1px solid #ddd; border-radius: 4px; background: #fff;
    margin-bottom: 20px;
}
.mcp-column-header {
    background: #2b3a55; color: #fff; padding: 10px 14px; border-radius: 4px 4px 0 0;
    display: flex; align-items: center; gap: 10px;
}
.mcp-column-title { font-weight: 600; font-size: 14px; }
.mcp-column-count { margin-left: auto; color: #b8c6dc; font-size: 12px; }

.mcp-group { border-bottom: 1px solid #eee; }
.mcp-group:last-child { border-bottom: none; }
.mcp-group-header {
    padding: 9px 14px; cursor: pointer; display: flex; align-items: center; gap: 8px;
    background: #f7f9fc; user-select: none;
}
.mcp-group-header:hover { background: #eef2f8; }
.mcp-caret { color: #999; font-size: 11px; transition: transform .15s; }
.mcp-group.open .mcp-caret { transform: rotate(180deg); }
.mcp-group-title { font-weight: 600; font-size: 13px; color: #333; }
.mcp-group-count { color: #888; font-size: 12px; }
.mcp-group-body { padding: 4px 14px 10px 32px; display: none; }
.mcp-group.open .mcp-group-body { display: block; }

.mcp-tool {
    display: flex; align-items: center; gap: 8px; padding: 5px 0;
    cursor: pointer; border-bottom: 1px dashed #f0f0f0;
}
.mcp-tool:last-child { border-bottom: none; }
.mcp-tool input[type=checkbox] { accent-color: #3b82f6; width: 16px; height: 16px; cursor: pointer; }
.mcp-tool-locked { opacity: .55; cursor: not-allowed; }
.mcp-tool-locked input[type=checkbox] { cursor: not-allowed; }
.mcp-tool-name { font-family: Menlo, Consolas, monospace; font-size: 12px; color: #1a3a6b; }
.mcp-tool-label { color: #888; font-size: 12px; }
.mcp-write-badge { font-size: 10px; margin-left: 2px; }
.mcp-lock { color: #b0a25a; font-size: 11px; margin-left: 4px; }

/* Switch (toggle) */
.mcp-switch { position: relative; display: inline-block; width: 40px; height: 20px; margin: 0; vertical-align: middle; }
.mcp-switch-sm { width: 34px; height: 17px; }
.mcp-switch input { opacity: 0; width: 0; height: 0; }
.mcp-slider {
    position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0;
    background: #ccc; border-radius: 20px; transition: .2s;
}
.mcp-slider:before {
    content: ""; position: absolute; height: 14px; width: 14px; left: 3px; bottom: 3px;
    background: #fff; border-radius: 50%; transition: .2s;
}
.mcp-switch-sm .mcp-slider:before { height: 11px; width: 11px; left: 3px; bottom: 3px; }
.mcp-switch input:checked + .mcp-slider { background: #3b82f6; }
.mcp-switch input:checked + .mcp-slider:before { transform: translateX(20px); }
.mcp-switch-sm input:checked + .mcp-slider:before { transform: translateX(17px); }
.mcp-switch input:disabled + .mcp-slider { opacity: .5; cursor: not-allowed; }
.mcp-switch input:indeterminate + .mcp-slider { background: #90b8f0; }

.mcp-endpoint {
    margin-top: 20px; padding: 10px 14px; background: #f5f7fa;
    border: 1px dashed #c9d4e3; border-radius: 4px; color: #555;
}
.mcp-newkey { word-break: break-all; }
CSS;
    }

    private static function js(): string
    {
        return <<<'JS'
(function () {
    'use strict';
    var modulelink = document.querySelector('form#mcp-token-form') ? '' : '';
    var form = document.getElementById('mcp-token-form');

    function token() {
        return form ? form.querySelector('input[name=token]').value : '';
    }

    function post(action, data, cb) {
        data = data || {};
        data.action = action;
        data.token = token();
        data.ajax = '1';
        var body = Object.keys(data).map(function (k) {
            return encodeURIComponent(k) + '=' + encodeURIComponent(data[k]);
        }).join('&');
        fetch(location.pathname + location.search.split('&tab=')[0] + '&ajax=1', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body,
            credentials: 'same-origin'
        }).then(function (r) { return r.json(); }).then(cb).catch(function (e) {
            console.error('MCP admin AJAX failed', e);
        });
    }

    // Grupos: expandir/colapsar
    document.querySelectorAll('.mcp-group-header').forEach(function (header) {
        header.addEventListener('click', function () {
            header.parentElement.classList.toggle('open');
        });
    });

    // Indeterminate
    document.querySelectorAll('input[data-indeterminate="1"]').forEach(function (el) {
        el.indeterminate = true;
    });

    // Toggle individual
    document.querySelectorAll('.mcp-toggle-tool').forEach(function (cb) {
        cb.addEventListener('change', function () {
            post('toggle_tool', { name: cb.dataset.name, enabled: cb.checked ? '1' : '0' }, function (res) {
                if (!res.ok) { cb.checked = !cb.checked; alert(res.error || 'Erro ao salvar'); return; }
                window.location.reload();
            });
        });
    });

    // Toggle por grupo
    document.querySelectorAll('.mcp-toggle-group').forEach(function (cb) {
        cb.addEventListener('change', function () {
            post('toggle_group', { group: cb.dataset.group, enabled: cb.checked ? '1' : '0' }, function (res) {
                if (!res.ok) { cb.checked = !cb.checked; alert(res.error || 'Erro ao salvar'); return; }
                window.location.reload();
            });
        });
    });

    // Toggle por coluna
    document.querySelectorAll('.mcp-toggle-column').forEach(function (cb) {
        cb.addEventListener('change', function () {
            post('toggle_column', { column: cb.dataset.column, enabled: cb.checked ? '1' : '0' }, function (res) {
                if (!res.ok) { cb.checked = !cb.checked; alert(res.error || 'Erro ao salvar'); return; }
                window.location.reload();
            });
        });
    });

    // Copiar chave
    document.querySelectorAll('.mcp-copy').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var text = btn.dataset.copy;
            var ta = document.createElement('textarea');
            ta.value = text; document.body.appendChild(ta);
            ta.select();
            try { document.execCommand('copy'); btn.textContent = 'Copiado!'; }
            catch (e) { btn.textContent = 'Selecione e copie'; }
            document.body.removeChild(ta);
        });
    });
})();
JS;
    }
}
