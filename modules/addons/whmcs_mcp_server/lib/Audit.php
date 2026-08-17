<?php

/**
 * WHMCS MCP Server — Trilha de auditoria
 *
 * Registra toda atividade do endpoint MCP: chamadas de tools
 * (sucesso/erro), tentativas sem autenticação e o rótulo da chave
 * usada. Persistência em `mod_whmcs_mcp_audit`.
 *
 * O registro NUNCA deve derrubar o endpoint: qualquer falha de escrita
 * é engolida com error_log (try/catch em record()).
 *
 * @package HadCloud\WhmcsMcp
 */

namespace HadCloud\Mcp;

use Illuminate\Database\Capsule\Manager as Capsule;

final class Audit
{
    private const TABLE = 'mod_whmcs_mcp_audit';

    private const PAGE_SIZE = 25;

    /**
     * Cria a tabela se não existir.
     */
    public static function table(): void
    {
        $schema = Capsule::schema();

        if (!$schema->hasTable(self::TABLE)) {
            $schema->create(self::TABLE, function ($table): void {
                $table->increments('id');
                $table->string('tool', 64);
                $table->string('status', 16);          // success | error | auth_fail
                $table->text('args')->nullable();       // JSON sanitizado
                $table->text('message')->nullable();    // erro ou observação
                $table->string('key_label', 100)->nullable();
                $table->string('ip', 45)->nullable();
                $table->string('session_id', 64)->nullable();
                $table->integer('duration_ms')->default(0);
                $table->tinyInteger('notified')->default(0); // 1 = alerta enviado
                $table->timestamp('created_at')->nullable();
                $table->index(['tool', 'created_at']);
            });
        }
    }

