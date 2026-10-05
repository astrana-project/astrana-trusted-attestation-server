<#
.SYNOPSIS
Runs every formatter, skipping any whose toolchain is not installed.
.EXAMPLE
scripts\format.ps1
#>
#Requires -Version 7
Push-Location (Join-Path $PSScriptRoot "..")

function Have($name) { [bool](Get-Command $name -ErrorAction SilentlyContinue) }
$status = 0
function Run($label, [scriptblock]$action) {
    Write-Host "== $label"
    $global:LASTEXITCODE = 1
    try { & $action } catch { $global:LASTEXITCODE = 1 }
    if ($LASTEXITCODE -eq 0) { Write-Host "   ok" } else { Write-Host "   FAILED"; $script:status = 1 }
}
function Skip($label, $why) { Write-Host "== $label"; Write-Host "   skipped, $why" }
# Maven from the PATH, or else the wrapper in java/, which downloads Maven on first use. Both need a Java development kit.
$maven = if (Have mvn) { "mvn" } elseif ((Have java) -or $env:JAVA_HOME) { $IsWindows ? "java/mvnw.cmd" : "java/mvnw" } else { "" }

if (Test-Path node_modules/.bin/prettier) { Run "prettier" { npm run --silent format } }
elseif (Have npm) { Skip "prettier" "run scripts\setup.ps1 first" }
else { Skip "prettier" "node is not installed" }

if (Have dotnet) { Run "dotnet format" { dotnet format dotnet/Astrana.TrustedAttestation.slnx --verbosity quiet } }
else { Skip "dotnet format" "the .NET SDK is not installed" }

if ($maven) { Run "spotless" { & $maven -q -f java/pom.xml spotless:apply } }
else { Skip "spotless" "the Java 25 development kit is not installed" }

if ((Have php) -and (Test-Path php/vendor/bin/pint)) { Run "pint" { Push-Location php; php vendor/bin/pint; Pop-Location } }
elseif (Have php) { Skip "pint" "run composer install in php/ first" }
else { Skip "pint" "php is not installed" }

Pop-Location
exit $status
