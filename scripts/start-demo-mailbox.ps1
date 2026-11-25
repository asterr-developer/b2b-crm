param(
    [Parameter(Mandatory)][string]$MailAddress,
    [string]$Folder = 'CRM-Demo',
    [string]$Python = 'python',
    [int]$Port = 8012
)

$ErrorActionPreference = 'Stop'
$project = Split-Path -Parent $PSScriptRoot
$php = (Get-Command php -CommandType Application).Source
& $Python -c 'import ssl, sqlite3; print("Python OK")'
if ($LASTEXITCODE -ne 0) { throw 'Pass the path to Python 3.10 or newer via -Python.' }

$securePassword = Read-Host "Yandex mail app password for $MailAddress" -AsSecureString
$passwordPointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($securePassword)
$server = $null
$locationPushed = $false
try {
    Push-Location -LiteralPath $project
    $locationPushed = $true
    $env:DB_DATABASE = 'crm_demo'
    $env:APP_URL = "http://127.0.0.1:$Port"
    $env:SESSION_COOKIE = 'crm_mailbox_demo_session'
    $env:APP_CONFIG_CACHE = Join-Path $project 'storage\framework\mailbox-demo-config.php'
    $env:CRM_REPLY_WEBHOOK_ACCOUNT = 'manager@hotel.example.test'
    $env:CRM_REPLY_WEBHOOK_SECRET = [guid]::NewGuid().ToString('N') + [guid]::NewGuid().ToString('N')
    $env:CRM_MAILBOX_ENDPOINT = "$env:APP_URL/api/intake/replies"
    $env:CRM_IMAP_HOST = 'imap.yandex.ru'
    $env:CRM_IMAP_PORT = '993'
    $env:CRM_IMAP_USER = $MailAddress
    $env:CRM_IMAP_FOLDER = $Folder
    $env:CRM_IMAP_STATE = Join-Path $project 'storage\app\demo-imap-checkpoint.sqlite'
    $env:CRM_IMAP_TRUSTED_AUTHSERV_ID = ''

    $server = Start-Process -FilePath $php -ArgumentList @('-S',"127.0.0.1:$Port",'../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php') -WorkingDirectory (Join-Path $project 'public') -WindowStyle Hidden -PassThru
    for ($attempt = 0; $attempt -lt 30; $attempt++) {
        if ($server.HasExited) { throw "CRM server did not start on port $Port." }
        try {
            $response = Invoke-WebRequest -UseBasicParsing -Uri "$env:APP_URL/up" -TimeoutSec 2
            if ($response.StatusCode -eq 200) { break }
        } catch {
            if ($attempt -eq 29) { throw }
            Start-Sleep -Milliseconds 300
        }
    }
    $env:CRM_IMAP_PASSWORD = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($passwordPointer)
    do {
        & $Python -B scripts/sync-mailbox.py
        if ($LASTEXITCODE -ne 0) { throw 'Sync failed. Check the IMAP settings or credentials and rerun with the same checkpoint.' }
        Write-Host 'Waiting 60 seconds. Stop with Ctrl+C.'
        Start-Sleep -Seconds 60
    } while ($true)
} finally {
    $env:CRM_IMAP_PASSWORD = $null
    $env:CRM_REPLY_WEBHOOK_SECRET = $null
    [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($passwordPointer)
    $securePassword.Dispose()
    if ($server -and -not $server.HasExited) { Stop-Process -Id $server.Id }
    if ($locationPushed) { Pop-Location }
}
