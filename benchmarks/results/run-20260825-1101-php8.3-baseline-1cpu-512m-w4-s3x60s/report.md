# Resultado — run-20260825-1101-php8.3-baseline-1cpu-512m-w4-s3x60s

> ✅ **Corrida íntegra.** A carga aplicada confere com a descrita, as amostras concordam entre si, nenhuma request falhou e a máquina estava livre durante as medições. Os números abaixo sustentam comparação.

## Contexto

| | |
|---|---|
| Executado em | 2026-08-25T14:01:21Z |
| Host | 12 cores, 7.7 GB, Docker Desktop |
| Docker | 29.2.0 (6.6.87.2-microsoft-standard-WSL2) |
| PHP | 8.3 · tuning: baseline |
| Orçamento | 1.0 CPU, 512m, 4 workers, reciclando a cada 0 requests |
| Medição | malha fechada até esgotar, janela de 60s, 3 amostras |

## O que foi medido

Cada linha das tabelas abaixo é um destes. O modelo de execução é o eixo que explica os números — quem reinicia a cada request paga o bootstrap toda vez, quem fica residente paga uma vez só, e quem tem corrotina ainda libera o worker enquanto espera.

| Runtime | Framework | Modelo de execução |
|---|---|---|
| `laravel-octane-roadrunner` | laravel | persistent-worker |
| `laravel-octane-swoole` | laravel | persistent-worker |
| `laravel-fpm` | laravel | process-per-request |
| `swoole` | vanilla | coroutine-worker |
| `frankenphp` | vanilla | embedded-per-request |
| `frankenphp-worker` | vanilla | persistent-worker |
| `roadrunner` | vanilla | persistent-worker |
| `fpm` | vanilla | process-per-request |

## Vazão (rps)

Requests por segundo escoadas com todo o poder de fogo em cima do runtime, sob o orçamento acima. Mediana das amostras.

| Runtime | blocking_wait | cpu | external_io | json | memory | noop |
|---|---|---|---|---|---|---|
| `laravel-octane-roadrunner` | 302 | 447 | 294 | 480 | 248 | 515 |
| `laravel-octane-swoole` | 333 | 629 | 327 | 734 | 315 | 754 |
| `laravel-fpm` | 310 | 414 | 253 | 432 | 242 | 470 |
| `swoole` | 13158 | 3160 | 5499 | 4671 | 550 | 12378 |
| `frankenphp` | 375 | 1457 | 310 | 1723 | 417 | 2480 |
| `frankenphp-worker` | 378 | 2274 | 363 | 2799 | 461 | 4483 |
| `roadrunner` | 363 | 1468 | 353 | 1774 | 410 | 2688 |
| `fpm` | 369 | 1456 | 305 | 1729 | 425 | 2760 |

## Dispersão entre amostras (%)

Distância entre a menor e a maior amostra, como fração da mediana. Acima de 10% as amostras discordam o bastante para que diferenças pequenas entre runtimes não signifiquem nada.

| Runtime | blocking_wait | cpu | external_io | json | memory | noop |
|---|---|---|---|---|---|---|
| `laravel-octane-roadrunner` | 1.1 | 0.3 | 1.8 | 3.0 | 1.7 | 5.0 |
| `laravel-octane-swoole` | 0.4 | 1.0 | 0.2 | 0.3 | 1.0 | 9.0 |
| `laravel-fpm` | 0.5 | 0.6 | 1.3 | 0.3 | 0.2 | 1.9 |
| `swoole` | 1.5 | 7.3 | 3.1 | 8.4 | 3.0 | 1.6 |
| `frankenphp` | 0.1 | 6.7 | 3.6 | 1.7 | 2.2 | 5.2 |
| `frankenphp-worker` | 0.1 | 2.4 | 0.6 | 0.1 | 4.1 | 0.2 |
| `roadrunner` | 0.1 | 0.2 | 0.1 | 0.0 | 1.6 | 1.4 |
| `fpm` | 0.1 | 1.0 | 0.9 | 0.1 | 2.5 | 3.9 |

## Vazão relativa ao `fpm`

