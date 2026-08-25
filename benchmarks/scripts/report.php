<?php

declare(strict_types=1);

/**
 * Turns a run directory into the comparison table.
 *
 * Written in PHP rather than shell because it parses JSON, and a report that
 * silently mangles a number is worse than no report. Run through the same
 * stock PHP image everything else uses:
 *
 *   docker run --rm -v "$PWD:/work" -w /work php:8.3-cli-alpine \
 *     php benchmarks/scripts/report.php benchmarks/results/run-...
 *
 * Every throughput figure is printed with its spread across samples. A number
 * on its own invites a comparison the data may not support: two runtimes 3%
 * apart are indistinguishable if each varies by 10% between runs, and only the
 * spread says so.
 */

const EXIT_USAGE = 1;
const CPU_SATURATION_WARN_RATIO = 0.9;

/** Spread past which samples are too scattered to compare against each other. */
const NOISY_SPREAD_PCT = 10.0;

/**
 * How many times p99 may exceed p95 before the tail is called suspect.
 *
 * An organic latency curve climbs; it does not step. A p99 orders of magnitude
 * above p95, landing within a millisecond of the maximum, is the shape of a
 * fixed stall rather than of queueing — and a stall that reproduces at the same
 * value regardless of window length is a property of the setup, not of load.
 * Left unflagged it reads as the runtime's latency, which the other 99% of the
 * distribution contradicts.
 */
const SUSPECT_TAIL_RATIO = 20.0;

/**
 * Runtime every other one is normalised against. FPM is the reference because
 * it is the model the others exist to improve on — a relative table only reads
 * well when the denominator is the familiar case.
 */
const BASELINE_RUNTIME = 'fpm';

/**
 * Host saturation past which a measurement was taken on a contended machine.
 *
 * Busy CPU across the whole machine, so 100% means every core was working. The
 * lab's own containers account for a large share of that by design, which is
 * why the threshold sits well above what a clean full-tilt run reports.
 */
const BUSY_HOST_PCT = 70.0;

/**
 * How far implied concurrency may drift from the configured virtual-user count
 * before the measurement is called into question.
 *
 * A healthy run lands within about 1%: the small shortfall is the requests
 * still in flight when the window closes. The tolerance sits far above that so
 * it flags corruption rather than boundary effects — a contaminated cell misses
 * by tens to hundreds of percent, never by a few.
 */
const LITTLES_LAW_TOLERANCE_PCT = 15.0;

/**
 * Fewest samples that allow saying anything at all about dispersion.
 *
 * With a single sample the distance between smallest and largest is zero *by
 * construction*, so a dispersion table would read `0.0` in every cell and the
 * verdict would claim the samples agree with each other — an empty statement
 * that lands as the strongest one in the report. A one-sample run is not
 * invalid; it just cannot claim reproducibility nobody measured.
 */
const MINIMUM_SAMPLES_FOR_DISPERSION = 2;

$runDirectory = $argv[1] ?? '';

if ($runDirectory === '' || !is_dir($runDirectory)) {
    fwrite(STDERR, "Usage: report.php <run-directory>\n");
    exit(EXIT_USAGE);
}

$manifestPath = $runDirectory . '/manifest.json';
$manifest = is_file($manifestPath)
    ? json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR)
    : [];

/**
 * Collects every runtime summary in a run directory.
 *
 * Summaries sit at <runtime>/summary.json, one level down, so a glob is enough
 * and no recursive walk is needed. Each summary carries its own taxonomy, so
 * the report never has to re-derive what a runtime is from its path.
 *
 * @return array<string, array<string, mixed>>
 */
function collectSummaries(string $runDirectory): array
{
    $summaries = [];

    foreach (glob($runDirectory . '/*/summary.json') ?: [] as $path) {
        $summary = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $runtimeName = $summary['runtime'] ?? basename(dirname($path));

        $summaries[$runtimeName] = $summary;
    }

    return $summaries;
}

$summariesByRuntime = collectSummaries($runDirectory);

// Ordered by what the runtimes are, not alphabetically: neighbouring rows then
// differ by one axis at a time, which is the only way a table of eight is read
// as a comparison rather than as a list.
uasort($summariesByRuntime, static function (array $left, array $right): int {
    $leftTaxonomy = [$left['framework'] ?? '', $left['model'] ?? '', $left['runtime'] ?? ''];
    $rightTaxonomy = [$right['framework'] ?? '', $right['model'] ?? '', $right['runtime'] ?? ''];

    return $leftTaxonomy <=> $rightTaxonomy;
});

