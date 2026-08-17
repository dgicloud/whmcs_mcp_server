<?php

/**
 * WHMCS MCP Server — Segurança
 *
 * Autenticação via Bearer token (API keys do addon), com comparação
 * em tempo constante. Opcionalmente restringe por IP (allowlist).
 *
 * @package HadCloud\WhmcsMcp
 */

namespace HadCloud\Mcp;

final class Security
{
    /** Rótulo da chave que autenticou o request atual (ou null). */
    private ?string $currentLabel = null;

    /** Prefixo da chave enviada no request atual (ou ''). */
    private string $attemptedPrefix = '';

    /**
     * Valida o header Authorization: Bearer ***
     */
    public function authorize(): bool
    {
        // Migra a chave única da v1.0.0 para a tabela (idempotente)
        ApiKeys::migrateLegacy();

        $header = $_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? '';

        if (!preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
            return false;
        }

        $provided = trim($matches[1]);

        if ($provided === '') {
            return false;
        }

        $this->attemptedPrefix = mb_substr($provided, 0, 12);

        // Chave da tabela (compat com a legada migrada)
        $label = ApiKeys::labelFor($provided);
        if ($label !== null) {
            $this->currentLabel = $label;
            return $this->ipAllowed();
        }

        // Compatibilidade: chave legada ainda não migrada
        $legacy = (string) Settings::get('api_key', '');
        if ($legacy !== '' && hash_equals($legacy, $provided)) {
            $this->currentLabel = 'Legacy (v1)';
            return $this->ipAllowed();
        }

        return false;
    }

    /**
     * Rótulo da chave autenticada no request atual ('Legacy (v1)' se legada).
     */
    public function keyLabel(): string
    {
        return $this->currentLabel ?? 'desconhecida';
    }

    /**
     * Prefixo da chave enviada (útil no log de tentativa sem auth).
     */
    public function attemptedPrefix(): string
    {
        return $this->attemptedPrefix;
    }

    /**
     * IP de origem do request atual.
     */
    public function clientIp(): string
    {
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    }

    /**
     * ID de sessão MCP enviado pelo cliente (header Mcp-Session-Id).
     */
    public function sessionId(): string
    {
        return (string) ($_SERVER['HTTP_MCP_SESSION_ID'] ?? '');
    }

    /**
     * Allowlist de IPs. Vazia = liberado para qualquer IP autenticado.
     */
    private function ipAllowed(): bool
    {
        $allowed = trim((string) Settings::get('allowed_ips', ''));

        if ($allowed === '') {
            return true;
        }

        $clientIp = $_SERVER['REMOTE_ADDR'] ?? '';
        $ips = array_map('trim', explode(',', $allowed));

        return in_array($clientIp, $ips, true);
    }
}
