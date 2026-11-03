# MedelFiguri — auditoria técnica

**Data da auditoria:** 28/10/2026  
**Escopo:** auditoria estática, migrations no banco isolado MedelFiguri,
seeders de desenvolvimento e validação das rotas da API. Nenhuma migration
destrutiva, remoção de tabela ou volume foi executada.

## Implementação — visualização interativa de models 3D

**Executada em:** 29/10/2026.

- Integrado o componente oficial `@google/model-viewer` **4.4.0** como
  dependência local do frontend e incluído no estágio de build da imagem
  Nginx. Não há CDN em runtime nem alteração de framework ou backend.
- Os cards usam os caminhos já presentes em `product.images[].path`: oferecem
  o modal 3D somente para `.glb`/`.gltf` e mostram imagens raster cadastradas
  (JPEG/PNG/WebP) no card. Produtos sem modelo não recebem um visualizador.
- O modelo só é importado e requisitado após o clique. O modal traz controles
  de câmera por mouse/toque, zoom, enquadramento inicial, fundo neutro,
  iluminação/sombras, prévia raster, progresso e mensagem de falha; rotação
  automática é opcional e inicia desligada. O elemento nativo `dialog` permite
  fechar por botão/Escape, restringe a interação ao modal e restaura o foco.
  Ao fechar, remove a fonte do modelo para liberar os recursos de renderização.
- A leitura pública da API confirmou estes produtos/modelos: Render dbz
  (`MODEL-DBZ`, `dbz.glb`), Render friren (`MODEL-FRIREN`, `friren.glb`),
  Render gatobotas (`MODEL-GATOBOTAS`, `gatobotas.glb`), Render lufyHead
  (`MODEL-LUFYHEAD`, `lufyHead.glb`), Render mander (`MODEL-MANDER`,
  `mander.glb`), Render mario (`MODEL-MARIO`, `mario.glb`) e Render zard
  (`MODEL-ZARD`, `zard.glb`). A aplicação usa o caminho de imagem retornado
  pela API, não uma tabela fixa de arquivos. Os arquivos variam de cerca de
  9,9 MB a 149,3 MB, portanto o carregamento sob demanda evita downloads
  antecipados, mas modelos grandes ainda podem levar tempo em redes lentas.
- O teste de API para preservar os caminhos de imagens do catálogo foi
  adicionado. `php artisan test`: **90 testes e 511 assertions aprovados**;
  Pint, sintaxe de todos os JavaScripts, `composer validate`,
  `docker compose config --quiet`, build da imagem Nginx e `npm audit --omit=dev`
  passaram (zero vulnerabilidades).
- Smoke test real via Nginx com a API existente: o catálogo retornou 15
  produtos; sete tinham botão 3D e oito corretamente não tinham. `Render
  friren` carregou de `/storage/friren.glb`; o elemento reportou
  `loaded=true`/`modelIsVisible=true`, confirmando parse e renderização, não
  apenas HTTP 200. A API e o proxy serviram o GLB com `model/gltf-binary`.
- O frontend atualizado foi iniciado no Compose em `localhost:8080`; backend e
  MySQL permaneceram saudáveis. Não foram executadas migrations/seeders nem
  alterados volumes.
- Interação testada no navegador: arrasto alterou os ângulos horizontal e
  vertical; roda do mouse alterou a distância da câmera; rotação automática
  movimentou a figure após a pausa padrão; reset voltou ao enquadramento
  inicial. Escape fechou o modal, limpou a propriedade `src`, descarregou o
  modelo e devolveu foco ao botão de abertura. O modelo também carregou em
  viewport 390×844; o modal coube em 390×844 e 320×740 sem overflow horizontal.
- Um produto com apenas imagem raster e um modelo indisponível foram
  simulados por respostas interceptadas no navegador: o primeiro não ofereceu
  botão 3D e exibiu a imagem; o segundo apresentou erro claro e manteve a
  imagem como alternativa. A imagem usada era uma fixture em memória, pois o
  catálogo atual não tem imagens raster. A ação de adicionar ao carrinho foi
  exercitada com APIs interceptadas e enviou o ID/quantidade esperados, sem
  mutação persistente.
- O GLB `dbz.glb` de 103 MB ainda estava carregando após 45 segundos; os outros
  cinco arquivos além de `friren.glb` e `dbz.glb` não tiveram renderização
  completa confirmada. Os modelos grandes e a interação por toque em aparelho
  real permanecem pendentes de validação manual; o atributo `camera-controls`
  e o layout responsivo estão habilitados.
- A leitura dos arquivos revelou que `CatalogSeeder` estava truncado e fazia
  apenas a checagem de assets; a suíte completa falhou por isso. O
  comportamento idempotente documentado foi restaurado e seu teste passou
  com `Storage::fake`. O seeder não foi executado no banco real; nenhum dado
  do catálogo, migration ou volume foi alterado. O SKU prefixo `RENDER-` do
  seeder restaurado difere dos `MODEL-` encontrados na API em execução; essa
  divergência de dados não foi reconciliada nesta etapa para preservar o
  catálogo atual.

## Implementação — etapas 10 a 15

**Executada em:** 29/10/2026.

- **Pagamento simulado:** `POST /api/orders/{id}/confirm-payment` confirma
  `pending → paid`, define método `simulated` e grava `paid_at` sob lock
  transacional. Repetir retorna sucesso idempotente; pedidos de terceiros
  retornam `404`, estados incompatíveis `422`. A API proíbe campos conhecidos
  de cartão/senha bancária; não há gateway nem tabela financeira.
- **Pedidos e cancelamento:** listagem paginada e detalhes usam o usuário
  autenticado e ocultam pedidos alheios com `404`. Cancelamento é permitido
  somente em `pending`/`paid`; restaura movimentos `sale` em transação e
  registra `return`, sem duplicar em repetição. Pedidos sem baixa não ganham
  devolução fictícia. O painel admin pode avançar exclusivamente
  `paid → processing → shipped → delivered`.
- **Frontend de compras:** implementadas as páginas `/checkout.html`,
  `/meus-pedidos.html` e `/pedido.html`, com CSRF/sessão usando o cliente
  existente. A página do carrinho agora oferece checkout quando há itens
  disponíveis. O pagamento é identificado como simulado e não pede cartão.
- **Administração:** rotas `/api/admin/*` protegidas por `auth:sanctum` e
  `role:admin` para catálogo, categorias, uploads de imagens, estoque,
  movimentações e estados de pedidos. Produtos/categorias são arquivados,
  não apagados. Ajustes de estoque geram ledger e não podem negativar o estoque.
  Upload aceita somente JPEG/PNG/WebP até 5 MiB no storage público.
  `/admin.html` fornece formulários conectados às APIs.
- **OpenAPI:** documento OpenAPI 3.1 em `/openapi.json` e Swagger UI em
  `/swagger.html`, com arquivos da interface servidos localmente. O interceptor
  solicita cookie CSRF e usa cookies de sessão na mesma origem.
- Nenhuma migration foi necessária: `orders.paid_at` já existe. Não foram
  executados seeders, alterados registros do banco de desenvolvimento ou
  removidos volumes.