if ($summariesByRuntime === []) {
    fwrite(STDERR, "No runtime summaries found in {$runDirectory}\n");
    exit(EXIT_USAGE);
}

/**
 * Flags a container that was pegged while the measurement was taken.
 *
 * A saturated proxy or dependency means the figure describes that component
 * rather than the runtime, which is the most common way a benchmark lies. The
 * app itself being pegged is the opposite — it is the confirmation that the
 * runtime, and not something around it, was the constraint.
 *
 * @param array<string, mixed> $route
 * @param array<string, mixed> $cpuLimits
 */
function describeSaturation(array $route, array $cpuLimits): string
{
    $utilization = $route['utilization'] ?? [];
    $notes = [];

    foreach ($utilization as $container => $usage) {
        $peak = (float) ($usage['peak_cpu_pct'] ?? 0);

        // The generator and the dependency are checked against their own
        // budgets rather than skipped: the claim this whole method rests on is
        // that neither of them was the thing being measured, and skipping them
        // leaves exactly that claim unverified.
        // The host is not a container and has no budget to breach; it is
        // reported on its own below rather than checked against a ceiling.
        if ($container === 'host') {
            continue;
        }

        $limit = match (true) {
            str_contains($container, 'nginx') => (float) ($cpuLimits['nginx'] ?? 200),
            str_contains($container, 'k6') => (float) ($cpuLimits['k6'] ?? 0),
            str_contains($container, 'stub') => (float) ($cpuLimits['stub'] ?? 200),
            default => (float) ($cpuLimits['app'] ?? 100),
        };

        $isNearBudget = $limit > 0.0 && $peak > $limit * CPU_SATURATION_WARN_RATIO;

        if (!$isNearBudget) {
            continue;
        }

        $budgetUsagePct = $peak / $limit * 100;

        $notes[] = sprintf('%s %.0f%%', $container, $budgetUsagePct);
    }

    return implode(', ', $notes);
}

/**
 * Totals a memory figure across a runtime's application containers.
 *
 * Only the application counts: the proxy, the dependency stub and the load
 * generator are the harness, and folding them in would make a deployment look
 * to cost whatever the harness happens to weigh. The FPM variants have two
 * containers, everything else has one, so it is a sum rather than a lookup.
 *
 * @param array<string, array<string, mixed>> $containers
 */
function sumApplicationMebibytes(array $containers, string $field): float
{
    $total = 0.0;

    foreach ($containers as $container => $usage) {
        if (str_contains($container, 'app')) {
            $total += (float) ($usage[$field] ?? 0);
        }
    }

    return $total;
}

/**
 * Renders one runtime-by-route table.
 *
 * Every table in this report has the same shape — runtimes down the side,
 * routes across the top — and differs only in what goes in a cell. Written out
 * per table, a change to the layout would have to be made in each one and any
 * table left behind would silently drift from the rest.
 *
 * @param array<string, array<string, mixed>> $summariesByRuntime
 * @param list<string>                        $routeLabels
 * @param callable(array<string, mixed>|null, array<string, mixed>, string): string $renderCell
 *        Receives the route's measurements (null when the runtime has none),
 *        the whole runtime summary, and the route label; returns the cell's text.
 */
function renderRouteTable(array $summariesByRuntime, array $routeLabels, callable $renderCell): void
{
    echo '| Runtime | ' . implode(' | ', $routeLabels) . " |\n";
    echo '|---|' . str_repeat('---|', count($routeLabels)) . "\n";

    foreach ($summariesByRuntime as $runtime => $summary) {
        $cells = [];

        foreach ($routeLabels as $label) {
            $cells[] = $renderCell($summary['routes'][$label] ?? null, $summary, $label);
        }

        echo "| `{$runtime}` | " . implode(' | ', $cells) . " |\n";
    }
}

$routeLabels = [];
foreach ($summariesByRuntime as $summary) {
    // Cast at insertion, not just relied on as an array key: PHP coerces a
    // numeric-looking key to int, so a route label that happens to be a bare
    // number would otherwise slip an int into what the rest of this file
    // treats as a list of strings.
    foreach (array_keys($summary['routes'] ?? []) as $label) {
        $routeLabels[(string) $label] = true;
    }
}
$routeLabels = array_keys($routeLabels);
sort($routeLabels);

