# Resultados de benchmark

> ℹ️ **Sobre esta análise.** Os números vêm da instrumentação do lab e do
> `report.php`, que os gera sem intervenção manual. O texto interpretativo
> abaixo — as seções "Leitura", "Por que os números são estes", "O que custa
> CPU de verdade", "Se a máquina fosse maior" e "O que este ambiente distorce"
> — foi escrito com auxílio de IA (Claude Opus 5, `claude-opus-5`) a partir dos
> dados medidos, e revisado contra a tabela: cada múltiplo citado foi
> recalculado a partir dos valores do relatório. Onde algo é hipótese e não
> medição, está marcado no texto.

## Corrida publicada

[`run-20260825-1101-php8.3-baseline-1cpu-512m-w4-s3x60s`](run-20260825-1101-php8.3-baseline-1cpu-512m-w4-s3x60s/report.md) — 1 CPU, 512MB, 4 workers, sem reciclagem, 3 amostras de 60s por rota, PHP 8.3, tuning baseline. Executada em 2026-08-25.

> ✅ **Íntegra.** As 48 células conferem a lei de Little dentro de ±15%, nenhuma request falhou em nenhum runtime, e o pico de saturação do host durante toda a corrida foi 25% — a máquina não disputou a medição. Nenhuma célula ficou acima do limiar de dispersão ruidosa (10%). O relatório completo, com as seis rotas, latência e memória, está no link acima.

### Vazão (rps, mediana de 3 amostras)

| Runtime | Modelo | `noop` | `cpu` | `blocking_wait` | `external_io` | `json` | `memory` |
|---|---|---|---|---|---|---|---|
| `swoole` | coroutine-worker | 12378 | 3160 | 13158 | 5499 | 4671 | 550 |
| `frankenphp-worker` | persistent-worker | 4483 | 2274 | 378 | 363 | 2799 | 461 |
| `fpm` | process-per-request | 2760 | 1456 | 369 | 305 | 1729 | 425 |
| `roadrunner` | persistent-worker | 2688 | 1468 | 363 | 353 | 1774 | 410 |
| `frankenphp` | embedded-per-request | 2480 | 1457 | 375 | 310 | 1723 | 417 |
| `laravel-octane-swoole` | persistent-worker | 754 | 629 | 333 | 327 | 734 | 315 |
| `laravel-fpm` | process-per-request | 470 | 414 | 310 | 253 | 432 | 242 |
| `laravel-octane-roadrunner` | persistent-worker | 515 | 447 | 302 | 294 | 480 | 248 |

### Leitura

A rota `blocking_wait` é onde a arquitetura fica mais visível, porque o
resultado dela dá para calcular de antemão. São 4 workers e uma espera fixa de
10ms, então qualquer runtime que prenda um worker durante a espera tem teto de
`4 ÷ 0,010 = 400 rps`. O que a tabela mostra: `frankenphp-worker` 378,
`frankenphp` 375, `fpm` 369, `roadrunner` 363, `laravel-octane-swoole` 333,
`laravel-fpm` 310, `laravel-octane-roadrunner` 302. Sete runtimes, todos abaixo
do teto, nenhum passando de 380.

O oitavo faz 13.158. `swoole` é o único com corrotina, e a corrotina cede o
worker durante a espera em vez de segurá-lo — o teto de `workers ÷ espera`
simplesmente não se aplica a ele. Que a previsão acerte sete casos e o único
desvio seja exatamente o runtime cuja arquitetura prevê o desvio é a evidência
mais forte de que o aparato mede o modelo de execução, e não outra coisa.

Para separar "worker persistente" de "servidor diferente", o par
`frankenphp`/`frankenphp-worker` é o único limpo: mesma imagem, mesmo binário,
só o Caddyfile montado muda. O ganho de não repetir o bootstrap aparece em
1,81× no `noop` (2480→4483), 1,62× no `json` (1723→2799) e 1,56× no `cpu`
(1457→2274). Já em `blocking_wait` são 1,01× (375→378) — ali o tempo é gasto
esperando, não bootando, então não há o que economizar.