- Testes adicionados em `OrderApiTest`, `AdminApiTest` e
  `CheckoutApiTest`. Validação final: **89 testes e 507 assertions aprovados**;
  Pint nos arquivos PHP alterados, sintaxe JavaScript, JSON OpenAPI,
  `docker compose config --quiet` e `composer validate` passaram. Os builds
  de produção do backend e frontend também passaram.
- Swagger UI foi aberto no navegador usando Nginx isolado e temporário; a
  página e o documento OpenAPI (30 paths) responderam corretamente. Esse teste
  serviu apenas os arquivos estáticos e não validou o proxy/API, login ou
  checkout integrado via Nginx.
- A validação final não iniciou os serviços Compose nem alterou banco, seeders
  ou volumes.
- Limitações: concorrência simultânea não pode ser exercitada com locking real
  no SQLite em memória dos testes; a confirmação ainda não tem gateway real,
  o frete segue grátis e não há fluxos de estorno financeiro, recuperação de
  senha ou verificação de e-mail.

## Implementação — checkout da API

**Executada em:** 29/10/2026.

- Implementado `POST /api/checkout` dentro do grupo `auth:sanctum`. O usuário
  vem da sessão; o endpoint localiza apenas o carrinho ativo da própria conta,
  sem aceitar IDs de usuário/carrinho nem valores monetários como fonte de
  verdade.
- `CheckoutService` valida carrinho, disponibilidade e quantidades, calcula
  valores com a aritmética decimal exata já usada pelo carrinho, cria pedido e
  snapshots de itens/endereço, desconta estoque e converte o carrinho dentro
  de uma única transação. Não foi necessária migration.
- `InventoryService` centraliza validação/baixa de estoque e cria uma
  movimentação `sale` negativa por produto, associada ao pedido e ao usuário.
  Falhas durante o processamento revertem pedido, itens, endereço, estoque,
  movimentos e status do carrinho.
- Como ainda não há API funcional de endereços salvos, o endpoint exige os
  campos do endereço e grava um snapshot em `order_addresses`. O frete fica
  fixo em R$ 0,00 nesta versão; pagamento ainda não é integrado e o pedido
  recebe status/método `pending`.
- `OrderResource` retorna dados públicos do pedido, método/status de pagamento,
  snapshot do endereço e itens, sem expor o proprietário. A implementação
  inicial do backend não incluía a interface; ela foi adicionada na etapa 10–15
  descrita acima.
- Adicionados oito testes de API cobrindo autenticação, carrinho vazio,
  isolamento de conta, dados adulterados, preço histórico, endereço, estoque,
  produtos inativos e rollback de falha.
- Validação: **9 testes de checkout aprovados, 69 assertions**; suíte completa
  com **69 testes e 379 assertions aprovados**. Pint passou nos arquivos PHP
  adicionados/modificados; rota `POST /api/checkout` registrada.

## Implementação — interface e recuperação do carrinho

**Executada em:** 28/10/2026.

- Criada a página responsiva `frontend/carrinho.html` e sua lógica em
  `frontend/cart.js`. O catálogo permite adicionar produtos disponíveis; o
  cabeçalho mostra a soma das unidades confirmada pela API; a página consulta
  preços/subtotais do servidor, atualiza quantidades e remove itens. O checkout
  permanece visivelmente desabilitado.
- O login redireciona para o carrinho quando há unidades fundidas ou quando a
  fusão precisa de atenção. A interface reconstrói conflitos após recarga com
  os carrinhos e a disponibilidade consultados na API; apresenta os carrinhos
  da conta e de visitante separadamente, permite reduzir quantidade ou remover
  itens indisponíveis e oferece uma nova tentativa de fusão.
- A inspeção confirmou que `GET /api/cart` autenticado seleciona o carrinho da
  conta e não permite corrigir a origem. Foram adicionadas rotas autenticadas
  `GET /api/cart/guest`, `PATCH /api/cart/guest/items/{id}`,
  `DELETE /api/cart/guest/items/{id}` e `POST /api/cart/merge-guest`.
  Elas identificam a origem somente pelo cookie HttpOnly existente; cookie
  ausente/inválido e itens de outro carrinho não são aceitos. A fusão continua
  transacional, preserva cookie/carrinhos em conflito e invalida o cookie
  somente em sucesso. Nenhuma migration foi criada.
- `CartItemResource` também fornece disponibilidade e imagem raster opcional,
  sem expor o token; arquivos de modelo 3D não são servidos como imagens. O
  resumo formata valores em reais sem aritmética decimal de ponto flutuante.
  Remover/alterar carrinhos não altera o estoque.
- Adicionados oito testes em `GuestCartRecoveryTest` cobrindo leitura após
  login com conflito, atualização e remoção, isolamento de propriedade, cookie
  inválido, autenticação obrigatória, nova tentativa, cookie após sucesso,
  idempotência e estoque inalterado.
- `php artisan test` antes das mudanças: **52 testes, 261 assertions**.
  Depois: **60 testes, 310 assertions aprovados**. `node --check` passou nos
  seis arquivos JavaScript alterados. `vendor/bin/pint --test` passou nos
  arquivos PHP modificados. A verificação Pint do repositório inteiro ainda
  aponta formatação preexistente em migrations, arquivos Stuff e outros
  arquivos não relacionados; eles não foram alterados.
- `docker compose up -d --build backend frontend` concluiu; backend e MySQL
  ficaram `healthy`, frontend ficou em execução. `docker compose config --quiet`
  passou, a lista de rotas mostrou as quatro novas operações, `/up` respondeu
  HTTP 200 e `/sanctum/csrf-cookie` respondeu HTTP 204.
- Integração pelo Nginx/browser: inclusão de produto real, reconstrução do
  carrinho após navegação/recarregamento, incremento, decremento, remoção,
  atualização do contador e subtotal BRL passaram. A página não teve overflow
  horizontal nos viewports testados de 320, 375, 768 e 1280 px. O item e o
  carrinho vazio criados exclusivamente para o primeiro teste foram removidos.
  Um segundo cenário usou uma conta temporária (sem usar o administrador) e
  estoque de demonstração: login preservado, conflito visível, redução da
  quantidade visitante, nova fusão e subtotal final correto passaram. A conta,
  carrinhos, itens e sessão temporários foram removidos por identificadores
  exatos. Os testes automatizados verificam estoque inalterado; os produtos
  usados permaneceram com estoque 8 e 3, respectivamente.
- Nenhuma migration, seeder, checkout, pedido, pagamento ou operação de estoque
  foi executada. Nenhum volume foi removido.
- Arquivos criados: `frontend/carrinho.html`, `frontend/cart.js`,
  `frontend/cart-state.js` e `tests/Feature/GuestCartRecoveryTest.php`.
- Arquivos modificados: `app/Services/CartService.php`,
  `app/Http/Resources/CartItemResource.php`,
  `app/Http/Controllers/Api/CartController.php`, `routes/api.php`,
  `frontend/index.html`, `frontend/app.js`, `frontend/api.js`,
  `frontend/login.js`, `frontend/login.html`, `frontend/cadastro.js`,
  `frontend/cadastro.html`, `frontend/styles.css`, `README.md` e este arquivo.
