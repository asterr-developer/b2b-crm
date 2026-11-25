$ErrorActionPreference = 'Stop'
Set-Location -LiteralPath (Split-Path -Parent $PSScriptRoot)
$env:DB_DATABASE = 'crm_demo'
$password = Read-Host 'New demo CRM password (at least 12 characters)' -AsSecureString
try {
    if ($password.Length -lt 12) { throw 'Use at least 12 characters.' }
    $pointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($password)
    try {
        $env:CRM_DEMO_RESET_PASSWORD = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($pointer)
        $code = 'if (!app()->environment("local") || DB::connection()->getDatabaseName() !== "crm_demo") { throw new RuntimeException("Expected local crm_demo"); } $user = App\Models\User::whereEmail("lead@hotel.example.test")->where("sales_role", "lead")->sole(); $user->forceFill(["password" => Hash::make(getenv("CRM_DEMO_RESET_PASSWORD")), "remember_token" => null])->save(); echo "Demo lead password updated.";'
        & php artisan tinker "--execute=$code"
        if ($LASTEXITCODE -ne 0) { throw 'Password update failed.' }
    } finally {
        $env:CRM_DEMO_RESET_PASSWORD = $null
        [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($pointer)
    }
} finally {
    $password.Dispose()
}
