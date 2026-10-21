[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
$repoRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$packages = Join-Path $repoRoot 'deployment\packages'
$tempBase = [System.IO.Path]::GetFullPath([System.IO.Path]::GetTempPath())
$work = Join-Path $tempBase ('epejabat-package-' + [guid]::NewGuid().ToString('N'))
$workFull = [System.IO.Path]::GetFullPath($work)

if (-not $workFull.StartsWith($tempBase, [System.StringComparison]::OrdinalIgnoreCase) -or
    (Split-Path $workFull -Leaf) -notmatch '^epejabat-package-[a-f0-9]{32}$') {
    throw 'Direktori sementara pakej tidak sah.'
}

Push-Location $repoRoot
try {
    $dirty = & git status --porcelain --untracked-files=normal
    if ($LASTEXITCODE -ne 0) { throw 'Repositori Git tidak dapat dibaca.' }
    if ($dirty) { throw 'Commit semua perubahan dahulu; pakej hanya dibina daripada HEAD yang bersih.' }

    $commit = (& git rev-parse --short=12 HEAD).Trim()
    if ($LASTEXITCODE -ne 0 -or $commit -notmatch '^[a-f0-9]{12}$') { throw 'Commit Git tidak sah.' }

    New-Item -ItemType Directory -Path $workFull -Force | Out-Null
    $archive = Join-Path $workFull 'source.zip'
    $stage = Join-Path $workFull 'stage'
    & git archive --format=zip --output=$archive HEAD
    if ($LASTEXITCODE -ne 0) { throw 'git archive gagal.' }
    Expand-Archive -LiteralPath $archive -DestinationPath $stage

    Copy-Item -LiteralPath (Join-Path $stage '.htaccess.production') -Destination (Join-Path $stage '.htaccess') -Force
    Copy-Item -LiteralPath (Join-Path $stage '.user.ini.production') -Destination (Join-Path $stage '.user.ini') -Force
    Copy-Item -LiteralPath (Join-Path $stage '.env.production.example') -Destination (Join-Path $stage '.env.production') -Force

    $runtimeDirectories = @(
        'storage\logs', 'storage\files', 'storage\sessions', 'storage\documents',
        'storage\imports', 'storage\backups', 'storage\uploads', 'storage\upgrades',
        'storage\deployments', 'storage\eforms-originals', 'storage\eforms-packages'
    )
    foreach ($relative in $runtimeDirectories) {
        $directory = Join-Path $stage $relative
        New-Item -ItemType Directory -Path $directory -Force | Out-Null
        New-Item -ItemType File -Path (Join-Path $directory '.gitkeep') -Force | Out-Null
    }

    New-Item -ItemType Directory -Path $packages -Force | Out-Null
    $output = Join-Path $packages ("epejabat-cpanel-$commit.zip")
    $checksum = $output + '.sha256'
    foreach ($target in @($output, $checksum)) {
        $full = [System.IO.Path]::GetFullPath($target)
        if (-not $full.StartsWith([System.IO.Path]::GetFullPath($packages), [System.StringComparison]::OrdinalIgnoreCase)) {
            throw 'Sasaran pakej berada di luar deployment/packages.'
        }
        if (Test-Path -LiteralPath $full) { Remove-Item -LiteralPath $full -Force }
    }

    Add-Type -AssemblyName System.IO.Compression
    Add-Type -AssemblyName System.IO.Compression.FileSystem
    $stream = [System.IO.File]::Open($output, [System.IO.FileMode]::CreateNew)
    try {
        $zip = [System.IO.Compression.ZipArchive]::new($stream, [System.IO.Compression.ZipArchiveMode]::Create, $false)
        try {
            foreach ($file in Get-ChildItem -LiteralPath $stage -Recurse -File) {
                $relative = $file.FullName.Substring($stage.Length).TrimStart('\', '/').Replace('\', '/')
                [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile(
                    $zip,
                    $file.FullName,
                    $relative,
                    [System.IO.Compression.CompressionLevel]::Optimal
                ) | Out-Null
            }
        } finally {
            $zip.Dispose()
        }
    } finally {
        $stream.Dispose()
    }
    $hash = (Get-FileHash -Algorithm SHA256 -LiteralPath $output).Hash.ToLowerInvariant()
    [System.IO.File]::WriteAllText($checksum, "$hash  $([System.IO.Path]::GetFileName($output))`n", [System.Text.UTF8Encoding]::new($false))
    Write-Output "Package: $output"
    Write-Output "SHA256: $hash"
} finally {
    Pop-Location
    if (Test-Path -LiteralPath $workFull) {
        $resolved = [System.IO.Path]::GetFullPath($workFull)
        if ($resolved.StartsWith($tempBase, [System.StringComparison]::OrdinalIgnoreCase) -and
            (Split-Path $resolved -Leaf) -match '^epejabat-package-[a-f0-9]{32}$') {
            Remove-Item -LiteralPath $resolved -Recurse -Force
        }
    }
}