- Pendências: checkout, cálculo de frete, pedidos, pagamento simulado, reserva
  ou baixa de estoque. Não foram marcados como concluídos.

## Implementação — fusão do carrinho no login

**Executada em:** 28/10/2026.

- O `AuthService` autentica e regenera a sessão antes de chamar
  `CartService::mergeGuestCart`, usando o usuário verificado pelo guard e o
  cookie HttpOnly `medelfiguri_guest_cart`. Não houve alteração na lógica de
  credenciais, no fluxo de logout ou no cliente do frontend.
- A resposta de login preserva `data` com o Resource do usuário e acrescenta
  `cart_merge` no nível superior: `merged`, `no_guest_cart` ou
  `needs_attention`. Conflitos incluem somente id/nome do produto, quantidade
  resultante, disponibilidade atual e razão. Falha inesperada da fusão é
  registrada sem token/cookie no log e mantém o login válido com
  `needs_attention` e motivo genérico `merge_failed`.
- `CartService` executa a fusão em transação com retries e bloqueios do usuário,
  carrinhos, itens e produtos. Planeja/valida todos os resultados antes da
  primeira escrita. Produtos comuns têm quantidades somadas; produtos
  exclusivos são transferidos; estoque deve cobrir a quantidade resultante.
  Qualquer produto inativo ou conflito de estoque retorna sem criar/modificar
  carrinhos ou itens, e o login continua autenticado.
- Após sucesso, os itens são transferidos e removidos da origem, que passa a
  `status=merged` e registra `merged_into_id`. O token persistido permite
  reconhecer repetição para o mesmo dono sem somar novamente; tokens de
  carrinhos fundidos em outra conta não revelam nem transferem o destino.
- `GuestCartCookie` centraliza nome, domínio, path, HttpOnly, SameSite, Secure
  e duração para emitir ou invalidar o cookie com atributos correspondentes.
  O cookie visitante só é removido após sucesso; em conflito, ausência de
  carrinho ou falha com rollback, permanece intacto. A sessão Sanctum não é
  invalidada.
- Adicionados 13 testes em `CartMergeTest` para ausência/invalidade de token,
  carrinho vazio, criação do destino, itens exclusivos e repetidos,
  preservação do carrinho da conta, conflitos por produto inativo/estoque,
  rollback, autenticação mantida, cookie, idempotência, isolamento entre contas
  e falha inesperada da fusão.
- `php artisan test`: **52 testes aprovados, 261 assertions**. Pint passou.
  `docker compose config --quiet`, `php artisan route:list --path=api` e build
  do backend passaram.
- Integração real por Nginx/Docker passou para fusão com quantidades repetidas,
  transferência de produtos exclusivos, estado/referência da origem,
  cenário de conflito de estoque sem alterações, cookie visitante preservado,
  `/api/me` autenticado após conflito e estoque de catálogo inalterado. Contas
  e carrinhos temporários foram removidos por identificadores exatos; não foi
  usada a conta administrativa.
- Nenhuma migration, seeder, alteração de senha, baixa de estoque ou operação
  destrutiva foi executada.
- Arquivos criados: `app/Support/GuestCartCookie.php` e
  `tests/Feature/CartMergeTest.php`.
- Arquivos modificados: `app/Services/AuthService.php`,
  `app/Services/CartService.php`,
  `app/Http/Controllers/Api/AuthController.php`,
  `app/Http/Controllers/Api/CartController.php`, `README.md` e este relatório.
- Pendências: apresentar visualmente `cart_merge.conflicts` na UI,
  implementação visual do carrinho, checkout, pedidos e baixa de estoque.
  Nenhum deles foi marcado como concluído.

## Implementação — API de carrinho

**Executada em:** 28/10/2026.

- Implementados `GET /api/cart`, `POST /api/cart/items`,
  `PATCH /api/cart/items/{id}` e `DELETE /api/cart/items/{id}` para visitantes
  e usuários autenticados. A lógica de propriedade, disponibilidade e
  persistência fica em `CartService`; Controllers usam Form Requests e
  `CartResource`/`CartItemResource`.
- O visitante recebe do backend um token aleatório de 64 caracteres no cookie
  `medelfiguri_guest_cart` (HttpOnly, SameSite=Lax, 30 dias, Secure em HTTPS).
  O token é armazenado em `carts.guest_token`, nunca retornado no JSON. O
  middleware stateful existente do Sanctum descriptografa/valida cookies na
  origem configurada; nenhuma autenticação própria ou token em JavaScript foi
  adicionada.
- Carrinhos de usuário vêm de `request->user()` e do `user_id` do Model
  autenticado, nunca de entrada do cliente. A criação do carrinho ativo é
  serializada pelo lock do usuário; se houver dados preexistentes com vários
  carrinhos ativos, a API seleciona o mais recente sem alterar os demais.
  Inclusão/alteração/remoção localizam o item por meio do relacionamento do
  carrinho atual; IDs de outros carrinhos retornam `404`.
- Apenas produtos `active` com estoque positivo aceitam quantidade não maior
  que o estoque atual. Produto inativo, quantidade inválida ou estoque
  insuficiente retorna `422`. Inclusões repetidas do mesmo produto definem a
  quantidade solicitada no item existente e não duplicam linhas. Nenhum preço
  do cliente é aceito; estoque não é reservado nem alterado.
- Resources retornam id e estado do carrinho, produtos, nomes, SKU, preço
  unitário atual, subtotal por linha, quantidade total e subtotal do carrinho.
  Valores monetários são strings decimais calculadas com aritmética inteira
  decimal, inclusive para valores além do limite de inteiros de 64 bits.
- O schema existente já contém `guest_token` único, status, proprietário,
  relação recursiva `merged_into_id` e unicidade `(cart_id, product_id)`.
  Nenhuma migration foi criada ou executada; migrations, seeders e estoque
  real permaneceram inalterados.
- `php artisan test`: **39 testes aprovados, 160 assertions**, incluindo 19
  testes de carrinho em SQLite em memória (cookies e persistência de visitante,
  criação de carrinho autenticado, propriedade, itens, validações, JSON,
  preço/estoque e precisão monetária).
- `docker compose config --quiet` passou; a imagem do backend foi reconstruída
  e os serviços terminaram com backend/db `healthy` e frontend em execução.
  Integração HTTP através do Nginx validou carrinho visitante e autenticado,
  cookie HttpOnly, persistência, adicionar/atualizar/remover, encerramento da
  sessão e estoque inalterado. A conta temporária e os carrinhos/itens criados
  para esse teste foram removidos por identificadores exatos; nenhum usuário
  real ou senha administrativa foi usado ou alterado.
- Arquivos criados: `app/Services/CartService.php`,
  `app/Support/CartMoney.php`, `app/Http/Controllers/Api/CartController.php`,
  `app/Http/Requests/AddCartItemRequest.php`,
  `app/Http/Requests/UpdateCartItemRequest.php`,
  `app/Http/Resources/CartResource.php`,
  `app/Http/Resources/CartItemResource.php` e
  `tests/Feature/CartApiTest.php`.