A mesma tabela acima, normalizada. Com oito runtimes, números absolutos não se comparam de cabeça; `1.00` é o fpm e cada célula diz quantas vezes o runtime escoou mais (ou menos) na mesma rota, sob o mesmo orçamento.

| Runtime | blocking_wait | cpu | external_io | json | memory | noop |
|---|---|---|---|---|---|---|
| `laravel-octane-roadrunner` | 0.82 | 0.31 | 0.96 | 0.28 | 0.58 | 0.19 |
| `laravel-octane-swoole` | 0.90 | 0.43 | 1.07 | 0.42 | 0.74 | 0.27 |
| `laravel-fpm` | 0.84 | 0.28 | 0.83 | 0.25 | 0.57 | 0.17 |
| `swoole` | 35.63 | 2.17 | 17.98 | 2.70 | 1.29 | 4.48 |
| `frankenphp` | 1.02 | 1.00 | 1.02 | 1.00 | 0.98 | 0.90 |
| `frankenphp-worker` | 1.02 | 1.56 | 1.19 | 1.62 | 1.08 | 1.62 |
| `roadrunner` | 0.98 | 1.01 | 1.15 | 1.03 | 0.97 | 0.97 |
| `fpm` | 1.00 | 1.00 | 1.00 | 1.00 | 1.00 | 1.00 |

## Latência ao esgotar (p50 / p95 / p99, ms)

Latência **no ponto de saturação**, que é o pior caso por construção. Não é a latência que o runtime entrega sob carga normal.

Os três percentis juntos porque a distância entre eles é o achado: um p50 baixo com p99 alto é uma fila que trava de vez em quando, enquanto os três próximos são um runtime uniformemente saturado. São problemas diferentes e o p95 sozinho não os separa.

| Runtime | blocking_wait | cpu | external_io | json | memory | noop |
|---|---|---|---|---|---|---|
| `laravel-octane-roadrunner` | 607 / 1030 / 1235 | 393 / 800 / 1210 | 622 / 971 / 1381 | 390 / 710 / 895 | 793 / 1078 / 1473 | 300 / 1005 / 1294 |
| `laravel-octane-swoole` | 594 / 683 / 1002 | 295 / 696 / 798 | 604 / 697 / 1030 | 214 / 623 / 783 | 601 / 996 / 1189 | 209 / 688 / 878 |
| `laravel-fpm` | 641 / 661 / 1069 | 493 / 555 / 705 | 705 / 1420 / 1497 | 486 / 501 / 654 | 807 / 893 / 1385 | 407 / 488 / 594 |
| `swoole` | 12 / 36 / 46 | 75 / 109 / 170 | 19 / 77 / 81 | 25 / 82 / 89 | 409 / 499 / 906 | 5 / 80 / 85 |
| `frankenphp` | 533 / 536 / 882 | 138 / 175 / 197 | 594 / 845 / 1017 | 116 / 133 / 155 | 493 / 508 / 749 | 81 / 95 / 108 |
| `frankenphp-worker` | 529 / 538 / 870 | 88 / 103 / 116 | 550 / 564 / 935 | 71 / 86 / 96 | 414 / 488 / 642 | 44 / 57 / 63 |
| `roadrunner` | 550 / 559 / 916 | 110 / 188 / 193 | 566 / 574 / 958 | 103 / 180 / 185 | 495 / 507 / 822 | 92 / 96 / 101 |
| `fpm` | 541 / 548 / 888 | 117 / 180 / 188 | 598 / 867 / 1039 | 107 / 166 / 173 | 487 / 506 / 700 | 80 / 89 / 101 |

## Servidor vs. fila de conexão (p95, ms)

A latência total é a soma de esperar por uma conexão e ser atendido. `servidor` é o tempo pensando (TTFB); `fila` é o tempo antes disso, esperando um slot de conexão. Dois runtimes com a mesma latência total podem estar em situações opostas: fila alta com servidor baixo é um backlog de accept, não um runtime lento.

