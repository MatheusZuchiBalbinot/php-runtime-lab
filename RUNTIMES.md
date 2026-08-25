# Os runtimes e como foram padronizados

Referência dos 8 deployments medidos por este lab: o que cada um é, como
atende uma request, e o que precisou ser feito para que a comparação entre
eles signifique alguma coisa.

Os números citados vêm da corrida publicada
(`run-20260825-1101`, 1 CPU / 512MB / 4 workers, 3 amostras de 60s por rota),
medida em Docker Desktop sobre WSL2. A tabela completa e a análise estão em
[benchmarks/results/README.md](benchmarks/results/README.md).

---

## Parte 1 — Características de cada runtime

### O eixo que separa todos eles

A pergunta que o lab existe para responder é **o que acontece com o estado do
PHP entre uma request e a próxima**. Há três respostas possíveis, e elas
explicam quase tudo:

| Modelo | O que acontece por request | Custo | Risco |
|---|---|---|---|
| **Bootstrap por request** | processo novo (ou reciclado) carrega tudo de novo | paga autoload e boot toda vez | nenhum: estado nasce limpo |
| **Worker persistente bloqueante** | processo vive; uma request por worker por vez | boot pago uma vez | vazamento de estado entre requests |
| **Worker persistente com corrotinas** | processo vive; muitas requests em voo no mesmo worker | idem, e espera não prende worker | idem, mais concorrência dentro do processo |

---

### `fpm` — PHP-FPM + nginx

**Arquitetura:** nginx recebe HTTP e repassa via FastCGI para um pool de
processos PHP-FPM. Cada request é atendida por um processo que carrega a
aplicação do zero.

**Estrutura:**

```
docker/fpm/Dockerfile      imagem php:8.3-fpm-alpine com OPcache
docker/fpm/www.conf        pool estatico; pm.max_children reescrito no start
docker/fpm/entrypoint.sh   injeta APP_WORKERS no www.conf antes do php-fpm subir
docker/nginx/default.conf  proxy FastCGI, sem pool de conexao (ver Parte 2)
app/public/index.php       o adapter
```

O entrypoint existe porque o PHP-FPM não interpola variável de ambiente em
diretiva de pool: exportar `APP_WORKERS` no container não basta, o valor tem de
ser escrito no arquivo antes do parse.

**Concorrência:** um processo por request simultânea. O teto é literalmente a
contagem de workers.

**Medido:** 9,2 MiB ocioso, o menor do lab. `noop` 2760 rps, `blocking_wait`
369 rps contra um teto teórico de `4 ÷ 0,010s = 400`. A medição encostar na
teoria é a melhor evidência de que o aparato mede o modelo, e não outra coisa.

**Trade-off:** menor footprint e imunidade a vazamento de estado, porque cada
request começa num processo limpo. Em troca, paga o bootstrap toda vez e
qualquer espera prende um worker inteiro. É também o único modelo que precisa
de um proxy na frente, e essa CPU não é de graça (ver
[a análise](benchmarks/results/README.md)).

---

### `swoole` — servidor HTTP em extensão C

**Arquitetura:** extensão C que embarca um servidor HTTP com event loop. O
`server.php` do lab chama `Runtime::enableCoroutine(SWOOLE_HOOK_ALL)`, que
troca funções bloqueantes do PHP (`usleep`, streams, cURL) por versões que
cedem a corrotina.

**Estrutura:**

```
docker/swoole/Dockerfile   compila a extensao via pecl, mantem libstdc++
docker/swoole/server.php   o adapter, e o unico runtime cujo servidor e PHP
```

O `server.php` chama `Runtime::enableCoroutine(SWOOLE_HOOK_ALL)` na inicialização
e monta o `Swoole\Http\Server` com a contagem de workers vinda do orçamento
compartilhado. É a única imagem que compila extensão em build.

**Concorrência:** muitas requests em voo por worker. Uma request em espera não
ocupa o worker; ele atende outra enquanto isso.

**Medido:** 12,3 MiB ocioso. `noop` 12378 rps, `blocking_wait` 13158 rps —
35,7× o FPM na mesma rota, com a mesma cota de workers. É o único runtime que
rompe o teto de `workers ÷ espera`, e o único que segura p95 abaixo de 200ms
nas seis rotas.

**Ressalva:** o 35,7× vem de `blocking_wait`, que é um `usleep`, o melhor caso
idealizado para corrotina. Em `external_io`, com socket de verdade, cai para
5499 rps — ainda 18× o FPM, mas 42% do número idealizado. Com um driver de
banco a pergunta se repete e o lab ainda não a responde.