- Arquivos modificados: `routes/api.php`, `README.md` e este relatório.
- Próximas etapas: interface visual do carrinho, fusão explícita de carrinhos
  no login, validação de estoque novamente no checkout, pedidos e fluxo de
  pagamento. Nenhum desses itens foi implementado nesta etapa.

## Implementação — frontend de autenticação e catálogo

**Executada em:** 28/10/2026.

- A página inicial agora carrega categorias e produtos reais da API, permite
  filtrar por categorias e navegar pelas páginas retornadas pelo Laravel.
  Modelos `.glb` são apresentados como links para os arquivos existentes; não
  foram adicionados dados comerciais fictícios.
- Criadas as páginas `/login.html` e `/cadastro.html`. O cadastro envia apenas
  os campos previstos pelo backend, exige aceite explícito dos termos e não
  solicita nem envia papel. A resposta de sucesso informa que é necessário
  entrar separadamente, conforme o comportamento real da API.
- Criados `frontend/api.js` (Fetch centralizado, JSON, CSRF, cookies de sessão
  e erros HTTP), `frontend/auth.js` (consulta `/api/me`, atualização visual e
  logout), `frontend/login.js` e `frontend/cadastro.js`. Cookies e senhas não
  são persistidos em `localStorage` ou `sessionStorage`; valores do servidor
  são inseridos no DOM como texto.
- Mantida a mesma origem de frontend e API no Nginx; não foi preciso alterar
  Compose, Nginx, portas, banco, migrations, seeders ou volumes.
- Validação JavaScript: `node --check` passou para os cinco módulos.
  `docker compose build frontend` passou e somente o serviço `frontend` foi
  recriado.
- Validação HTTP via Nginx: `/`, `/login.html`, `/cadastro.html`, CSS e os
  cinco módulos JavaScript responderam **200**; `/api/categories`,
  `/api/products` e `/up` responderam **200**; `/sanctum/csrf-cookie`
  respondeu **204**.
- Testes no navegador: catálogo carregou 15 produtos; filtros exibiram 8
  itens de Anime e 7 de Jogos; viewport móvel de 375 px não apresentou
  overflow horizontal e exibiu uma coluna. Login inválido mostrou a mensagem
  genérica sem marcar campos que identifiquem uma conta. Cadastro inválido
  alcançou a API com CSRF e apresentou o erro de senha no campo correspondente
  (**422**); nenhuma conta foi criada.
- `php artisan test tests/Feature/AuthApiTest.php tests/Feature/CatalogApiTest.php`:
  **16 testes aprovados, 57 assertions**.
- Não foi feito login válido nem logout no banco Docker nesta etapa para
  preservar os registros reais; esses fluxos seguem cobertos pelos testes
  automatizados do backend, e devem ser exercitados manualmente no navegador
  com uma conta autorizada. Também não foi validado visualmente um fluxo de
  logout autenticado nesta execução.
- Arquivos criados: `frontend/api.js`, `frontend/auth.js`,
  `frontend/login.js`, `frontend/cadastro.js`, `frontend/login.html` e
  `frontend/cadastro.html`.
- Arquivos modificados: `frontend/index.html`, `frontend/app.js`,
  `frontend/styles.css`, `README.md` e este relatório.
- Pendências de produto: painel administrativo, recuperação de senha,
  verificação de e-mail, checkout e pedidos.

## Implementação — autenticação e autorização Sanctum

**Executada em:** 28/10/2026.

- Situação inicial confirmada com `php artisan test`: 6 testes e 21
  assertions aprovados. Sanctum 4.3.3 estava instalado; `statefulApi()` ainda
  não estava habilitado em `bootstrap/app.php`.
- Habilitado o middleware stateful Sanctum, mantendo API e `/sanctum` na mesma
  origem por meio do proxy Nginx existente. O Compose já fornece
  `localhost:8080,127.0.0.1:8080` em `SANCTUM_STATEFUL_DOMAINS`; não foi
  necessário adicionar CORS ou alterar portas. Cookies usam as configurações
  de sessão existentes.
- Implementados `POST /api/register`, `POST /api/login`, `POST /api/logout`,
  `GET /api/me` e `GET /api/admin/me`. Cadastro valida nome, username único,
  e-mail válido/único, senha confirmada forte (mínimo 12 caracteres, letras
  maiúsculas/minúsculas, números e símbolos) e aceite obrigatório dos termos.
  Versão dos termos é configurada por `TERMS_VERSION`/`app.terms_version`.
- Cadastro cria sessão? **Não.** Retorna `201`; login separado é necessário.
  Todo cadastro recebe somente `client` via relacionamento `roles()`;
  tentativa de enviar papel `admin` é ignorada. Login aceita e-mail ou
  username, usa guard `web`, mensagem genérica para credenciais inválidas e
  regenera sessão. Logout invalida sessão, regenera token CSRF e limpa os
  guards em cache.
- Criado `UserResource` com somente id, nome, username, e-mail e papéis
  públicos. Nenhuma senha, hash, token ou remember token é serializado.
- Criado middleware `role` registrado em `bootstrap/app.php`. `/api/admin/me`
  exige `auth:sanctum` e papel admin; visitante recebe `401`, cliente
  autenticado `403`. Autorização consulta o relacionamento de roles no banco.
- Login está limitado a cinco tentativas por minuto por IP.
- Testes em SQLite em memória confirmam cadastro, duplicatas, aceite dos
  termos, rejeição de elevação para admin, hash da senha, login por e-mail e
  username, senha incorreta, `/me`, logout, autorização admin/cliente/visitante
  e regressão do catálogo. `php artisan test`: **20 testes aprovados, 69
  assertions**.
- Teste real via Nginx/Docker com cookie jar mantido em memória (sem imprimir
  credenciais/cookies): `/sanctum/csrf-cookie` **204**; login sem CSRF **419**;
  login com CSRF **200**; `/api/me` autenticado **200**; `/api/admin/me` para
  admin **200**; logout **200**; `/api/me` e rota admin após logout **401**.
  Login/logout atualizaram o cookie CSRF.
- Não foram executadas migrations, seeders, mudanças de senha, alterações em
  volumes ou operações no banco de desenvolvimento durante esta etapa.
- Arquivos criados: `app/Http/Controllers/Api/AuthController.php`,
  `app/Http/Requests/RegisterRequest.php`,
  `app/Http/Requests/LoginRequest.php`,
  `app/Http/Resources/UserResource.php`,
  `app/Services/AuthService.php`,
  `app/Http/Middleware/EnsureUserHasRole.php` e
  `tests/Feature/AuthApiTest.php`.
- Arquivos modificados: `bootstrap/app.php`, `config/app.php`,
  `routes/api.php`, `.env.example`, `README.md` e este relatório.
- Pendências: recuperação de senha, verificação de e-mail, limitação de
  requisições específica para cadastro, frontend/telas de autenticação e fluxos
  comerciais não fazem parte desta etapa. A autorização aplicada restringe
  somente a rota de verificação administrativa atual; futuras rotas admin
  também deverão receber middleware `role:admin`.