| Runtime | blocking_wait | cpu | external_io | json | memory | noop |
|---|---|---|---|---|---|---|
| `laravel-octane-roadrunner` | 1030 / 0 | 800 / 0 | 971 / 0 | 710 / 0 | 1078 / 0 | 1005 / 0 |
| `laravel-octane-swoole` | 683 / 0 | 696 / 0 | 697 / 0 | 622 / 0 | 996 / 0 | 688 / 0 |
| `laravel-fpm` | 661 / 0 | 554 / 0 | 1420 / 0 | 501 / 0 | 893 / 0 | 488 / 0 |
| `swoole` | 36 / 0 | 109 / 0 | 77 / 0 | 81 / 0 | 499 / 0 | 80 / 0 |
| `frankenphp` | 536 / 0 | 175 / 0 | 845 / 0 | 133 / 0 | 508 / 0 | 95 / 0 |
| `frankenphp-worker` | 538 / 0 | 103 / 0 | 564 / 0 | 86 / 0 | 488 / 0 | 57 / 0 |
| `roadrunner` | 559 / 0 | 188 / 0 | 574 / 0 | 180 / 0 | 507 / 0 | 96 / 0 |
| `fpm` | 548 / 0 | 180 / 0 | 867 / 0 | 165 / 0 | 506 / 0 | 89 / 0 |

## Memória por request (pico, KiB)

Quanto uma request precisou de memória para existir, medido pelo próprio runtime: o pico que ela atingiu acima da linha de base em que começou. É o pico, não o retido — uma request que aloca 8 MiB e libera antes de responder não guarda nada, mas precisou dos 8 MiB. É esse número que dimensiona um worker.

| Runtime | blocking_wait | cpu | external_io | json | memory | noop |
|---|---|---|---|---|---|---|
| `laravel-octane-roadrunner` | 1 | 1 | 1 | 155 | 2120 | 1 |
| `laravel-octane-swoole` | 1 | 1 | 1 | 155 | 2120 | 1 |
| `laravel-fpm` | 29 | 29 | 29 | 172 | 2137 | 3 |
| `swoole` | 1 | 1 | 1 | 155 | 2120 | 1 |
| `frankenphp` | 29 | 29 | 29 | 172 | 2137 | 3 |
| `frankenphp-worker` | 1 | 1 | 1 | 155 | 2120 | 1 |
| `roadrunner` | 1 | 1 | 1 | 155 | 2120 | 1 |
| `fpm` | 29 | 29 | 29 | 172 | 2137 | 3 |

## Memória total sob carga (pico residente, MiB)

O que o deployment inteiro ocupou enquanto escoava sua vazão máxima. Memória residente conta cada página uma vez, então o que o worker reaproveita entre requests **não soma** — é o total no sentido de "quanto foi preciso ter", não de "quanto foi alocado ao longo do tempo". Entre parênteses, o quanto isso subiu acima do ocioso: essa diferença é a memória que a carga de fato exigiu.

| Runtime | blocking_wait | cpu | external_io | json | memory | noop |
|---|---|---|---|---|---|---|
| `laravel-octane-roadrunner` | 204 (+82) | 202 (+80) | 205 (+82) | 205 (+83) | 210 (+87) | 203 (+80) |
| `laravel-octane-swoole` | 160 (+9) | 160 (+9) | 161 (+10) | 160 (+9) | 165 (+14) | 160 (+8) |
| `laravel-fpm` | 30 (+3) | 30 (+3) | 34 (+7) | 33 (+6) | 36 (+9) | 30 (+3) |
| `swoole` | 31 (+18) | 22 (+10) | 46 (+33) | 46 (+33) | 71 (+59) | 23 (+11) |
| `frankenphp` | 52 (+33) | 47 (+27) | 52 (+33) | 48 (+28) | 55 (+36) | 44 (+24) |
| `frankenphp-worker` | 46 (+26) | 41 (+21) | 47 (+27) | 42 (+22) | 58 (+38) | 42 (+21) |
| `roadrunner` | 80 (+34) | 78 (+32) | 80 (+34) | 81 (+35) | 82 (+36) | 78 (+32) |
| `fpm` | 12 (+2) | 12 (+3) | 16 (+7) | 14 (+5) | 19 (+9) | 12 (+2) |

