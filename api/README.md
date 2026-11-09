# MedelFiguri

Loja de figures em desenvolvimento com Laravel 12, PHP 8.4, FrankenPHP,
Nginx e MySQL. O Compose mantém frontend, backend e banco em contêineres
separados. O frontend HTML/CSS/JavaScript oferece catálogo, carrinho, checkout,
pedidos, administração, login e cadastro, integrados à API pelo Nginx.

## Executar com Docker Compose

Em uma instalação nova, copie `.env.example` para `.env` e gere `APP_KEY`.
Se `.env` já existir, preserve-o: não o substitua. Configure `DB_PASSWORD`
com uma senha local forte antes de iniciar os contêineres. `MYSQL_ROOT_PASSWORD`
é opcional e, quando omitida, o Compose usa `DB_PASSWORD` como fallback.
Antes de executar os seeders, configure `DEV_ADMIN_NAME`,
`DEV_ADMIN_USERNAME`, `DEV_ADMIN_EMAIL` e `DEV_ADMIN_PASSWORD` no `.env`.
Use uma senha exclusiva de pelo menos 12 caracteres; nenhum acesso padrão é
criado ou fornecido pelo projeto.

```sh
docker compose up --build -d
```

- Frontend (Nginx): <http://localhost:8080>
- Backend (FrankenPHP): <http://localhost:8000>
- MySQL: serviço `db`, porta `3306` somente na rede interna do Compose; não
  há porta MySQL publicada no host.

O banco usa o volume local independente `medelfiguri_mysql_data`. O volume
`bookshelf-api_mysql_data` pertence a outro projeto e não é utilizado pelo
MedelFiguri. Evite `docker compose down -v` se precisar preservar os dados do
MedelFiguri.

`Dockerfile.dev` é uma imagem opcional de desenvolvimento: mantém o runtime
FrankenPHP/PHP 8.4 e instala também as dependências Composer de desenvolvimento.
O Compose padrão usa `Dockerfile`; `Dockerfile.dev` não é usado por nenhum
serviço do Compose atual.

Migrations não são executadas automaticamente. Para um banco de desenvolvimento
novo, confira o status e aplique as migrations antes dos dados de demonstração:

```sh
docker compose exec api php artisan migrate:status
docker compose exec api php artisan migrate
docker compose exec api php artisan db:seed
```

O `db:seed` cria papéis, categorias, produtos e a conta administrativa
configurada no `.env`; ele falha antes de gravar dados se as quatro variáveis
do administrador estiverem ausentes ou inválidas.

O catálogo público em `/` consulta `GET /api/categories` e
`GET /api/products` (com paginação); as categorias filtram os produtos da
página carregada. Os detalhes continuam disponíveis por
`GET /api/products/{slug}`.
Os sete renders `.glb` em `storage/app/public` são semeados como modelos de
demonstração, associados a categorias e disponibilizados em `/api/storage/`.
O catálogo oferece as categorias **Animes** e **Jogos**; o filtro **Todas**
mostra os produtos sem restringir a categoria. Luffy, Goku, Frieren e Gato de
Botas ficam em Animes; Mario, Zard e Mander ficam em Jogos. Cada produto tem
uma descrição específica do personagem ou universo representado.

### Catálogo e visualização interativa 3D

O catálogo público lista apenas produtos ativos com um arquivo `.glb` ou
`.gltf` associado e presente em `storage/app/public`; produtos sem modelo ficam
inativos e não aparecem para clientes. A administração, restrita ao papel
`admin`, separa os produtos nas abas **Ativos** e **Inativos**. Um produto sem
arquivo 3D disponível não pode ser ativado; remover seu último modelo também o
torna inativo.

Cada card renderiza o modelo 3D e abre a visualização ampliada ao ser clicado.
O componente oficial Google `<model-viewer>` **4.4.0** é instalado localmente
e servido pelo Nginx, sem CDN em tempo de execução. Aceita modelos `.glb` e
`.gltf`; os assets de demonstração atuais são GLB em `storage/app/public`,
publicados pela rota `/api/storage/`.

Os sete arquivos atualmente associados a produtos no catálogo são:

| Produto | SKU | Arquivo |
|---|---|---|
| Render dbz | MODEL-DBZ | `dbz.glb` |
| Render friren | MODEL-FRIREN | `friren.glb` |
| Render gatobotas | MODEL-GATOBOTAS | `gatobotas.glb` |
| Render lufyHead | MODEL-LUFYHEAD | `lufyHead.glb` |
| Render mander | MODEL-MANDER | `mander.glb` |
| Render mario | MODEL-MARIO | `mario.glb` |
| Render zard | MODEL-ZARD | `zard.glb` |

