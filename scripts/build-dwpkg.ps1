[CmdletBinding()]
param(
    [string]$GamePayloadPath = (Join-Path $PSScriptRoot '..\SkyrimPlugin\package'),
    [string]$OutputPath
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest
Add-Type -AssemblyName System.IO.Compression.FileSystem
Add-Type -AssemblyName System.IO.Compression

$repoRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$packageTemplatePath = Join-Path $repoRoot 'dwemer-package.json'
$legacyManifestPath = Join-Path $repoRoot 'manifest.json'
$gamePayload = (Resolve-Path -LiteralPath $GamePayloadPath).Path
$legacyManifest = Get-Content -LiteralPath $legacyManifestPath -Raw | ConvertFrom-Json
$packageManifest = Get-Content -LiteralPath $packageTemplatePath -Raw | ConvertFrom-Json
$packageManifest.version = [string]$legacyManifest.version

$requiredGameFiles = @(
    'SKSE\Plugins\CHIMCustom.dll',
    'SKSE\Plugins\CHIMCustom.ini'
)
foreach ($relativePath in $requiredGameFiles) {
    if (-not (Test-Path -LiteralPath (Join-Path $gamePayload $relativePath) -PathType Leaf)) {
        throw "Missing game payload file: $relativePath"
    }
}

if ([string]::IsNullOrWhiteSpace($OutputPath)) {
    $OutputPath = Join-Path $repoRoot ("release\CHIM-Custom-{0}.dwpkg" -f $packageManifest.version)
}
$OutputPath = [System.IO.Path]::GetFullPath($OutputPath)
[System.IO.Directory]::CreateDirectory([System.IO.Path]::GetDirectoryName($OutputPath)) | Out-Null

$stageRoot = Join-Path ([System.IO.Path]::GetTempPath()) ('chim-custom-dwpkg-' + [guid]::NewGuid().ToString('N'))
$serverStage = Join-Path $stageRoot 'server'
$gameStage = Join-Path $stageRoot 'game\skyrim-se'

try {
    [System.IO.Directory]::CreateDirectory($serverStage) | Out-Null
    [System.IO.Directory]::CreateDirectory($gameStage) | Out-Null

    foreach ($file in @('context_pre.php', 'globals.php', 'index.php', 'manifest.json', 'README.md')) {
        Copy-Item -LiteralPath (Join-Path $repoRoot $file) -Destination (Join-Path $serverStage $file)
    }
    foreach ($directory in @('api', 'lib', 'migrations')) {
        Copy-Item -LiteralPath (Join-Path $repoRoot $directory) -Destination $serverStage -Recurse
    }
    Copy-Item -Path (Join-Path $gamePayload '*') -Destination $gameStage -Recurse

    $manifestJson = $packageManifest | ConvertTo-Json -Depth 20
    [System.IO.File]::WriteAllText((Join-Path $stageRoot 'manifest.json'), $manifestJson + "`n", [System.Text.UTF8Encoding]::new($false))

    $checksumLines = foreach ($file in Get-ChildItem -LiteralPath $stageRoot -Recurse -File | Sort-Object FullName) {
        if ($file.Name -eq 'checksums.sha256') { continue }
        $relative = $file.FullName.Substring($stageRoot.Length + 1).Replace('\', '/')
        $hash = (Get-FileHash -LiteralPath $file.FullName -Algorithm SHA256).Hash.ToLowerInvariant()
        "$hash  $relative"
    }
    [System.IO.File]::WriteAllText((Join-Path $stageRoot 'checksums.sha256'), ($checksumLines -join "`n") + "`n", [System.Text.UTF8Encoding]::new($false))

    if (Test-Path -LiteralPath $OutputPath) {
        Remove-Item -LiteralPath $OutputPath -Force
    }
    $archiveStream = [System.IO.File]::Open($OutputPath, [System.IO.FileMode]::CreateNew)
    try {
        $archive = [System.IO.Compression.ZipArchive]::new($archiveStream, [System.IO.Compression.ZipArchiveMode]::Create, $false)
        try {
            foreach ($file in Get-ChildItem -LiteralPath $stageRoot -Recurse -File | Sort-Object FullName) {
                $relative = $file.FullName.Substring($stageRoot.Length + 1).Replace('\', '/')
                $entry = $archive.CreateEntry($relative, [System.IO.Compression.CompressionLevel]::Optimal)
                $entryStream = $entry.Open()
                $inputStream = [System.IO.File]::OpenRead($file.FullName)
                try {
                    $inputStream.CopyTo($entryStream)
                }
                finally {
                    $inputStream.Dispose()
                    $entryStream.Dispose()
                }
            }
        }
        finally {
            $archive.Dispose()
        }
    }
    finally {
        $archiveStream.Dispose()
    }
    Write-Output $OutputPath
}
finally {
    if (Test-Path -LiteralPath $stageRoot) {
        Remove-Item -LiteralPath $stageRoot -Recurse -Force
    }
}
