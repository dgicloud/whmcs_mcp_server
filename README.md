# WHMCS MCP Server

Addon para WHMCS que expõe ferramentas MCP por HTTP em
`/modules/addons/whmcs_mcp_server/mcp.php`.

## Requisito importante

O diretório `modules/addons/whmcs_mcp_server/vendor/` **não faz parte do pacote
fonte** e, portanto, não estará presente após baixar/clonar este repositório. É
um artefato gerado pelo Composer a partir de `composer.lock`. Ele é necessário
em execução, pois o endpoint MCP carrega `vendor/autoload.php`, mas não deve ser
versionado nem copiado entre versões arbitrárias do módulo.

O módulo exige PHP 8.1 ou superior, uma instalação funcional do WHMCS e acesso
de escrita do processo PHP em `modules/addons/whmcs_mcp_server/storage/`.

## Instalação inicial ou reinstalação

1. Faça backup do banco de dados e, se houver chaves MCP que precisam ser
   preservadas, não exclua as tabelas `mod_whmcs_mcp_*`.
2. Copie a pasta deste repositório `modules/addons/whmcs_mcp_server` para:

   ```text
   <WHMCS_ROOT>/modules/addons/whmcs_mcp_server
   ```

   Copie `composer.json`, `composer.lock`, `lib/`, `templates/`, `scripts/`,
   `mcp.php`, `hooks.php` e `whmcs_mcp_server.php`. Não copie `vendor/`,
   `storage/sessions/` nem `error_log` de outra instalação. O passo seguinte
   recria o `vendor/` correto para esta versão.
3. No mesmo ambiente PHP que executa o WHMCS, entre na pasta do addon e recrie
   as dependências travadas:

   ```bash
   cd <WHMCS_ROOT>/modules/addons/whmcs_mcp_server
   composer install --no-dev --prefer-dist --optimize-autoloader
   ```

   O `composer.lock` fixa atualmente `mcp/sdk` em `v0.7.0` e o comando executa
   automaticamente `scripts/fix-vendor-compat.php`. Esse ajuste é obrigatório
   para a compatibilidade do SDK com o `psr/container` 1.x carregado pelo WHMCS.
4. Confirme que os métodos no arquivo instalado abaixo não têm tipos nos
   parâmetros/retorno:

   ```text
   vendor/mcp/sdk/src/Capability/Registry/Container.php
   public function get($id)
   public function has($id)
   ```

5. Garanta permissão de escrita para o usuário do PHP-FPM/Apache:

   ```bash
   mkdir -p storage/sessions
   chown -R <usuario-web>:<grupo-web> storage
   chmod -R u+rwX,g+rwX storage
   ```

6. No admin do WHMCS, acesse **Configuração do Sistema > Módulos Addon**,
   ative **WHMCS MCP Server** e conceda acesso somente aos administradores
   necessários. A ativação cria as tabelas e uma chave MCP inicial, sem apagar
   dados de instalações anteriores.
7. Consulte **Addons > WHMCS MCP Server**, crie/recupere uma chave e configure
   o cliente MCP para usar `https://<dominio-whmcs>/modules/addons/whmcs_mcp_server/mcp.php`
   com `Authorization: Bearer <chave>`.

## Atualização e recuperação

- Para atualizar código, preserve `composer.lock` e execute novamente
  `composer install`, não `composer update`.
- Se `vendor/` estiver ausente — o estado esperado no pacote-fonte — repita o
  passo 3; não é necessário rodar a ativação do addon outra vez.
- Se o painel mostrar o erro de assinatura de `Container::get()` ou
  `Container::has()`, execute manualmente:

  ```bash
  php scripts/fix-vendor-compat.php
  ```

- Desativar e reativar o addon preserva chaves, auditoria e configurações. A
  remoção das tabelas `mod_whmcs_mcp_*` é uma operação destrutiva e não faz parte
  de uma reinstalação normal.

## Verificação rápida

```bash
test -f vendor/autoload.php
php scripts/fix-vendor-compat.php
```

Em seguida, confirme no WHMCS que o addon está ativo e faça uma chamada MCP de
teste com uma chave válida. Não exponha a chave em logs, commits ou tickets.
