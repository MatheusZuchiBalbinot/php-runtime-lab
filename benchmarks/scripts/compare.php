<?php

declare(strict_types=1);

/**
 * Diffs two finished runs, cell by cell.
 *
 * Two of the axes this lab is built around — the PHP tuning profile and the
 * PHP version — do not exist inside a single run. They are the difference
 * *between* two runs, and until now that difference had to be worked out by
 * opening two reports side by side. Every comparison made during development
 * was a throwaway script, written again the next time.
 *
 * Run through the same stock PHP image as the report:
 *
 *   docker run --rm -v "$PWD:/work" -w /work php:8.3-cli-alpine \
 *     php benchmarks/scripts/compare.php <baseline-run> <candidate-run>
 *
 * The baseline is the run being compared *against*, so a positive delta means
 * the candidate did more.
 */

const EXIT_USAGE = 1;

/**
 * Change below which a difference is treated as noise rather than movement.
 *
 * Set from what this lab actually observes: a healthy run's samples disagree
 * with each other by a few percent, so anything under this is inside the
 * measurement's own resolution and should not be read as an effect.
 */
const MEANINGFUL_DELTA_PCT = 5.0;

/**
 * Loads one run's summaries, keyed by runtime.
 *
 * @return array{manifest: array<string, mixed>, runtimes: array<string, array<string, mixed>>}
 */
function loadRun(string $directory): array
{
    $manifestPath = $directory . '/manifest.json';
    $manifest = is_file($manifestPath)
        ? json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR)
        : [];

    $runtimes = [];

    foreach (glob($directory . '/*/summary.json') ?: [] as $path) {
        $summary = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $runtimes[$summary['runtime'] ?? basename(dirname($path))] = $summary;
    }

    return ['manifest' => $manifest, 'runtimes' => $runtimes];
}

/**
 * Describes what actually differs between the two runs' parameters.
 *
 * A comparison is only meaningful when one thing changed. Listing the
 * differences up front is what lets the reader judge whether the deltas below
 * can be attributed to anything at all — two runs differing in tuning *and*
 * worker count explain nothing.
 *
 * @param array<string, mixed> $baselineManifest
 * @param array<string, mixed> $candidateManifest
 *
 * @return list<string>
 */
function describeParameterDifferences(array $baselineManifest, array $candidateManifest): array
{
    $comparable = [
        'php_version' => 'versão do PHP',
        'php_tuning' => 'tuning',
    ];

    $differences = [];

    foreach ($comparable as $key => $label) {
        $before = $baselineManifest[$key] ?? '?';
        $after = $candidateManifest[$key] ?? '?';

        if ($before !== $after) {
            $differences[] = "{$label}: `{$before}` → `{$after}`";
        }
    }

    foreach (($candidateManifest['budget'] ?? []) as $key => $after) {
        $before = $baselineManifest['budget'][$key] ?? null;

        if ($before !== null && (string) $before !== (string) $after) {
            $differences[] = "orçamento `{$key}`: `{$before}` → `{$after}`";
        }
    }

    foreach (($candidateManifest['load'] ?? []) as $key => $after) {
        $before = $baselineManifest['load'][$key] ?? null;

        if ($before !== null && (string) $before !== (string) $after) {
            $differences[] = "carga `{$key}`: `{$before}` → `{$after}`";
        }
    }

    return $differences;
}

$baselineDirectory = $argv[1] ?? '';
$candidateDirectory = $argv[2] ?? '';

if (!is_dir($baselineDirectory) || !is_dir($candidateDirectory)) {
    fwrite(STDERR, "Usage: compare.php <baseline-run> <candidate-run>\n");
    exit(EXIT_USAGE);
}

$baseline = loadRun($baselineDirectory);
$candidate = loadRun($candidateDirectory);

$sharedRuntimes = array_intersect(
    array_keys($baseline['runtimes']),
    array_keys($candidate['runtimes']),
);

if ($sharedRuntimes === []) {
    fwrite(STDERR, "As duas corridas não têm nenhum runtime em comum.\n");
    exit(EXIT_USAGE);
}

