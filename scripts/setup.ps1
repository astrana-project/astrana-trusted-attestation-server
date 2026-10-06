<#
.SYNOPSIS
Prepares a clone for contributing. Safe to run again at any time.
.DESCRIPTION
Installs the formatter and reports toolchains. With -Hooks, also installs the git hooks (sign off each commit, check
sign-off and changelog on push). With -NoHooks, removes those hooks.
.EXAMPLE
scripts\setup.ps1
.EXAMPLE
scripts\setup.ps1 -Hooks
#>
#Requires -Version 7
param([switch]$Hooks, [switch]$NoHooks)

Push-Location (Join-Path $PSScriptRoot "..")
$status = 0

function Report($name, $hint) {
    $version = if (Get-Command $name -ErrorAction SilentlyContinue) { @(& $name --version 2>$null)[0] } else { $null }
    if ($version) { Write-Host ("  {0,-10} found    {1}" -f $name, $version) }
    else { Write-Host ("  {0,-10} missing  {1}" -f $name, $hint) }
}
# On Windows "python3" can be a Store shortcut that only prints a message, so try each candidate.
$python = ""
foreach ($candidate in "python3", "python", "py") {
    if (Get-Command $candidate -ErrorAction SilentlyContinue) {
        & $candidate -c "import sys" *> $null
        if ($LASTEXITCODE -eq 0) { $python = $candidate; break }
    }
}

Write-Host "== toolchains"
Report node "formatter: https://nodejs.org"
Report ($python ? $python : "python") "sign-off and changelog checks: https://www.python.org"
Report dotnet ".NET implementation: https://dotnet.microsoft.com/download"
Report java "Java implementation: a Java 25 development kit, for example https://adoptium.net"
if ((Get-Command mvn -ErrorAction SilentlyContinue) -or -not (Test-Path java/mvnw.cmd)) { Report mvn "Java implementation: https://maven.apache.org" }
else { Write-Host ("  {0,-10} wrapper  {1}" -f "mvn", "java\mvnw supplies Maven, no install needed") }
Report php "PHP implementation: https://www.php.net/downloads"
Report composer "PHP implementation: https://getcomposer.org"
Report docker "demonstration stacks and integration suites: https://docs.docker.com/get-docker/"
Write-Host "  a missing toolchain only matters for what it builds, continuous integration runs everything"

if (Get-Command npm -ErrorAction SilentlyContinue) {
    Write-Host "== formatter"
    npm ci --no-audit --no-fund --silent
    if ($LASTEXITCODE -eq 0) { Write-Host "prettier installed" } else { Write-Host "npm ci failed, run it by hand" }
}

$hookDir = git rev-parse --git-path hooks
$marker = "astrana-trusted-attestation-server"
function Ours($path) { (Test-Path $path) -and (Select-String -Path $path -Pattern $marker -Quiet) }
function Install-Hook($name) {
    $target = Join-Path $hookDir $name
    if ((Test-Path $target) -and -not (Ours $target)) { Write-Host "a $name hook that is not ours exists at $target, leaving it alone"; return }
    Copy-Item "scripts/hooks/$name" $target -Force
    if (-not $IsWindows) { chmod +x $target }
    Write-Host "$name hook installed"
}
function Remove-Hook($name) {
    $target = Join-Path $hookDir $name
    if (Ours $target) { Remove-Item $target -Force; Write-Host "$name hook removed" } else { Write-Host "no $name hook of ours to remove" }
}
if ($Hooks) {
    Write-Host "== hooks"
    $hooksPath = git config --get core.hooksPath
    if ($hooksPath -and -not [System.IO.Path]::GetFullPath($hooksPath, $PWD.Path).StartsWith($PWD.Path)) {
        Write-Host "core.hooksPath is $hooksPath, outside this repository, not installing there"
        $status = 1
    } else {
        New-Item -ItemType Directory -Force $hookDir | Out-Null
        Install-Hook prepare-commit-msg
        Install-Hook pre-push
    }
} elseif ($NoHooks) {
    Write-Host "== hooks"
    Remove-Hook prepare-commit-msg
    Remove-Hook pre-push
}

Write-Host "== next"
if (-not $Hooks) { Write-Host "  sign off every commit with git commit -s, or run scripts\setup.ps1 -Hooks to have it added for you" }
if ((Get-Command php -ErrorAction SilentlyContinue) -and -not (Test-Path php/vendor)) { Write-Host "  PHP: run composer install in php/" }
Write-Host "  scripts\format.ps1 formats, scripts\check.ps1 runs what continuous integration runs, scripts\integration.ps1 runs the suites"

Pop-Location
exit $status