O custo do framework sai de `laravel-fpm` contra `fpm`, os dois únicos
`process-per-request` da tabela. No `noop`: 470 contra 2760, ou ~5,9× mais
caro. Vale não confundir com a distância entre `swoole` e
`laravel-octane-swoole` (16,4×), porque ali há dois efeitos somados — o
framework e o Octane desligando corrotina.

Uma linha do relatório completo que a tabela de vazão não mostra: `swoole` é o
único que segura p95 abaixo de 200ms nas seis rotas. Os outros seguram nas três
CPU-bound (`noop`, `cpu`, `json`) e perdem nas três em que há espera
(`blocking_wait`, `external_io`, `memory`).

Sobre confiabilidade: nenhuma célula passou do limiar de dispersão ruidosa
(10%), mas três chegaram perto — `swoole/json` 8,4%, `swoole/cpu` 7,3%,
`frankenphp/cpu` 6,7%. Não invalidam nada; só significa que um número exato
dessas três oscila mais entre amostras que o resto da corrida.

### Por que os números são estes

Cada número tem um knob concreto atrás, não só "o modelo explica". Três níveis
de evidência abaixo: **medido nesta rodada**, **medido antes num teste isolado
deste lab** (histórico, registrado em código ou em `RUNTIMES.md`), e
**hipótese não testada aqui**.

**O fator 35,7× do `swoole` é um hook, não é "swoole é rápido".**
`Runtime::enableCoroutine(SWOOLE_HOOK_ALL)`, em `docker/swoole/server.php`,
troca `usleep()` — e o `curl_exec` que `external_io` usa — por versões que
cedem o worker em vez de bloqueá-lo. Removendo essa linha, `usleep(10000)`
volta a bloquear a thread inteira e `swoole` cai para o mesmo teto sequencial
dos outros seis. O que está sendo medido é o hook, não uma vantagem genérica
do runtime.

**Esse fator já caiu uma vez, por um knob diferente, e ficou documentado.** Com
`APP_MAX_REQUESTS` acima de zero, `max_wait_time` (3s por padrão) limita quanto
tempo o manager espera um worker reciclando drenar — e um worker de corrotina
tem dezenas de requests em voo, uma parada em I/O que não termina antes do cap
expirar. Medido num teste isolado deste lab, com reciclagem ligada:
`blocking_wait` a ~1.900 rps, p99 de 3.064ms. Com reciclagem genuinamente
desligada (o `?:` que tratava a string `"0"` como falso foi corrigido nesta
sessão): os 13.158 rps / p99 de 46ms desta tabela. O comentário em
`server.php` existe para que religar reciclagem sem saber disso não
reintroduza a cauda.

**O teto de ~370-380 rps em `blocking_wait` é `workers ÷ espera`, e é um
número escolhido, não uma propriedade do runtime.** `workers_per_cpu: 4` em
`performance.json`, com `APP_CPUS=1.0`, dá `APP_WORKERS=4`; a rota espera
10ms fixos: `4 ÷ 0,010 = 400`. Os seis runtimes sequenciais ficam a poucos por
cento disso. Dobrar `workers_per_cpu` para 8 dobraria esse teto para ~800 —
não porque o runtime melhorou, porque a fórmula multiplica. É por isso que o
valor é igual nos oito: variar sem perceber infla exatamente esta comparação.

**`roadrunner` e `fpm` praticamente empatam nesta rodada, e isso também se
explica.** Cinco das seis rotas ficam dentro de ±3,5% (`noop` -2,6%, `cpu`
+0,8%, `blocking_wait` -1,6%, `json` +2,6%, `memory` -3,5%); só `external_io`
abre distância real (+15,7%). RoadRunner vende "boot pago uma vez", mas o app
vanilla deste lab tem quase nada para bootar — um autoloader de poucas linhas
e sete classes, sem framework. O par `frankenphp`/`frankenphp-worker` mediu
essa mesma economia isolada, com tudo mais igual, em 1,56-1,81×: é modesta
porque o app é modesto. Um app com bootstrap pesado mostraria RoadRunner
puxando mais à frente do FPM — é o que os 5,9× de custo do framework em
`laravel-fpm` vs `fpm` sugerem que aconteceria: há bastante Laravel para
economizar bootando uma vez em vez de a cada request. O confundimento que o
`RUNTIMES.md` já registrava (servidor Go e worker persistente andam juntos
neste design) fica visível aqui: o ganho líquido dos dois efeitos é pequeno
quando não há bootstrap para economizar.

