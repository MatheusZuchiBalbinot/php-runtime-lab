param(
    [Parameter(Mandatory = $true)]
    [string]$Runtime
)

# Deliberately not a ValidateSet. A hardcoded list here went stale the moment
# runtimes were added and started rejecting five of the eight as unknown, which
# reads as "that runtime does not exist" rather than "this script was not
# updated". The name is validated below against benchmarks/runtimes.conf, which
# is the single source of truth every other script already reads.

$ErrorActionPreference = 'Stop'
Set-Location (Join-Path $PSScriptRoot '..\..')

<#
.SYNOPSIS
    Runs docker with the error preference relaxed, then leaves $LASTEXITCODE
    for the caller to judge.
.DESCRIPTION
    Windows PowerShell 5.1 turns anything a native command writes to stderr
    into an ErrorRecord, and under $ErrorActionPreference = 'Stop' that aborts
    the script even when the command actually succeeded. Docker writes all of
    its build and container progress to stderr, so every docker call here
    would be a coin flip. Success is instead judged the only way that is
    meaningful for a native command: by its exit code.

    Deliberately declared without a param() block: with a declared parameter,
    PowerShell's prefix matching would bind the "-d" of "compose up -d" to it
    as a parameter name instead of passing it through to docker. The automatic
    $args collection has no such binding step.
#>
function Invoke-Docker {
    $previousPreference = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        & docker @args
    }
    finally {
        $ErrorActionPreference = $previousPreference
    }
}

# Which service answers HTTP per runtime comes from benchmarks/runtimes.conf,
# shared with the bash scripts, so the mapping is not restated per script.
$publicServiceByRuntime = @{}
foreach ($line in Get-Content 'benchmarks/runtimes.conf') {
    # Only the service name is taken: the taxonomy fields after the first pipe
    # describe the runtime and are consumed by the reporting side.
    $isConfigEntry = $line -match '^\s*([^#=\s]+)\s*=\s*([^|\s]+)'
    if ($isConfigEntry) {
        $publicServiceByRuntime[$Matches[1]] = $Matches[2]
    }
}

$publicService = $publicServiceByRuntime[$Runtime]
if ([string]::IsNullOrWhiteSpace($publicService)) {
    throw "Unknown runtime '$Runtime'; benchmarks/runtimes.conf has no entry for it."
}

$healthcheckTimeoutSeconds = 30
$k6ThresholdFailureExitCode = 99

# Derives the worker count every runtime must run with, from the CPU budget.
# One formula for every runtime is the point: with an equal allowance, a difference
# in the results belongs to the concurrency model rather than to a number
# someone picked per runtime. Mirrors export_worker_budget in lib.sh.
$performanceConfig = Get-Content -Raw 'performance.json' | ConvertFrom-Json
$workersPerCpu = $performanceConfig.resources.workers_per_cpu
$appCpus = if ($env:APP_CPUS) { [double]$env:APP_CPUS } else { 1.0 }
$appWorkers = [Math]::Max(1, [Math]::Ceiling($appCpus * $workersPerCpu))
$env:APP_WORKERS = "$appWorkers"

Write-Host "Worker budget: $appWorkers ($appCpus cpus x $workersPerCpu per cpu), applied to every runtime."

Write-Host "Starting $Runtime..."
Invoke-Docker compose --profile $Runtime up -d --build
if ($LASTEXITCODE -ne 0) { throw "docker compose up failed for '$Runtime'." }

try {
    $publishedAddress = Invoke-Docker compose --profile $Runtime port $publicService 8080 |
        Select-Object -First 1
    if ([string]::IsNullOrWhiteSpace($publishedAddress)) {
        throw "Could not determine the published host port for service '$publicService'."
    }
    $hostPort = $publishedAddress.Split(':')[-1].Trim()
    $healthcheckUrl = "http://localhost:$hostPort/"

    Write-Host "Waiting for $Runtime to become healthy on port $hostPort..."
    $isHealthy = $false
    $stopwatch = [System.Diagnostics.Stopwatch]::StartNew()

    while (-not $isHealthy -and $stopwatch.Elapsed.TotalSeconds -lt $healthcheckTimeoutSeconds) {
        try {
            $response = Invoke-WebRequest -Uri $healthcheckUrl -UseBasicParsing -TimeoutSec 2
            $isHealthy = $response.StatusCode -eq 200
        } catch {
            Start-Sleep -Milliseconds 500
        }
    }

    if (-not $isHealthy) {
        throw "Runtime '$Runtime' did not become healthy within $healthcheckTimeoutSeconds seconds."
    }

    # Forward load-shape overrides only when they are set in the environment,
    # so performance.json stays the single place that owns the defaults.
    $overridableSettings = @(
        'MEASURE_SECONDS', 'TARGET_RPS', 'WARMUP_SECONDS',
        'GRACEFUL_STOP_SECONDS', 'COOLDOWN_SECONDS',
        'LATENCY_BUDGET_P95_MS', 'ERROR_RATE_BUDGET', 'PRE_ALLOCATED_VUS', 'MAX_VUS'
    )
    $k6EnvArgs = @()
    foreach ($overrideName in $overridableSettings) {
        $overrideValue = [Environment]::GetEnvironmentVariable($overrideName)
        if (-not [string]::IsNullOrWhiteSpace($overrideValue)) {
            $k6EnvArgs += @('-e', "$overrideName=$overrideValue")
        }
    }

    $timestamp = Get-Date -Format 'yyyyMMdd-HHmmss'
    $resultFile = "$Runtime-$timestamp.json"
    $targetUrl = "http://${publicService}:8080"

    Write-Host "Running k6 load test against $targetUrl..."
    $k6Args = @('compose', '--profile', 'bench', 'run', '--rm',
                '-e', "TARGET_URL=$targetUrl",
                '-e', "RESULT_FILE=$resultFile") +
              $k6EnvArgs +
              @('k6', 'run', 'load-test.js')

    Invoke-Docker @k6Args
    $k6ExitCode = $LASTEXITCODE

    if ($k6ExitCode -eq 0) {
        Write-Host "Result saved to benchmarks/results/$resultFile"
    }
    elseif ($k6ExitCode -eq $k6ThresholdFailureExitCode) {
        # A breached threshold means the runtime could not hold the
        # latency/error budget at this load. That is the finding this lab
        # exists to produce, not a failure of the run: the result file is
        # complete and is what tells you where the runtime broke.
        Write-Warning "Thresholds not met at this load - that is a result, not an error."
        Write-Host "Result saved to benchmarks/results/$resultFile"
    }
    else {
        throw "k6 failed with exit code $k6ExitCode; no usable result was produced."
    }
}
finally {
    # Tear the stack down on every exit path - success, a failed health check,
    # a k6 failure, or Ctrl-C. Without this the containers stay up exactly
    # when a run goes wrong, and the next run collides with them.
    Invoke-Docker compose --profile $Runtime down
}