## Atualização — conta administrativa e renders

**Executada em:** 28/10/2026.

- A configuração foi movida para `config/development_admin.php`; os seeders
  leem `config('development_admin.*')`, compatível com config cache do Laravel.
  O cache de configuração foi verificado e estava **não cacheado**; não foi
  necessário limpá-lo. As quatro variáveis foram confirmadas presentes no
  ambiente do backend sem imprimir seus valores.
- O seeder usa `User`, o relacionamento `roles()` e o papel `admin`. Uma conta
  existente recebe os dados de identidade configurados e o vínculo de papel,
  mas não tem a senha redefinida; uma conta nova recebe a senha via cast
  `hashed` do Model. O seeder valida formato/uniqueness, exige senha de pelo
  menos 12 caracteres e só roda nos ambientes `local`/`testing`.
- `php artisan db:seed` executou com sucesso duas vezes no serviço `backend`.
  A segunda execução não duplicou papéis, categorias, produtos nem renders e
  não redefiniu a senha. Nenhuma migration foi executada nesta etapa.
- Verificação no banco, sem exibir credenciais ou hash: conta existente;
  nome, username e e-mail correspondem à configuração; papel `admin`
  associado; `Hash::check` confirmou que a senha configurada corresponde ao
  hash persistido e o valor persistido não é texto puro.
- Os sete arquivos `.glb` presentes no diretório foram verificados dentro do
  contêiner. O seeder cria sete produtos de demonstração neutros, relaciona
  cada render a uma categoria existente e cria sete imagens primárias com
  paths no disco `public`. O link de armazenamento foi criado na imagem
  FrankenPHP e o Nginx encaminha `/storage/` ao backend.
- Contagens verificadas após reexecução: 2 papéis, 6 categorias, 15 produtos
  (8 de catálogo e 7 baseados nos renders), 15 associações de categoria e
  7 imagens; todos os renders semeados apontam para arquivos existentes.
- `curl` via Nginx: `/api/categories`, `/api/products`,
  `/api/products/render-lufyhead` e `/up` retornaram **200**;
  `/sanctum/csrf-cookie` retornou **204**; `HEAD /storage/lufyHead.glb`
  retornou **200** com tipo `model/gltf-binary`.
- `php artisan test`: **6 testes aprovados, 21 assertions**, incluindo teste
  de idempotência dos renders e teste de que a senha de conta existente não é
  redefinida.
- `.gitignore` contém uma regra literal `.env`, e os quatro valores
  correspondentes no `.env.example` estão vazios. O workspace não possui
  metadados Git; portanto, não foi possível confirmar via índice Git se `.env`
  já estava versionado. A regra de ignore existe, mas o estado do índice fica
  não verificado.
- Arquivos alterados/criados nesta conclusão: `config/development_admin.php`,
  `database/seeders/DatabaseSeeder.php`,
  `database/seeders/DevelopmentAdminSeeder.php`,
  `database/seeders/CatalogSeeder.php`,
  `app/Http/Controllers/Api/CatalogController.php`, `Dockerfile`,
  `Dockerfile.dev`, `frontend/nginx.conf`,
  `tests/Feature/DevelopmentAdminSeederTest.php`,
  `tests/Feature/CatalogSeederTest.php`, `tests/Feature/CatalogApiTest.php`,
  `README.md` e este relatório. O `.env` local foi lido, não modificado.
- Serviços no final: `db` e `backend` **healthy**; `frontend` **running**.
  MySQL usa `medelfiguri_mysql_data`; o volume antigo
  `bookshelf-api_mysql_data` continua presente e não está montado.

## Banco, seeders e API — validação do Prompt 3

**Executada em:** 28/10/2026.

- Antes de migrar, `docker compose ps` mostrou `db` e `backend` saudáveis.
  Compose e inspeção dos mounts confirmaram `DB_HOST=db`, database
  `medelfiguri`, volume `medelfiguri_mysql_data` montado somente no serviço
  `db`, e nenhum mount do volume `bookshelf-api_mysql_data`. O volume antigo
  continua existente, com o label do projeto `bookshelf-api`.
- `php artisan about` confirmou Laravel 12.70.0, PHP 8.4.27 e driver MySQL.
  A conexão PDO do backend executou `SELECT 1` em `medelfiguri`.
- `migrate:status` inicialmente informou que a tabela de migrations ainda não
  existia. Após revisar as 19 migrations existentes e confirmar o banco
  dedicado vazio de schema, `php artisan migrate` aplicou todas as migrations
  normais em batch 1. O status final mostra todas como `Ran`; nenhuma migration
  nova ou `migrate:fresh` foi usada.
- Confirmadas as tabelas base, Sanctum e domínio: `users`, `sessions`,
  `personal_access_tokens`, `roles`, `role_user`, `categories`, `products`,
  `category_product`, `addresses`, `carts`, `cart_items`, `orders`,
  `order_items`, `order_addresses`, `reviews`, `product_images` e
  `inventory_movements`, além das tabelas padrão de cache/jobs.
- Migrations existentes têm FKs com ações de exclusão definidas, unicidade
  para e-mail/username, SKU/slug, pivots, carrinho-produto, endereço de pedido
  e avaliação por usuário/produto. Não foi identificada falha que justificasse
  migration corretiva nesta fase. Regras como estoque não negativo e nota
  limitada a 1–5 permanecem sem constraint de banco e devem ser tratadas com as
  regras de domínio.
- O seeder agora prevê papéis `client` e `admin`, dados idempotentes de
  catálogo, e conta administrativa de desenvolvimento criada somente a partir
  de `DEV_ADMIN_NAME`, `DEV_ADMIN_USERNAME`, `DEV_ADMIN_EMAIL` e
  `DEV_ADMIN_PASSWORD`. A senha deve ser configurada localmente, tem mínimo de
  12 caracteres, não existe valor padrão e o seeder é restrito a `local` ou
  `testing`. `.env.example` documenta as variáveis vazias, sem segredos.
- Na primeira verificação, as variáveis administrativas estavam ausentes e o
  preflight de `db:seed` interrompeu antes de gravar. Após a configuração local,
  o seeder completo foi executado e validado; o resultado atual consta na
  seção “Atualização — conta administrativa e renders” acima.
- O scaffold antigo de rotas `Stuffs`/`inStuffs` referenciava Controller
  inexistente e modelos/requests que não existem. As rotas quebradas foram
  substituídas por endpoints públicos de leitura para categorias, listagem de
  produtos e detalhe por slug. O arquivo base `Controller.php` foi movido do
  subdiretório `Api/` para o caminho PSR-4 correto.
- `route:list --path=api` registra 3 rotas. `GET /api/categories`,
  `GET /api/products`, um detalhe de produto por slug e `/up` responderam HTTP
  200 pelo Nginx. `/sanctum/csrf-cookie` respondeu 204 após a migration criar
  `sessions`.
- Na etapa inicial, `php artisan test` teve 4 testes aprovados e 10 assertions;
  a suíte atual tem 6 testes e 21 assertions, detalhados acima.
