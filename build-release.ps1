param(
    [string] $OutputPath = (Join-Path $PSScriptRoot 'whmcs_mcp_server_latest.zip')
)

$ErrorActionPreference = 'Stop'

$moduleSource = Join-Path $PSScriptRoot 'modules\addons\whmcs_mcp_server'
$temporaryRoot = Join-Path ([IO.Path]::GetTempPath()) ('whmcs-mcp-release-' + [guid]::NewGuid().ToString('N'))
$packageRoot = Join-Path $temporaryRoot 'package'
$moduleTarget = Join-Path $packageRoot 'modules\addons\whmcs_mcp_server'
$temporaryZip = Join-Path $temporaryRoot 'release.zip'

$topLevelFiles = @(
    'composer.json',
    'composer.lock',
    'hooks.php',
    'mcp.php',
    'whmcs_mcp_server.php'
)

try {
    New-Item -ItemType Directory -Path $moduleTarget -Force | Out-Null

    foreach ($file in $topLevelFiles) {
        Copy-Item -LiteralPath (Join-Path $moduleSource $file) -Destination $moduleTarget
    }

    Copy-Item -LiteralPath (Join-Path $moduleSource 'lib') -Destination $moduleTarget -Recurse
    Copy-Item -LiteralPath (Join-Path $moduleSource 'templates') -Destination $moduleTarget -Recurse

    $scriptsTarget = Join-Path $moduleTarget 'scripts'
    New-Item -ItemType Directory -Path $scriptsTarget -Force | Out-Null
    Copy-Item -LiteralPath (Join-Path $moduleSource 'scripts\fix-vendor-compat.php') -Destination $scriptsTarget

    $sessionsTarget = Join-Path $moduleTarget 'storage\sessions'
    New-Item -ItemType Directory -Path $sessionsTarget -Force | Out-Null
    Copy-Item -LiteralPath (Join-Path $moduleSource 'storage\.htaccess') -Destination (Split-Path $sessionsTarget -Parent)
    Copy-Item -LiteralPath (Join-Path $moduleSource 'storage\sessions\.gitkeep') -Destination $sessionsTarget

    Copy-Item -LiteralPath (Join-Path $PSScriptRoot 'README.md') -Destination $packageRoot
    Copy-Item -LiteralPath (Join-Path $PSScriptRoot 'LICENSE') -Destination $packageRoot

    & tar.exe -a -c -f $temporaryZip -C $packageRoot .
    if ($LASTEXITCODE -ne 0) {
        throw "Falha ao compactar o pacote (tar exit code $LASTEXITCODE)"
    }

    Move-Item -LiteralPath $temporaryZip -Destination $OutputPath -Force
} finally {
    if (Test-Path -LiteralPath $temporaryRoot) {
        Remove-Item -LiteralPath $temporaryRoot -Recurse -Force
    }
}

Write-Output "Pacote criado: $OutputPath"
