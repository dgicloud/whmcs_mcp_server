<?php

/**
 * WHMCS MCP Server — Definições das tools
 *
 * Cada tool mapeia 1:1 para um comando da WHMCS API (localAPI).
 * As tools são agrupadas por categoria para gestão no painel admin.
 *
 * @package HadCloud\WhmcsMcp
 */

namespace HadCloud\Mcp;

final class Tools
{
    public const GROUPS = [
        'client'    => 'Client Management',
        'product'   => 'Products',
        'service'   => 'Service Management',
        'invoice'   => 'Invoice Management',
        'order'     => 'Order Management',
        'ticket'    => 'Ticket Management',
        'system'    => 'System',
    ];

    /**
     * @return array<int, array{
     *     name: string,
     *     command: string,
     *     title: string,
     *     description: string,
     *     label: string,
     *     group: string,
     *     write: bool,
     *     schema: array<string, mixed>
     * }>
     */
    public static function definitions(): array
    {
        $string = ['type' => 'string'];
        $int = ['type' => 'integer'];

        return [
            // ==================== CLIENT MANAGEMENT ====================
            [
                'name' => 'get_clients',
                'command' => 'GetClients',
                'title' => 'Listar clientes',
                'label' => 'List clients',
                'group' => 'client',
                'description' => 'Lista clientes do WHMCS com filtro opcional por nome, email ou empresa. Use limitnum para paginar.',
                'write' => false,
                'schema' => [
                    'type' => 'object',
                    'properties' => [
                        'search' => $string + ['description' => 'Busca por nome, email ou empresa'],
                        'limitnum' => $int + ['description' => 'Máximo de registros (default 25, máx 100)'],
                        'limitstart' => $int + ['description' => 'Offset para paginação'],
                    ],
                ],
            ],
            [
                'name' => 'get_client_details',
                'command' => 'GetClientsDetails',
                'title' => 'Detalhes do cliente',
                'label' => 'Get client by ID',
                'group' => 'client',
                'description' => 'Retorna dados completos de um cliente: contato, endereço, status, grupo, saldo, créditos e produtos ativos.',
                'write' => false,
                'schema' => [
                    'type' => 'object',
                    'properties' => [
                        'clientid' => $int + ['description' => 'ID do cliente'],
                        'email' => $string + ['description' => 'Email do cliente (alternativo ao clientid)'],
                    ],
                    'required' => [],
                ],
            ],
            [
                'name' => 'create_client',
                'command' => 'AddClient',
                'title' => 'Criar cliente',
                'label' => 'Create client',
                'group' => 'client',
                'description' => 'Cria um novo cliente. Campos obrigatórios: firstname, lastname, email, address1, city, state, postcode, country, phonenumber, password2.',
                'write' => true,
                'schema' => [
                    'type' => 'object',
                    'properties' => [
                        'firstname' => $string + ['description' => 'Nome'],
                        'lastname' => $string + ['description' => 'Sobrenome'],
                        'email' => $string + ['description' => 'Email'],
                        'companyname' => $string + ['description' => 'Empresa (opcional)'],
                        'address1' => $string + ['description' => 'Endereço'],
                        'city' => $string + ['description' => 'Cidade'],
                        'state' => $string + ['description' => 'Estado'],
                        'postcode' => $string + ['description' => 'CEP'],
                        'country' => $string + ['description' => 'País (código ISO, ex: BR)'],
                        'phonenumber' => $string + ['description' => 'Telefone'],
                        'password2' => $string + ['description' => 'Senha do cliente'],
                        'customfields' => [
                            'type' => 'object',
                            'additionalProperties' => ['type' => 'string'],
                            'description' => 'Campos customizados do cliente: mapa {id_do_campo: valor} (ex: {"1": "12.345.678/0001-99"})',
                        ],
                    ],
                    'required' => ['firstname', 'lastname', 'email', 'address1', 'city', 'state', 'postcode', 'country', 'phonenumber', 'password2'],
                ],
            ],
            [
                'name' => 'update_client',
                'command' => 'UpdateClient',
                'title' => 'Atualizar cliente',
                'label' => 'Update client',
                'group' => 'client',
                'description' => 'Atualiza dados de um cliente existente. Envie apenas os campos a alterar (clientid obrigatório).',
                'write' => true,
                'schema' => [
                    'type' => 'object',
                    'properties' => [
                        'clientid' => $int + ['description' => 'ID do cliente'],
                        'firstname' => $string,
                        'lastname' => $string,
                        'email' => $string,
                        'companyname' => $string,
                        'phonenumber' => $string,
                        'status' => $string + ['description' => 'Active | Inactive | Closed'],
                    ],
                    'required' => ['clientid'],
                ],
            ],
            [
                'name' => 'update_client_password',
                'command' => 'UpdateClient',
                'title' => 'Trocar senha do cliente',
                'label' => 'Update client password',
                'group' => 'client',
                'description' => 'Troca a senha do usuário do WHMCS (portal do cliente). Use para reset de senha. Envie clientid + password (a confirmação password2 é preenchida automaticamente se omitida).',
                'write' => true,
                'schema' => [
                    'type' => 'object',
                    'properties' => [
                        'clientid' => $int + ['description' => 'ID do cliente'],
                        'password' => $string + ['description' => 'Nova senha do cliente (mín 8 caracteres)'],
                        'password2' => $string + ['description' => 'Confirmação da senha (opcional — usada a mesma da password)'],
                    ],
                    'required' => ['clientid', 'password'],
                ],
            ],

            // ==================== PRODUCTS ====================
            [
                'name' => 'get_products',
                'command' => 'GetProducts',
                'title' => 'Listar produtos',
                'label' => 'List products',
                'group' => 'product',
                'description' => 'Lista produtos/serviços configurados no WHMCS (planos, grupos, preços).',
                'write' => false,
                'schema' => [
                    'type' => 'object',
                    'properties' => [
                        'gid' => $int + ['description' => 'Filtra por grupo de produtos'],
                        'pid' => $int + ['description' => 'Retorna apenas o produto com este ID'],
                    ],
                ],
            ],

            // ==================== SERVICE MANAGEMENT ====================
            [
                'name' => 'get_services',
                'command' => 'GetClientsProducts',
                'title' => 'Listar serviços dos clientes',
                'label' => 'List client services',
                'group' => 'service',
                'description' => 'Lista produtos/serviços ativos dos clientes, com filtro por cliente e status.',
                'write' => false,
                'schema' => [
                    'type' => 'object',
                    'properties' => [
                        'clientid' => $int + ['description' => 'Filtra por cliente'],
                        'status' => $string + ['description' => 'Active | Pending | Suspended | Terminated | Cancelled'],
                    ],
                ],
            ],
            [
                'name' => 'get_domains',
                'command' => 'GetDomains',
                'title' => 'Listar domínios',
                'label' => 'List domains',
                'group' => 'service',
                'description' => 'Lista domínios registrados/transferidos com status, datas de expiração e auto-renew.',
                'write' => false,
                'schema' => [
                    'type' => 'object',
                    'properties' => [
                        'clientid' => $int + ['description' => 'Filtra por cliente'],
                        'status' => $string + ['description' => 'Active | Pending | Expired | Cancelled'],
                    ],
                ],
            ],
            [
                'name' => 'unsuspend_service',
                'command' => 'ModuleUnsuspend',
                'title' => 'Reativar serviço',
                'label' => 'Unsuspend service',
                'group' => 'service',
                'description' => 'Reativa um serviço suspenso (ex: após confirmação de pagamento). Chama o módulo do servidor (ModuleUnsuspend). Use serviceid do serviço.',
                'write' => true,
                'schema' => [
                    'type' => 'object',
                    'properties' => [
                        'serviceid' => $int + ['description' => 'ID do serviço (produto do cliente)'],
                    ],
                    'required' => ['serviceid'],
                ],
            ],
            [
                'name' => 'suspend_service',
                'command' => 'ModuleSuspend',
                'title' => 'Suspender serviço',
                'label' => 'Suspend service',
                'group' => 'service',
                'description' => 'Suspende um serviço ativo (ex: falta de pagamento). Chama o módulo do servidor (ModuleSuspend).',
                'write' => true,
                'schema' => [
                    'type' => 'object',
                    'properties' => [
                        'serviceid' => $int + ['description' => 'ID do serviço'],
                        'suspendreason' => $string + ['description' => 'Motivo da suspensão (opcional)'],
                    ],
                    'required' => ['serviceid'],
                ],
            ],
            [
                'name' => 'update_service_password',
                'command' => 'UpdateClientProduct',
                'title' => 'Trocar senha do serviço',
                'label' => 'Update service password',
                'group' => 'service',
                'description' => 'Troca a senha do serviço/plano do cliente (ex: senha do painel do produto, tipo cPanel). Atualiza no WHMCS e aplica no módulo do servidor quando aplicável.',
                'write' => true,
                'schema' => [
                    'type' => 'object',
                    'properties' => [
                        'serviceid' => $int + ['description' => 'ID do serviço (produto do cliente)'],
                        'password' => $string + ['description' => 'Nova senha do serviço (mín 8 caracteres)'],
                    ],
                    'required' => ['serviceid', 'password'],
                ],
            ],

            // ==================== INVOICE MANAGEMENT ====================
            [
                'name' => 'get_invoices',
                'command' => 'GetInvoices',
                'title' => 'Listar faturas',
                'label' => 'List invoices',
                'group' => 'invoice',
                'description' => 'Lista faturas com filtros por cliente, status e datas. status: Paid, Unpaid, Cancelled, Refunded, Collections.',
                'write' => false,
                'schema' => [
                    'type' => 'object',
                    'properties' => [
                        'userid' => $int + ['description' => 'Filtra por cliente'],
                        'status' => $string + ['description' => 'Paid | Unpaid | Cancelled | Refunded | Collections'],
                        'limitnum' => $int + ['description' => 'Máximo de registros'],
                    ],
                ],
            ],
            [
                'name' => 'get_invoice',
                'command' => 'GetInvoice',
                'title' => 'Detalhes da fatura',
                'label' => 'Get invoice by ID',
                'group' => 'invoice',
                'description' => 'Retorna uma fatura com itens, totais, status e histórico de pagamentos.',
                'write' => false,
                'schema' => [
                    'type' => 'object',
                    'properties' => [
                        'invoiceid' => $int + ['description' => 'ID da fatura'],
                    ],
                    'required' => ['invoiceid'],
                ],
            ],
            [
                'name' => 'get_transactions',
                'command' => 'GetTransactions',
                'title' => 'Listar transações',
                'label' => 'List transactions',
                'group' => 'invoice',
                'description' => 'Lista transações financeiras (pagamentos recebidos).',
                'write' => false,
                'schema' => [
                    'type' => 'object',
                    'properties' => [
                        'userid' => $int + ['description' => 'Filtra por cliente'],
                        'limitnum' => $int + ['description' => 'Máximo de registros'],
                    ],
                ],
            ],
            [
                'name' => 'create_invoice',
                'command' => 'CreateInvoice',
                'title' => 'Criar fatura',
                'label' => 'Create invoice',
                'group' => 'invoice',
                'description' => 'Cria uma fatura para um cliente com itens (description + amount) e vencimento.',
                'write' => true,
                'schema' => [
                    'type' => 'object',
                    'properties' => [
                        'userid' => $int + ['description' => 'ID do cliente'],
                        'paymentmethod' => $string + ['description' => 'Método de pagamento (ex: paypal, banktransfer)'],
                        'date' => $string + ['description' => 'Data de emissão (YYYY-MM-DD)'],
                        'duedate' => $string + ['description' => 'Vencimento (YYYY-MM-DD)'],
                        'itemdescription1' => $string + ['description' => 'Descrição do item 1'],
                        'itemamount1' => $string + ['description' => 'Valor do item 1'],
                        'itemtaxed1' => $string + ['description' => '0 ou 1 (incide imposto)'],
                    ],
                    'required' => ['userid', 'itemdescription1', 'itemamount1'],
                ],
            ],
            [
                'name' => 'add_invoice_payment',
                'command' => 'AddInvoicePayment',
                'title' => 'Registrar pagamento',
                'label' => 'Add invoice payment',
                'group' => 'invoice',
                'description' => 'Registra um pagamento recebido em uma fatura (totalmente ou parcial).',
                'write' => true,
                'schema' => [
                    'type' => 'object',
                    'properties' => [
                        'invoiceid' => $int + ['description' => 'ID da fatura'],
                        'transid' => $string + ['description' => 'ID da transação'],
                        'gateway' => $string + ['description' => 'Gateway (ex: paypal, banktransfer)'],
                        'date' => $string + ['description' => 'Data do pagamento (YYYY-MM-DD)'],
                        'amount' => $string + ['description' => 'Valor (omitir para fatura inteira)'],
                    ],
                    'required' => ['invoiceid', 'transid', 'gateway'],
                ],
            ],

            // ==================== ORDER MANAGEMENT ====================
            [
                'name' => 'get_orders',
                'command' => 'GetOrders',
                'title' => 'Listar pedidos',
                'label' => 'List orders',
                'group' => 'order',
                'description' => 'Lista pedidos com filtro por cliente e status (Pending, Active, Cancelled, Fraud).',
                'write' => false,
                'schema' => [
                    'type' => 'object',
                    'properties' => [
                        'userid' => $int + ['description' => 'Filtra por cliente'],
                        'status' => $string + ['description' => 'Pending | Active | Cancelled | Fraud'],
                        'limitnum' => $int + ['description' => 'Máximo de registros'],
                    ],
                ],
            ],
            [
                'name' => 'get_order',
                'command' => 'GetOrder',
                'title' => 'Detalhes do pedido',
                'label' => 'Get order by ID',
                'group' => 'order',
                'description' => 'Retorna um pedido com itens, status e informações de pagamento.',
                'write' => false,
                'schema' => [
                    'type' => 'object',
                    'properties' => [
                        'id' => $int + ['description' => 'ID do pedido'],
                    ],
                    'required' => ['id'],
                ],
            ],
            [
                'name' => 'accept_order',
                'command' => 'AcceptOrder',
                'title' => 'Aprovar pedido',
                'label' => 'Accept order',
                'group' => 'order',
                'description' => 'Aprova um pedido pendente, ativando os serviços.',
                'write' => true,
                'schema' => [
                    'type' => 'object',
                    'properties' => [
                        'orderid' => $int + ['description' => 'ID do pedido'],
                        'sendemail' => ['type' => 'boolean', 'description' => 'Enviar email de confirmação (default true)'],
                    ],
                    'required' => ['orderid'],
                ],
            ],
            [
                'name' => 'cancel_order',
                'command' => 'CancelOrder',
                'title' => 'Cancelar pedido',
                'label' => 'Cancel order',
                'group' => 'order',
                'description' => 'Cancela um pedido pendente.',
                'write' => true,
                'schema' => [
                    'type' => 'object',
                    'properties' => [
                        'orderid' => $int + ['description' => 'ID do pedido'],
                    ],
                    'required' => ['orderid'],
                ],
            ],

            // ==================== TICKET MANAGEMENT ====================
            [
                'name' => 'get_tickets',
                'command' => 'GetTickets',
                'title' => 'Listar tickets',
                'label' => 'List tickets',
                'group' => 'ticket',
                'description' => 'Lista tickets de suporte com filtro por cliente, departamento e status.',
                'write' => false,
                'schema' => [
                    'type' => 'object',
                    'properties' => [
                        'userid' => $int + ['description' => 'Filtra por cliente'],
                        'deptid' => $int + ['description' => 'Filtra por departamento'],
                        'status' => $string + ['description' => 'Open | Answered | Customer-Reply | Closed | On Hold | In Progress'],
                        'limitnum' => $int + ['description' => 'Máximo de registros'],
                    ],
                ],
            ],
            [
                'name' => 'get_ticket',
                'command' => 'GetTicket',
                'title' => 'Detalhes do ticket',
                'label' => 'Get ticket by ID',
                'group' => 'ticket',
                'description' => 'Retorna um ticket com todas as respostas da conversa.',
                'write' => false,
                'schema' => [
                    'type' => 'object',
                    'properties' => [
                        'ticketid' => $int + ['description' => 'ID do ticket'],
                    ],
                    'required' => ['ticketid'],
                ],
            ],
            [
                'name' => 'open_ticket',
                'command' => 'OpenTicket',
                'title' => 'Abrir ticket',
                'label' => 'Open ticket',
                'group' => 'ticket',
                'description' => 'Abre um novo ticket de suporte. Obrigatórios: deptid, subject, message, clientid.',
                'write' => true,
                'schema' => [
                    'type' => 'object',
                    'properties' => [
                        'clientid' => $int + ['description' => 'ID do cliente'],
                        'deptid' => $int + ['description' => 'ID do departamento'],
                        'subject' => $string + ['description' => 'Assunto'],
                        'message' => $string + ['description' => 'Mensagem'],
                        'priority' => $string + ['description' => 'Low | Medium | High'],
                        'customfields' => [
                            'type' => 'object',
                            'additionalProperties' => ['type' => 'string'],
                            'description' => 'Campos customizados do ticket: mapa {id_do_campo: valor}',
                        ],
                    ],
                    'required' => ['clientid', 'deptid', 'subject', 'message'],
                ],
            ],
            [
                'name' => 'update_ticket',
                'command' => 'UpdateTicket',
                'title' => 'Responder ticket',
                'label' => 'Reply to ticket',
                'group' => 'ticket',
                'description' => 'Adiciona resposta a um ticket ou altera status/prioridade. Use ticketid + message.',
                'write' => true,
                'schema' => [
                    'type' => 'object',
                    'properties' => [
                        'ticketid' => $int + ['description' => 'ID do ticket'],
                        'message' => $string + ['description' => 'Resposta a adicionar'],
                        'status' => $string + ['description' => 'Novo status (Open, Answered, Closed...)'],
                        'priority' => $string + ['description' => 'Low | Medium | High'],
                    ],
                    'required' => ['ticketid'],
                ],
            ],
            [
                'name' => 'get_support_departments',
                'command' => 'GetSupportDepartments',
                'title' => 'Departamentos de suporte',
                'label' => 'List support departments',
                'group' => 'ticket',
                'description' => 'Lista os departamentos de suporte configurados.',
                'write' => false,
                'schema' => ['type' => 'object'],
            ],
            [
                'name' => 'get_support_statuses',
                'command' => 'GetSupportStatuses',
                'title' => 'Status de suporte',
                'label' => 'List support statuses',
                'group' => 'ticket',
                'description' => 'Lista os status de ticket configurados.',
                'write' => false,
                'schema' => ['type' => 'object'],
            ],

            // ==================== SYSTEM ====================
            [
                'name' => 'get_activity_log',
                'command' => 'GetActivityLog',
                'title' => 'Log de atividades',
                'label' => 'Get activity log',
                'group' => 'system',
                'description' => 'Retorna o log de atividades do sistema, com filtro por usuário.',
                'write' => false,
                'schema' => [
                    'type' => 'object',
                    'properties' => [
                        'userid' => $int + ['description' => 'Filtra por usuário'],
                        'limitnum' => $int + ['description' => 'Máximo de registros'],
                    ],
                ],
            ],
        ];
    }

    /**
     * Apenas tools de leitura.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function readDefinitions(): array
    {
        return array_values(array_filter(
            self::definitions(),
            fn(array $t): bool => empty($t['write'])
        ));
    }

    /**
     * Todas as tools, incluindo escrita (quando habilitadas).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function activeDefinitions(bool $allowWrite): array
    {
        if (!$allowWrite) {
            return self::readDefinitions();
        }

        return self::definitions();
    }

    /**
     * Tools agrupadas por categoria.
     *
     * @return array<string, array<int, array<string, mixed>>> group => tools
     */
    public static function byGroup(): array
    {
        $groups = [];
        foreach (self::definitions() as $def) {
            $groups[$def['group']][] = $def;
        }

        return $groups;
    }
}
