$ErrorActionPreference = 'Stop'
Set-Location $PSScriptRoot
$demoPhp = 'C:\wamp64\bin\php\php8.3.14\php.exe'
if (-not (Test-Path $demoPhp)) { throw 'PHP not found. Update $demoPhp in start-demo.ps1 to your WAMP PHP path.' }
& $demoPhp bin\setup.php
if ($LASTEXITCODE -ne 0) { throw 'Setup failed. Read the error above.' }
Write-Host 'Save the passwords shown above. Open http://127.0.0.1:8080/index.html'
Write-Host 'Leave this terminal running. Press Ctrl+C to stop.'
& $demoPhp -d upload_max_filesize=5M -d post_max_size=6M -S 127.0.0.1:8080 -t public