$host = $manifest['host'] ?? [];
$budget = $manifest['budget'] ?? [];

/**
 * Answers the only question worth asking before any table is read: can these
 * numbers be used?
 *
 * Everything here is already computed further down; the point of gathering it
 * at the top is that a run measuring the instrument rather than the runtimes
 * looks exactly like a run that worked, and that is not something a reader
 * should have to discover on page three.
 *
 * @param array<string, array<string, mixed>> $summariesByRuntime
 * @param array<string, mixed>                $manifest
 *
 * @return list<string> One line per reason to distrust the run; empty is a pass.
 */
function collectTrustWarnings(array $summariesByRuntime, array $manifest): array
{
    $warnings = [];
    $virtualUsers = (float) ($manifest['load']['overload_vus'] ?? 0);
    $samplesPerRoute = (int) ($manifest['load']['samples_per_route'] ?? 0);

    $declaredRuntimes = $manifest['runtimes'] ?? [];
    $missing = array_diff($declaredRuntimes, array_keys($summariesByRuntime));

    if ($missing !== []) {
        $warnings[] = sprintf(
            '**%d runtime(s) sem resultado**: %s. A corrida não terminou.',
            count($missing),
            implode(', ', $missing),
        );
    }

    $inconsistent = 0;
    $checkableCells = 0;
    $totalCells = 0;
    $noisy = [];
    $erroring = [];
    $contended = [];

    foreach ($summariesByRuntime as $runtime => $summary) {
        foreach ($summary['routes'] ?? [] as $label => $route) {
            $meanMs = (float) ($route['avg_ms'] ?? 0);
            $throughput = (float) ($route['throughput_rps'] ?? 0);
            $spreadPct = (float) ($route['spread_pct'] ?? 0);
            $errorRate = (float) ($route['error_rate'] ?? 0);
            $hostSaturation = (float) ($route['utilization']['host']['peak_mem_pct'] ?? 0);
            $cell = "{$runtime}/{$label}";

            $totalCells++;

            $isLittlesLawCheckable = $meanMs > 0.0 && $throughput > 0.0 && $virtualUsers > 0.0;

            if ($isLittlesLawCheckable) {
                $checkableCells++;
                $impliedConcurrency = $throughput * $meanMs / 1000;
                $deviationPct = ($impliedConcurrency - $virtualUsers) / $virtualUsers * 100;

                if (abs($deviationPct) > LITTLES_LAW_TOLERANCE_PCT) {
                    $inconsistent++;
                }
            }

            if ($spreadPct > NOISY_SPREAD_PCT) {
                $noisy[] = $cell;
            }

            if ($errorRate > 0.0) {
                $erroring[] = $cell;
            }

            if ($hostSaturation > BUSY_HOST_PCT) {
                $contended[] = $cell;
            }
        }
    }

    // A check that could not run must say so. Staying silent would let a run
    // predating the field pass the verdict by omission, which is the opposite
    // of what this block is for.
    if ($totalCells > 0 && $checkableCells === 0) {
        $warnings[] = '**A consistência da carga não pôde ser verificada** — '
            . 'esta corrida não registrou latência média, então a lei de Little '
            . 'não se aplica a ela. Corridas anteriores a esse campo já foram '
            . 'encontradas medindo o instrumento em todas as células.';
    }

    if ($inconsistent > 0) {
        $warnings[] = sprintf(
            '**%d célula(s) não fecham na lei de Little.** A carga aplicada não '
                . 'foi a que este relatório descreve — os números não valem.',
            $inconsistent,
        );
    }

    if ($contended !== []) {
        $warnings[] = sprintf(
            '**%d célula(s) medidas com a máquina disputada.** A dispersão delas '
                . 'diz mais sobre o host que sobre o runtime.',
            count($contended),
        );
    }

    if ($erroring !== []) {
        $listedErroring = implode(', ', array_slice($erroring, 0, 5));

        if (count($erroring) > 5) {
            $listedErroring .= '…';
        }

        $warnings[] = sprintf(
            '**%d célula(s) com requests falhando.** Responder erro é mais '
                . 'barato que responder certo, então a vazão delas está inflada: %s.',
            count($erroring),
            $listedErroring,
        );
    }

    if ($noisy !== []) {
        $listedNoisy = implode(', ', array_slice($noisy, 0, 5));

        if (count($noisy) > 5) {
            $listedNoisy .= '…';
        }

        $warnings[] = sprintf(
            '**%d célula(s) com dispersão acima de %s%%.** Diferenças pequenas '
                . 'nessas linhas não significam nada: %s.',
            count($noisy),
            NOISY_SPREAD_PCT,
            $listedNoisy,
        );
    }

    // Must follow the block above: with one sample the dispersion check cannot
    // fire, so without this warning its silence would read as approval.
    if ($samplesPerRoute < MINIMUM_SAMPLES_FOR_DISPERSION) {
        $warnings[] = sprintf(
            '**Dispersão não foi medida** — esta corrida tem %d amostra(s) por '
                . 'rota. As medianas valem, mas nada aqui diz se elas se repetem: '
                . 'diferenças de poucos por cento entre runtimes não são '
                . 'atribuíveis. Para publicar, use `--medium` ou `--large`.',
            $samplesPerRoute,
        );
    }

    return $warnings;
}

