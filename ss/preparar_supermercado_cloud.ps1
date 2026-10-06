$ErrorActionPreference = 'Stop'

$source = (Resolve-Path -LiteralPath $PSScriptRoot).Path
$documents = [Environment]::GetFolderPath('MyDocuments')
$target = Join-Path $documents 'supermercaso cloud'

if ([string]::Equals($source, $target, [StringComparison]::OrdinalIgnoreCase)) {
  throw 'El origen y destino son la misma carpeta.'
}
if (Test-Path -LiteralPath $target) {
  throw "Ya existe '$target'. No se sobrescribieron archivos. Cambia el nombre de la carpeta existente antes de volver a ejecutar."
}

New-Item -ItemType Directory -Path $target | Out-Null
$robocopy = Join-Path $env:SystemRoot 'System32\robocopy.exe'
$excludeDirs = @('.git', 'node_modules', '.idea')
$excludeFiles = @('.env', '.env.*', '*.log', '*.bak', '*.sql.gz', '*backup*.sql', '*respaldo*.sql', 'dump*.sql')
& $robocopy $source $target /E /R:1 /W:1 /XD $excludeDirs /XF $excludeFiles
$copyResult = $LASTEXITCODE
if ($copyResult -ge 8) {
  throw "No se pudo completar la copia (Robocopy $copyResult). El origen permanece intacto. Revisa y elimina manualmente la copia incompleta antes de intentarlo de nuevo."
}

Write-Host ''
Write-Host 'Copia del proyecto preparada:' -ForegroundColor Green
Write-Host $target
Write-Host ''
Write-Host 'La base de datos y sus respaldos no se copiaron. Consulta README_CLOUDFLARE.md dentro de la carpeta para continuar.'