- Arquivos alterados/criados nesta etapa: `routes/api.php`,
  `app/Http/Controllers/Controller.php` (movido do caminho incorreto),
  `app/Http/Controllers/Api/CatalogController.php`,
  `database/seeders/DatabaseSeeder.php`, `CatalogSeeder.php`,
  `RoleSeeder.php`, `DevelopmentAdminSeeder.php`, `.env.example`,
  `tests/Feature/CatalogApiTest.php`, `README.md` e este relatório.
- O estado final da conta administrativa, incluindo associação do papel e
  confirmação do hash sem expor o segredo, consta na seção de atualização
  acima.

## Revalidação da infraestrutura Docker

**Executada em:** 28/10/2026.

- Arquivos examinados: `compose.yaml`, `Dockerfile`, `Dockerfile.dev`,
  `docker/backend/Caddyfile`, `frontend/Dockerfile`, `frontend/nginx.conf`,
  `.env`, `.env.example`, `.dockerignore`, `config/database.php`,
  `composer.json`, `README.md` e este relatório.
- Arquivos modificados nesta revalidação: `README.md` e
  `PROJECT_STATUS.md`. A configuração Docker existente já atendia à
  arquitetura; não foi necessário alterar os Dockerfiles, Compose ou proxy.
- `docker compose config --quiet` passou. `docker compose up -d --build`
  construiu as imagens e iniciou os três serviços.
- O volume efetivamente resolvido e montado em `/var/lib/mysql` é
  `medelfiguri_mysql_data` (volume local, não externo). O volume
  `bookshelf-api_mysql_data` permanece presente, com label do projeto
  `bookshelf-api`, e não está montado em nenhum contêiner MedelFiguri.
- A configuração local tem `DB_CONNECTION=mysql`, `DB_HOST=db`,
  `DB_PORT=3306`, database/usuário `medelfiguri` e `DB_PASSWORD` configurada.
  `MYSQL_ROOT_PASSWORD` não está definida no `.env`, mas o Compose usa
  `DB_PASSWORD` como fallback; a autenticação MySQL com essa configuração
  passou. Nenhum valor secreto foi registrado.
- Estado final: `db` e `backend` **healthy**; `frontend` **running**. Os logs
  confirmam MySQL 8.4.11, Nginx e FrankenPHP com PHP 8.4.27. O backend executou
  conexão PDO e `SELECT 1` na database `medelfiguri`.
- Portas publicadas: `127.0.0.1:8080` (frontend) e `127.0.0.1:8000`
  (backend). MySQL `3306` não é publicado no host.
- Respostas HTTP: `/`, `/styles.css`, `/app.js` e `/up` via Nginx retornaram
  **200**; `/up` no backend também retornou **200**. `/api` e `/sanctum`
  retornaram **404** tanto via Nginx quanto diretamente no backend.
  `/sanctum/csrf-cookie` retornou **500** por ambos os caminhos; a consulta
  somente de leitura confirmou que a tabela `sessions` não existe. O proxy
  mantém o encaminhamento dos paths; a falha CSRF não foi ocultada nem corrigida
  com healthcheck.
- `php artisan route:list --path=api` continua falhando porque
  `App\Http\Controllers\Api\inStuffController` não existe. Fica registrado
  para a próxima etapa de correção da API. Não foram executadas migrations ou
  seeders.
- O log do Caddy mantém aviso não fatal de formatação do Caddyfile. O servidor
  inicia e atende `/up`; HTTP/2 e HTTP/3 são omitidos no listener sem TLS.
- Comandos executados: `docker compose config --quiet`,
  `docker compose up -d --build`, `docker compose ps`, `docker compose logs
  --tail=100` para cada serviço, probes `curl` nos recursos estáticos e rotas,
  conexão PDO/`SELECT 1`, consulta somente leitura de `sessions`, listagem de
  rotas da API e inspeção/listagem dos nomes dos volumes.

## Atualização — infraestrutura Docker

**Executada em:** 28/10/2026, após a auditoria inicial.

- Arquivos inspecionados: `compose.yaml`, `Dockerfile`, `Dockerfile.dev`,
  `docker/backend/Caddyfile`, `frontend/Dockerfile`, `frontend/nginx.conf`,
  `.dockerignore`, `.env`, `.env.example` e `config/database.php`.
- Arquivos modificados: `compose.yaml`, `Dockerfile`, `Dockerfile.dev`,
  `.env`, `.env.example` e este relatório (`PROJECT_STATUS.md`). O Nginx e o
  Caddyfile já encaminhavam os paths requeridos e não precisaram de alterações.
  Nenhum arquivo foi criado nesta fase.
- Compose validado com serviços `frontend`, `backend` e `db`.
- O MySQL usa o volume nomeado e independente `medelfiguri_mysql_data`.
  O volume `bookshelf-api_mysql_data` continua presente e associado ao projeto
  `bookshelf-api`; não foi montado, removido nem alterado.
- O `.env` local foi alinhado para `DB_HOST=db` e database/usuário
  `medelfiguri`. A senha existente foi preservada e não foi impressa. Como
  `MYSQL_ROOT_PASSWORD` não está definida localmente, Compose usa
  `DB_PASSWORD` como fallback para inicialização local; pode-se configurar uma
  senha root distinta nessa variável.
- `db` e `backend` ficaram saudáveis. O healthcheck do backend confirma uma
  conexão PDO com a database configurada e executa `SELECT 1`, além de
  verificar `/up`. Nenhuma migration ou seeder foi executada.
- Nginx serviu HTML, CSS e JavaScript com HTTP 200. `/up` respondeu 200, e
  respostas para probes em `/api` e `/sanctum` corresponderam às respostas
  diretas do backend, confirmando o proxy e a preservação dos caminhos.
- `/sanctum/csrf-cookie` respondeu HTTP 500 tanto pelo Nginx quanto pelo
  backend. A tabela `sessions` está ausente no banco novo; `SESSION_DRIVER`
  está configurado como `database`. Não foi criada por migration nesta etapa.
- As portas publicadas são `127.0.0.1:8080` (frontend) e
  `127.0.0.1:8000` (backend). O MySQL não publica porta no host.
- O log do backend confirma FrankenPHP/PHP 8.4.27 iniciado. As falhas de
  permissão dos diretórios de dados/configuração do Caddy foram corrigidas.
  Permanece um aviso não fatal de formatação do Caddyfile; HTTP/2 e HTTP/3
  também são omitidos no listener HTTP sem TLS.
- Os builds de produção (frontend/backend) e de desenvolvimento passaram.
  `docker compose config --quiet` e `php artisan test` também passaram
  (2 testes/2 assertions).
- A listagem de rotas ainda é um problema da API identificado na auditoria
  inicial; os probes HTTP não substituem a correção de `inStuffController`.

## Resumo executivo

O projeto contém as migrations e os Models centrais do domínio de e-commerce,
além de uma infraestrutura Docker com Nginx, FrankenPHP e MySQL. Porém, a API
atual ainda é um scaffold de “Stuff”, não implementa os fluxos da loja e nem
consegue listar suas rotas: uma classe de controller importada não existe.
Sanctum está instalado, mas a autenticação SPA por sessão/cookie não está
conectada ao middleware da API nem há endpoints de autenticação ou autorização.