**A cauda de 41,8% do `external_io` (`swoole`: 13.158 → 5.499) é o preço de
sair do `usleep` idealizado para um socket de verdade.** `usleep` é um
temporizador puro, não sai do processo. `external_io` faz `curl_exec` contra o
stub Node por HTTP real — handshake (reaproveitado pelo pool de conexões do
handler), troca de contexto com o kernel, ida e volta até outro container.
Nenhum desses custos existe no `blocking_wait`. O hook de corrotina continua
funcionando (por isso 5.499, não os 363 do teto sequencial), mas o chão
embaixo dela é mais caro.

**Não testado nesta rodada, mas previsível pela própria configuração.** O
tuning fica em `baseline` — JIT desligado, sem preload.
`docker/shared/tuning/jit.ini` existe para a rota `cpu`, desenhada para rodar
só opcode de VM sem chamar extensão C, exatamente o que o JIT compila. Rodar
`PHP_TUNING=jit` deveria mover `cpu` e deixar `blocking_wait`/`external_io`
quase parados, porque eles gastam o tempo esperando, não executando.
`preload.ini` deveria favorecer desproporcionalmente `fpm`/`laravel-fpm`, que
pagam bootstrap a cada request — um worker persistente já mantém as classes
carregadas e tem pouco a ganhar. Nenhuma das duas hipóteses foi medida aqui;
são o motivo de existir o eixo de tuning.

**O `fpm`/`laravel-fpm` desta tabela não é o teto que o proxy poderia dar — é
o teto seguro.** Uma correção histórica registrada no `RUNTIMES.md`
(`fastcgi_keep_conn` mais um pool `keepalive` no nginx) rendeu +18% de vazão
no `noop` (2188→2591 rps) num teste isolado, ao custo de fila: p95 caiu para
1,9ms enquanto o p99 subia para 19 segundos, e o par Laravel chegou a 0,7-1,3%
de erro. O nginx deste lab roda **sem** esse pool, de propósito — o comentário
em `docker/nginx/default.conf` existe para que ninguém reintroduza isso sem
reler por quê primeiro. Dava para o `fpm` escoar mais nesta tabela, trocando
por caudas de dezenas de segundos que a lei de Little teria pegado na hora.

### O que custa CPU de verdade

O orçamento de 1 CPU vale para o container da aplicação. O nginx tem orçamento
próprio, e ele não é de graça: durante o `noop` do `fpm` o proxy consumiu 79% de
uma CPU **além** dos 106% do app. Os seis runtimes que servem HTTP sozinhos não
pagam nada disso.

Normalizando a vazão pela CPU realmente consumida (app + proxy, pico medido
durante a janela do `noop`):

| Runtime | `noop` rps | CPU consumida | rps por 100% de CPU |
|---|---|---|---|
| `swoole` | 12378 | 104% | ~11.900 |
| `frankenphp-worker` | 4483 | 95% | ~4.720 |
| `fpm` | 2760 | 185% (106 + 79) | ~1.490 |
| `laravel-fpm` | 470 | 123% (105 + 18) | ~382 |

Na tabela de vazão o `swoole` faz 4,5× o `fpm`. Por CPU efetivamente gasta, a
distância é de ~8×. A diferença entre os dois números é o hop de proxy, que a
comparação por orçamento nominal esconde e a fatura de um provedor não esconde.

O `laravel-fpm` gasta menos CPU de proxy em termos absolutos (18%) simplesmente
porque escoa menos requests — há menos tráfego para o nginx processar.

### Se a máquina fosse maior

Extrapolações abaixo, com o nível de confiança de cada uma. **Nada aqui foi
medido** — o lab só rodou em 1 CPU / 512 MB.

**Rotas CPU-bound devem escalar quase linearmente, até certo ponto.** O
container da aplicação bateu 95-106% do próprio orçamento em praticamente
todas as células — ou seja, a CPU era o limite, não a memória nem o gerador de
carga. Dobrar para 2 CPUs deve aproximadamente dobrar `noop`, `cpu` e `json`.
O `swoole` sairia da faixa de 12k para algo perto de 24k rps no `noop`. A
ressalva é que escalabilidade linear em CPU não sobrevive indefinidamente:
banda de memória, contenção de lock e o próprio event loop viram gargalo em
algum ponto que este lab não sondou.

