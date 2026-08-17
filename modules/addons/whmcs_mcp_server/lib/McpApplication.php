<?php

/**
 * WHMCS MCP Server — Aplicação MCP
 *
 * Monta o servidor MCP com o SDK oficial (mcp/sdk) e executa o
 * transporte Streamable HTTP, chamando localAPI() do WHMCS.
 *
 * @package HadCloud\WhmcsMcp
 */

namespace HadCloud\Mcp;

use Mcp\Server;
use Mcp\Schema\Tool;
use Mcp\Server\Session\FileSessionStore;
use Mcp\Server\Transport\Http\Middleware\CorsMiddleware;
use Mcp\Server\Transport\Http\Middleware\DnsRebindingProtectionMiddleware;
use Mcp\Server\Transport\Http\Middleware\ProtocolVersionMiddleware;
use Mcp\Server\Transport\StreamableHttpTransport;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class McpApplication
{
    private const VERSION = '1.0.0';

    public function __construct(
        private readonly Security $security = new Security(),
    ) {
    }

    /**
     * Ponto de entrada do endpoint mcp.php.
     */
    public function handle(): void
    {
        try {
            // Preflight CORS
            if (($this->method() ?? 'GET') === 'OPTIONS') {
                $this->emitPreflight();
                return;
            }

            // Autenticação obrigatória (Bearer token)
            if (!$this->security->authorize()) {
                Audit::record([
                    'tool' => '__auth__',
                    'status' => 'auth_fail',
                    'key_label' => $this->security->attemptedPrefix(),
                    'ip' => $this->security->clientIp(),
                    'session_id' => $this->security->sessionId(),
                    'message' => 'Unauthorized: API key inválida ou ausente',
                ]);
                $this->emitJson(401, ['error' => 'Unauthorized: API key inválida ou ausente']);
                return;
            }

            $request = $this->createServerRequest();

            $allowWrite = Settings::get('allow_write_tools') === '1';
            $definitions = ToolState::exposedDefinitions($allowWrite);

            $sessionTtl = max(60, (int) Settings::get('session_ttl', 7200));

            $builder = Server::builder()
                ->setServerInfo('whmcs-mcp-server', self::VERSION)
                ->setSession(sessionStore: new FileSessionStore(
                    __DIR__ . '/../storage/sessions',
                    $sessionTtl,
                ))
                ->setInstructions(
                    'Servidor MCP do WHMCS da HAD Cloud. '
                    . 'Consulte clientes, faturas, tickets, pedidos, produtos, domínios e transações. '
                    . 'Sempre verifique IDs antes de operações de escrita. '
                    . 'Responda em português do Brasil.'
                );

            foreach ($definitions as $def) {
                $builder->add(
                    new Tool(
                        name: $def['name'],
                        title: $def['title'],
                        inputSchema: $def['schema'],
                        description: $def['description'],
                        annotations: null,
                    ),
                    new McpToolHandler($def, $this),
                );
            }

            $server = $builder->build();

            // Middlewares padrão do SDK + allowlist com o host do WHMCS
            // (a proteção contra DNS rebinding bloqueia domínios desconhecidos)
            $systemUrl = (string) \WHMCS\Config\Setting::getValue('SystemURL');
            $host = strtolower((string) parse_url($systemUrl, PHP_URL_HOST));

            $transport = new StreamableHttpTransport(
                $request,
                middleware: [
                    new CorsMiddleware(),
                    new DnsRebindingProtectionMiddleware(
                        array_values(array_filter(['localhost', '127.0.0.1', '[::1]', $host]))
                    ),
                    new ProtocolVersionMiddleware(),
                ],
            );

            $response = $server->run($transport);

            $this->emit($response);
        } catch (\Throwable $e) {
            $this->logError($e);
            $this->emitJson(500, [
                'jsonrpc' => '2.0',
                'error' => ['code' => -32603, 'message' => 'Internal error: ' . $e->getMessage()],
            ]);
        }
    }

    /**
     * Executa um comando da WHMCS API via localAPI.
     *
     * Registra a chamada na trilha de auditoria (sucesso ou erro).
     * Público porque é chamado pelo McpToolHandler (caminho explícito do SDK).
     *
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public function runCommand(array $def, array $args): array
    {
        // Remove injeções internas do SDK
        unset($args['_session'], $args['_request']);

        // Parâmetros fixos da tool (ex: ModuleCommand command=unsuspend)
        $args = array_merge($def['fixed'] ?? [], $args);

        // Normaliza customfields: o WHMCS 8.13 espera base64_encode(serialize())
        // (faz base64_decode no valor) — converte array de {id => valor} automaticamente
        if (isset($args['customfields']) && is_array($args['customfields'])) {
            $args['customfields'] = base64_encode(serialize($args['customfields']));
        }

        // Troca de senha do cliente: no WHMCS 8.13+ a senha do login fica em
        // tblusers.password (bcrypt), linkada via tblusers_clients (owner=1).
        // A API UpdateClient NÃO troca essa senha (retorna success sem efeito) —
        // por isso o handler faz a troca direta com o mesmo hash do AddClient.
        if (($def['name'] ?? '') === 'update_client_password') {
            return $this->updateClientPassword($def, $args);
        }

        // UpdateClientProduct do WHMCS 8.13 usa 'servicepassword' (não 'password')
        // para trocar a senha do serviço — mapeia o campo amigável do schema.
        if (($def['name'] ?? '') === 'update_service_password' && isset($args['password'])) {
            $args['servicepassword'] = $args['password'];
            unset($args['password']);
        }

        $command = $def['command'];
        $start = microtime(true);

        try {
            $result = localAPI($command, $args);

            if (!is_array($result)) {
                throw new \RuntimeException("Resposta inválida da API para {$command}");
            }

            if (($result['result'] ?? '') === 'error') {
                $message = $result['message'] ?? 'Erro desconhecido da API WHMCS';
                throw new \RuntimeException("[{$command}] {$message}");
            }

            Audit::record([
                'tool' => $command,
                'status' => 'success',
                'args' => self::sanitizeArgs($args),
                'key_label' => $this->security->keyLabel(),
                'ip' => $this->security->clientIp(),
                'session_id' => $this->security->sessionId(),
                'duration_ms' => (int) round((microtime(true) - $start) * 1000),
            ]);

            return $result;
        } catch (\Throwable $e) {
            Audit::record([
                'tool' => $command,
                'status' => 'error',
                'args' => self::sanitizeArgs($args),
                'message' => $e->getMessage(),
                'key_label' => $this->security->keyLabel(),
                'ip' => $this->security->clientIp(),
                'session_id' => $this->security->sessionId(),
                'duration_ms' => (int) round((microtime(true) - $start) * 1000),
            ]);

            error_log('[whmcs-mcp-server] ' . $command . ' args=' . json_encode(self::sanitizeArgs($args)) . ' => ' . $e->getMessage());

            throw $e;
        }
    }

    /**
     * Troca a senha do usuário WHMCS do cliente (WHMCS 8.13+ armazena em tblusers).
     *
     * O AddClient cria o usuário com hash bcrypt em tblusers.password (linkado via
     * tblusers_clients, owner=1). A API UpdateClient NÃO troca essa senha (retorna
     * success sem efeito) e a API UpdateUser retorna "Invalid User ID requested"
     * via localAPI — o caminho oficial que funciona é o model WHMCS\User\User:
     * o método updatePassword() aplica o hash correto, dispara os eventos/mutators
     * do WHMCS (mesma classe usada pela UI de admin e pelo reset de senha).
     *
     * @param array<string, mixed> $def
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    private function updateClientPassword(array $def, array $args): array
    {
        $start = microtime(true);
        $clientId = (int) ($args['clientid'] ?? 0);
        $password = (string) ($args['password'] ?? '');

        try {
            if ($clientId <= 0 || $password === '') {
                throw new \RuntimeException('[UpdateClientPassword] clientid e password são obrigatórios');
            }
            if (strlen($password) < 8) {
                throw new \RuntimeException('[UpdateClientPassword] a senha deve ter no mínimo 8 caracteres');
            }

            $link = \Illuminate\Database\Capsule\Manager::table('tblusers_clients')
                ->where('client_id', $clientId)
                ->orderBy('owner', 'desc')
                ->first();

            if (!$link) {
                throw new \RuntimeException("[UpdateClientPassword] cliente {$clientId} não possui usuário de login vinculado");
            }

            // Caminho oficial: model WHMCS\User\User::updatePassword() — mesmo
            // mecanismo da UI do admin / reset de senha (não é SQL cru).
            $user = \WHMCS\User\User::find($link->auth_user_id);
            if (!$user) {
                throw new \RuntimeException("[UpdateClientPassword] usuário {$link->auth_user_id} não encontrado");
            }
            $user->updatePassword($password);

            $result = ['result' => 'success', 'userid' => $clientId, 'user_id' => $link->auth_user_id];

            Audit::record([
                'tool' => $def['command'],
                'status' => 'success',
                'args' => self::sanitizeArgs($args),
                'key_label' => $this->security->keyLabel(),
                'ip' => $this->security->clientIp(),
                'session_id' => $this->security->sessionId(),
                'duration_ms' => (int) round((microtime(true) - $start) * 1000),
            ]);

            return $result;
        } catch (\Throwable $e) {
            Audit::record([
                'tool' => $def['command'],
                'status' => 'error',
                'args' => self::sanitizeArgs($args),
                'message' => $e->getMessage(),
                'key_label' => $this->security->keyLabel(),
                'ip' => $this->security->clientIp(),
                'session_id' => $this->security->sessionId(),
                'duration_ms' => (int) round((microtime(true) - $start) * 1000),
            ]);

            throw $e;
        }
    }

    /**
     * Remove campos sensíveis (senha, segredo, token) antes de gravar no audit.
     *
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    private static function sanitizeArgs(array $args): array
    {
        $sensitive = '/pass|secret|token|apikey|api_key|pwd|credential/i';

        foreach ($args as $key => $value) {
            if (is_string($key) && preg_match($sensitive, $key)) {
                $args[$key] = '••••••••';
            } elseif (is_array($value)) {
                $args[$key] = self::sanitizeArgs($value);
            }
        }

        return $args;
    }

    /**
     * Constrói um PSR-7 ServerRequest a partir do PHP global.
     */
    private function createServerRequest(): ServerRequestInterface
    {
        $psr17 = new \Http\Discovery\Psr17FactoryDiscovery();
        $requestFactory = $psr17::findServerRequestFactory();
        $streamFactory = $psr17::findStreamFactory();

        $request = $requestFactory->createServerRequest(
            $this->method() ?? 'GET',
            $_SERVER['REQUEST_URI'] ?? '/',
            $_SERVER,
        );

        foreach ($this->requestHeaders() as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        if (($this->method() ?? '') === 'POST') {
            $rawBody = file_get_contents('php://input');
            $request = $request->withBody($streamFactory->createStream($rawBody ?: ''));
        }

        return $request;
    }

    /**
     * @return array<string, string>
     */
    private function requestHeaders(): array
    {
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            if (is_array($headers)) {
                return array_change_key_case($headers, CASE_LOWER);
            }
        }

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = str_replace('_', '-', strtolower(substr($key, 5)));
                $headers[$name] = $value;
            }
        }

        return $headers;
    }

    private function method(): ?string
    {
        return $_SERVER['REQUEST_METHOD'] ?? null;
    }

    /**
     * Envia a resposta PSR-7 para o cliente.
     */
    private function emit(ResponseInterface $response): void
    {
        http_response_code($response->getStatusCode());

        foreach ($response->getHeaders() as $name => $values) {
            header($name . ': ' . implode(', ', $values), false);
        }

        echo $response->getBody()->getContents();
    }

    private function emitPreflight(): void
    {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Authorization, Content-Type, Accept, Mcp-Session-Id');
        header('Access-Control-Max-Age: 86400');
        http_response_code(204);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function emitJson(int $status, array $body): void
    {
        header('Content-Type: application/json');
        http_response_code($status);
        echo json_encode($body);
    }

    private function logError(\Throwable $e): void
    {
        try {
            logActivity('WHMCS MCP Server: ' . $e->getMessage());
        } catch (\Throwable) {
            error_log('[whmcs-mcp-server] ' . $e->getMessage());
        }
    }
}