echo "# Resultado — {$manifest['run_id']}\n\n";

$trustWarnings = collectTrustWarnings($summariesByRuntime, $manifest);

if ($trustWarnings === []) {
    echo '> ✅ **Corrida íntegra.** A carga aplicada confere com a descrita, as '
        . 'amostras concordam entre si, nenhuma request falhou e a máquina '
        . 'estava livre durante as medições. Os números abaixo sustentam '
        . "comparação.\n\n";
} else {
    echo '> ⚠️ **Leia isto antes das tabelas.** Esta corrida tem '
        . count($trustWarnings) . ' problema(s) que afetam o que os números '
        . "significam:\n>\n";

    foreach ($trustWarnings as $warning) {
        echo "> - {$warning}\n";
    }

    echo ">\n> O detalhe de cada um está nas seções correspondentes.\n\n";
}


echo "## Contexto\n\n";
echo "| | |\n|---|---|\n";
echo '| Executado em | ' . ($manifest['started_at'] ?? '?') . " |\n";

$hostMemoryBytes = (int) ($host['memory_bytes'] ?? 0);
$hostMemoryGigabytes = round($hostMemoryBytes / 1073741824, 1);

echo '| Host | ' . ($host['cpus'] ?? '?') . ' cores, '
    . $hostMemoryGigabytes . ' GB, '
    . ($host['os'] ?? '?') . " |\n";
echo '| Docker | ' . ($host['docker_version'] ?? '?') . ' (' . ($host['kernel'] ?? '?') . ") |\n";
echo '| PHP | ' . ($manifest['php_version'] ?? '?') . ' · tuning: '
    . ($manifest['php_tuning'] ?? '?') . " |\n";
echo '| Orçamento | ' . ($budget['app_cpus'] ?? '?') . ' CPU, ' . ($budget['app_mem'] ?? '?')
    . ', ' . ($budget['app_workers'] ?? '?') . ' workers, reciclando a cada '
    . ($budget['app_max_requests_per_worker'] ?? '?') . " requests |\n";
echo '| Medição | malha fechada até esgotar, janela de '
    . ($manifest['load']['measure_seconds'] ?? '?') . 's, '
    . ($manifest['load']['samples_per_route'] ?? '?') . " amostras |\n\n";

// A corrected number that does not announce itself is worse than the error it
// replaced: anyone comparing this report against an earlier one would see a
// column move and have no way to know why.
foreach ($manifest['amendments'] ?? [] as $amendment) {
    $remeasuredDate = substr((string) ($amendment['remeasured_at'] ?? '?'), 0, 10);

    echo "> **Emenda — rota `{$amendment['route']}` re-medida em "
        . $remeasuredDate . '.** '
        . ($amendment['reason'] ?? '') . "\n\n";
}

echo "## O que foi medido\n\n";
echo 'Cada linha das tabelas abaixo é um destes. O modelo de execução é o eixo '
    . 'que explica os números — quem reinicia a cada request paga o bootstrap '
    . 'toda vez, quem fica residente paga uma vez só, e quem tem corrotina '
    . "ainda libera o worker enquanto espera.\n\n";

echo "| Runtime | Framework | Modelo de execução |\n|---|---|---|\n";

foreach ($summariesByRuntime as $runtime => $summary) {
    printf(
        "| `%s` | %s | %s |\n",
        $runtime,
        $summary['framework'] ?? '?',
        $summary['model'] ?? '?',
    );
}