**`blocking_wait` escala diferente para cada modelo, e é o caso mais
interessante.** Para os sete runtimes sequenciais, o teto é `workers ÷ espera`,
e `workers` sai de `ceil(APP_CPUS × 4)`. Com 4 CPUs seriam 16 workers, teto de
1.600 rps — crescimento linear e previsível. O `swoole` **não** escalaria da
mesma forma, e por um motivo que não é do runtime: com 200 VUs e espera de
10ms, o teto imposto pelo próprio gerador de carga é `200 ÷ 0,010 = 20.000
rps`. Os 13.158 medidos já estão a 66% disso. Mais CPU não passaria muito de
20k sem antes subir `overload.vus` — o limite ali deixou de ser o servidor.

**RAM decide quantos workers cabem, e é aí que o Laravel dói.** No orçamento de
512 MB, o `laravel-octane-swoole` ocupa 151 MiB antes de qualquer request
(29% do orçamento) e o `laravel-octane-roadrunner` 123 MiB. O `fpm` vanilla
ocupa 9 MiB, e o `swoole` 12 MiB. Numa máquina com 4 GB, esse custo fixo deixa
de importar para qualquer um deles — 151 MiB viram 3,7% do orçamento em vez de
29%. A conclusão prática se inverte conforme o tamanho da máquina: em container
apertado o footprint do Octane é decisivo, em host generoso ele é irrelevante e
só a vazão importa.

**Em contexto de produção.** Deliberadamente não cito preços — variam por
provedor e região, e datariam este documento em meses. Mas a forma do resultado
é útil: uma instância de 2 vCPU, que é o menor tamanho comum em qualquer
provedor, deveria colocar o `swoole` na casa das dezenas de milhares de rps
para respostas JSON simples, e o `fpm` vanilla na casa dos 5-6k. Para muita
API real isso significa que a escolha do runtime importa menos que parece: se
o alvo são centenas de rps, qualquer um dos oito entrega em hardware barato, e
o critério passa a ser operacional — risco de vazamento de estado, maturidade
de ferramental, familiaridade da equipe. O runtime vira decisivo quando o alvo
sobe para milhares de rps por instância, ou quando o workload é dominado por
espera em I/O, que é onde `swoole` abre ~18× contra o `fpm` no `external_io`.

### O que este ambiente distorce

Nenhum destes é bug do lab — são propriedades do ambiente que mudam o que os
números significam fora dele.

**Docker Desktop sobre WSL2 não é um host de produção.** A rede é
virtualizada e soma custo fixo a toda request, o que **comprime** as diferenças
relativas: todo runtime paga o mesmo pedágio, então quem seria muito mais
rápido parece só um pouco mais rápido. Em Linux com Docker nativo os números
absolutos dos oito devem subir, e as distâncias entre eles devem **aumentar**,
não diminuir. Quanto exatamente é desconhecido — nenhuma régua não-PHP foi
medida — e essa é a maior lacuna aberta deste lab. Um servidor Go trivial sob o
mesmo orçamento daria esse piso, e sem ele a comparação relativa entre os dois
runtimes mais rápidos carrega um viés não quantificado.

**O nginx só existe na frente das variantes FPM, e isso é inerente ao modelo.**
Não existe PHP-FPM sem servidor web na frente: FastCGI não fala HTTP. Então o
hop não é um viés corrigível do lab, é uma propriedade do deployment — e em
produção o custo é o mesmo, como a seção anterior mostra. O que o lab distorce
é o contrário: dá ao nginx 2 CPUs, folga deliberada para ele nunca ser o
gargalo. Em produção ninguém provisiona assim; com o proxy apertado, o teto do
FPM passa a ser o do proxy. Isso já aconteceu aqui — a 0.5 CPU o nginx saturava
em 101% enquanto o app tinha 35% de folga, e o número publicado como "teto do
FPM" era o teto do nginx.