## Custo de existir (memória ociosa, MiB)

Memória ocupada **antes de qualquer request chegar**. É o preço do modelo: um worker persistente carrega o framework uma vez e o mantém residente, enquanto o FPM sobe e derruba a cada request. Nenhuma tabela de vazão mostra isso, e num orçamento fixo é o que decide quantos workers cabem.

| Runtime | Ocioso |
|---|---|
| `laravel-octane-roadrunner` | php-runtime-lab-app-laravel-octane-roadrunner-1 123 MiB (24%) |
| `laravel-octane-swoole` | php-runtime-lab-app-laravel-octane-swoole-1 151 MiB (30%) |
| `laravel-fpm` | php-runtime-lab-app-laravel-fpm-1 27 MiB (5%), php-runtime-lab-nginx-laravel-1 3 MiB (5%) |
| `swoole` | php-runtime-lab-app-swoole-1 12 MiB (2%) |
| `frankenphp` | php-runtime-lab-app-frankenphp-1 20 MiB (4%) |
| `frankenphp-worker` | php-runtime-lab-app-frankenphp-worker-1 20 MiB (4%) |
| `roadrunner` | php-runtime-lab-app-roadrunner-1 46 MiB (9%) |
| `fpm` | php-runtime-lab-app-fpm-1 9 MiB (2%), php-runtime-lab-nginx-1 3 MiB (5%) |

## Erros

Nenhuma request falhou em nenhum runtime. Toda a vazão das tabelas acima é de respostas 200 — vazão com erro não seria vazão.

## Quem segurou a latência mesmo saturado

Estes escoaram sua vazão máxima **e ainda** mantiveram p95 abaixo de 200ms. Vazão sem degradar latência é o resultado mais forte que uma linha desta tabela pode ter:

- `swoole` / `noop`
- `swoole` / `cpu`
- `swoole` / `blocking_wait`
- `swoole` / `external_io`
- `swoole` / `json`
- `frankenphp` / `noop`
- `frankenphp` / `cpu`
- `frankenphp` / `json`
- `frankenphp-worker` / `noop`
- `frankenphp-worker` / `cpu`
- `frankenphp-worker` / `json`
- `roadrunner` / `noop`
- `roadrunner` / `cpu`
- `roadrunner` / `json`
- `fpm` / `noop`
- `fpm` / `cpu`
- `fpm` / `json`

## Onde estava o gargalo

Componentes no teto durante a medição. O container da aplicação aparecer aqui é **bom**: confirma que o runtime era o limite. O proxy ou o stub aparecerem significa que o número mede **eles**:

- `laravel-octane-roadrunner` / `noop`: app-laravel-octane-roadrunner 105% do próprio orçamento
- `laravel-octane-roadrunner` / `cpu`: app-laravel-octane-roadrunner 105% do próprio orçamento
- `laravel-octane-roadrunner` / `blocking_wait`: app-laravel-octane-roadrunner 99% do próprio orçamento
- `laravel-octane-roadrunner` / `external_io`: app-laravel-octane-roadrunner 98% do próprio orçamento
- `laravel-octane-roadrunner` / `json`: app-laravel-octane-roadrunner 105% do próprio orçamento
- `laravel-octane-roadrunner` / `memory`: app-laravel-octane-roadrunner 104% do próprio orçamento
- `laravel-octane-swoole` / `noop`: app-laravel-octane-swoole 106% do próprio orçamento
- `laravel-octane-swoole` / `cpu`: app-laravel-octane-swoole 102% do próprio orçamento
- `laravel-octane-swoole` / `json`: app-laravel-octane-swoole 102% do próprio orçamento
- `laravel-octane-swoole` / `memory`: app-laravel-octane-swoole 103% do próprio orçamento
- `laravel-fpm` / `noop`: app-laravel-fpm 105% do próprio orçamento
- `laravel-fpm` / `cpu`: app-laravel-fpm 103% do próprio orçamento
- `laravel-fpm` / `external_io`: app-laravel-fpm 102% do próprio orçamento
- `laravel-fpm` / `json`: app-laravel-fpm 105% do próprio orçamento
- `laravel-fpm` / `memory`: app-laravel-fpm 102% do próprio orçamento
- `swoole` / `noop`: app-swoole 104% do próprio orçamento
- `swoole` / `cpu`: app-swoole 104% do próprio orçamento
- `swoole` / `blocking_wait`: app-swoole 106% do próprio orçamento
- `swoole` / `external_io`: app-swoole 104% do próprio orçamento
- `swoole` / `json`: app-swoole 105% do próprio orçamento
- `swoole` / `memory`: app-swoole 103% do próprio orçamento
- `frankenphp` / `noop`: app-frankenphp 102% do próprio orçamento
- `frankenphp` / `cpu`: app-frankenphp 106% do próprio orçamento
- `frankenphp` / `external_io`: app-frankenphp 102% do próprio orçamento
- `frankenphp` / `json`: app-frankenphp 104% do próprio orçamento
- `frankenphp` / `memory`: app-frankenphp 104% do próprio orçamento
- `frankenphp-worker` / `noop`: app-frankenphp-worker 95% do próprio orçamento
- `frankenphp-worker` / `cpu`: app-frankenphp-worker 99% do próprio orçamento
- `frankenphp-worker` / `json`: app-frankenphp-worker 95% do próprio orçamento
- `frankenphp-worker` / `memory`: app-frankenphp-worker 102% do próprio orçamento
- `roadrunner` / `noop`: app-roadrunner 106% do próprio orçamento
- `roadrunner` / `cpu`: app-roadrunner 106% do próprio orçamento
- `roadrunner` / `json`: app-roadrunner 106% do próprio orçamento
- `roadrunner` / `memory`: app-roadrunner 106% do próprio orçamento
- `fpm` / `noop`: app-fpm 106% do próprio orçamento
- `fpm` / `cpu`: app-fpm 105% do próprio orçamento
- `fpm` / `external_io`: app-fpm 102% do próprio orçamento
- `fpm` / `json`: app-fpm 105% do próprio orçamento
- `fpm` / `memory`: app-fpm 103% do próprio orçamento

## A medição se sustenta? (lei de Little)

A varredura dirige uma malha fechada com um número fixo de VUs, então a concorrência está presa: **vazão × latência média tem que voltar a esse número**. Uma célula que erra isso não está reportando um runtime lento — está reportando uma medição que não descreve a carga que diz descrever.

É a checagem mais dura do relatório porque não depende de nenhuma expectativa sobre os runtimes. Aplicada a uma corrida anterior, as **48 células** falharam, todas na mesma direção: a média estava inflada por uma cauda artefatual, e o número que parecia latência era instrumento.

As 48 células conferem dentro de ±15%. A carga aplicada foi a que o relatório diz que foi.

## A máquina estava ocupada?

Saturação do host durante cada medição — CPU realmente ocupada na máquina inteira, medida entre duas leituras de `/proc/stat` a um segundo de distância. Não é load average: aquele é suavizado por um minuto, então dentro de uma janela de 60s ainda está subindo quando ela acaba, e reportava 42% numa máquina que estava a 348%. Acima de 70% a máquina estava disputada, e uma célula com dispersão alta ali provavelmente diz mais sobre o host do que sobre o runtime. Sem isso, "este runtime é ruidoso" e "a máquina estava ocupada quando ele foi medido" são indistinguíveis.

Nenhuma célula foi medida com a máquina disputada — o pico de saturação do host em toda a corrida foi 25%.

## Como ler

- **Isto é saturação, não capacidade de produção.** Mede-se o teto bruto; ninguém opera um servidor nesse ponto.
- **`blocking_wait` não é I/O.** É um `usleep`, o melhor caso idealizado para corrotinas. Para I/O real sobre socket, veja `external_io`.
- **`memory` mede banda**, não capacidade — veja RUNTIMES.md.
- **nginx só serve as variantes FPM**, que pagam um hop de proxy que as outras não pagam. É inerente ao modelo, não um viés corrigível.
- Os números valem como **comparação relativa** sob condições idênticas nesta máquina, não como valores absolutos.