echo "\n## Vazão (rps)\n\n";
echo 'Requests por segundo escoadas com todo o poder de fogo em cima do '
    . "runtime, sob o orçamento acima. Mediana das amostras.\n\n";

renderRouteTable($summariesByRuntime, $routeLabels, static function (?array $route): string {
    if ($route === null) {
        return '—';
    }

    $wholeRps = (int) ($route['throughput_rps'] ?? 0);

    return (string) $wholeRps;
});

echo "\n## Dispersão entre amostras (%)\n\n";

$samplesPerRoute = (int) ($manifest['load']['samples_per_route'] ?? 0);
$isDispersionMeasurable = $samplesPerRoute >= MINIMUM_SAMPLES_FOR_DISPERSION;

if (!$isDispersionMeasurable) {
    // Printing 0.0 here would be worse than printing nothing: a column of zeros
    // reads as perfect reproducibility, when what happened was no measurement.
    echo "Não medida — esta corrida tem {$samplesPerRoute} amostra(s) por rota, "
        . 'e dispersão exige pelo menos ' . MINIMUM_SAMPLES_FOR_DISPERSION . '. '
        . 'As medianas das tabelas acima valem; o que não existe é evidência de '
        . 'que se repetem. Diferenças de poucos por cento entre runtimes não '
        . "devem ser lidas como ordenação.\n";
} else {
    echo 'Distância entre a menor e a maior amostra, como fração da mediana. '
        . 'Acima de ' . NOISY_SPREAD_PCT . '% as amostras discordam o bastante '
        . "para que diferenças pequenas entre runtimes não signifiquem nada.\n\n";

    renderRouteTable($summariesByRuntime, $routeLabels, static function (?array $route): string {
        if ($route === null) {
            return '—';
        }

        $spread = (float) ($route['spread_pct'] ?? 0);

        return $spread > NOISY_SPREAD_PCT ? sprintf('**%.1f**', $spread) : sprintf('%.1f', $spread);
    });
}

echo "\n## Vazão relativa ao `" . BASELINE_RUNTIME . "`\n\n";
echo 'A mesma tabela acima, normalizada. Com oito runtimes, números absolutos '
    . 'não se comparam de cabeça; `1.00` é o ' . BASELINE_RUNTIME . ' e cada '
    . 'célula diz quantas vezes o runtime escoou mais (ou menos) na mesma '
    . "rota, sob o mesmo orçamento.\n\n";

$baselineSummary = $summariesByRuntime[BASELINE_RUNTIME] ?? reset($summariesByRuntime);

renderRouteTable(
    $summariesByRuntime,
    $routeLabels,
    static function (?array $route, array $summary, string $label) use ($baselineSummary): string {
        $throughput = (float) ($route['throughput_rps'] ?? 0);
        $baseline = (float) ($baselineSummary['routes'][$label]['throughput_rps'] ?? 0);

        return $throughput <= 0.0 || $baseline <= 0.0
            ? '—'
            : sprintf('%.2f', $throughput / $baseline);
    },
);

echo "\n## Latência ao esgotar (p50 / p95 / p99, ms)\n\n";
echo 'Latência **no ponto de saturação**, que é o pior caso por construção. '
    . "Não é a latência que o runtime entrega sob carga normal.\n\n";
echo 'Os três percentis juntos porque a distância entre eles é o achado: um '
    . 'p50 baixo com p99 alto é uma fila que trava de vez em quando, enquanto '
    . 'os três próximos são um runtime uniformemente saturado. São problemas '
    . "diferentes e o p95 sozinho não os separa.\n\n";

$suspectTails = [];

renderRouteTable(
    $summariesByRuntime,
    $routeLabels,
    static function (?array $route, array $summary, string $label) use (&$suspectTails): string {
        if ($route === null || ($route['p95_ms'] ?? null) === null) {
            return '—';
        }

        $p95 = (float) $route['p95_ms'];
        $p99 = (float) $route['p99_ms'];
        $hasSuspectTail = $p95 > 0.0 && $p99 > $p95 * SUSPECT_TAIL_RATIO;

        if ($hasSuspectTail) {
            $suspectTails[] = sprintf(
                '- `%s` / `%s`: p95 %.0fms, p99 %.0fms',
                $summary['runtime'] ?? '?',
                $label,
                $p95,
                $p99,
            );
        }

        return sprintf(
            '%.0f / %.0f / %.0f%s',
            (float) ($route['p50_ms'] ?? 0),
            $p95,
            $p99,
            $hasSuspectTail ? ' ⚠' : '',
        );
    },
);