**Cliente e servidor dividem a mesma máquina.** O k6 roda em container no mesmo
host, com orçamento de 5 CPUs. Durante o `noop` do `swoole` ele chegou a 159% —
ou seja, sobrou folga, mas o pico de CPU do host inteiro naquela célula foi
300% de 1200% disponíveis. Não houve disputa (o relatório confirma: máximo de
25% de saturação do host em toda a corrida), mas num benchmark sério o gerador
de carga fica em outra máquina, e este não fica.

**O `memory` mede banda, não capacidade.** Com contagem fixa de workers,
capacidade não pode ser o gargalo — encher 512 MiB exigiria ~128 MiB por
request, e escrever tudo isso derruba a vazão muito antes. A capacidade aparece
neste lab de outra forma: como footprint ocioso, na tabela da seção anterior.

**`blocking_wait` é `usleep`, não I/O.** É o melhor caso idealizado para
corrotina: um temporizador que o hook do Swoole intercepta perfeitamente. Para
I/O real sobre socket a rota é `external_io`, e a queda de 13.158 para 5.499
entre as duas é justamente a medida de quanto o caso idealizado exagera.

### Melhorias futuras

Em ordem aproximada de quanto mudariam as conclusões, não de esforço.

**Banco de dados de verdade.** É a lacuna mais séria para quem quer aplicar
estes números a uma aplicação real. Hoje `external_io` faz `curl_exec` contra
um stub HTTP, e cURL é uma das funções que o Swoole intercepta explicitamente —
então a vantagem de ~18× ali é medida no cenário em que o hook funciona. Um
driver de banco é outra pergunta: se o PDO/MySQL da imagem não ceder a
corrotina, o worker do Swoole bloqueia igual ao do FPM e a vantagem some
exatamente onde a maioria das aplicações passa o tempo. O lab hoje **não
responde isso** — e não é uma questão retórica, é o tipo de coisa que decide
uma migração de FPM para Octane.

Não é só subir um container: nenhuma das oito imagens tem `pdo_mysql`
instalado, então a extensão teria de entrar nas oito de forma idêntica, sob o
mesmo cuidado de padronização do resto do lab. E o banco viraria um novo
componente que pode ser o gargalo — precisaria de orçamento próprio e de
aparecer na seção de saturação, senão o lab passaria a medir MySQL e reportar
como se fosse PHP. É o mesmo erro que já aconteceu aqui com o nginx.

**Régua não-PHP.** Um servidor Go trivial, mesmo orçamento, mesma rede,
respondendo o mesmo payload do `noop`. Sem isso, o piso de overhead fixo do
Docker/WSL2 não está quantificado e a comparação relativa entre os dois
runtimes mais rápidos carrega um viés desconhecido. É a adição mais barata da
lista e a que mais muda a leitura de tudo que já está medido — inclusive
permitiria dizer quanto da compressão de diferenças descrita acima é infra e
quanto é PHP.

**Medir a escala de orçamento em vez de extrapolar.** A seção "Se a máquina
fosse maior" é raciocínio, não medição. Rodar a mesma matriz em 0.5, 1, 2 e 4
CPUs transformaria aquilo em dado, e responderia se a linearidade que assumi
para as rotas CPU-bound se sustenta ou quebra — e onde. O aparato já aceita
`APP_CPUS` por variável de ambiente, então é tempo de máquina, não código novo.

**Os eixos de tuning já construídos e nunca medidos.** `PHP_TUNING=jit` e
`PHP_TUNING=preload` existem, com perfis prontos em `docker/shared/tuning/`, e
nenhum dos dois entrou numa corrida completa. A rota `cpu` foi reescrita
justamente para dar ao JIT algo que compilar, e `--compare` existe para diffar
duas corridas célula a célula. É o eixo mais barato de todos: nada a
construir, só rodar.

**Cold start.** Quanto tempo entre `docker run` e a primeira resposta 200, e
entre isso e a vazão estável. Nenhum benchmark de PHP costuma medir, e é o que
decide arquitetura sob autoscaling: o FPM sobe rápido e não tem warm-up,
enquanto Octane e Swoole demoram a subir e ainda pagam OPcache frio nas
primeiras requests. O lab hoje descarta exatamente essa janela como warmup —
inverter o que se joga fora daria a tabela.