    /**
     * Grava um evento. Falhas de gravação não podem quebrar o endpoint.
     *
     * @param array<string, mixed> $entry
     */
    public static function record(array $entry): void
    {
        try {
            self::table();

            $status = mb_substr((string) ($entry['status'] ?? 'error'), 0, 16);
            $entry['args'] = isset($entry['args']) && is_array($entry['args'])
                ? json_encode($entry['args'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : null;

            $id = Capsule::table(self::TABLE)->insertGetId([
                'tool' => mb_substr((string) ($entry['tool'] ?? ''), 0, 64),
                'status' => $status,
                'args' => $entry['args'],
                'message' => mb_substr((string) ($entry['message'] ?? ''), 0, 1000),
                'key_label' => mb_substr((string) ($entry['key_label'] ?? ''), 0, 100),
                'ip' => mb_substr((string) ($entry['ip'] ?? ''), 0, 45),
                'session_id' => mb_substr((string) ($entry['session_id'] ?? ''), 0, 64),
                'duration_ms' => max(0, (int) ($entry['duration_ms'] ?? 0)),
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            // Alerta (e-mail/Telegram) em erro de tool ou falha de autenticação
            if ($status === 'error' || $status === 'auth_fail') {
                $sent = Alerts::notify($status, $entry);

                if ($sent) {
                    Capsule::table(self::TABLE)
                        ->where('id', $id)
                        ->update(['notified' => 1]);
                }
            }
        } catch (\Throwable $e) {
            error_log('[whmcs-mcp-server] audit record falhou: ' . $e->getMessage());
        }
    }

    /**
     * Totais para o resumo do painel.
     *
     * @return array<string, int>
     */
    public static function stats(): array
    {
        self::table();

        $stats = ['success' => 0, 'error' => 0, 'auth_fail' => 0, 'total' => 0, 'last_24h' => 0];

        try {
            $rows = Capsule::table(self::TABLE)
                ->selectRaw('status, COUNT(*) as total')
                ->groupBy('status')
                ->get();

            foreach ($rows as $row) {
                $stats[(string) $row->status] = (int) $row->total;
                $stats['total'] += (int) $row->total;
            }

            $stats['last_24h'] = (int) Capsule::table(self::TABLE)
                ->where('created_at', '>=', date('Y-m-d H:i:s', time() - 86400))
                ->count();
        } catch (\Throwable $e) {
            error_log('[whmcs-mcp-server] audit stats falhou: ' . $e->getMessage());
        }

        return $stats;
    }

    /**
     * Consulta paginada com filtros.
     *
     * @param array<string, mixed> $filters
     * @return array{total:int, page:int, pages:int, rows:array<int, array<string, mixed>>}
     */
    public static function query(array $filters = [], int $page = 1, int $perPage = self::PAGE_SIZE): array
    {
        self::table();

        $page = max(1, $page);
        $perPage = max(5, min(100, $perPage));

        $q = Capsule::table(self::TABLE);

        if (!empty($filters['tool'])) {
            $q->where('tool', 'like', '%' . (string) $filters['tool'] . '%');
        }
        if (!empty($filters['status'])) {
            $q->where('status', (string) $filters['status']);
        }
        if (!empty($filters['key'])) {
            $q->where('key_label', 'like', '%' . (string) $filters['key'] . '%');
        }
        if (!empty($filters['from'])) {
            $q->where('created_at', '>=', (string) $filters['from'] . ' 00:00:00');
        }
        if (!empty($filters['to'])) {
            $q->where('created_at', '<=', (string) $filters['to'] . ' 23:59:59');
        }

        $total = (int) (clone $q)->count();

        $rows = (clone $q)
            ->orderByDesc('id')
            ->skip(($page - 1) * $perPage)
            ->take($perPage)
            ->get()
            ->map(function ($row): array {
                return [
                    'id' => (int) $row->id,
                    'tool' => (string) $row->tool,
                    'status' => (string) $row->status,
                    'args' => (string) ($row->args ?? ''),
                    'message' => (string) ($row->message ?? ''),
                    'key_label' => (string) ($row->key_label ?? ''),
                    'ip' => (string) ($row->ip ?? ''),
                    'session_id' => (string) ($row->session_id ?? ''),
                    'duration_ms' => (int) $row->duration_ms,
                    'notified' => (int) ($row->notified ?? 0),
                    'created_at' => (string) ($row->created_at ?? ''),
                ];
            })
            ->all();

        return [
            'total' => $total,
            'page' => $page,
            'pages' => max(1, (int) ceil($total / $perPage)),
            'rows' => $rows,
        ];
    }

    /**
     * Apaga registros. $days = null apaga TUDO; senão apaga os mais antigos que $days.
     */
    public static function purge(?int $days = null): int
    {
        self::table();

        if ($days === null) {
            return (int) Capsule::table(self::TABLE)->delete();
        }

        return (int) Capsule::table(self::TABLE)
            ->where('created_at', '<', date('Y-m-d H:i:s', time() - $days * 86400))
            ->delete();
    }

    /**
     * Lista bruta para exportação CSV (máx. 1000 linhas, filtros aplicados).
     *
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public static function export(array $filters = [], int $limit = 1000): array
    {
        self::table();

        $q = Capsule::table(self::TABLE);

        if (!empty($filters['tool'])) {
            $q->where('tool', 'like', '%' . (string) $filters['tool'] . '%');
        }
        if (!empty($filters['status'])) {
            $q->where('status', (string) $filters['status']);
        }

        return (clone $q)
            ->orderByDesc('id')
            ->take(max(1, min(5000, $limit)))
            ->get()
            ->map(function ($row): array {
                return [
                    'id' => (int) $row->id,
                    'created_at' => (string) ($row->created_at ?? ''),
                    'tool' => (string) $row->tool,
                    'status' => (string) $row->status,
                    'key_label' => (string) ($row->key_label ?? ''),
                    'ip' => (string) ($row->ip ?? ''),
                    'session_id' => (string) ($row->session_id ?? ''),
                    'duration_ms' => (int) $row->duration_ms,
                    'args' => (string) ($row->args ?? ''),
                    'message' => (string) ($row->message ?? ''),
                ];
            })
            ->all();
    }
}