if ($suspectTails !== []) {
    echo "\n⚠ **p99 suspeito** — salto de mais de " . (int) SUSPECT_TAIL_RATIO
        . '× sobre o p95, aterrissando em cima do máximo. Curva de latência '
        . 'real sobe, não dá degrau. Nestes casos o p99 é um stall de valor '
        . 'fixo, reproduzível em janelas de 3s e de 60s, inteiro no '
        . 'time-to-first-byte e com o estabelecimento de conexão perto de '
        . 'zero. **Use p50/p95 para comparar; o p99 destas linhas ainda não '
        . "foi explicado:**\n\n";
    echo implode("\n", $suspectTails) . "\n";
}

echo "\n## Servidor vs. fila de conexão (p95, ms)\n\n";
echo 'A latência total é a soma de esperar por uma conexão e ser atendido. '
    . '`servidor` é o tempo pensando (TTFB); `fila` é o tempo antes disso, '
    . 'esperando um slot de conexão. Dois runtimes com a mesma latência total '
    . 'podem estar em situações opostas: fila alta com servidor baixo é um '
    . "backlog de accept, não um runtime lento.\n\n";

renderRouteTable($summariesByRuntime, $routeLabels, static function (?array $route): string {
    $serverMs = $route['server_p95_ms'] ?? null;

    return $serverMs === null
        ? '—'
        : sprintf('%.0f / %.0f', (float) $serverMs, (float) ($route['connect_queue_p95_ms'] ?? 0));
});

echo "\n## Memória por request (pico, KiB)\n\n";
echo 'Quanto uma request precisou de memória para existir, medido pelo próprio '
    . 'runtime: o pico que ela atingiu acima da linha de base em que começou. '
    . 'É o pico, não o retido — uma request que aloca 8 MiB e libera antes de '
    . 'responder não guarda nada, mas precisou dos 8 MiB. É esse número que '
    . "dimensiona um worker.\n\n";

renderRouteTable($summariesByRuntime, $routeLabels, static function (?array $route): string {
    $peakBytes = $route['request_memory_peak_bytes'] ?? null;

    return $peakBytes === null ? '—' : sprintf('%.0f', (float) $peakBytes / 1024);
});

echo "\n## Memória total sob carga (pico residente, MiB)\n\n";
echo 'O que o deployment inteiro ocupou enquanto escoava sua vazão máxima. '
    . 'Memória residente conta cada página uma vez, então o que o worker '
    . 'reaproveita entre requests **não soma** — é o total no sentido de '
    . '"quanto foi preciso ter", não de "quanto foi alocado ao longo do '
    . 'tempo". Entre parênteses, o quanto isso subiu acima do ocioso: essa '
    . "diferença é a memória que a carga de fato exigiu.\n\n";

renderRouteTable($summariesByRuntime, $routeLabels, static function (?array $route, array $summary): string {
    $idle = sumApplicationMebibytes($summary['idle_footprint'] ?? [], 'idle_mebibytes');
    $peak = sumApplicationMebibytes($route['utilization'] ?? [], 'peak_mem_mib');

    if ($peak <= 0.0) {
        return '—';
    }

    // What the load itself demanded, over what the deployment costs at rest.
    // Clamped at zero: an idle reading taken between two other measurements can
    // land above the peak, and a negative "growth" would read as a saving.
    $loadGrowthMebibytes = max(0.0, $peak - $idle);

    return sprintf('%.0f (+%.0f)', $peak, $loadGrowthMebibytes);
});

echo "\n## Custo de existir (memória ociosa, MiB)\n\n";
echo 'Memória ocupada **antes de qualquer request chegar**. É o preço do '
    . 'modelo: um worker persistente carrega o framework uma vez e o mantém '
    . 'residente, enquanto o FPM sobe e derruba a cada request. Nenhuma tabela '
    . 'de vazão mostra isso, e num orçamento fixo é o que decide quantos '
    . "workers cabem.\n\n";

$footprintRows = [];

foreach ($summariesByRuntime as $runtime => $summary) {
    $appContainers = [];

    foreach ($summary['idle_footprint'] ?? [] as $container => $usage) {
        $isSupportContainer = str_contains($container, 'k6') || str_contains($container, 'stub');

        if ($isSupportContainer) {
            continue;
        }

        $appContainers[] = sprintf(
            '%s %.0f MiB (%.0f%%)',
            $container,
            (float) ($usage['idle_mebibytes'] ?? 0),
            (float) ($usage['idle_mem_pct'] ?? 0),
        );
    }

    if ($appContainers !== []) {
        $footprintRows[] = "| `{$runtime}` | " . implode(', ', $appContainers) . " |\n";
    }
}