**Soak de uma hora, com o eixo tempo.** `WorkerStats` já expõe
`worker_requests` e `memory_bytes` em toda resposta; falta plotar contra o
tempo. Responderia "o Octane vaza?", que é a pergunta nº1 de quem considera
sair do FPM. Vale notar que este teste só passou a fazer sentido agora: com
reciclagem ligada o worker reiniciava antes de acumular qualquer coisa, então
o vazamento era estruturalmente invisível. Faz sentido rodar só nos cinco
runtimes persistentes — um `process-per-request` não vaza entre requests por
construção — com o `fpm` como controle negativo.

**Curva de degradação além do teto.** Hoje mede-se o teto em malha fechada, que
é o certo para "quanto escoa". Falta o que acontece a 1,5× disso: o FPM
enfileira e mantém p99 civilizado, ou o Swoole aceita tudo e derrete? Essa é a
pergunta de produção, e o executor de malha aberta do k6 já está no
`load-test.js`. A armadilha conhecida: em malha aberta o k6 sobe VUs até o teto
e depois conta `dropped_iterations` — sem ler esse campo, mede-se o gerador
acabando de VU, não o app degradando.

**Uma corrida em Linux nativo.** Todos os números aqui saem de Docker Desktop
sobre WSL2. Repetir a matriz em Linux com Docker nativo diria quanto do
resultado é PHP e quanto é a camada de virtualização — e a previsão registrada
acima (as distâncias entre runtimes *aumentam*, não diminuem) vira testável.

**Escopo do Laravel.** As rotas são registradas como API stateless, sem sessão
e sem CSRF. É a escolha certa para endpoint JSON, mas significa que os 5,9× de
custo do framework **excluem** o que uma rota `web` real pagaria. Uma segunda
rota Laravel dentro do grupo `web` mostraria esse delta.

## Como os resultados são arquivados

`./benchmark.sh` cria um diretório por rodada, com nome autodescritivo:

```
run-<data>-php<versão>-<tuning>-<cpu>-<mem>-w<workers>-s<amostras>/
  manifest.json           host, versões, orçamento, parâmetros de carga
  report.md               tabelas comparativas geradas por report.php
  fpm/
    summary.json          medianas, dispersão e utilização por rota
    cpu/s1.json           cada medição individual do k6 por trás delas
    memory/s1.json
  swoole/
    ...
```

Rodada, runtime, e então o recurso exaurido: cada nível responde uma pergunta,
então um resultado se acha sem saber como foi produzido.

A corrida publicada está versionada inteira em
[`run-20260825-1101-php8.3-baseline-1cpu-512m-w4-s3x60s/`](run-20260825-1101-php8.3-baseline-1cpu-512m-w4-s3x60s),
com manifesto, relatório, resumos por runtime e as 144 amostras individuais.

Rodadas avulsas de `sweep.sh` salvam em `sweep-<runtime>-<timestamp>/`, com a
mesma estrutura interna, e ficam ignoradas pelo git.

## O que cada arquivo de amostra contém

```jsonc
{
  "target_url": "http://nginx:8080",
  "load_profile": "overload",
  // Como a carga foi aplicada — gravado junto para a rodada ser reproduzível
  "measure_seconds": 60,
  "warmup_seconds": 5,
  "overload_vus": 200,
  "routes": {
    "cpu": {
      "requests": 0,
      "avg_rps": 0,
      // A distribuição inteira, não só a cauda: um p50 baixo com p99 alto é
      // uma fila que trava de vez em quando, enquanto os três próximos são um
      // runtime uniformemente saturado. São problemas diferentes.
      "p50_ms": 0, "p90_ms": 0, "p95_ms": 0, "p99_ms": 0, "max_ms": 0,
      // Onde o tempo foi: `waiting` é o servidor pensando (TTFB), `blocked` é
      // a espera por um slot de conexão, que pertence ao cliente. Latência
      // total sozinha não separa runtime lento de backlog de accept.
      "waiting_p95_ms": 0, "blocked_p95_ms": 0,
      "bytes_per_response": 0,
      "error_rate": 0.0,
      // false = a rota estourou o orçamento de p95 ao saturar. É informação
      // sobre o estado de saturação, não um erro: saturar significa parar de
      // responder rápido.
      "within_budget": true
    }
  }
}
```

## Como ler o `summary.json`