**Trade-off:** a maior vazão do lab por larga margem, com footprint quase igual
ao do FPM. Em troca, o estado vive entre requests dentro do worker, então
vazamento e variável estática viram problema real, e várias corrotinas rodando
dentro do mesmo processo exigem que o código seja seguro para isso.

---

### `roadrunner` — servidor Go + workers PHP

**Arquitetura:** um binário Go faz todo o handling HTTP e supervisiona
processos PHP persistentes, falando com eles por um protocolo binário sobre
pipes (goridge). O worker PHP roda um loop PSR-7.

**Estrutura:**

```
docker/roadrunner/Dockerfile      binario rr copiado de imagem propria, versao pinada
docker/roadrunner/.rr.yaml        num_workers e max_jobs vem do orcamento
docker/roadrunner/composer.json   spiral/roadrunner-http, o worker PSR-7
docker/roadrunner/composer.lock   instalado no build, nunca resolvido de novo
docker/roadrunner/worker.php      o adapter
```

A versão do binário é pinada na mesma release que o `composer.lock` resolve:
servidor e biblioteca falam um protocolo compartilhado, e deixar os dois
divergirem é quebra latente.

**Concorrência:** uma request por worker por vez, bloqueante como o FPM. A
diferença é que o boot é pago uma vez, não por request.

**Medido:** 46,2 MiB ocioso. `noop` 2688 rps, praticamente empatado com o FPM
(-2,6%). Cinco das seis rotas ficam dentro de ±3,5%; só `external_io` abre
distância (+15,7%). O motivo é que o app vanilla deste lab quase não tem
bootstrap para economizar — um autoloader curto e sete classes. Com um
framework por cima a distância cresce, que é o que os 5,9× de custo do Laravel
sugerem.

**Detalhe operacional que custou caro:** o protocolo é binário sobre STDOUT.
Qualquer `warning` do PHP impresso ali corrompe o frame e derruba o worker com
erro de CRC. Por isso `display_errors` aponta para `stderr`.

**Trade-off:** worker persistente sem precisar de extensão C compilada, e o
handling HTTP fica num binário Go maduro. Em troca, o footprint ocioso é 5×
o do FPM, o protocolo é frágil a qualquer saída inesperada em STDOUT, e o
ganho sobre o FPM só aparece quando há bootstrap de verdade para economizar.

**Confundimento a ter em mente:** quando o RoadRunner ganha do FPM, parte do
ganho pode vir do servidor Go e não do worker persistente. Os dois efeitos
estão acoplados neste design — é o par FrankenPHP que separa os dois.

---

### `frankenphp` — Caddy embarcando PHP, modo clássico

**Arquitetura:** um binário Go (Caddy) com o PHP embarcado. No modo clássico
faz o bootstrap por request, como o FPM, mas sem processo separado nem
FastCGI — o PHP roda dentro do próprio servidor.

**Estrutura:** a imagem é compartilhada com o modo worker; só o Caddyfile muda.

```
docker/frankenphp/Dockerfile         imagem dunglas/frankenphp, uma so para os dois modos
docker/frankenphp/Caddyfile.classic  num_threads = orcamento; sem bloco worker
docker/frankenphp/public/index.php   o adapter do modo worker (nao usado aqui)
app/public/index.php                 o adapter que o modo classico serve
```

**Concorrência:** limitada pelo pool de threads. Uma request ocupa uma thread
pelo tempo que durar, então a contagem de threads *é* a concorrência.

**Medido:** 19,5 MiB ocioso. `noop` 2480 rps, `blocking_wait` 375 rps —
mesma faixa do FPM, como o modelo prevê.

**Por que importa:** é o FPM sem o hop de proxy e sem FastCGI, o que separa
quanto daquele modelo é custo de arquitetura e quanto é custo de transporte.

**Trade-off:** um binário só, sem proxy separado nem pool FastCGI para
configurar, mantendo o estado limpo a cada request. Em troca, paga o bootstrap
toda vez, igual ao FPM.

---

### `frankenphp-worker` — o mesmo binário, worker persistente

**Arquitetura:** mesma imagem, mesmo servidor, **só o Caddyfile muda**. O
script do worker roda um loop com `frankenphp_handle_request()`.

**Estrutura:**

