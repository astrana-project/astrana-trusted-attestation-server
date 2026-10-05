<#
.SYNOPSIS
Runs what continuous integration runs on a pull request. A check whose toolchain is not installed is skipped.
.DESCRIPTION
Formatting, sign-off and changelog checks. With -Tests, also each implementation's unit tests. With -Base REF,
compares against REF instead of origin/master.
.EXAMPLE
scripts\check.ps1
.EXAMPLE
scripts\check.ps1 -Tests
#>
#Requires -Version 7
param([switch]$Tests, [string]$Base = "")

Push-Location (Join-Path $PSScriptRoot "..")

function Have($name) { [bool](Get-Command $name -ErrorAction SilentlyContinue) }

if (-not $Base) {
    foreach ($candidate in "origin/master", "master") {
        git rev-parse --verify --quiet $candidate *> $null
        if ($LASTEXITCODE -eq 0) { $Base = $candidate; break }
    }
}
# On Windows "python3" can be a Store shortcut that only prints a message, so try each candidate.
$py = ""
foreach ($candidate in "python3", "python", "py") {
    if (Have $candidate) {
        & $candidate -c "import sys" *> $null
        if ($LASTEXITCODE -eq 0) { $py = $candidate; break }
    }
}
# Maven from the PATH, or else the wrapper in java/, which downloads Maven on first use. Both need a Java development kit.
$maven = if (Have mvn) { "mvn" } elseif ((Have java) -or $env:JAVA_HOME) { $IsWindows ? "java/mvnw.cmd" : "java/mvnw" } else { "" }

$passed = @(); $failed = @(); $skipped = @()
function Run($label, [scriptblock]$action) {
    Write-Host ""; Write-Host "== $label"
    $global:LASTEXITCODE = 1
    try { & $action } catch { $global:LASTEXITCODE = 1 }
    if ($LASTEXITCODE -eq 0) { $script:passed += $label } else { $script:failed += $label }
}
function Skip($label, $why) { Write-Host ""; Write-Host "== $label"; Write-Host "   skipped, $why"; $script:skipped += $label }

if (Test-Path node_modules/.bin/prettier) { Run "prettier" { npm run --silent format:check } }
elseif (Have npm) { Skip "prettier" "run scripts\setup.ps1 first" }
else { Skip "prettier" "node is not installed" }

if (Have dotnet) { Run "dotnet format" { dotnet format dotnet/Astrana.TrustedAttestation.slnx --verify-no-changes --verbosity quiet } }
else { Skip "dotnet format" "the .NET SDK is not installed" }

if ($maven) { Run "spotless" { & $maven -q -f java/pom.xml spotless:check } }
else { Skip "spotless" "the Java 25 development kit is not installed" }

if ((Have php) -and (Test-Path php/vendor/bin/pint)) { Run "pint" { Push-Location php; php vendor/bin/pint --test; Pop-Location } }
elseif (Have php) { Skip "pint" "run composer install in php/ first" }
else { Skip "pint" "php is not installed" }

if ($py -and $Base) {
    Write-Host ""; Write-Host "comparing against $Base at $(git log -1 --format='%h, %cr' $Base)"
    if (git status --porcelain) { Write-Host "uncommitted changes are not included in the sign-off and changelog checks, commit first" }
    Run "sign-off" { & $py .github/scripts/dco_check.py $Base }
    Run "changelog" { & $py .github/scripts/changelog_check.py $Base }
} elseif (-not $py) {
    Skip "sign-off and changelog" "python is not installed"
} else {
    Skip "sign-off and changelog" "no master to compare against (fetch origin first)"
}

if ($Tests) {
    if (Have dotnet) { Run "dotnet test" { dotnet test dotnet/Astrana.TrustedAttestation.slnx --nologo --verbosity quiet } }
    else { Skip "dotnet test" "the .NET SDK is not installed" }
    if ($maven) { Run "mvn test" { & $maven -q -f java/pom.xml test } }
    else { Skip "mvn test" "the Java 25 development kit is not installed" }
    if ((Have php) -and (Test-Path php/vendor/bin/phpunit)) { Run "phpunit" { Push-Location php; php vendor/bin/phpunit; Pop-Location } }
    elseif (Have php) { Skip "phpunit" "run composer install in php/ first" }
    else { Skip "phpunit" "php is not installed" }
}

Write-Host ""; Write-Host "== summary"
if ($passed.Count)  { Write-Host "   passed:  $($passed -join ', ')" }
if ($skipped.Count) { Write-Host "   skipped: $($skipped -join ', ') (continuous integration runs these)" }
if ($failed.Count)  { Write-Host "   FAILED:  $($failed -join ', ')" }
if ($failed | Where-Object { $_ -in "prettier", "dotnet format", "spotless", "pint" }) { Write-Host "   scripts\format.ps1 fixes formatting" }
if ($Tests) { Write-Host "   not run: the integration suites (scripts\integration.ps1)" }
else { Write-Host "   not run: unit tests (-Tests), the integration suites (scripts\integration.ps1)" }

Pop-Location
exit ($failed.Count ? 1 : 0)