Além das medianas por rota, ele carrega o que torna os números interpretáveis:

- **`spread_pct`** — distância entre a menor e a maior amostra, como fração da
  mediana. Acima de 10% as amostras discordam o bastante para que diferenças
  pequenas entre runtimes não signifiquem nada.
- **`utilization`** — pico de CPU e de memória residente por container durante
  a janela medida. O container da aplicação aparecer no teto é **bom**:
  confirma que o runtime era o limite. Proxy, stub ou gerador de carga no teto
  significa que o número mede **eles**.
- **`idle_footprint`** — memória ocupada antes de qualquer request chegar. É o
  preço do modelo, e nenhuma tabela de vazão mostra isso.
- **`request_memory_peak_bytes`** — pico de memória que uma request precisou
  para existir, medido pelo próprio runtime. Sondado fora da janela medida:
  lê-lo de cada resposta fazia o gerador de carga virar o gargalo.
- **`language` / `framework` / `model`** — a taxonomia do runtime, para o
  relatório agrupar por modelo de execução em vez de por nome.

## O que é medido, e por que assim

Uma coisa por rota: **quanta vazão o runtime escoa com tudo em cima dele**, sob
o orçamento fixo. Malha fechada — cada VU manda, espera a resposta e manda de
novo — então a vazão se auto-limita ao que o servidor dá conta.

Malha aberta a essa altura empilharia fila e mediria a fila, não o servidor.
Observado aqui: p99 de 12 segundos com o servidor escoando *menos* do que
escoava sob carga menor.

Cada rota esgota um recurso diferente:

| Rota | Recurso | O que revela |
|---|---|---|
| `/bench/noop` | nenhum | custo por request do runtime, sem workload diluindo — é o piso contra o qual as outras se leem |
| `/bench/cpu` | VM do PHP | laço aritmético por opcode, sem chamar extensão C: é o que o JIT compila |
| `/bench/blocking-wait` | concorrência sob espera | teto do modelo: corrotina cede, processo bloqueia |
| `/bench/external-io` | I/O de rede real | socket de verdade contra um stub HTTP |
| `/bench/json` | CPU + serializador | `json_encode` de estrutura grande |
| `/bench/memory` | banda de memória | custo de alocar e escrever |
| `/` | — | healthcheck, fora do benchmark |

Duas honestidades sobre a nomenclatura, repetidas aqui porque mudam a leitura
de qualquer tabela: **`blocking-wait` não faz I/O** (é `usleep`, o melhor caso
idealizado para corrotina — para socket real, `external-io`), e **`memory` mede
banda, não capacidade** (com contagem fixa de workers, capacidade não pode ser o
gargalo; ela aparece como footprint ocioso).

Adicionar uma rota: uma entrada em `routes.json`, uma classe em
`app/src/Handlers/` implementando `RouteHandlerInterface`, e uma linha no
`match()` de `app/src/Routing/RouteRegistry.php`. Nenhum adapter é tocado. O
label precisa ser válido como nome de métrica k6 — o smoke test verifica.

A fórmula que iguala a contagem de workers entre os oito, e tudo mais que
precisou ser padronizado para a comparação valer, está em
[RUNTIMES.md](../../RUNTIMES.md).

## Orçamento de recursos

O orçamento restringe **só o que está sob teste**. Todo o resto do aparato é
deliberadamente folgado, porque um componente do aparato saturando vira o teto
medido e o resultado passa a descrever o instrumento.

| Container | CPUs | Memória | Papel |
|---|---|---|---|
| app | `APP_CPUS=1.0` | `APP_MEM=512m` | **sob teste** |
| nginx | `NGINX_CPUS=2.0` | 64m | proxy do FPM; folgado de propósito |
| stub | `STUB_CPUS=2.0` | 512m | dependência HTTP do `external-io` |
| k6 | `APP_CPUS` × 5 | derivado | gerador de carga |

Como a seção "O que custa CPU de verdade" mostra, essa folga tem consequência
na leitura: o proxy consome CPU real que o orçamento nominal do app não conta.

## Rodando

Um comando só, sem variável de ambiente nenhuma:

```bash
./benchmark.sh                  # todos os runtimes, medição completa
./benchmark.sh fpm swoole       # só estes

./benchmark.sh --small          # 1 amostra de 30s por rota   (~35 min)
./benchmark.sh --medium         # 2 amostras de 45s por rota  (~90 min)
./benchmark.sh --large          # 3 amostras de 60s por rota  (~2h50) — o default

./benchmark.sh --check          # preflight, não mede nada
./benchmark.sh --quick          # janelas mínimas, para provar que o setup funciona
./benchmark.sh --status         # quanto falta da rodada mais recente
./benchmark.sh --watch          # o mesmo, atualizando sozinho

./benchmark.sh --runs                    # histórico, com veredito de uma linha
./benchmark.sh --report <corrida>        # regenera o relatório
./benchmark.sh --compare <base> <cand.>  # diff célula a célula entre corridas
./benchmark.sh --resume                  # termina a corrida que não completou
```

Para soltar do terminal:

```bash
nohup ./benchmark.sh > /tmp/matrix.log 2>&1 &
./benchmark.sh --watch
```

Uma rota ou runtime específico, sem a matriz inteira:

```bash
./benchmarks/scripts/sweep.sh swoole                  # todas as rotas
./benchmarks/scripts/sweep.sh laravel-fpm cpu memory  # rotas específicas
```

Para variar um eixo, sobrescreva na shell — a config continua sendo a fonte
dos defaults:

```bash
PHP_TUNING=jit ./benchmark.sh
APP_CPUS=0.5 APP_MEM=256m ./benchmark.sh
MEASURE_SECONDS=30 ./benchmark.sh
```

### O que cada modo garante

O **`--small` não substitui o `--large` para publicar**: com uma amostra não
existe dispersão, e sem dispersão não se distingue diferença real de ruído do
escalonador. Ele responde "isso que mudei mexeu em alguma coisa?". O `--medium`
é o menor que ainda produz dispersão, e o relatório recusa afirmar concordância
entre amostras quando só houve uma.

O **`--compare`** é o que torna os eixos de tuning e de versão do PHP
utilizáveis: eles não existem dentro de uma corrida, são a diferença *entre*
duas. Ele lê os dois manifestos, lista o que mudou, e avisa quando mais de uma
variável mudou — porque aí nenhuma diferença é atribuível. Mostra a cauda ao
lado da vazão, que é o que impede uma troca ruim de passar por melhoria: uma
correção de proxy aqui já deu +18% de vazão criando requests de 19 segundos.

O **`--resume`** retoma pelo `summary.json`, que só é escrito quando todas as
rotas de um runtime terminam — a presença dele prova que aquele runtime está
completo. Uma matriz que morre no sétimo custa 20 minutos em vez de três horas.

Antes de medir, roda um **preflight**: duas rotas em cada runtime com janela de
três segundos. Não é health check — é a varredura de verdade, então falha em
tudo que a corrida real falharia, inclusive nas falhas que deixam um runtime
parecendo saudável por fora. Uma corrida já terminou com dois de oito runtimes
no relatório sem reportar falha nenhuma.

Uma trava de instância única impede duas corridas concorrentes. Elas não falham
alto quando colidem — medem a contenção uma da outra, silenciosamente.

## Observando memory creep sem rodar benchmark

Toda resposta, de qualquer rota, carrega três campos do worker que a atendeu:

| Campo | Para quê |
|---|---|
| `worker_requests` | quantas requests aquele processo já serviu |
| `memory_bytes` | memória atual; é o que sobe se houver vazamento |
| `memory_peak_bytes` | pico histórico; monotônico, é teto e não tendência |

Batendo na mesma rota várias vezes, a diferença entre os modelos aparece
sozinha: no FPM o `pid` muda e o contador fica baixo, enquanto no Swoole e no
RoadRunner o mesmo `pid` reaparece com o contador subindo. Se `memory_bytes`
crescer junto com `worker_requests`, é creep.

## Variantes de tuning

Uma rodada por perfil. A rota `cpu` é a que responde: ela roda no VM do PHP,
sem chamar extensão C, que é exatamente o que o JIT compila. Medido em
`fpm`/`cpu`: baseline 1217 rps contra 1537 com JIT.

| Perfil | Runtime | Rota | RPS | Δ vs baseline |
|---|---|---|---|---|
| jit | | | | |
| preload | | | | |