if ($footprintRows === []) {
    echo "Não capturado nesta corrida.\n";
} else {
    echo "| Runtime | Ocioso |\n|---|---|\n" . implode('', $footprintRows);
}

$erroringRoutes = [];

foreach ($summariesByRuntime as $runtime => $summary) {
    foreach ($summary['routes'] ?? [] as $label => $route) {
        $errorRate = $route['error_rate'] ?? null;

        if ($errorRate === null || (float) $errorRate <= 0.0) {
            continue;
        }

        $erroringRoutes[] = sprintf(
            '- `%s` / `%s`: %.2f%% das requests falharam',
            $runtime,
            $label,
            (float) $errorRate * 100,
        );
    }
}

echo "\n## Erros\n\n";

if ($erroringRoutes === []) {
    echo 'Nenhuma request falhou em nenhum runtime. Toda a vazão das tabelas '
        . "acima é de respostas 200 — vazão com erro não seria vazão.\n";
} else {
    echo 'Vazão medida com falhas dentro. Uma linha aqui desconta a linha '
        . 'correspondente na tabela de vazão: responder erro é mais barato do '
        . "que responder certo:\n\n";
    echo implode("\n", $erroringRoutes) . "\n";
}

$budgetNotes = [];
$saturationNotes = [];

foreach ($summariesByRuntime as $runtime => $summary) {
    $cpuLimits = $summary['cpu_limit_pct'] ?? [];

    foreach ($summary['routes'] ?? [] as $label => $route) {
        if (($route['held_budget'] ?? false) === true) {
            $budgetNotes[] = "- `{$runtime}` / `{$label}`";
        }

        $note = describeSaturation($route, $cpuLimits);

        if ($note !== '') {
            $saturationNotes[] = "- `{$runtime}` / `{$label}`: {$note} do próprio orçamento";
        }
    }
}

$latencyBudget = $manifest['load']['latency_budget_p95_ms'] ?? 200;

echo "\n## Quem segurou a latência mesmo saturado\n\n";

if ($budgetNotes === []) {
    echo 'Nenhum. Todos estouraram o orçamento de p95 no ponto de saturação — '
        . "o que é o esperado: saturar significa parar de responder rápido.\n";
} else {
    echo 'Estes escoaram sua vazão máxima **e ainda** mantiveram p95 abaixo de '
        . "{$latencyBudget}ms. Vazão sem degradar latência é o resultado mais "
        . "forte que uma linha desta tabela pode ter:\n\n";
    echo implode("\n", $budgetNotes) . "\n";
}

echo "\n## Onde estava o gargalo\n\n";

if ($saturationNotes === []) {
    echo 'Nenhum container atingiu o próprio teto de CPU. Se a vazão parou '
        . 'abaixo do esperado, o limite não foi CPU — vale investigar rede, '
        . "contagem de workers ou o gerador de carga.\n";
} else {
    echo 'Componentes no teto durante a medição. O container da aplicação '
        . 'aparecer aqui é **bom**: confirma que o runtime era o limite. O '
        . "proxy ou o stub aparecerem significa que o número mede **eles**:\n\n";
    echo implode("\n", $saturationNotes) . "\n";
}

echo "\n## A medição se sustenta? (lei de Little)\n\n";
echo 'A varredura dirige uma malha fechada com um número fixo de VUs, então a '
    . 'concorrência está presa: **vazão × latência média tem que voltar a '
    . 'esse número**. Uma célula que erra isso não está reportando um runtime '
    . 'lento — está reportando uma medição que não descreve a carga que diz '
    . "descrever.\n\n";
echo 'É a checagem mais dura do relatório porque não depende de nenhuma '
    . 'expectativa sobre os runtimes. Aplicada a uma corrida anterior, as **48 '
    . 'células** falharam, todas na mesma direção: a média estava inflada por '
    . "uma cauda artefatual, e o número que parecia latência era instrumento.\n\n";

$inconsistentCells = [];
$checkedCells = 0;
$virtualUsers = (float) ($manifest['load']['overload_vus'] ?? 0);