A associação é lida dos dados reais da API por `product_images.path`; o
frontend não contém um mapa fixo de produtos. A API também omite modelos cujo
arquivo não existe no armazenamento público. Se o arquivo falhar durante a
renderização, o card mantém o placeholder acessível.

Mouse, gestos de toque, zoom, enquadramento inicial e rotação automática
opcional são oferecidos no modal. O modal nativo pode ser fechado pelo botão
ou por Escape, mantém o foco acessível e devolve o foco ao controle que o
abriu. A rotação automática começa desligada. As imagens aceitas como prévia
são JPEG, PNG e WebP; não há conversão de imagem 2D em modelo.

Validação realizada via Nginx com os dados reais da API: os sete produtos com
modelo aparecem no catálogo público; os oito sem modelo ficam inativos e
visíveis apenas na administração. `Render friren` foi baixado,
interpretado e renderizado no navegador. A interação por mouse, zoom,
rotação automática, reset, fechamento por Escape e layout em viewport móvel
foram testados. Toque físico em dispositivo móvel e a renderização dos outros
seis modelos ainda precisam de validação manual; o `dbz.glb` (103 MB) não
terminou de carregar no intervalo de 45 segundos observado. O catálogo atual
não possui prévias raster reais; a interface de imagem/fallback foi testada
com uma imagem de fixture, sem gravar arquivos ou dados no projeto.

## Autenticação da API

A API usa Laravel Sanctum com sessão do guard `web`; não emite tokens bearer.
O Nginx expõe frontend, `/api` e `/sanctum` na mesma origem (`localhost:8080`),
e o middleware stateful do Sanctum é habilitado em `bootstrap/app.php`.
Mantenha os hosts de acesso em `SANCTUM_STATEFUL_DOMAINS` no `.env`.

Antes do primeiro login ou cadastro, solicite o cookie CSRF:

```http
GET /sanctum/csrf-cookie
```

Envie os cookies automaticamente nas requisições e o valor URL-decoded do
cookie `XSRF-TOKEN` no header `X-XSRF-TOKEN` para métodos não seguros.
O cookie de sessão é HttpOnly; não armazene cookies nem tokens em `localStorage`.
Login e logout regeneram a sessão/token CSRF, e o Laravel devolve o cookie
CSRF atualizado nas respostas.

Endpoints:

- `POST /api/register`: `name`, `username`, `email`, `password`,
  `password_confirmation` e `terms_accepted: true`. `TERMS_VERSION` configura a
  versão gravada. Responde `201` com dados públicos e orientação para fazer
  login; cadastro **não** autentica automaticamente. O papel `client` é
  atribuído pelo servidor; campos de papel enviados pelo cliente são ignorados.
- `POST /api/login`: `login` (e-mail ou username) e `password`. Responde com o
  usuário público e papéis; credenciais inválidas recebem `401` genérico.
- `GET /api/me`: sessão autenticada requerida; retorna perfil e papéis.
- `POST /api/logout`: sessão autenticada e CSRF requerido; invalida a sessão.
- `GET /api/admin/me`: requer sessão e papel `admin`; clientes autenticados
  recebem `403`, visitantes `401`.

Exemplo de cadastro:

```json
{
  "name": "Nome do cliente",
  "username": "cliente-01",
  "email": "cliente@example.com",
  "password": "UmaSenhaForte123!",
  "password_confirmation": "UmaSenhaForte123!",
  "terms_accepted": true
}
```

Login recebe `{"login":"cliente-01","password":"..."}`. Sucesso de cadastro
responde `201`; login, `/me` e `/admin/me` respondem com
`{"data":{"id":1,"name":"...","username":"...","email":"...","roles":[{"slug":"client","name":"Cliente"}]}}`.
Login limita a cinco tentativas por minuto por IP.

O resource público não expõe senha, hash, tokens ou remember token. A API ainda
não implementa recuperação de senha nem verificação de e-mail.

## Carrinho

Os endpoints públicos funcionam para visitantes e usuários autenticados:

- `GET /api/cart`: retorna o carrinho ativo atual ou cria um carrinho vazio.
- `POST /api/cart/items`: recebe `product_id` e `quantity`; retorna o carrinho.
- `PATCH /api/cart/items/{id}`: recebe a nova `quantity` de um item próprio.
- `DELETE /api/cart/items/{id}`: remove um item próprio e retorna o carrinho.

Todos retornam JSON e HTTP `200` em operações concluídas. Dados inválidos,
produto inativo ou quantidade indisponível retornam `422`; item inexistente ou
que não pertença ao carrinho atual retorna `404`. O servidor determina o
proprietário; `user_id`, `cart_id`, preço e subtotal enviados pelo cliente não
controlam o carrinho nem os valores. Repetir a inclusão do mesmo produto
atualiza a quantidade para o valor solicitado em vez de duplicar o item.