```
docker/frankenphp/Dockerfile         a mesma imagem do modo classico
docker/frankenphp/Caddyfile.worker   bloco worker + num_threads = workers + 1
docker/frankenphp/public/index.php   o loop com frankenphp_handle_request()
```

O adapter fica no document root de propósito: o FrankenPHP só entrega a request
a um worker quando o script que a URL resolve *é* o arquivo do worker. Com ele
fora da raiz, o servidor atende pelo entrypoint clássico e o worker nunca vê
request nenhuma, o que de fora é indistinguível de worker mode funcionando.

**Concorrência:** os workers são **threads** dentro do processo do Caddy, não
processos separados, o que é diferente de todos os outros runtimes do lab.

**Medido:** 20,2 MiB ocioso, praticamente igual ao modo clássico. `noop` 4483
rps contra 2480 do clássico (1,81×), `json` 1,62×, `cpu` 1,56× — e
`blocking_wait` 378 contra 375, ou seja 1,01×, porque ali não há bootstrap a
economizar.

**Por que é o par mais valioso do lab:** comparar `frankenphp` com
`frankenphp-worker` isola "worker persistente" sem trocar de servidor junto.
Nenhum outro par consegue isso; comparar `fpm` com `roadrunner` muda o modelo
de worker *e* o servidor ao mesmo tempo.

**Trade-off:** o ganho de bootstrap sem nenhum custo de memória perceptível
sobre o modo clássico, e sem trocar de stack. Em troca, herda o risco de estado
entre requests de qualquer worker persistente.

---

### `laravel-fpm`, `laravel-octane-swoole`, `laravel-octane-roadrunner`

