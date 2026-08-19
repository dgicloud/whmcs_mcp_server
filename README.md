# WHMCS MCP Server

Addon para WHMCS que disponibiliza um servidor MCP (Model Context Protocol) por
HTTP. Ele permite que um cliente MCP autenticado consulte e, quando autorizado,
execute operações do WHMCS usando a API interna (`localAPI`).

O endpoint do módulo é:

```text
https://<dominio-do-whmcs>/modules/addons/whmcs_mcp_server/mcp.php
```

## O que o módulo oferece

- Catálogo MCP de ferramentas para clientes, produtos, serviços, domínios,
  faturas, transações, pedidos, tickets e log de atividades.
- Operações de leitura disponíveis por padrão.
- Operações de escrita controladas globalmente e por ferramenta, como criar ou
  atualizar clientes, suspender/reativar serviços, criar faturas, registrar
  pagamentos, aprovar/cancelar pedidos e abrir/responder tickets.
- Múltiplos tokens de acesso com rótulo, revogação e registro de último uso.
- Log de auditoria de chamadas, erros e falhas de autenticação, com filtros,
  exportação CSV e limpeza manual.
- Allowlist de IPs, validade configurável das sessões MCP e alertas de auditoria
  por e-mail ou Telegram.
- Limitação por IP de 300 requisições por minuto e, adicionalmente, de 20
  falhas de autenticação a cada 5 minutos.

## Requisitos

- WHMCS instalado e funcional.
- PHP 8.1 ou superior, com as extensões exigidas pelo WHMCS e pelo Composer.
- Composer disponível no mesmo ambiente PHP que executa o WHMCS.
- Permissão de escrita do processo PHP em
  `modules/addons/whmcs_mcp_server/storage/`.

## Instalação

1. Copie a pasta do módulo para o diretório de addons do WHMCS:

   ```text
   <WHMCS_ROOT>/modules/addons/whmcs_mcp_server
   ```

2. No diretório do módulo, instale as dependências definidas no projeto:

   ```bash
   cd <WHMCS_ROOT>/modules/addons/whmcs_mcp_server
   composer install --no-dev --prefer-dist --optimize-autoloader
   ```

   O comando também executa automaticamente o ajuste de compatibilidade do
   módulo com o `psr/container` carregado pelo WHMCS.

3. Garanta a escrita no armazenamento de sessões pelo usuário do servidor web:

   ```bash
   mkdir -p storage/sessions
   chown -R <usuario-web>:<grupo-web> storage
   chmod -R u+rwX,g+rwX storage
   ```

4. No painel administrativo do WHMCS, acesse **Configuração do Sistema >
   Módulos Addon**, localize **WHMCS MCP Server**, clique em **Ativar** e marque
   somente os administradores que poderão abrir o painel do módulo.

5. Abra **Addons > WHMCS MCP Server**. A ativação cria as tabelas de
   configuração, ferramentas, tokens e auditoria. Uma chave inicial é criada
   automaticamente apenas quando ainda não existir nenhuma chave.

6. Na aba **API Keys**, gere uma chave com um rótulo identificável, por exemplo
   `Assistente de suporte` ou `Claude Code - produção`.

## Tokens de acesso

Os clientes MCP devem enviar o token no cabeçalho HTTP:

```text
Authorization: Bearer <TOKEN>
```

Ao gerar uma chave, o valor completo é exibido somente uma vez. Copie-o e
armazene-o em um gerenciador de segredos. O módulo mantém no banco apenas o hash
da chave e mostra somente um prefixo mascarado no painel.

Para interromper o acesso de um cliente, revogue a chave correspondente na aba
**API Keys**. A revogação tem efeito imediato. Crie uma chave distinta para cada
integração ou ambiente, evitando o compartilhamento de credenciais.

## Permissões e segurança

O acesso ao painel administrativo é controlado pela permissão nativa de addon
do WHMCS, definida no momento da ativação. Somente administradores selecionados
ali devem administrar tokens, ferramentas, configurações e auditoria.

No endpoint MCP, a autorização é formada por dois controles:

1. Um token Bearer ativo e válido é obrigatório.
2. Se a allowlist de IPs estiver preenchida em **Settings**, o IP de origem
   também deve constar nela. Uma allowlist vazia permite qualquer IP que possua
   um token válido.

O endpoint não autoriza chamadas de páginas em outras origens por CORS. Isso não
afeta clientes MCP que se conectam diretamente por HTTP.

Os tokens não possuem escopos individuais. Cada token ativo recebe exatamente o
conjunto de ferramentas globalmente exposto. Para reduzir o acesso:

- Mantenha **Tools de escrita** desativado; esse é o padrão do módulo.
- Em **Tool Management**, habilite somente as ferramentas e grupos necessários.
  Uma ferramenta desabilitada deixa de aparecer no catálogo MCP e é rejeitada
  quando um cliente tenta chamá-la.
- Habilite ferramentas de escrita apenas após revisar as permissões e o impacto
  operacional. Elas incluem alterações de dados e ações em serviços, faturas,
  pedidos e tickets.

As chamadas autenticadas, erros e tentativas com token inválido são registradas
na aba **Audit Log** com ferramenta, status, rótulo da chave, IP, sessão e tempo
de execução. Os argumentos sensíveis, como senhas e tokens, são mascarados no
registro de auditoria.

## Configurações do painel

Na aba **Settings** é possível configurar:

- Liberação global das ferramentas de escrita.
- Allowlist de IPs, separada por vírgulas.
- TTL de sessão MCP, entre 60 e 86.400 segundos; o padrão é 7.200 segundos.
- Alertas de auditoria para erros de ferramentas e falhas de autenticação.
- Destinatário de e-mail e integração Telegram, ambos opcionais.
- Janela de rate limit de alertas, entre 1 e 1.440 minutos.

## Atualização e recuperação

Ao atualizar o módulo, preserve `composer.lock` e execute novamente:

```bash
composer install --no-dev --prefer-dist --optimize-autoloader
```

Não use `composer update` em produção, pois ele pode trocar versões das
dependências. Desativar e reativar o addon preserva tokens, configurações e
auditoria; não remova as tabelas `mod_whmcs_mcp_*` em uma reinstalação normal.

## Verificação após a instalação

1. Confirme que o addon aparece como ativo em **Módulos Addon**.
2. Abra o painel em **Addons > WHMCS MCP Server** e gere um token de teste.
3. Verifique o endpoint usando o cabeçalho `Authorization: Bearer <TOKEN>`.
4. Confira o resultado na aba **Audit Log**.

Nunca envie tokens em tickets, e-mails, commits ou logs externos.
