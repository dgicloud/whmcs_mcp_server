<?php

/**
 * WHMCS MCP Server — Acesso às configurações do addon
 *
 * Armazenadas em tbladdonmodules (padrão WHMCS para addons).
 *
 * @package HadCloud\WhmcsMcp
 */

namespace HadCloud\Mcp;

use WHMCS\Database\Capsule;

final class Settings
{
    private const MODULE = 'whmcs_mcp_server';

    /**
     * Lê uma configuração do addon.
     */
    public static function get(string $key, ?string $default = null): ?string
    {
        $value = Capsule::table('tbladdonmodules')
            ->where('module', self::MODULE)
            ->where('setting', $key)
            ->value('value');

        return $value !== null ? (string) $value : $default;
    }

    /**
     * Grava uma configuração do addon.
     */
    public static function set(string $key, string $value): void
    {
        Capsule::table('tbladdonmodules')->updateOrInsert(
            ['module' => self::MODULE, 'setting' => $key],
            ['value' => $value]
        );
    }
}
