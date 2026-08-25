# PHP Runtime Lab

O PHP Runtime Lab é um projeto que criei com o intuito de aprender mais sobre
os diferentes tipos de runtime PHP e como eles se comportam em determinados
contextos e orçamentos de recurso num servidor web.

| Runtime                     | Porta | Modelo               | O que isola                         |
| --------------------------- | ----- | -------------------- | ----------------------------------- |
| `fpm`                       | 8081  | process-per-request  | baseline: bootstrap a cada request  |
| `swoole`                    | 8082  | coroutine-worker     | worker persistente + corrotina      |
| `roadrunner`                | 8083  | persistent-worker    | worker persistente + servidor Go    |
| `laravel-fpm`               | 8084  | process-per-request  | custo do framework sobre o baseline |
| `laravel-octane-swoole`     | 8085  | persistent-worker    | framework + worker persistente      |
| `laravel-octane-roadrunner` | 8086  | persistent-worker    | idem, com o servidor Go             |
| `frankenphp`                | 8087  | embedded-per-request | Caddy embarcando PHP                |
| `frankenphp-worker`         | 8088  | persistent-worker    | mesma imagem, worker persistente    |

## Documentação

Este arquivo é só a porta de entrada. O conteúdo está em:

| Documento                                                    | O que tem                                                                                  |
| ------------------------------------------------------------ | ------------------------------------------------------------------------------------------ |
| [benchmarks/results/README.md](benchmarks/results/README.md) | os resultados e a análise, mais o manual de operação do `benchmark.sh`                     |
| [RUNTIMES.md](RUNTIMES.md)                                   | o que cada runtime é, e tudo que precisou ser igualado para a comparação valer             |
| `report.md` de cada corrida                                  | as tabelas completas, geradas pelo `report.php`, com um veredito de confiabilidade no topo |

## Resultado

Corrida mais recente: `run-20260825-1101-php8.3-baseline-1cpu-512m-w4-s3x60s`,
com 1 CPU, 512MB, 4 workers e 3 amostras de 60s por rota. O relatório dela saiu
íntegro nas checagens de confiabilidade.

| Runtime                     | `noop` | `cpu` | `blocking_wait` | `json` |
| --------------------------- | ------ | ----- | --------------- | ------ |
| `swoole`                    | 12378  | 3160  | 13158           | 4671   |
| `frankenphp-worker`         | 4483   | 2274  | 378             | 2799   |
| `fpm`                       | 2760   | 1456  | 369             | 1729   |
| `roadrunner`                | 2688   | 1468  | 363             | 1774   |
| `frankenphp`                | 2480   | 1457  | 375             | 1723   |
| `laravel-octane-swoole`     | 754    | 629   | 333             | 734    |
| `laravel-fpm`               | 470    | 414   | 310             | 432    |
| `laravel-octane-roadrunner` | 515    | 447   | 302             | 480    |

Vazão em rps, mediana de 3 amostras. Três coisas que valem destacar:

Na rota `blocking_wait` dá para calcular o resultado antes de medir. São 4
workers e uma espera fixa de 10ms, então quem prende um worker durante a espera
tem teto de 400 rps. Sete runtimes ficaram entre 302 e 378. O oitavo é o
`swoole`, que faz 13.158 porque a corrotina libera o worker em vez de segurá-lo.

O par `frankenphp` e `frankenphp-worker` sai da mesma imagem e muda só o
Caddyfile montado, o que permite medir o ganho de worker persistente sem trocar
de servidor junto: 1,81× no `noop`, 1,62× no `json`, e 1,01× no
`blocking_wait`, onde não há bootstrap para economizar.

A tabela subestima a distância entre os runtimes. O orçamento de 1 CPU vale só
para o container da aplicação, e durante o `noop` do `fpm` o nginx consumiu 79%
de uma CPU a mais. Normalizando pela CPU realmente gasta, a vantagem do
`swoole` sobre o `fpm` sai de 4,5× para cerca de 8×.

O resto da leitura, incluindo o que este ambiente distorce e o que falta medir,
está em [benchmarks/results/README.md](benchmarks/results/README.md). A parte
interpretativa daquele documento foi escrita com auxílio de IA a partir dos
dados medidos, e declara isso na primeira linha.

## Rodando

Precisa de Docker Desktop com Compose v2. Nada de PHP, Composer ou gerador de
carga instalado no host.

```bash
cp .env.example .env
./benchmark.sh --check     # sobe os oito e mede duas rotas, sem publicar nada
./benchmark.sh             # a matriz completa, ~2h50
./benchmark.sh --watch     # acompanha uma corrida em andamento
```

Os presets, os eixos de tuning e o que cada flag faz estão documentados em
[benchmarks/results/README.md](benchmarks/results/README.md).

Para inspecionar sem medir, os oito sobem juntos, cada um numa porta:

```bash
docker compose --profile all up -d --build
curl http://localhost:8082/bench/cpu    # swoole
docker compose --profile all down
```

Isso serve para olhar as respostas, não para comparar vazão. Com os oito
disputando a mesma máquina, nenhum número sai limpo.

## Estrutura

```
routes.json           única fonte de verdade das rotas, lida pelo PHP e pelo k6
performance.json      única fonte de verdade dos parâmetros de workload e carga
app/src/Http/         Request, Response, HttpStatusCode, ResponseEnvelope
app/src/Routing/      Router + RouteRegistry
app/src/Handlers/     um handler por workload
app/src/Runtime/      WorkerStats — contadores por worker
app/public/index.php  adapter FPM
docker/shared/        php.ini e perfis de tuning, aplicados igual aos oito
docker/<runtime>/     Dockerfile e adapter de cada runtime
docker/stub/          dependência HTTP da rota external-io
benchmarks/scripts/   orquestração, script k6, gerador de relatório
tests/                smoke test do app compartilhado
```

Rotas e handlers são os mesmos para os oito. Cada runtime tem só o seu adapter,
que converte o request nativo do servidor em `RuntimeLab\Http\Request` e a
`Response` compartilhada de volta no formato nativo.

## Qualidade

```bash
docker compose --profile test run --rm test    # smoke test, roda em segundos
```

Análise estática em nível 8 nas duas configurações, a biblioteca compartilhada
(`phpstan.neon`) e os adapters mais o Laravel (`phpstan-adapters.neon`, que usa
stubs de Swoole e FrankenPHP):

```bash
docker run --rm -v "$PWD:/app:ro" -w /app ghcr.io/phpstan/phpstan:latest analyse --no-progress
docker run --rm -v "$PWD:/app:ro" -w /app ghcr.io/phpstan/phpstan:latest \
  analyse -c phpstan-adapters.neon --no-progress
```

Os adapters já ficaram fora da análise por um tempo, o que é ruim porque eles
são o único código que difere entre os runtimes. Dois bugs reais apareceram ali
quando entraram no escopo: um `use Throwable` órfão cujo warning corrompia o
protocolo binário do RoadRunner, e um nome de runtime cravado no código que
fazia o FrankenPHP se reportar como `fpm`.

O [CI](.github/workflows/ci.yml) roda análise estática, formatação com Pint,
smoke test, build das imagens e uma verificação de que toda rota responde em
todo runtime. Não roda benchmark, porque runner compartilhado não tem CPU fixa
nem vizinhança silenciosa.
