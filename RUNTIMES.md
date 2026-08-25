# Os runtimes e como foram padronizados

Referência dos 8 deployments medidos por este lab: o que cada um é, como
atende uma request, e o que precisou ser feito para que a comparação entre
eles signifique alguma coisa.

Os números citados foram **medidos nesta máquina** (Docker Desktop / WSL2,
12 cores, 8 GB para a VM) durante a construção do lab. Valem como ordem de
grandeza e como evidência das decisões — não como resultado publicável, que é
o que a Fase 4 vai produzir.

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

**Concorrência:** um processo por request simultânea. O teto de concorrência é
literalmente a contagem de workers.

**Medido aqui:** ~10 MiB ocioso, 2 MiB por worker. Na rota de espera
bloqueante sustentou **350 rps** com 4 workers — contra um teto teórico de
`4 ÷ 0,010s = 400 rps`. A medição encostar na teoria é a melhor evidência de
que o aparato está medindo o modelo, e não outra coisa.

**Onde brilha:** o menor footprint do lab, e imunidade a vazamento de estado.

**Onde sofre:** qualquer espera prende um worker inteiro.

---

### `swoole` — servidor HTTP em extensão C

**Arquitetura:** extensão C que embarca um servidor HTTP com event loop. O
`server.php` do lab chama `Runtime::enableCoroutine(SWOOLE_HOOK_ALL)`, que
troca funções bloqueantes do PHP (`usleep`, streams, cURL) por versões que
cedem a corrotina.

**Concorrência:** muitas requests em voo por worker. Uma request em espera não
ocupa o worker — ele atende outra enquanto isso.

**Medido aqui:** ~13 MiB ocioso, 4 MiB por worker. Na rota de espera passou de
**6400 rps sem saturar** (o gerador de carga estourou antes do runtime) —
contra os 350 do FPM com a mesma cota de workers. Fator ~18×, atribuível ao
modelo de concorrência porque tudo o mais estava igualado.

**Ressalva importante:** esse número vem da rota `blocking_wait`, que é um
`usleep` — o **melhor caso idealizado** para corrotinas. Com I/O real a
vantagem depende do driver ser coroutine-aware, e é exatamente isso que a
rota `external_io` foi criada para descobrir.

---

### `roadrunner` — servidor Go + workers PHP

**Arquitetura:** um binário Go faz todo o handling HTTP e supervisiona
processos PHP persistentes, falando com eles por um protocolo binário sobre
pipes (goridge). O worker PHP roda um loop PSR-7.

**Concorrência:** uma request por worker por vez — **bloqueante**, como o FPM.
A diferença é que o boot é pago uma vez, não por request.

**Medido aqui:** ~53 MiB ocioso, 4 MiB por worker.

**Detalhe operacional que custou caro:** o protocolo é binário sobre STDOUT.
Qualquer `warning` do PHP impresso ali corrompe o frame e derruba o worker.
Um `use Throwable;` órfão no adapter gerava um warning e o RoadRunner morria
com erro de CRC. Por isso `display_errors` aponta para `stderr`.

**Confundimento a ter em mente:** quando o RoadRunner ganha do FPM, parte do
ganho pode vir do **servidor Go**, não do worker persistente. Os dois efeitos
estão acoplados neste design.

---

### `frankenphp` — Caddy embarcando PHP, modo clássico

**Arquitetura:** um binário Go (Caddy) com o PHP embarcado. No modo clássico
faz o bootstrap por request, como o FPM, mas sem processo separado nem
FastCGI — o PHP roda dentro do próprio servidor.

**Concorrência:** limitada pelo pool de threads.

**Medido aqui:** `worker_requests` sempre 1, confirmando bootstrap por
request.

**Por que importa:** é o FPM sem o hop de proxy e sem FastCGI — isola quanto
daquele modelo é custo de *arquitetura* e quanto é custo de *transporte*.

---

### `frankenphp-worker` — o mesmo binário, worker persistente

**Arquitetura:** mesma imagem, mesmo servidor, **só o Caddyfile muda**. O
script do worker roda um loop com `frankenphp_handle_request()`.

**Concorrência:** workers são **threads** dentro do processo do Caddy, não
processos separados — diferente de todos os outros runtimes do lab.

**Medido aqui:** `worker_requests` subindo (16 → 19) com o mesmo pid,
confirmando persistência real.

**Por que é o par mais valioso do lab:** comparar `frankenphp` com
`frankenphp-worker` isola "worker persistente" **sem trocar de servidor
junto**. Nenhum outro par consegue isso — comparar `fpm` com `roadrunner`
muda o modelo de worker *e* o servidor ao mesmo tempo.

---

### `laravel-fpm`, `laravel-octane-swoole`, `laravel-octane-roadrunner`

Os mesmos três modelos acima, com um framework real por cima. Executam **os
mesmos handlers** que as variantes vanilla — o `composer.json` do Laravel
mapeia `RuntimeLab\` para o mesmo `app/src/`.

**Medido aqui (ocioso, de 512 MiB):**

| | vanilla | com Laravel | custo do framework |
|---|---|---|---|
| FPM | ~10 MiB | ~28 MiB | ~18 MiB |
| Swoole | ~13 MiB | ~163 MiB | ~150 MiB |
| RoadRunner | ~53 MiB | ~192 MiB | ~139 MiB |

O footprint por worker sai de 4 MiB (Swoole vanilla) para 20 MiB
(Octane/Swoole): o framework fica **residente** no worker persistente. É o
custo de existir, antes de qualquer request.

**O Octane sobre Swoole sequencia as requests.** Apesar de rodar sobre Swoole,
ele define `enable_coroutine => false` nos próprios defaults: o container e as
facades do Laravel guardam estado por request que corrotinas concorrentes
dentro de um worker corromperiam. Na taxonomia do lab ele é **worker
persistente**, não worker de corrotina — igual ao RoadRunner. Medido, não
presumido: na rota de espera de 10 ms escoou 345 rps, o teto de
workers÷latência, contra 5.468 do Swoole vanilla no mesmo handler. Ligar a
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

### 4. Mesma política de reciclagem

`APP_MAX_REQUESTS = 500` nos 8. Era inconsistente: FPM reciclava a cada 500,
Swoole vanilla **nunca**, RoadRunner vanilla nunca, Octane a cada 500.
Reciclar custa um bootstrap completo, então a política desigual penalizava
silenciosamente quem reciclava.

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