foreach ($summariesByRuntime as $runtime => $summary) {
    foreach ($summary['routes'] ?? [] as $label => $route) {
        $meanMs = (float) ($route['avg_ms'] ?? 0);
        $throughput = (float) ($route['throughput_rps'] ?? 0);

        if ($meanMs <= 0.0 || $throughput <= 0.0 || $virtualUsers <= 0.0) {
            continue;
        }

        $checkedCells++;
        $impliedConcurrency = $throughput * $meanMs / 1000;
        $deviationPct = ($impliedConcurrency - $virtualUsers) / $virtualUsers * 100;

        if (abs($deviationPct) > LITTLES_LAW_TOLERANCE_PCT) {
            $inconsistentCells[] = sprintf(
                '- `%s` / `%s`: %.0f VUs implícitos contra %.0f (%+.0f%%)',
                $runtime,
                $label,
                $impliedConcurrency,
                $virtualUsers,
                $deviationPct,
            );
        }
    }
}

if ($checkedCells === 0) {
    echo 'Não verificável nesta corrida: falta a latência média ou a contagem '
        . "de VUs.\n";
} elseif ($inconsistentCells === []) {
    printf(
        'As %d células conferem dentro de ±%d%%. A carga aplicada foi a que o '
            . "relatório diz que foi.\n",
        $checkedCells,
        (int) LITTLES_LAW_TOLERANCE_PCT,
    );
} else {
    printf(
        '**%d de %d células não fecham.** Trate os números delas como '
            . "suspeitos até a causa aparecer:\n\n",
        count($inconsistentCells),
        $checkedCells,
    );
    echo implode("\n", $inconsistentCells) . "\n";
}

echo "\n## A máquina estava ocupada?\n\n";
echo 'Saturação do host durante cada medição — CPU realmente ocupada na '
    . 'máquina inteira, medida entre duas leituras de `/proc/stat` a um '
    . 'segundo de distância. Não é load average: aquele é suavizado por um '
    . 'minuto, então dentro de uma janela de 60s ainda está subindo quando ela '
    . 'acaba, e reportava 42% numa máquina que estava a 348%. Acima de '
    . BUSY_HOST_PCT . '% a máquina estava '
    . 'disputada, e uma célula com dispersão alta ali provavelmente diz mais '
    . 'sobre o host do que sobre o runtime. Sem isso, "este runtime é '
    . 'ruidoso" e "a máquina estava ocupada quando ele foi medido" são '
    . "indistinguíveis.\n\n";

$busyCells = [];
$hostPeak = 0.0;

foreach ($summariesByRuntime as $runtime => $summary) {
    foreach ($summary['routes'] ?? [] as $label => $route) {
        $hostSaturation = (float) ($route['utilization']['host']['peak_mem_pct'] ?? 0);

        if ($hostSaturation <= 0.0) {
            continue;
        }

        $hostPeak = max($hostPeak, $hostSaturation);

        if ($hostSaturation > BUSY_HOST_PCT) {
            $busyCells[] = sprintf(
                '- `%s` / `%s`: host a %.0f%%',
                $runtime,
                $label,
                $hostSaturation,
            );
        }
    }
}

if ($hostPeak <= 0.0) {
    echo "Não capturado nesta corrida.\n";
} elseif ($busyCells === []) {
    printf(
        'Nenhuma célula foi medida com a máquina disputada — o pico de '
            . "saturação do host em toda a corrida foi %.0f%%.\n",
        $hostPeak,
    );
} else {
    echo 'Estas foram medidas com a máquina sob disputa. Trate a dispersão '
        . "delas com desconfiança antes de atribuí-la ao runtime:\n\n";
    echo implode("\n", $busyCells) . "\n";
}

echo "\n## Como ler\n\n";
echo '- **Isto é saturação, não capacidade de produção.** Mede-se o teto '
    . "bruto; ninguém opera um servidor nesse ponto.\n";
echo '- **`blocking_wait` não é I/O.** É um `usleep`, o melhor caso idealizado '
    . "para corrotinas. Para I/O real sobre socket, veja `external_io`.\n";
echo "- **`memory` mede banda**, não capacidade — veja RUNTIMES.md.\n";
echo '- **nginx só serve as variantes FPM**, que pagam um hop de proxy que as '
    . "outras não pagam. É inerente ao modelo, não um viés corrigível.\n";
echo '- Os números valem como **comparação relativa** sob condições idênticas '
    . "nesta máquina, não como valores absolutos.\n";
