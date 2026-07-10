<#
.SYNOPSIS
    Build a distributable FormCatch plugin zip, honouring .distignore.

.DESCRIPTION
    Copies the plugin into a staging directory, strips everything listed in
    .distignore, and zips the result as build/formcatch-<version>.zip.

    The zip contains a single top-level `formcatch/` folder, which is what both
    WordPress and Freemius expect.

    Exists because `.distignore` is only understood by `wp dist-archive`
    (a WP-CLI package), and that command shells out to a `zip` binary that
    Windows does not ship. This script needs neither.

.EXAMPLE
    pwsh -NoProfile -File bin/build.ps1
#>

$ErrorActionPreference = 'Stop'

$root       = Split-Path -Parent $PSScriptRoot
$slug       = 'formcatch'
$distIgnore = Join-Path $root '.distignore'
$buildDir   = Join-Path $root 'build'
$stageDir   = Join-Path $buildDir $slug

# Read the version straight from the plugin header so the zip can never
# disagree with what WordPress reports.
$mainFile = Join-Path $root "$slug.php"
$version  = (Select-String -Path $mainFile -Pattern '^\s*\*\s*Version:\s*(.+)$').Matches[0].Groups[1].Value.Trim()
if (-not $version) { throw "Could not read Version from $mainFile" }

Write-Host "Building $slug $version" -ForegroundColor Cyan

# Parse .distignore: skip blanks and comments.
$patterns = @()
if (Test-Path $distIgnore) {
    $patterns = Get-Content $distIgnore |
        ForEach-Object { $_.Trim() } |
        Where-Object { $_ -and -not $_.StartsWith('#') }
}

# Always exclude the build output itself, or we would recurse into it.
$patterns += 'build'

function Test-Excluded {
    param([string]$RelativePath)

    # Match on any path segment, so `assets` also excludes `assets/icon.png`.
    $segments = $RelativePath -split '[\\/]'

    foreach ($pattern in $patterns) {
        foreach ($segment in $segments) {
            if ($segment -like $pattern) { return $true }
        }
    }
    return $false
}

# Fresh staging directory every run.
if (Test-Path $buildDir) { Remove-Item $buildDir -Recurse -Force }
New-Item -ItemType Directory -Path $stageDir -Force | Out-Null

$copied  = 0
$skipped = 0

Get-ChildItem -Path $root -Recurse -File -Force | ForEach-Object {
    $relative = $_.FullName.Substring($root.Length).TrimStart('\', '/')

    if (Test-Excluded -RelativePath $relative) {
        $skipped++
        return
    }

    $target    = Join-Path $stageDir $relative
    $targetDir = Split-Path -Parent $target
    if (-not (Test-Path $targetDir)) {
        New-Item -ItemType Directory -Path $targetDir -Force | Out-Null
    }
    Copy-Item -LiteralPath $_.FullName -Destination $target
    $copied++
}

$zipPath = Join-Path $buildDir "$slug-$version.zip"
Compress-Archive -Path $stageDir -DestinationPath $zipPath -CompressionLevel Optimal

Remove-Item $stageDir -Recurse -Force

$sizeMb = [math]::Round((Get-Item $zipPath).Length / 1MB, 2)
Write-Host "  $copied files packaged, $skipped excluded" -ForegroundColor DarkGray
Write-Host "  -> $zipPath ($sizeMb MB)" -ForegroundColor Green
