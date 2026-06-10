# Creates thesis-prototype-upload.zip ready for FTP upload (InfinityFree / similar)
$root = Split-Path -Parent $PSScriptRoot
$zipPath = Join-Path $PSScriptRoot "thesis-prototype-upload.zip"

if (Test-Path $zipPath) { Remove-Item $zipPath -Force }

$excludeDirs = @('deploy', '.git', '.cursor')
$excludeFiles = @('prepare_upload.ps1', 'bracu_thesis_full.sql', 'thesis-prototype-upload.zip')

Add-Type -AssemblyName System.IO.Compression.FileSystem
$zip = [System.IO.Compression.ZipFile]::Open($zipPath, 'Create')

Get-ChildItem -Path $root -Recurse -File | ForEach-Object {
    $rel = $_.FullName.Substring($root.Length + 1)
    $skip = $false
    foreach ($d in $excludeDirs) {
        if ($rel -like "$d\*" -or $rel -like "$d/*") { $skip = $true; break }
    }
    if ($excludeFiles -contains $_.Name -and $_.DirectoryName -like "*\deploy") { $skip = $true }
    if ($rel -eq 'config.production.php') { $skip = $true }
    if (-not $skip) {
        [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile($zip, $_.FullName, $rel.Replace('\', '/'))
    }
}

$zip.Dispose()
Write-Host "Created: $zipPath"
Write-Host "Size: $((Get-Item $zipPath).Length) bytes"
Write-Host "Also upload deploy/bracu_thesis_full.sql separately and import in phpMyAdmin."