$routeLabels = [];
foreach ($candidate['runtimes'] as $summary) {
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

echo "# Comparação\n\n";
echo '- **base:** `' . basename($baselineDirectory) . "`\n";
echo '- **candidata:** `' . basename($candidateDirectory) . "`\n\n";

$differences = describeParameterDifferences($baseline['manifest'], $candidate['manifest']);

if ($differences === []) {
    echo '> ⚠️ **Os parâmetros registrados das duas corridas são idênticos.** '
        . 'Se algo mudou entre elas, mudou fora do que o manifesto grava — '
        . 'código, imagem, ou a máquina. Uma diferença aqui não é atribuível a '
        . "nenhuma variável declarada.\n\n";
} else {
    echo "**O que mudou entre elas:**\n\n";
    foreach ($differences as $difference) {
        echo "- {$difference}\n";
    }
    echo "\n";

    if (count($differences) > 1) {
        echo '> ⚠️ Mais de uma variável mudou, então uma diferença nos números '
            . "abaixo não pode ser atribuída a nenhuma delas em particular.\n\n";
    }
}

echo "## Vazão: variação (%)\n\n";
echo 'Positivo significa que a candidata escoou mais. Abaixo de '
    . MEANINGFUL_DELTA_PCT . '% em módulo fica em branco: é menor que a '
    . 'discordância entre amostras de uma mesma corrida, então não é efeito, é '
    . "resolução.\n\n";

echo '| Runtime | ' . implode(' | ', $routeLabels) . " |\n";
echo '|---|' . str_repeat('---|', count($routeLabels)) . "\n";

$movements = [];

foreach ($sharedRuntimes as $runtime) {
    $cells = [];

    foreach ($routeLabels as $label) {
        $before = (float) ($baseline['runtimes'][$runtime]['routes'][$label]['throughput_rps'] ?? 0);
        $after = (float) ($candidate['runtimes'][$runtime]['routes'][$label]['throughput_rps'] ?? 0);

        if ($before <= 0.0 || $after <= 0.0) {
            $cells[] = '—';
            continue;
        }

        $deltaPct = ($after - $before) / $before * 100;

        if (abs($deltaPct) < MEANINGFUL_DELTA_PCT) {
            $cells[] = '·';
            continue;
        }

        $movements[] = [
            'cell' => "{$runtime}/{$label}",
            'delta' => $deltaPct,
            'before' => $before,
            'after' => $after,
        ];

        $cells[] = sprintf('%+.0f%%', $deltaPct);
    }

    echo "| `{$runtime}` | " . implode(' | ', $cells) . " |\n";
}

echo "\n## Maiores movimentos\n\n";

if ($movements === []) {
    echo 'Nenhuma célula se moveu mais que ' . MEANINGFUL_DELTA_PCT . "%.\n";
} else {
    usort($movements, static fn (array $a, array $b): int => abs($b['delta']) <=> abs($a['delta']));

    foreach (array_slice($movements, 0, 10) as $movement) {
        printf(
            "- `%s`: %.0f → %.0f rps (%+.0f%%)\n",
            $movement['cell'],
            $movement['before'],
            $movement['after'],
            $movement['delta'],
        );
    }
}

echo "\n## Cauda: pior request (ms)\n\n";
echo 'A vazão pode subir enquanto a cauda piora — foi assim que uma correção '
    . 'de proxy deu +18% de vazão neste lab enquanto criava requests de 19 '
    . 'segundos. As duas colunas juntas são o que impede esse tipo de troca de '
    . "passar por melhoria.\n\n";

echo '| Runtime | ' . implode(' | ', $routeLabels) . " |\n";
echo '|---|' . str_repeat('---|', count($routeLabels)) . "\n";

foreach ($sharedRuntimes as $runtime) {
    $cells = [];

    foreach ($routeLabels as $label) {
        $before = (float) ($baseline['runtimes'][$runtime]['routes'][$label]['max_ms'] ?? 0);
        $after = (float) ($candidate['runtimes'][$runtime]['routes'][$label]['max_ms'] ?? 0);

        $cells[] = $before <= 0.0 || $after <= 0.0
            ? '—'
            : sprintf('%.0f → %.0f', $before, $after);
    }

    echo "| `{$runtime}` | " . implode(' | ', $cells) . " |\n";
}

$onlyInBaseline = array_diff(array_keys($baseline['runtimes']), $sharedRuntimes);
$onlyInCandidate = array_diff(array_keys($candidate['runtimes']), $sharedRuntimes);

if ($onlyInBaseline !== [] || $onlyInCandidate !== []) {
    echo "\n## Fora da comparação\n\n";

    if ($onlyInBaseline !== []) {
        echo '- só na base: `' . implode('`, `', $onlyInBaseline) . "`\n";
    }

    if ($onlyInCandidate !== []) {
        echo '- só na candidata: `' . implode('`, `', $onlyInCandidate) . "`\n";
    }
}
