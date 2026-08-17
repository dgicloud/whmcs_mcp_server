<?php

/**
 * WHMCS MCP Server — Alertas de auditoria
 *
 * Notifica por e-mail e/ou Telegram quando a auditoria registra
 * erro de tool ou falha de autenticação. Com rate limit persistente
 * (janela configurável) para evitar spam em tentativas em massa.
 *
 * Configurações (tbladdonmodules):
 *   alerts_enabled          '1'/'0' — liga/desliga alertas
 *   alerts_on_error         '1'/'0' — alerta em erro de tool
 *   alerts_on_auth_fail     '1'/'0' — alerta em falha de autenticação
 *   alerts_email_enabled    '1'/'0'
 *   alerts_email_to         e-mail específico (vazio = todos os admins)
 *   alerts_telegram_enabled '1'/'0'
 *   alerts_telegram_token   bot token
 *   alerts_telegram_chat    chat_id de destino
 *   alerts_telegram_api_base base URL (default https://api.telegram.org)
 *   alerts_rate_minutes     janela de rate limit (default 5)
 *
 * O alerta NUNCA pode derrubar o endpoint: todo o fluxo é protegido
 * por try/catch + error_log.
 *
 * @package HadCloud\WhmcsMcp
 */

namespace HadCloud\Mcp;

use Illuminate\Database\Capsule\Manager as Capsule;

final class Alerts
{
    private const TABLE = 'mod_whmcs_mcp_notifs';

    /**
     * Cria a tabela de rate limit se não existir.
     */
    public static function table(): void
    {
        $schema = Capsule::schema();

        if (!$schema->hasTable(self::TABLE)) {
            $schema->create(self::TABLE, function ($table): void {
                $table->string('type', 16)->primary();   // error | auth_fail
                $table->integer('last_sent')->default(0);
                $table->integer('count')->default(0);     // tentativas suprimidas na janela
                $table->timestamp('created_at')->nullable();
            });
        }
    }

    /**
     * Dispara o alerta se o tipo estiver habilitado e fora da janela
     * de rate limit. Retorna true se uma notificação foi enviada.
     *
     * @param array<string, mixed> $context contexto do evento (tool, message, ip, ...)
     */
    public static function notify(string $type, array $context): bool
    {
        try {
            self::table();

            if (Settings::get('alerts_enabled') !== '1') {
                return false;
            }

            $enabledFor = [
                'error'     => Settings::get('alerts_on_error'),
                'auth_fail' => Settings::get('alerts_on_auth_fail'),
            ];

            if (($enabledFor[$type] ?? null) !== '1') {
                return false;
            }

            // Rate limit: no máximo 1 alerta por tipo a cada N minutos
            $minutes = max(1, (int) Settings::get('alerts_rate_minutes', 5));
            $now = time();
            $row = Capsule::table(self::TABLE)->where('type', $type)->first();

            if ($row && ((int) $row->last_sent + $minutes * 60) > $now) {
                Capsule::table(self::TABLE)
                    ->where('type', $type)
                    ->update(['count' => ((int) $row->count) + 1]);
                return false;
            }

            [$subject, $text] = self::format($type, $context);

            $sent = false;

            if (Settings::get('alerts_email_enabled') === '1') {
                $sent = self::sendEmail($subject, $text) || $sent;
            }

            if (Settings::get('alerts_telegram_enabled') === '1') {
                $sent = self::sendTelegram($text) || $sent;
            }

            if ($sent) {
                Capsule::table(self::TABLE)->updateOrInsert(
                    ['type' => $type],
                    ['last_sent' => $now, 'count' => 0]
                );
            }

            return $sent;
        } catch (\Throwable $e) {
            error_log('[whmcs-mcp-server] alerta falhou: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * @return array{0: string, 1: string} [assunto e-mail, corpo (também usado no Telegram)]
     */
    private static function format(string $type, array $c): array
    {
        $tool = (string) ($c['tool'] ?? '—');
        $msg = trim((string) ($c['message'] ?? ''));
        $time = date('d/m/Y H:i:s');

        $subject = sprintf(
            '[WHMCS MCP] %s em %s',
            $type === 'error' ? 'Erro' : 'Falha de autenticação',
            $tool
        );

        $lines = [
            ($type === 'error' ? '⚠️ Erro em tool' : '🔐 Falha de autenticação') . ' — WHMCS MCP',
            '──────────────',
            '🕐 ' . $time,
            '🔧 Tool: ' . $tool,
        ];

        if ($msg !== '') {
            $lines[] = '📄 ' . $msg;
        }
        if (!empty($c['key_label'])) {
            $lines[] = '🔑 Chave: ' . $c['key_label'];
        }
        if (!empty($c['ip'])) {
            $lines[] = '🌐 IP: ' . $c['ip'];
        }
        if (!empty($c['duration_ms'])) {
            $lines[] = '⚡ ' . (int) $c['duration_ms'] . 'ms';
        }
        if (!empty($c['args']) && is_array($c['args'])) {
            $argsJson = json_encode($c['args'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $lines[] = '🧾 Args: ' . mb_substr((string) $argsJson, 0, 600);
        }

        return [$subject, implode("\n", $lines)];
    }

    private static function sendEmail(string $subject, string $body): bool
    {
        $to = trim((string) Settings::get('alerts_email_to', ''));

        if ($to !== '') {
            // Destinatário específico via Mailer do WHMCS (PHPMailer)
            $mailer = new \WHMCS\Mail\Mailer();
            $mailer->addAddress($to);
            $mailer->Subject = $subject;
            $mailer->Body = $body;
            $mailer->AltBody = strip_tags($body);
            $sent = $mailer->send();

            if (!$sent) {
                error_log('[whmcs-mcp-server] alerta email falhou: ' . $mailer->ErrorInfo);
            }

            return $sent;
        }

        // Sem destinatário: notifica todos os admins (função global do WHMCS)
        sendAdminNotification($subject, $body);
        return true;
    }

    private static function sendTelegram(string $text): bool
    {
        $token = trim((string) Settings::get('alerts_telegram_token', ''));
        $chat = trim((string) Settings::get('alerts_telegram_chat', ''));

        if ($token === '' || $chat === '') {
            return false;
        }

        $apiBase = rtrim((string) Settings::get('alerts_telegram_api_base', 'https://api.telegram.org'), '/');
        $url = $apiBase . '/bot' . $token . '/sendMessage';

        $payload = json_encode([
            'chat_id' => $chat,
            'text' => mb_substr($text, 0, 3800),
            'disable_web_page_preview' => true,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Content-Length: ' . strlen((string) $payload),
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_CONNECTTIMEOUT => 4,
        ]);

        $resp = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);

        if ($resp === false) {
            error_log('[whmcs-mcp-server] telegram curl: ' . $err);
            return false;
        }

        $decoded = json_decode((string) $resp, true);

        if (!is_array($decoded) || empty($decoded['ok'])) {
            error_log('[whmcs-mcp-server] telegram resposta: ' . mb_substr((string) $resp, 0, 300));
            return false;
        }

        return true;
    }
}