Os mesmos três modelos acima, com um framework real por cima. Executam **os
mesmos handlers** que as variantes vanilla — o `composer.json` do Laravel
mapeia `RuntimeLab\` para o mesmo `app/src/`.

**Estrutura:**

```
laravel/                                    a aplicacao, com RuntimeLab\ mapeado para app/src
laravel/app/Http/Controllers/BenchmarkController.php   o adapter
docker/laravel-fpm/Dockerfile               FPM + nginx, via docker/nginx/laravel.conf
docker/laravel-octane-swoole/Dockerfile     Octane sobre Swoole
docker/laravel-octane-roadrunner/Dockerfile Octane sobre RoadRunner
docker/laravel-shared/entrypoint.sh         config:cache e route:cache no start
```

O cache de config roda no start do container e não no build, porque
`config:cache` congela os valores de `env()` no momento em que roda — cachear
no build faria os três serviços reportarem o mesmo label errado.

**Medido — ocioso, de 512 MiB, e vazão no `noop`:**

| | vanilla | com Laravel | custo do framework |
|---|---|---|---|
| FPM | 9,2 MiB · 2760 rps | 26,8 MiB · 470 rps | +17,6 MiB · 5,9× mais lento |
| Swoole | 12,3 MiB · 12378 rps | 151,0 MiB · 754 rps | +138,7 MiB · 16,4× mais lento |
| RoadRunner | 46,2 MiB · 2688 rps | 122,8 MiB · 515 rps | +76,6 MiB · 5,2× mais lento |

O framework fica **residente** no worker persistente, então o custo de memória
é muito maior ali do que no FPM, que sobe e derruba o processo. É o custo de
existir, antes de qualquer request.

A linha do Swoole é a que engana: 16,4× parece o custo do framework, mas ali há
dois efeitos somados, porque o Octane também desliga a corrotina. A comparação
limpa do custo do framework é a do FPM, 5,9×, onde o modelo de execução é o
mesmo dos dois lados.

Vale notar que o footprint só é decisivo em container apertado: os 151 MiB do
Octane/Swoole são 29% de um orçamento de 512 MB e 3,7% de um host de 4 GB.

**O Octane sobre Swoole sequencia as requests.** Apesar de rodar sobre Swoole,
ele define `enable_coroutine => false` nos próprios defaults: o container e as
facades do Laravel guardam estado por request que corrotinas concorrentes
dentro de um worker corromperiam. Na taxonomia do lab ele é **worker
persistente**, não worker de corrotina — igual ao RoadRunner. Medido, não
presumido: na rota de espera de 10 ms escoou 333 rps, o teto de
workers÷latência, contra 13.158 do Swoole vanilla no mesmo handler. Ligar a
flag não deixaria mais rápido, deixaria errado.

A consequência para leitura: a diferença entre `swoole` e
`laravel-octane-swoole` **não** é só o custo do framework — é modelo de
execução diferente.

**Escopo do que é medido:** as rotas do Laravel são registradas como API
**stateless**. O grupo `web` iniciaria sessão e validaria CSRF — trabalho real,
mas que nenhum endpoint JSON faria. Então o "custo do Laravel" aqui **exclui**
sessão e CSRF.

---

## Parte 2 — O que foi feito para padronizar as capacidades

Cada item abaixo existe porque, sem ele, a comparação media outra coisa.
Vários foram descobertos como bug, não previstos.

### 1. O mesmo código, não código equivalente

Todos os 8 executam as classes `RuntimeLab\Handlers\*`. Não há
reimplementação por runtime. Os adapters (`server.php`, `worker.php`,
`index.php`) apenas traduzem request/response nativos para os DTOs
compartilhados.

`routes.json` é a fonte única dos paths, lida pelo PHP e pelo k6.

### 2. Mesma versão de PHP e mesmo `php.ini`

`docker/shared/php.ini` é montado nos 8. **Verificado em runtime**, não
presumido — e foi assim que apareceu o bug: o OPcache estava **inativo** no
Swoole e no RoadRunner. Eles rodam sob SAPI `cli`, onde `opcache.enable` é
ignorado e só `opcache.enable_cli` vale. O baseline estava tunado e os
concorrentes não.

Hoje: `enabled=true`, `enable_cli=1`, `validate_timestamps=0`, PHP 8.3.33 nos
oito.

### 3. Mesma contagem de workers, por fórmula

```
APP_WORKERS = max(1, ceil(APP_CPUS × workers_per_cpu))
```

Uma fórmula, aplicada aos 8. Antes eram números arbitrários e desiguais
(FPM 16, Swoole 2, RoadRunner 2) — e na rota bloqueante isso **decide o
resultado sozinho**: com 10ms de espera, 16 workers chegam a 1600 rps e 2
workers travam em 200, por configuração.

Pior era o Octane, que **ignorava** a configuração e usava `auto`. Dentro de
um container `auto` resolve para os cores do **host**, porque `cpus: 1.0` é
cota CFS e não cpuset: ele subia ~12 workers num orçamento de 1 CPU e ocupava
**458 MiB de 512 MiB antes de receber qualquer request**.

Exceção documentada: o FrankenPHP em worker mode exige `num_threads` maior que
a contagem de workers, senão se recusa a subir. A thread extra é exigência
estrutural do servidor, não capacidade a mais — a grandeza mantida igual
continua sendo o número de workers.

#### De onde vêm os valores, e o que muda se você trocá-los

Os três parâmetros que definem o orçamento estão em `.env` e
`performance.json`. Nenhum deles foi calibrado por experimento; são escolhas,
e vale saber o efeito de cada uma.

| Parâmetro | Valor | Por quê |
|---|---|---|
| `APP_CPUS` | `1.0` | força o cenário onde o modelo de execução importa. Com CPU sobrando, quase tudo entrega o suficiente e a comparação fica sem graça |
| `APP_MEM` | `512m` | container pequeno, tipo pod de Kubernetes com limite apertado. É o que faz o footprint ocioso virar critério: 151 MiB do Octane são 29% desse orçamento |
| `workers_per_cpu` | `4` | convenção para workload PHP bloqueante, onde o worker passa tempo parado esperando e por isso compensa ter mais workers que núcleos |

O `workers_per_cpu` é o mais consequente dos três, porque ele **define
sozinho** o teto da rota `blocking_wait`: `workers ÷ espera`, ou
`4 ÷ 0,010s = 400 rps`. Sete dos oito runtimes ficaram entre 302 e 378, ou
seja, colados nesse teto. Subir para 8 dobraria o número deles sem que nada
tenha melhorado — e é exatamente por isso que o valor é igual para todos:
variá-lo sem perceber infla a comparação inteira.

O orçamento também não é o único recurso em jogo. O nginx, o stub e o k6 têm
orçamentos próprios e deliberadamente folgados, para que nenhum deles vire o
gargalo medido. A tabela completa está em
[benchmarks/results/README.md](benchmarks/results/README.md), junto com a
consequência de o proxy consumir CPU real que o orçamento nominal do app não
conta.

### 4. Mesma política de reciclagem

`APP_MAX_REQUESTS = 0` nos 8, ou seja **nunca reciclar**, que é o default de
todos menos o Octane. Era inconsistente: FPM reciclava a cada 500, Swoole
vanilla nunca, RoadRunner vanilla nunca, Octane a cada 500. Reciclar custa um
bootstrap completo, então a política desigual penalizava silenciosamente quem
reciclava.

O valor esteve em 500 por um tempo, e desligá-lo mudou o resultado do Swoole em
ordem de grandeza: `blocking_wait` saiu de ~1.900 rps com p99 de 3.064ms para
13.158 rps com p99 de 46ms. A causa é o `max_wait_time`, que limita a 3s a
espera pelo dreno de um worker reciclando — e um worker de corrotina tem dezenas
de requests em voo quando isso acontece. O comentário em
`docker/swoole/server.php` documenta isso para que religar reciclagem não
reintroduza a cauda sem ninguém entender de onde veio.

### 5. Mesmo protocolo HTTP

O Caddy negocia HTTP/2 por padrão; nginx e os demais respondem HTTP/1.1 ao k6.
Comparar throughput entre protocolos mede o protocolo, não o runtime. Os dois
Caddyfiles fixam `protocols h1`.

### 6. Laravel em modo produção equivalente

`APP_DEBUG=false`, `APP_ENV=production`, e `config:cache` + `route:cache`
executados **no start do container**, não no build — porque `config:cache`
congela os valores de `env()`, e cachear no build faria os três serviços
reportarem o mesmo label errado.

Sem esse cache, o Laravel reparseia toda a config a cada request no FPM, e o
viés é assimétrico: penaliza justamente o `laravel-fpm`, metade da comparação
"clássico vs Octane".

### 7. O proxy não pode ser o gargalo

Descoberto por instrumentação, com experimento controlado:

| nginx | RPS máx (`noop`) | nginx (pico/limite) | app-fpm (pico/limite) |
|---|---|---|---|
| 0.5 CPU | 1600 | **101% — saturado** | 65% |
| 2.0 CPU | **2400** | 38% | **87%** |

Com 0.5 CPU o número do FPM era o teto do **nginx**, não do FPM. O default
subiu para 2.0, e a varredura agora **avisa** se o proxy passar de 90% do
próprio orçamento.

Mais dois problemas do mesmo tipo apareceram depois, e ambos cobravam só das
duas variantes FPM — os outros seis servem HTTP sozinhos e não pagam esse hop:

**O nginx abria uma conexão FastCGI nova a cada request.** Sem
`fastcgi_keep_conn` e sem upstream com `keepalive`, cada request pagava
handshake e deixava um socket em TIME_WAIT. Corrigido, o `noop` do FPM foi de
2211 para 2968 rps.

**E rodava 12 processos worker num orçamento de 2 CPUs**, porque
`worker_processes auto` conta os cores do *host* — `cpus:` é cota CFS, não
cpuset. Passou a usar o mecanismo da própria imagem oficial, que lê a cota do
cgroup. Com as duas correções: **3087 rps**, 40% acima do ponto de partida.

O mesmo padrão "default que enxerga o host" apareceu três vezes no lab, em
componentes diferentes: aqui, no FrankenPHP clássico adivinhando a contagem de
threads, e no Octane adivinhando a de workers. Dentro de container é uma
categoria de erro, não um acidente isolado.

### 8. Um só rótulo de runtime, vindo do ambiente

O entrypoint clássico é servido por mais de um runtime (FPM atrás do nginx e
FrankenPHP clássico). Com o nome cravado no código, os dois se reportavam como
`fpm` — uma coluna inteira da tabela sairia rotulada errada.

---

## O que continua não sendo comparável

Registrado porque afeta como os resultados podem ser publicados:

- **nginx só na frente das variantes FPM.** Inerente ao modelo — não existe
  FPM sem servidor web na frente — mas é um hop que os outros não pagam.
- **`blocking_wait` não é I/O.** É `usleep`; o melhor caso para corrotinas.
- **`memory` mede banda, não capacidade.** Com contagem fixa de workers,
  capacidade não pode ser o gargalo: encher 512 MiB exigiria ~128 MiB por
  request, e escrever tudo isso derruba o throughput antes. Capacidade aparece
  como o footprint ocioso, na tabela da Parte 1.
- **O k6 vira o teto** em combinações rápidas — já observado (7704 rps
  entregues contra 12800 pedidos).
- **Cliente e servidor na mesma máquina**, com a rede virtualizada do Docker
  Desktop somando custo fixo a todos — o que **comprime** as diferenças
  relativas, sobretudo na rota `noop`.