O Compose agora usa três serviços com MySQL em volume independente; frontend e
backend estão em execução e saudáveis. O endpoint CSRF ainda falha porque o
banco novo não tem a tabela de sessões, e migrations foram intencionalmente
adiadas. O `.env` local e o backend estão alinhados à database MySQL
`medelfiguri` no serviço `db`. O volume antigo `bookshelf-api_mysql_data` foi
preservado e não é usado pelo Compose MedelFiguri. O arquivo SQLite local
contém somente as migrations-base do Laravel, não o schema MedelFiguri.

## Estrutura encontrada

- `app/Models`: 13 Models de domínio.
- `app/Http/Controllers`, `Requests`, `Resources`: somente componentes de
  scaffold `Stuff`.
- `app/Services`: somente `StuffService`.
- `database/migrations`: migrations-base do Laravel, Sanctum e schema do
  domínio.
- `database/seeders`: `DatabaseSeeder` e `CatalogSeeder`.
- `database/factories`: apenas `UserFactory`.
- `routes`: `api.php`, `web.php` e `console.php`.
- `frontend`: página HTML/CSS/JavaScript estática servida pelo Nginx.
- `resources`: assets e view padrão Laravel/Vite.
- `tests`: dois testes de exemplo, sem cobertura de regras de negócio.
- Não há diretórios de Policies, nem arquivos Swagger/OpenAPI.

## Versões e dependências

- PHP instalado no ambiente: **8.4.27**.
- Composer exige PHP **`^8.2`**; a configuração `platform.php` fixa **8.3.0**
  para resolução de dependências.
- Laravel instalado: **12.70.0**.
- Sanctum instalado: **4.3.3**; a migration de `personal_access_tokens` existe.
- `composer check-platform-reqs` e `composer validate --no-check-publish`
  passaram.
- Dependências JavaScript declaradas em `package.json` incluem Vite, Tailwind,
  Laravel Vite Plugin e Axios; `node_modules` não está instalado.
- Não há dependência de Swagger/OpenAPI no Composer ou no frontend.

## Tabelas e Models

As migrations declaram os seguintes nomes reais:

| Tabela | Migration | Model / observação |
|---|---|---|
| `users` | `create_users_table` e migration de perfil/termos | `User` |
| `roles` | `create_roles_table` | `Role` |
| `role_user` | `create_role_user_table` | Pivot de `User`/`Role` |
| `addresses` | `create_addresses_table` | `Address` |
| `categories` | `create_categories_table` | `Category` |
| `products` | `create_products_table` | `Product` |
| `category_product` | `create_category_product_table` | Pivot de `Category`/`Product` |
| `product_images` | `create_product_images_table` | `ProductImage` |
| `reviews` | `create_reviews_table` | `Review` |
| `carts` | `create_carts_table` | `Cart` |
| `cart_items` | `create_cart_items_table` | `CartItem` |
| `orders` | `create_orders_table` | `Order` |
| `order_items` | `create_order_items_table` | `OrderItem` |
| `order_addresses` | `create_order_addresses_table` | `OrderAddress` |
| `inventory_movements` | `create_inventory_movements_table` | `InventoryMovement` |
| `personal_access_tokens` | migration do Sanctum | Tabela de tokens do pacote |

Todas as entidades/tabelas listadas na especificação foram encontradas como
migrations; `role_user`, `category_product` e `cart_items` são tabelas pivot
sem Model próprio, o que é apropriado para o uso atual.

**Não foi encontrada tabela `payments`, migration de pagamentos ou Model
`Payment`.** Isso é compatível com a especificação de confirmação simulada
diretamente no pedido.

O SQLite local (`database/database.sqlite`) possui apenas tabelas padrão do
Laravel e registra três migrations-base no batch 1. Não possui as tabelas de
Sanctum ou do domínio. O MySQL configurado não estava acessível; portanto, o
estado atual das migrations nele permanece **não verificado**.

## Models, relacionamentos e integridade

Os relacionamentos declarados entre os Models principais correspondem, em
geral, às FKs e pivots das migrations: usuários e papéis; categorias e
produtos; carrinhos e itens; pedidos, itens, endereço e movimentações de
estoque. `order_addresses.order_id` é único, coerente com `Order::address()`.
Itens de pedido mantêm nome, SKU, preço unitário e total como snapshot.

Pontos a tratar antes de implementar operações:

- Quantidades, estoque, avaliações e estados são em grande parte colunas
  inteiras/strings sem constraints de domínio no banco; validação e regras
  transacionais ainda precisam ser implementadas no backend.
- Não há implementação que assegure baixa/devolução de estoque, atomicidade
  do checkout, idempotência de devolução ou prevenção de vendas concorrentes.
- `ProductImage` tem `is_primary`, mas não há constraint que limite a uma
  imagem principal por produto.
- Não foi identificada inconsistência inequívoca entre os relacionamentos
  centrais existentes e suas FKs nas migrations.

## Rotas, Controllers e API

`routes/api.php` declara recursos `inStuffs` e `Stuffs`, não rotas de catálogo,
autenticação, carrinho, pedidos ou administração. A listagem falhou com:

```text
ReflectionException: Class "App\Http\Controllers\Api\inStuffController" does not exist
```

O scaffold relacionado também possui bloqueios:

- `StuffService` referencia `App\Models\Stuff`, que não existe, e relação
  `inStuff`, também inexistente.
- `StuffController` depende de `UpdateStuffRequest`; o arquivo existente não
  declara essa classe.
- O `Controller.php` está em `app/Http/Controllers/Api/`, mas declara namespace
  `App\Http\Controllers`, incompatível com o caminho PSR-4 esperado.
- `StuffResource` tenta serializar `$this->bool`, embora o request use `color`.
- Requests, Resource e Service tratam campos de “Stuff”, não entidades da loja.

Logo, não há API funcional de e-commerce nem uma camada Service aplicando as
regras da especificação.

## Seeders e factories

- `CatalogSeeder` cria categorias e oito variantes de produtos de exemplo, de
  forma idempotente por slug/SKU. Não cria imagens nem avaliações.
- `DatabaseSeeder` chama apenas `CatalogSeeder`; não cria papéis ou usuário
  administrador.
- `UserFactory` existe e fornece username/termos, campos acrescentados por uma
  migration posterior. Não há factories para os demais Models.

## Banco e ambiente

- O projeto está configurado para **MySQL**, não PostgreSQL, em `.env`,
  `.env.example` e Compose. A escolha MySQL já está explícita na configuração.
- `.env` e `.env.example` usam `DB_HOST=db`, database `medelfiguri` e usuário
  `medelfiguri`. Não foram copiados valores de senha para este relatório.
- `.env` está ignorado pelo Git e contém `APP_DEBUG=true`, apropriado somente
  para desenvolvimento local.
- `SESSION_DRIVER=database`; existe migration padrão para `sessions`.
- `docker compose up -d --build` inicializou uma database vazia no volume
  exclusivo `medelfiguri_mysql_data`. O healthcheck do backend confirmou a
  conexão pela rede interna. Nenhuma migration ou seeder foi executada; a
  tabela `sessions` não existe ainda.
