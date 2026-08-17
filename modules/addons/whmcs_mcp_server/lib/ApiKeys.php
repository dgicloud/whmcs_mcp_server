<?php

/**
 * WHMCS MCP Server — API Keys
 *
 * Suporte a múltiplas chaves de acesso com rótulo, revogação e
 * registro de último uso. Persistência em `mod_whmcs_mcp_api_keys`.
 *
 * A chave crua é exibida apenas UMA vez, no momento da criação;
 * o banco guarda apenas o hash (SHA-256), impossível de recuperar.
 *
 * @package HadCloud\WhmcsMcp
 */

namespace HadCloud\Mcp;

use Illuminate\Database\Capsule\Manager as Capsule;

final class ApiKeys
{
    private const TABLE = 'mod_whmcs_mcp_api_keys';

    private const PREFIX = 'mcp_';

    /**
     * Cria a tabela se não existir.
     */
    public static function table(): void
    {
        $schema = Capsule::schema();

        if (!$schema->hasTable(self::TABLE)) {
            $schema->create(self::TABLE, function ($table): void {
                $table->increments('id');
                $table->string('label', 100)->default('API Key');
                $table->string('key_hash', 64);
                $table->string('key_prefix', 12);
                $table->boolean('revoked')->default(false);
                $table->timestamp('created_at')->nullable();
                $table->timestamp('last_used_at')->nullable();
            });
        }
    }

    /**
     * Gera uma nova chave. Retorna a chave crua (mostrar UMA vez).
     */
    public static function create(string $label = 'API Key'): string
    {
        self::table();

        $raw = self::PREFIX . bin2hex(random_bytes(32));
        $prefix = substr($raw, 0, 12);

        Capsule::table(self::TABLE)->insert([
            'label' => mb_substr(trim($label) ?: 'API Key', 0, 100),
            'key_hash' => hash('sha256', $raw),
            'key_prefix' => $prefix,
            'revoked' => false,
            'created_at' => date('Y-m-d H:i:s'),
            'last_used_at' => null,
        ]);

        return $raw;
    }

    /**
     * Valida uma chave contra o hash armazenado (tempo constante).
     */
    public static function verify(string $key): bool
    {
        return self::labelFor($key) !== null;
    }

    /**
     * Valida a chave e devolve o rótulo da chave correspondente
     * (ou null se inválida/revogada). Atualiza last_used_at.
     */
    public static function labelFor(string $key): ?string
    {
        self::table();

        $row = Capsule::table(self::TABLE)
            ->where('key_hash', hash('sha256', $key))
            ->where('revoked', false)
            ->first();

        if ($row === null) {
            return null;
        }

        Capsule::table(self::TABLE)
            ->where('id', $row->id)
            ->update(['last_used_at' => date('Y-m-d H:i:s')]);

        return (string) $row->label;
    }

    public static function revoke(int $id): void
    {
        self::table();

        Capsule::table(self::TABLE)
            ->where('id', $id)
            ->update(['revoked' => true]);
    }

    public static function revokeAll(): void
    {
        self::table();

        Capsule::table(self::TABLE)
            ->where('revoked', false)
            ->update(['revoked' => true]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function all(): array
    {
        self::table();

        return Capsule::table(self::TABLE)
            ->orderBy('revoked')
            ->orderByDesc('created_at')
            ->get()
            ->map(function ($row): array {
                return [
                    'id' => (int) $row->id,
                    'label' => (string) $row->label,
                    'key_prefix' => (string) $row->key_prefix,
                    'revoked' => (bool) $row->revoked,
                    'created_at' => (string) ($row->created_at ?? ''),
                    'last_used_at' => (string) ($row->last_used_at ?? ''),
                ];
            })
            ->all();
    }

    /**
     * Migra a chave única legada (tbladdonmodules.api_key) para a tabela,
     * preservando o acesso existente. Idempotente: só roda se a tabela
     * estiver vazia e existir chave legada.
     */
    public static function migrateLegacy(): void
    {
        self::table();

        $count = (int) Capsule::table(self::TABLE)->count();

        if ($count > 0) {
            return;
        }

        $legacy = (string) Settings::get('api_key', '');

        if ($legacy === '') {
            return;
        }

        $prefix = substr($legacy, 0, 12);

        Capsule::table(self::TABLE)->insert([
            'label' => 'Default (migrada)',
            'key_hash' => hash('sha256', $legacy),
            'key_prefix' => $prefix,
            'revoked' => false,
            'created_at' => date('Y-m-d H:i:s'),
            'last_used_at' => null,
        ]);
    }

    /**
     * Máscara amigável para exibição (prefixo + pontos).
     */
    public static function mask(string $prefix): string
    {
        return $prefix . str_repeat('•', 16);
    }
}
