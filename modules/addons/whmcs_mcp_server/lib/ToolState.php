<?php

/**
 * WHMCS MCP Server — Estado das tools
 *
 * Persistência em `mod_whmcs_mcp_tools` do estado habilitada/desabilitada
 * de cada tool individual. Tools desabilitadas são removidas do catálogo
 * (tools/list) e rejeitadas na chamada (tools/call → Tool not found).
 *
 * @package HadCloud\WhmcsMcp
 */

namespace HadCloud\Mcp;

use Illuminate\Database\Capsule\Manager as Capsule;

final class ToolState
{
    private const TABLE = 'mod_whmcs_mcp_tools';

    /**
     * Cria a tabela se não existir.
     */
    public static function table(): void
    {
        $schema = Capsule::schema();

        if (!$schema->hasTable(self::TABLE)) {
            $schema->create(self::TABLE, function ($table): void {
                $table->string('name', 64)->primary();
                $table->string('group', 32)->default('system');
                $table->boolean('enabled')->default(true);
                $table->timestamp('updated_at')->nullable();
            });
        }
    }

    /**
     * Garante que todas as tools do catálogo existam na tabela.
     */
    public static function sync(): void
    {
        self::table();

        $now = date('Y-m-d H:i:s');

        foreach (Tools::definitions() as $def) {
            Capsule::table(self::TABLE)->updateOrInsert(
                ['name' => $def['name']],
                ['group' => $def['group'], 'updated_at' => $now]
            );
        }
    }

    /**
     * @return array<string, bool> name => enabled
     */
    public static function all(): array
    {
        self::sync();

        $states = [];
        foreach (Capsule::table(self::TABLE)->pluck('enabled', 'name') as $name => $enabled) {
            $states[(string) $name] = (bool) $enabled;
        }

        return $states;
    }

    public static function isEnabled(string $name): bool
    {
        $row = Capsule::table(self::TABLE)->where('name', $name)->first();

        return $row === null || (bool) $row->enabled;
    }

    public static function set(string $name, bool $enabled): void
    {
        self::sync();

        Capsule::table(self::TABLE)
            ->where('name', $name)
            ->update(['enabled' => $enabled, 'updated_at' => date('Y-m-d H:i:s')]);
    }

    /**
     * Habilita/desabilita um grupo inteiro.
     */
    public static function setGroup(string $group, bool $enabled): void
    {
        self::sync();

        Capsule::table(self::TABLE)
            ->where('group', $group)
            ->update(['enabled' => $enabled, 'updated_at' => date('Y-m-d H:i:s')]);
    }

    /**
     * Habilita/desabilita todas as tools.
     */
    public static function setAll(bool $enabled): void
    {
        self::sync();

        Capsule::table(self::TABLE)
            ->update(['enabled' => $enabled, 'updated_at' => date('Y-m-d H:i:s')]);
    }

    /**
     * @return array{enabled: int, disabled: int, total: int}
     */
    public static function counts(): array
    {
        $states = self::all();
        $total = count($states);
        $enabled = count(array_filter($states));

        return [
            'enabled' => $enabled,
            'disabled' => $total - $enabled,
            'total' => $total,
        ];
    }

    /**
     * Definitions filtráveis pela API (leitura + escrita quando liberada).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function exposedDefinitions(bool $allowWrite): array
    {
        return array_values(array_filter(
            Tools::definitions(),
            function (array $def) use ($allowWrite): bool {
                if (!$allowWrite && !empty($def['write'])) {
                    return false;
                }

                return self::isEnabled($def['name']);
            }
        ));
    }
}
