<?php

/**
 * WHMCS MCP Server — Handler explícito de tools
 *
 * O SDK oficial (mcp/sdk v0.7) tem um TODO no mapeamento de parâmetros
 * variadic ("Handle variadic parameters"): closures `fn (...$args)` são
 * registradas via ReflectedElementLoader e o ReferenceHandler pula o
 * parâmetro variadic, chamando o handler SEM os argumentos da tool.
 *
 * O caminho suportado é o explícito (Builder::add + ToolHandlerInterface):
 * o ExplicitElementLoader envolve o handler num closure que recebe o bag
 * cru de arguments (sem _session/_request) e chama execute().
 *
 * @package HadCloud\WhmcsMcp
 */

namespace HadCloud\Mcp;

use Mcp\Server\ClientGateway;
use Mcp\Server\Handler\ToolHandlerInterface;

final class McpToolHandler implements ToolHandlerInterface
{
    public function __construct(
        private readonly array $def,
        private readonly McpApplication $app,
    ) {
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public function execute(array $arguments, ClientGateway $gateway): mixed
    {
        return $this->app->runCommand($this->def, $arguments);
    }
}
