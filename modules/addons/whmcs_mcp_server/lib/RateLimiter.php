<?php

/**
 * Limitação de requisições do endpoint MCP.
 *
 * Usa janelas fixas persistidas no banco para funcionar entre processos PHP.
 * Há um limite geral por IP e um limite mais restritivo para falhas de
 * autenticação, evitando crescimento ilimitado da auditoria.
 *
 * @package HadCloud\WhmcsMcp
 */

namespace HadCloud\Mcp;

use Illuminate\Database\Capsule\Manager as Capsule;

final class RateLimiter
{
    private const TABLE = 'mod_whmcs_mcp_rate_limits';

    private const ENDPOINT_LIMIT = 300;
    private const ENDPOINT_WINDOW_SECONDS = 60;

    private const AUTH_FAILURE_LIMIT = 20;
    private const AUTH_FAILURE_WINDOW_SECONDS = 300;

    public static function table(): void
    {
        $schema = Capsule::schema();

        if (!$schema->hasTable(self::TABLE)) {
            $schema->create(self::TABLE, function ($table): void {
                $table->string('bucket', 64)->primary();
                $table->unsignedInteger('hits')->default(0);
                $table->unsignedInteger('window_started_at');
                $table->timestamp('updated_at')->nullable();
            });
        }
    }

    /**
     * @return array{allowed: bool, retry_after: int, remaining: int}
     */
    public static function endpoint(string $ip): array
    {
        return self::consume(
            'endpoint',
            $ip,
            self::ENDPOINT_LIMIT,
            self::ENDPOINT_WINDOW_SECONDS,
        );
    }

    /**
     * @return array{allowed: bool, retry_after: int, remaining: int}
     */
    public static function authFailure(string $ip): array
    {
        return self::consume(
            'auth_failure',
            $ip,
            self::AUTH_FAILURE_LIMIT,
            self::AUTH_FAILURE_WINDOW_SECONDS,
        );
    }

    /**
     * @return array{allowed: bool, retry_after: int, remaining: int}
     */
    private static function consume(
        string $scope,
        string $identity,
        int $limit,
        int $windowSeconds,
    ): array {
        self::table();

        $now = time();
        $bucket = hash('sha256', $scope . "\0" . ($identity !== '' ? $identity : 'unknown'));

        // Limpeza probabilística mantém a tabela limitada sem criar um cron.
        if (mt_rand(1, 1000) === 1) {
            Capsule::table(self::TABLE)
                ->where('updated_at', '<', date('Y-m-d H:i:s', $now - 86400))
                ->delete();
        }

        return Capsule::connection()->transaction(function () use (
            $bucket,
            $limit,
            $windowSeconds,
            $now,
        ): array {
            Capsule::table(self::TABLE)->insertOrIgnore([
                'bucket' => $bucket,
                'hits' => 0,
                'window_started_at' => $now,
                'updated_at' => date('Y-m-d H:i:s', $now),
            ]);

            $row = Capsule::table(self::TABLE)
                ->where('bucket', $bucket)
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                throw new \RuntimeException('Não foi possível verificar o limite de requisições');
            }

            $result = self::evaluateWindow(
                (int) $row->hits,
                (int) $row->window_started_at,
                $now,
                $limit,
                $windowSeconds,
            );

            Capsule::table(self::TABLE)
                ->where('bucket', $bucket)
                ->update([
                    'hits' => $result['hits'],
                    'window_started_at' => $result['window_started_at'],
                    'updated_at' => date('Y-m-d H:i:s', $now),
                ]);

            return [
                'allowed' => $result['allowed'],
                'retry_after' => $result['retry_after'],
                'remaining' => $result['remaining'],
            ];
        });
    }

    /**
     * @return array{
     *     allowed: bool,
     *     retry_after: int,
     *     remaining: int,
     *     hits: int,
     *     window_started_at: int
     * }
     */
    private static function evaluateWindow(
        int $hits,
        int $startedAt,
        int $now,
        int $limit,
        int $windowSeconds,
    ): array {
        if ($startedAt <= 0 || ($now - $startedAt) >= $windowSeconds) {
            return [
                'allowed' => true,
                'retry_after' => 0,
                'remaining' => max(0, $limit - 1),
                'hits' => 1,
                'window_started_at' => $now,
            ];
        }

        $nextHits = min($limit + 1, $hits + 1);
        $allowed = $nextHits <= $limit;

        return [
            'allowed' => $allowed,
            'retry_after' => $allowed ? 0 : max(1, $windowSeconds - ($now - $startedAt)),
            'remaining' => max(0, $limit - $nextHits),
            'hits' => $nextHits,
            'window_started_at' => $startedAt,
        ];
    }
}
