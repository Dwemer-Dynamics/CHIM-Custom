[CmdletBinding()]
param(
    [string]$GamePackagePath,
    [string]$OutputPath
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest
Add-Type -AssemblyName System.IO.Compression.FileSystem

$repoRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
if ([string]::IsNullOrWhiteSpace($GamePackagePath)) {
    $GamePackagePath = Join-Path $repoRoot 'SkyrimPlugin\package'
}
$gamePackage = (Resolve-Path -LiteralPath $GamePackagePath).Path

foreach ($requiredFile in @(
    'SKSE\Plugins\CHIMCustom.dll',
    'SKSE\Plugins\CHIMCustom.ini'
)) {
    if (-not (Test-Path -LiteralPath (Join-Path $gamePackage $requiredFile) -PathType Leaf)) {
        throw "Release payload is missing $requiredFile. Build CHIMCustom and stage the game package first."
    }
}

if ([string]::IsNullOrWhiteSpace($OutputPath)) {
    $OutputPath = Join-Path $repoRoot 'release\CHIM - Custom.zip'
}
$OutputPath = [System.IO.Path]::GetFullPath($OutputPath)
[System.IO.Directory]::CreateDirectory([System.IO.Path]::GetDirectoryName($OutputPath)) | Out-Null

$stageRoot = Join-Path ([System.IO.Path]::GetTempPath()) ('chim-custom-release-' + [guid]::NewGuid().ToString('N'))
try {
    [System.IO.Directory]::CreateDirectory($stageRoot) | Out-Null
    Get-ChildItem -LiteralPath $gamePackage -Force | Copy-Item -Destination $stageRoot -Recurse -Force

    $bundledPlugins = Join-Path $stageRoot 'CHIM\server-plugins'
    if (Test-Path -LiteralPath $bundledPlugins) {
        [System.IO.Directory]::Delete($bundledPlugins, $true)
    }

    & (Join-Path $PSScriptRoot 'build-dwpkg.ps1') -GamePackagePath $stageRoot | Out-Null

    if (Test-Path -LiteralPath $OutputPath) {
        Remove-Item -LiteralPath $OutputPath -Force
    }
    [System.IO.Compression.ZipFile]::CreateFromDirectory(
        $stageRoot,
        $OutputPath,
        [System.IO.Compression.CompressionLevel]::Optimal,
        $false
    )
    Write-Output $OutputPath
}
finally {
    if (Test-Path -LiteralPath $stageRoot) {
        Remove-Item -LiteralPath $stageRoot -Recurse -Force
    }
}
