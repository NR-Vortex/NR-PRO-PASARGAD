param([string]$ProjectPath = 'C:\Users\alire\Projects\NR-PRO-PASARGAD')

# Interactive local-only admin: secret never appears in arguments, shell history or git.
$ErrorActionPreference = 'Stop'
$root = (Resolve-Path -LiteralPath $ProjectPath -ErrorAction Stop).Path
$cli = Join-Path $root 'scripts/local/panel-vault-manager.php'
if (-not (Test-Path -LiteralPath $cli -PathType Leaf)) { throw 'Stage20 is not installed.' }
if (-not (Get-Command php -ErrorAction SilentlyContinue)) { throw 'PHP CLI missing.' }

Write-Host '=== REGISTER VPN PANEL (LOCAL ADMIN ONLY) ===' -ForegroundColor Cyan
$owner = Read-Host 'Telegram admin numeric ID'
$id = Read-Host 'Panel ID (example eu-marzban-1)'
$name = Read-Host 'Panel name (example EU Node 1)'
$type = Read-Host 'Driver type: marzban or xui3'
$url = Read-Host 'Panel HTTPS URL (example https://panel.example.com)'

if ($owner -notmatch '^[1-9][0-9]{0,17}$') { throw 'Invalid admin ID' }
if ($type -notin @('marzban','xui3')) { throw 'Unsupported driver for this release' }
if ($url -cnotmatch '^https://') { throw 'HTTPS is required' }

$credentials = @{}
$secureToken = $null
$securePassword = $null
$ptr = [IntPtr]::Zero
$plain = $null
$json = $null
try {
    if ($type -eq 'xui3') {
        $secureToken = Read-Host 'Panel bearer API token (hidden)' -AsSecureString
        $ptr = [System.Runtime.InteropServices.Marshal]::SecureStringToBSTR($secureToken)
        $plain = [System.Runtime.InteropServices.Marshal]::PtrToStringBSTR($ptr)
        $credentials['token'] = $plain
        $plain = $null
        [System.Runtime.InteropServices.Marshal]::ZeroFreeBSTR($ptr)
        $ptr = [IntPtr]::Zero
    } else {
        $auth = Read-Host 'Marzban authentication: token or password'
        if ($auth -eq 'token') {
            $secureToken = Read-Host 'Marzban API bearer token (hidden)' -AsSecureString
            $ptr = [System.Runtime.InteropServices.Marshal]::SecureStringToBSTR($secureToken)
            $plain = [System.Runtime.InteropServices.Marshal]::PtrToStringBSTR($ptr)
            $credentials['token'] = $plain
            $plain = $null
            [System.Runtime.InteropServices.Marshal]::ZeroFreeBSTR($ptr)
            $ptr = [IntPtr]::Zero
        } elseif ($auth -eq 'password') {
            $credentials['username'] = Read-Host 'Marzban username'
            $securePassword = Read-Host 'Marzban password (hidden)' -AsSecureString
            $ptr = [System.Runtime.InteropServices.Marshal]::SecureStringToBSTR($securePassword)
            $plain = [System.Runtime.InteropServices.Marshal]::PtrToStringBSTR($ptr)
            $credentials['password'] = $plain
            $plain = $null
            [System.Runtime.InteropServices.Marshal]::ZeroFreeBSTR($ptr)
            $ptr = [IntPtr]::Zero
        } else { throw 'Invalid Marzban authentication choice' }
    }
    $payload = @{
        owner_id = [long]::Parse($owner)
        panel_id = $id
        name = $name
        type = $type
        url = $url
        credentials = $credentials
    }
    $json = ConvertTo-Json -InputObject $payload -Depth 4 -Compress
    $json | & php $cli add
    if ($LASTEXITCODE -ne 0) { throw 'Encrypted panel registration failed.' }
    Write-Host '[OK] PANEL REGISTERED. Secrets are stored encrypted in local PostgreSQL.' -ForegroundColor Green
} finally {
    if ($ptr -ne [IntPtr]::Zero) { [System.Runtime.InteropServices.Marshal]::ZeroFreeBSTR($ptr) }
    $plain = $null
    $json = $null
    $credentials.Clear()
    $secureToken = $null
    $securePassword = $null
}
