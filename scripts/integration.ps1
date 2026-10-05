<#
.SYNOPSIS
Runs the conformance, differential and accessibility suites against all three implementations, in the Docker stack
continuous integration uses. The report lands in shared\test\ci\reports\index.html.
.DESCRIPTION
The first run downloads several gigabytes of images and builds three applications, so it takes a while. The stack uses
ports 15443, 16443 and 17443, the same as the demonstration stacks, so stop those first. -Keep leaves the stack running,
-NoBuild reuses the images from a previous run, -Down tears the stack down.
.EXAMPLE
scripts\integration.ps1
.EXAMPLE
scripts\integration.ps1 -NoBuild -Keep
#>
#Requires -Version 7
param([switch]$Keep, [switch]$NoBuild, [switch]$Down)

Push-Location (Join-Path $PSScriptRoot "..")
$status = 0

if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
    Write-Host "docker is not installed: https://docs.docker.com/get-docker/"
    Pop-Location; exit 1
}
$compose = "compose", "-f", "shared/test/ci/docker-compose.ci.yml"
if ($Down) { docker @compose --profile suite down -v; $status = $LASTEXITCODE; Pop-Location; exit $status }

Write-Host "== starting the stack"
$build = $NoBuild ? "--no-build" : "--build"
docker @compose up -d $build keycloak postgres dotnet-app java-app php-app proxy
if ($LASTEXITCODE -ne 0) { Write-Host "the stack did not start, scripts\integration.ps1 -Down cleans up"; Pop-Location; exit 1 }
if (-not $NoBuild) {
    docker @compose --profile suite build suite
    if ($LASTEXITCODE -ne 0) { Write-Host "the suite image did not build, scripts\integration.ps1 -Down stops the stack"; Pop-Location; exit 1 }
}

Write-Host "== running the suites"
docker @compose run --rm --no-deps suite bash ci/run-suites.sh /reports
$status = $LASTEXITCODE

if ($Keep) {
    Write-Host "stack left running, scripts\integration.ps1 -Down stops it"
} else {
    Write-Host "== stopping the stack"
    docker @compose --profile suite down -v
}
Write-Host "report: shared\test\ci\reports\index.html"

Pop-Location
exit $status