- `MYSQL_ROOT_PASSWORD` está ausente no `.env` local; Compose usa a senha
  configurada em `DB_PASSWORD` como fallback. A autenticação root foi
  verificada sem imprimir a senha.
- O volume antigo `bookshelf-api_mysql_data` continua presente com o label do
  projeto `bookshelf-api`, e não é referenciado pelo Compose MedelFiguri.

## Docker

- `compose.yaml` declara os serviços `frontend`, `backend` e `db`;
  `docker compose config --quiet` passou.
- `Dockerfile` usa FrankenPHP com PHP 8.4 e extensão `pdo_mysql`.
- `Dockerfile.dev` também usa FrankenPHP/PHP 8.4 e mantém dependências Composer
  de desenvolvimento; foi construído com sucesso.
- `frontend/Dockerfile` usa Nginx e serve arquivos estáticos; o Nginx encaminha
  `/api`, `/sanctum` e `/up` para o backend preservando os paths.
- O backend publica `127.0.0.1:8000`; o frontend publica `127.0.0.1:8080`.
  O serviço `db` não publica a porta MySQL.
- O backend aguarda a saúde do `db`; o frontend aguarda a saúde do backend. O
  healthcheck do backend verifica conectividade MySQL e `/up`.
- Volume persistente: `medelfiguri_mysql_data`. O volume antigo não é usado nem
  foi modificado.
- `docker compose ps`: os três serviços estavam ativos; `db` e `backend`
  saudáveis. MySQL responde ao healthcheck e a credencial root foi verificada.
  O backend executou conexão PDO com `SELECT 1`.
- Logs confirmam Nginx iniciado e FrankenPHP 8.4.27 executando. Após ajuste de
  permissões, não há erros de acesso aos diretórios Caddy. Persiste somente
  aviso de formatação não fatal do Caddyfile.

## Frontend

O frontend separado é uma página de apresentação com verificação de saúde da
API. Ainda não há catálogo, navegação de categorias, carrinho, login ou telas
administrativas. Há uma configuração Laravel Vite/Tailwind paralela em
`resources/` e `vite.config.js`, mas o Nginx serve `frontend/` diretamente e
não usa o build Vite. As dependências npm não estão instaladas; o build do
frontend não foi executado.

## Testes

- `php artisan test`: **passou**, 2 testes e 2 assertions.
- Os testes são apenas os exemplos padrão (uma resposta HTTP da página inicial
  e uma assertion trivial); não cobrem banco, API, autenticação ou negócio.
- `php artisan route:list --path=api`: **falhou** pela classe
  `inStuffController` inexistente.
- `php artisan migrate:status`: **não pôde consultar o banco**, por conexão
  recusada na auditoria inicial. Na fase Docker não foi executado novamente
  para evitar operações de schema; nenhuma migration ou seeder foi aplicada.
- A inspeção do arquivo SQLite foi feita com conexão somente leitura.
- `docker compose config --quiet`: **passou**.
- Builds do Compose (frontend/backend) e `docker build -f Dockerfile.dev`:
  **passaram**.
- `docker compose ps`: `db` e `backend` **healthy**; frontend **running**.
- Nginx: HTML, CSS, JS e `/up` **HTTP 200**. Probes de `/api` e `/sanctum`
  apresentaram o mesmo status direto no backend e via Nginx.
- `/sanctum/csrf-cookie`: **HTTP 500** nas duas rotas. A tabela `sessions`
  está ausente; é necessária migration antes de validar o fluxo CSRF completo.
  Nenhum cookie foi impresso.

## Autenticação e autorização

- Sanctum está instalado e tem configuração padrão com guard `web`, middleware
  de cookies/CSRF e migration de tokens.
- O guard `web` é de sessão. O Model `User` não usa `HasApiTokens`; isso não é
  necessário para SPA stateful por cookies, mas não há autenticação implementada.
- `bootstrap/app.php` não habilita `statefulApi()`. Assim, o middleware de
  requisições stateful do Sanctum não está conectado ao grupo da API.
- `SANCTUM_STATEFUL_DOMAINS` é injetado pelo Compose; não está definido no
  `.env` local para execução fora do Compose.
- `/sanctum/csrf-cookie` é encaminhado pelo Nginx sem remoção do caminho, mas
  retorna 500 porque a database dedicada ainda não tem `sessions` e o driver
  configurado é `database`. Nenhuma migration foi executada nesta fase.
- Não há rotas de registro/login/logout, nem implementação frontend de
  autenticação. O endpoint de CSRF do Sanctum não substitui esses fluxos.
- Não há Policies, middleware de papel, Gates ou autorização para `client` e
  `admin`; `roles` existe apenas como schema e relacionamento.

## Componentes completos, parciais e ausentes

**Existem e estão razoavelmente estruturados**

- Migrations iniciais para as entidades do domínio e os relacionamentos
  relacionais esperados.
- Models e relações principais.
- Seed de categorias e catálogo de exemplo.
- Esqueleto Docker Compose de três serviços e frontend estático.
- Dependências Composer instaladas e compatíveis com o PHP disponível.

**Existem, mas precisam de correções ou integração**

- Rotas e scaffold `Stuff`, atualmente inconsistentes e incapazes de carregar
  todas as rotas.
- Sanctum: middleware stateful, configuração de domínios e endpoints de sessão.
- O schema de sessões deve ser aplicado antes de testar CSRF/autenticação; não
  foi aplicado nesta fase.
- `MYSQL_ROOT_PASSWORD` opcional ainda não está definida separadamente; no
  desenvolvimento, Compose usa o fallback documentado.
- Caddyfile ainda produz um aviso de formatação não fatal.
- Seeders não preparam papéis/administrador nem dados de imagens/avaliações.
- Frontend e pipeline Vite estão paralelos, sem integração funcional.
- Suite de testes não verifica requisitos do MedelFiguri.

**Ausentes**

- API de catálogo/categorias/imagens/avaliações e recursos JSON do domínio.
- Form Requests específicos do domínio, Services e Controllers de negócio.
- Registro, login, logout e sessão Sanctum stateful por cookie/CSRF.
- Middleware/Policies para papéis e isolamento de pedidos por usuário.
- Carrinho visitante persistente e fusão transacional no login.
- Checkout, cálculo de frete/preços, pagamento simulado, controle concorrente
  de estoque, pedidos, cancelamento e movimentações.
- Área administrativa.
- Swagger UI/OpenAPI.
- Testes automatizados para as funcionalidades especificadas.

## Próxima etapa recomendada

Com a infraestrutura em execução no volume isolado, a próxima etapa é revisar
o plano de migrations pendentes para a database `medelfiguri` e obter
autorização antes de aplicá-las. Depois, corrigir o scaffold de rotas `Stuff`
e implementar a API por módulos, começando pelo catálogo e testes. A conexão
de sessão/CSRF deve ser validada após existir a tabela `sessions`; autenticação,
carrinho e checkout ficam para etapas posteriores com Services, autorização e
testes transacionais.