Depois do login, se a fusão automática encontrar conflitos, a pessoa autenticada
pode consultar e corrigir o carrinho visitante sem expor o token:

- `GET /api/cart/guest`: consulta o carrinho ativo identificado pelo cookie.
- `PATCH /api/cart/guest/items/{id}`: atualiza um item desse carrinho.
- `DELETE /api/cart/guest/items/{id}`: remove um item desse carrinho.
- `POST /api/cart/merge-guest`: tenta novamente a fusão transacional.

Essas quatro rotas exigem sessão Sanctum e usam exclusivamente o cookie
HttpOnly existente para localizar o carrinho. Cookie ausente, inválido ou item
de outro carrinho retorna `404`. A resposta de fusão mantém o estado em `data`;
em sucesso informa `merged_quantity` e invalida o cookie. Em conflito, preserva
os dois carrinhos e o cookie. Nenhuma operação altera o estoque.

Para visitantes, o backend cria um token aleatório e o guarda em cookie
`medelfiguri_guest_cart`, HttpOnly, SameSite=Lax e válido por 30 dias. O cookie
é seguro em conexões HTTPS; frontend e API devem permanecer na mesma origem
proxyada pelo Nginx e dentro de `SANCTUM_STATEFUL_DOMAINS`. O token não aparece
no JSON e não deve ser lido nem persistido pelo JavaScript.

Usuários autenticados usam o carrinho ativo associado à sessão Sanctum e ao
usuário autenticado. Cada linha retorna preço unitário e subtotal usando o
preço atual do banco; o carrinho informa `total_quantity` e `subtotal` geral.
Valores monetários são devolvidos como strings decimais exatas. A quantidade
deve estar disponível no estoque atual, mas adicionar ou remover itens não
altera nem reserva o estoque.

Após login bem-sucedido, a API tenta fundir automaticamente o carrinho de
visitante indicado pelo cookie na conta autenticada. Produtos repetidos têm as
quantidades somadas; produtos exclusivos são transferidos. O carrinho de
origem fica com status `merged` e `merged_into_id` aponta para o destino. A
fusão é transacional e idempotente; itens de origem só são removidos depois de
validados e copiados.

A resposta de login preserva `data` com o usuário e acrescenta `cart_merge` no
nível superior. O campo `status` é `merged`, `no_guest_cart` ou
`needs_attention`. Em conflitos, `conflicts` identifica produto, quantidade
solicitada, estoque atual e motivo. O login permanece válido, nenhum dos
carrinhos nem o estoque é alterado e o cookie visitante é preservado. Em
sucesso, apenas o cookie `medelfiguri_guest_cart` é invalidado; a sessão de
autenticação permanece. A página `/carrinho.html` apresenta os itens
conflitantes, permite reduzir quantidades ou remover produtos e disponibiliza
a nova tentativa. Após o login, o frontend redireciona para essa página quando
a fusão requer atenção ou transferiu unidades.

O catálogo oferece inclusão de produtos disponíveis. O contador do cabeçalho
representa a soma das unidades confirmadas pela API; durante um conflito soma
as unidades dos carrinhos da conta e de visitante. A página do carrinho mostra
preços e subtotais recebidos do backend, estados de disponibilidade e controles
de atualização/remoção; o checkout fica disponível quando há itens disponíveis.
Se a API falhar, o
contador informa que está indisponível em vez de manter um valor possivelmente
desatualizado.

### Checkout da API

`POST /api/checkout` exige sessão autenticada Sanctum. O servidor usa somente
o carrinho ativo do usuário autenticado; carrinho vazio, produto inativo,
quantidade inválida ou estoque insuficiente retornam `422`. O checkout calcula
preços e totais a partir do banco e processa pedido, itens, snapshot do
endereço, baixa de estoque, movimentações e conversão do carrinho em uma única
transação. Falhas revertem todas essas alterações.

Como ainda não existe API de endereços salvos, envie o endereço de entrega no
corpo da requisição para ser registrado como snapshot histórico:

```json
{
  "address": {
    "recipient_name": "Nome do destinatário",
    "cep": "01001-000",
    "street": "Praça da Sé",
    "number": "100",
    "complement": "",
    "neighborhood": "Sé",
    "city": "São Paulo",
    "state": "SP"
  }
}
```

O frete nesta versão é fixo em `R$ 0,00`. Campos de usuário, carrinho e valores
enviados pelo cliente não são usados para definir o pedido. O pedido é criado com status `pending` e método `pending`. A resposta pública
inclui status do pagamento e `paid_at`, sem expor o proprietário do pedido.

## Pagamento simulado e pedidos

Não há gateway financeiro nem cobrança real. A confirmação é uma ação explícita
e não solicita nem aceita dados de cartão:

- `POST /api/orders/{id}/confirm-payment`: somente o dono autenticado do pedido;
  transiciona `pending` para `paid`, define `payment_method=simulated` e grava
  `paid_at`. Repetições retornam `200` sem efeitos duplicados e indicam
  `payment.already_confirmed`; pedido de outra conta retorna `404`; estados
  incompatíveis e campos de cartão proibidos retornam `422`.
- `GET /api/orders`: lista paginada dos pedidos do usuário autenticado.
- `GET /api/orders/{id}`: detalhes do pedido próprio; pedidos inexistentes ou
  de terceiros retornam `404`.
- `POST /api/orders/{id}/cancel`: permite cancelar `pending` e `paid` antes da
  preparação. A devolução de estoque ocorre somente para movimentações `sale`
  vinculadas ao pedido, na mesma transação, e somente uma vez. Pedidos
  `processing`, `shipped` ou `delivered` não são canceláveis por essa rota;
  não existe estorno financeiro.

O fluxo administrativo avança somente `paid → processing → shipped →
delivered`. O pagamento continua restrito ao endpoint de confirmação simulada.

## Administração

Todas as rotas `/api/admin/*` requerem sessão Sanctum e papel `admin`; clientes
recebem `403`, visitantes `401`.

- `/api/admin/products`: listar, criar, consultar, atualizar e arquivar produtos.
  Atualizações comuns não podem alterar estoque; cadastro de estoque inicial e
  ajustes geram movimentações auditáveis.
- `/api/admin/products/{productId}/images`: upload de imagem raster (JPEG, PNG
  ou WebP, até 5 MiB) e remoção por produto/imagem. Os arquivos usam o disk
  público e nomes gerados pelo Laravel.
- `/api/admin/categories`: CRUD lógico; arquivamento mantém registros e
  relacionamentos. Ciclos hierárquicos são rejeitados.
- `POST /api/admin/products/{productId}/stock-adjustments` e
  `GET /api/admin/inventory/movements`: ajustes transacionais sem estoque
  negativo e histórico de movimentações.
- `/api/admin/orders`: consulta de pedidos, cliente e movimentações. A rota
  `PATCH /api/admin/orders/{id}/status` valida cada transição permitida e não
  permite ao administrador marcar pagamento.

A página `/admin.html` consome essas APIs; autorização é aplicada no backend,
independentemente da interface.

## Swagger UI / OpenAPI

Com o Compose iniciado, acesse <http://localhost/api/docs>. A interface e a
especificação OpenAPI 3.1 (`api/openapi.yaml`) são servidas pelo backend; o
documento também está disponível em <http://localhost/api/docs/openapi.yaml>.
As requisições de escrita pela interface solicitam CSRF e enviam
`X-XSRF-TOKEN`; rotas protegidas usam o cookie de sessão Sanctum. Nenhuma
credencial real está incluída no documento.

## Páginas de autenticação

- `/login.html`: autentica com e-mail ou nome de usuário e confirma o usuário
  atual consultando `GET /api/me` antes de retornar ao catálogo ou apresentar a
  situação da fusão na página do carrinho.
- `/cadastro.html`: envia nome, username, e-mail, senha, confirmação e aceite
  explícito dos termos. O cadastro não inicia uma sessão; após o sucesso, a
  pessoa deve entrar pela página de login.
- `frontend/src/api/api.js` centraliza Fetch, cookies de sessão, cabeçalho CSRF
  e erros HTTP. Para operações não seguras, solicita primeiro
  `GET /sanctum/csrf-cookie`, lê o cookie `XSRF-TOKEN` e envia
  `X-XSRF-TOKEN` com `credentials: 'include'`.
- `frontend/src/domain/auth.js` consulta `/api/me` como fonte de verdade e
  realiza logout por `POST /api/logout`. A interface não armazena sessão, senha
  ou token em `localStorage` ou `sessionStorage`.
- O código do frontend fica em `frontend/src`: `api/` para comunicação HTTP,
  `app/` para os scripts das páginas, `domain/` para regras e estado da loja,
  `ui/` para componentes e comportamento visual, `styles/` para CSS e `assets/`
  para recursos estáticos. `src/main.js` inicia a página principal; os HTMLs
  ficam na raiz de `frontend/` para preservar as URLs atuais. Arquivos públicos,
  incluindo o viewer 3D fornecido pelo build Docker, ficam em `frontend/public/`.
- O Nginx serve as páginas e encaminha `/api` e `/sanctum` para o backend na
  mesma origem. O catálogo permanece público; a interface não substitui a
  autorização feita pelo backend.

Recuperação de senha, verificação de e-mail, gateway de pagamento e cálculo
real de frete continuam fora do escopo atual.

## Testes locais

```sh
php artisan test
```

Última execução completa: **96 testes aprovados, 545 assertions**.
