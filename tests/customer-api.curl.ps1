#Requires -Version 5.1

<#
.SYNOPSIS
Runs customer/bettor API regression cases using the real curl executable.
.DESCRIPTION
Use a development/test database with the current migrations and roles seeded.
Start the API and, for an asynchronous queue, its queue worker before running.
This script does not migrate, seed, start Laravel, or edit .env.
Initialize the separate admin audit database first with artisan audit:setup.
Audit records are only for authenticated admin actors; customer and anonymous
requests are checked for exclusion. HTTP logging still covers all requests.

Creates two fresh customers, dedicated payment methods/accounts, deposits,
withdrawals, and 2D/3D bets. Tests approval, rejection, wallet reservations,
winning/losing bets, payout preview/settlement, cancellation/refunds, ownership,
validation, profile changes, and token rotation/revocation. Also checks admin
wallet CRUD, traced manual credits/debits, retry protection, and API audit logs.
Admin wallet history includes paged payments, withdrawal requests, and ledger
entries, with detail endpoints for tracing their relationships.
Customer wallets are created once during registration; admins have no wallet.
Financial history and test customers remain for inspection. Dedicated payment fixtures are
deactivated at the end. Runs must be sequential against a private test target:
odds are global settings and result actions affect an entire date/session.
Existing odds are restored by appending a new settings row in finally; odds
history remains. If a game initially has no odds, its test settings remain.

All customer routes in routes/api.php are exercised. Admin coverage supports
the bettor workflow; this is not an exhaustive admin-management/load suite.
Simultaneous requests, real provider transfers, expiry/time travel, SMTP
delivery, and enabled cutoff boundaries need separate infrastructure tests.

Requires Windows PowerShell 5.1+ or PowerShell 7+ and curl.exe (curl on Unix).
Uses UTF-8 JSON files with --data-binary to avoid shell JSON quoting problems.
Does not retry writes, follow redirects, disable TLS, or save request bodies,
passwords, tokens, or raw API responses. JSON/CSV/JUnit reports include case
results, HTTP status/timing, coverage, and created IDs for investigation.
Prints PASS, FAIL, BLOCKED, and SKIP. Exit 0 = all cases passed; 1 = failed or
blocked; 2 = incomplete (password reset skipped because no mailbox supplied).
Dependency failures block dependent cases; independent cases keep running.

Password-reset success/reuse is automatic with either RecoveryMailLogPath
(local Laravel MAIL_MAILER=log) or ResetTokenProvider (a test mailbox reader).
The log reader only reads bytes appended after this run's reset request and
matches the recipient and notification text. It never reads the database.
Without a mailbox, forgot-password and invalid-reset cases still run, but the
successful reset case is explicitly skipped and the suite exits 2.

Auth requests are paced below the shared per-minute middleware limits. Other
clients sharing the same IP can still consume the server's throttle budget.
.PARAMETER BaseUrl
Application root or API root, for example http://127.0.0.1:8000 or /api.
.PARAMETER AdminEmail
Active Super Admin email. Defaults to CUSTOMER_API_ADMIN_EMAIL.
.PARAMETER AdminPassword
Super Admin password. Defaults to CUSTOMER_API_ADMIN_PASSWORD; never reported.
.PARAMETER CustomerPassword
Password for the two new customers. Defaults to CUSTOMER_API_TEST_PASSWORD or
a generated password. Generated passwords exist only in this process.
.PARAMETER DrawDate
Unused future date (yyyy-MM-dd). Default is randomly chosen 1-10 years ahead.
2D uses AM for settlement and PM for cancellation; 3D uses PM on this date
for settlement and PM on the following date for cancellation. Preflight
refuses sessions containing existing bets/results, including other customers.
.PARAMETER OutputDirectory
Report parent directory; each run gets a unique subdirectory. Default is the
system temporary directory, so reports do not dirty the repository.
.PARAMETER RecoveryMailLogPath
Local Laravel mail log when MAIL_MAILER=log. Must belong to this API instance.
.PARAMETER ResetTokenProvider
Scriptblock receiving (email, requestedAtUtc); must wait for that recipient's
new recovery message and return exactly one reset-token string. Do not print
mail bodies or tokens. Overrides RecoveryMailLogPath when both are supplied.
.EXAMPLE
$env:CUSTOMER_API_ADMIN_EMAIL = 'test@example.com'
$env:CUSTOMER_API_ADMIN_PASSWORD = '<your local admin password>'
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\tests\customer-api.curl.ps1 `
    -BaseUrl 'http://127.0.0.1:8000' `
    -RecoveryMailLogPath '.\storage\logs\laravel.log'
$LASTEXITCODE
.EXAMPLE
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\tests\customer-api.curl.ps1 `
    -BaseUrl 'http://2dapp.test' `
    -OutputDirectory '.\storage\app\api-test-reports'
.EXAMPLE
Get-Help .\tests\customer-api.curl.ps1 -Full
#>
[CmdletBinding()]
param(
    [string] $BaseUrl = 'http://127.0.0.1:8000',
    [string] $AdminEmail = $env:CUSTOMER_API_ADMIN_EMAIL,
    [string] $AdminPassword = $env:CUSTOMER_API_ADMIN_PASSWORD,
    [string] $CustomerPassword = $env:CUSTOMER_API_TEST_PASSWORD,
    [string] $DrawDate = '',
    [string] $OutputDirectory = (Join-Path ([IO.Path]::GetTempPath()) '2dapp-customer-api'),
    [string] $RecoveryMailLogPath = '',
    [scriptblock] $ResetTokenProvider,
    [ValidateRange(5, 600)] [int] $CalculationTimeoutSeconds = 90,
    [ValidateRange(5, 300)] [int] $RecoveryTimeoutSeconds = 45,
    [ValidateRange(1, 120)] [int] $RequestTimeoutSeconds = 30
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$script:Cases = [Collections.Generic.List[object]]::new()
$script:Requests = [Collections.Generic.List[object]]::new()
$script:Secrets = [Collections.Generic.List[string]]::new()
$script:ThrottleTimes = @{}
$script:TokenOwners = @{}
$script:CurrentCase = 'Initialization'
$script:AssertionCount = 0
$script:Utf8 = [Text.UTF8Encoding]::new($false)
$script:StartedAt = [DateTime]::UtcNow
$runId = '{0}-{1}' -f $script:StartedAt.ToString('yyyyMMdd-HHmmss'), [guid]::NewGuid().ToString('N').Substring(0, 12)
$state = @{
    Target = '(invalid or unconfigured target)'
    Customer = @{ Email = "curl.$runId@example.test"; Access = ''; Refresh = ''; Id = ''; RoleId = '' }
    Other = @{ Email = "curl.other.$runId@example.test"; Access = ''; Refresh = ''; Id = ''; RoleId = '' }
    Admin = @{ Email = $AdminEmail; Access = ''; Refresh = ''; Id = ''; RoleId = '' }
    ExpectedAvailable = [decimal] 0; ExpectedLocked = [decimal] 0
    Movements = [Collections.Generic.List[object]]::new()
    OriginalOdds = @{}; LastOdds = @{}; Created = @{}; Games = @{}
}

function Protect-Text {
    param([AllowEmptyString()] [string] $Text)
    foreach ($secret in $script:Secrets) {
        if (-not [string]::IsNullOrEmpty($secret)) { $Text = $Text.Replace($secret, '[REDACTED]') }
    }
    return $Text
}

function Assert-True {
    param([bool] $Condition, [string] $Message)
    $script:AssertionCount++
    if (-not $Condition) { throw $Message }
}

function Assert-Equal {
    param([AllowNull()] $Actual, [AllowNull()] $Expected, [string] $Message)
    $script:AssertionCount++
    if ($Actual -cne $Expected) { throw "$Message (expected '$Expected', got '$Actual')." }
}

function Assert-Money {
    param($Actual, [decimal] $Expected, [string] $Message)
    Assert-True ($null -ne $Actual) "$Message is missing."
    $amount = [decimal]::Parse([string] $Actual, [Globalization.CultureInfo]::InvariantCulture)
    Assert-Equal $amount $Expected $Message
}

function Require-Value {
    param($Value, [string] $Dependency)
    if ($null -eq $Value -or [string]::IsNullOrWhiteSpace([string] $Value)) {
        throw "BLOCKED: $Dependency did not complete."
    }
}

function Require-State {
    param([string[]] $Keys)
    foreach ($key in $Keys) {
        if (-not $state.ContainsKey($key)) { throw "BLOCKED: $key was not created." }
        Require-Value $state[$key] $key
    }
}

function Invoke-Case {
    param([string] $Name, [scriptblock] $Action)
    $script:CurrentCase = $Name
    $watch = [Diagnostics.Stopwatch]::StartNew()
    $assertionsBefore = $script:AssertionCount
    $outcome = 'PASS'
    $detail = ''
    try { & $Action | Out-Null }
    catch {
        $detail = Protect-Text $_.Exception.Message
        if ($detail.StartsWith('BLOCKED:')) { $outcome = 'BLOCKED' }
        elseif ($detail.StartsWith('SKIP:')) { $outcome = 'SKIP' }
        else { $outcome = 'FAIL' }
    }
    $watch.Stop()
    $script:Cases.Add([pscustomobject]@{
        Name = $Name; Result = $outcome; Assertions = $script:AssertionCount - $assertionsBefore
        DurationMs = $watch.ElapsedMilliseconds; Detail = $detail
    })
    $color = switch ($outcome) { 'PASS' { 'Green' }; 'SKIP' { 'Yellow' }; default { 'Red' } }
    Write-Host ("[{0}] {1}{2}" -f $outcome, $Name, $(if ($detail) { ": $detail" } else { '' })) -ForegroundColor $color
}

function Wait-AuthBudget {
    param([string] $Method, [string] $Path, [string] $Token)
    $route = "$Method $($Path.Split('?')[0])"
    $limits = @{ 'POST /auth/login' = 9; 'POST /auth/register' = 9; 'POST /auth/forgot-password' = 4
        'POST /auth/reset-password' = 9; 'POST /profile/password' = 9; 'POST /auth/refresh' = 29 }
    if (-not $limits.ContainsKey($route)) { return }
    # Unnamed Laravel throttles share domain/IP (guest) or user ID, not route.
    $key = if ($Token -and $script:TokenOwners.ContainsKey($Token)) { 'user:' + $script:TokenOwners[$Token] } else { 'guest' }
    if (-not $script:ThrottleTimes.ContainsKey($key)) { $script:ThrottleTimes[$key] = [Collections.Generic.List[datetime]]::new() }
    $times = $script:ThrottleTimes[$key]
    while ($times.Count -gt 0 -and ([DateTime]::UtcNow - $times[0]).TotalSeconds -ge 61) { $times.RemoveAt(0) }
    if ($times.Count -ge $limits[$route]) {
        Write-Host "Pacing $route to stay below the shared API throttle..." -ForegroundColor DarkYellow
        while ($times.Count -ge $limits[$route]) {
            while (([DateTime]::UtcNow - $times[0]).TotalSeconds -lt 61) { Start-Sleep -Milliseconds 500 }
            while ($times.Count -gt 0 -and ([DateTime]::UtcNow - $times[0]).TotalSeconds -ge 61) { $times.RemoveAt(0) }
        }
    }
    $times.Add([DateTime]::UtcNow)
}

function Update-AuthBudget {
    param([string] $Token, [string] $Headers)
    $limit = [regex]::Match($Headers, '(?im)^X-RateLimit-Limit:\s*(\d+)\s*$')
    $remaining = [regex]::Match($Headers, '(?im)^X-RateLimit-Remaining:\s*(\d+)\s*$')
    if (-not $limit.Success -or -not $remaining.Success) { return }
    $key = if ($Token -and $script:TokenOwners.ContainsKey($Token)) { 'user:' + $script:TokenOwners[$Token] } else { 'guest' }
    if (-not $script:ThrottleTimes.ContainsKey($key)) { return }
    $observedAttempts = [int] $limit.Groups[1].Value - [int] $remaining.Groups[1].Value
    # Include a prior run's still-active requests when the server reports them.
    while ($script:ThrottleTimes[$key].Count -lt $observedAttempts) {
        $script:ThrottleTimes[$key].Add([DateTime]::UtcNow)
    }
}

function Invoke-Api {
    param(
        [ValidateSet('GET', 'POST', 'PATCH', 'DELETE')] [string] $Method,
        [string] $Path,
        [string] $Token = '',
        [AllowNull()] $Body = $null,
        [int[]] $ExpectedStatus = @(200),
        [switch] $RawUser
    )
    Wait-AuthBudget $Method $Path $Token
    $files = [Collections.Generic.List[string]]::new()
    $watch = [Diagnostics.Stopwatch]::StartNew()
    $status = 0
    $curlExit = -1
    $httpRequestId = ''
    try {
        $responseFile = [IO.Path]::GetTempFileName(); $files.Add($responseFile)
        $errorFile = [IO.Path]::GetTempFileName(); $files.Add($errorFile)
        $headersFile = [IO.Path]::GetTempFileName(); $files.Add($headersFile)
        $configFile = [IO.Path]::GetTempFileName(); $files.Add($configFile)
        $config = "header = `"Accept: application/json`"`n"
        if ($Token) { $config += "header = `"Authorization: Bearer $Token`"`n" }
        [IO.File]::WriteAllText($configFile, $config, $script:Utf8)
        $curlArguments = @('--disable', '--silent', '--show-error', '--config', $configFile,
            '--connect-timeout', '10', '--max-time', [string] $RequestTimeoutSeconds,
            '--request', $Method, '--url', ($script:ApiRoot + $Path),
            '--output', $responseFile, '--dump-header', $headersFile, '--write-out', '%{http_code}')
        if ($null -ne $Body) {
            $bodyFile = [IO.Path]::GetTempFileName(); $files.Add($bodyFile)
            [IO.File]::WriteAllText($bodyFile, (ConvertTo-Json -InputObject $Body -Depth 20 -Compress), $script:Utf8)
            $curlArguments += @('--header', 'Content-Type: application/json', '--data-binary', "@$bodyFile")
        }
        $previousPreference = $ErrorActionPreference
        try {
            $ErrorActionPreference = 'Continue'
            $statusText = (& $script:CurlPath @curlArguments 2> $errorFile) -join ''
            $curlExit = $LASTEXITCODE
        } finally { $ErrorActionPreference = $previousPreference }
        if ($statusText -match '^\d{3}$') { $status = [int] $statusText }
        Update-AuthBudget $Token ([IO.File]::ReadAllText($headersFile))
        Assert-Equal $curlExit 0 "curl transport failed for $Method $Path; check target connectivity/TLS (curl exit $curlExit)"
        Assert-True ($ExpectedStatus -contains $status) "$Method $Path expected HTTP $($ExpectedStatus -join '/'), received $status"
        $idHeader = [regex]::Match([IO.File]::ReadAllText($headersFile), '(?im)^X-Request-ID:\s*([a-f0-9-]{36})\s*$')
        $httpRequestId = $idHeader.Groups[1].Value
        $parsedRequestId = [guid]::Empty
        Assert-True ([guid]::TryParse($httpRequestId, [ref] $parsedRequestId)) "$Method $Path must return a valid X-Request-ID."
        try { $json = [IO.File]::ReadAllText($responseFile) | ConvertFrom-Json }
        catch { throw "$Method $Path returned HTTP $status with a non-JSON response." }
        Assert-True ($null -ne $json) "$Method $Path returned an empty JSON response."
        if ($status -eq 200 -and -not $RawUser) {
            Assert-True ($json.PSObject.Properties.Name -contains 'status') "$Method $Path response lacks status."
            Assert-Equal $json.status 200 "$Method $Path application status"
            Assert-True ($json.PSObject.Properties.Name -contains 'data') "$Method $Path response lacks data."
        }
        return [pscustomobject]@{ Status = $status; Json = $json; RequestId = $httpRequestId }
    } finally {
        $watch.Stop()
        $script:Requests.Add([pscustomobject]@{
            Case = $script:CurrentCase; Method = $Method; Path = $Path; HttpStatus = $status
            CurlExit = $curlExit; DurationMs = $watch.ElapsedMilliseconds; RequestId = $httpRequestId
        })
        foreach ($file in $files) { if ([IO.File]::Exists($file)) { [IO.File]::Delete($file) } }
    }
}

function Assert-Validation {
    param([string] $Method, [string] $Path, [string] $Token, $Body, [string[]] $Fields)
    $response = Invoke-Api $Method $Path $Token $Body -ExpectedStatus 422
    foreach ($field in $Fields) {
        Assert-True ($response.Json.errors.PSObject.Properties.Name -contains $field) "Missing validation error for $field."
    }
}

function Set-Login {
    param([hashtable] $User, [string] $Password)
    $response = Invoke-Api POST '/auth/login' -Body @{ email = $User.Email; password = $Password }
    $data = $response.Json.data
    Require-Value $data.access_token 'access token'
    Require-Value $data.refresh_token 'refresh token'
    $script:Secrets.Add($data.access_token); $script:Secrets.Add($data.refresh_token)
    Assert-Equal $data.token_type 'Bearer' 'Login token type'
    $User.Access = $data.access_token; $User.Refresh = $data.refresh_token
    $User.Id = $data.user.id; $User.RoleId = $data.user.role.id
    $User.RoleName = $data.user.role.name
    $script:TokenOwners[$User.Access] = $User.Id; $script:TokenOwners[$User.Refresh] = $User.Id
}

function Assert-Wallet {
    Require-Value $state.Customer.Access 'customer login'
    $response = Invoke-Api GET '/wallet' $state.Customer.Access
    Assert-Money $response.Json.data.avaliableBalance $state.ExpectedAvailable 'Available wallet balance'
    Assert-Money $response.Json.data.lockedBalance $state.ExpectedLocked 'Locked wallet balance'
}

function Add-Movement {
    param([string] $Type, [string] $Direction, [decimal] $Amount, [string] $ReferenceType, [string] $ReferenceId)
    $state.Movements.Add([pscustomobject]@{ Type = $Type; Direction = $Direction; Amount = $Amount
        ReferenceType = $ReferenceType; ReferenceId = $ReferenceId })
}

function New-DepositBody {
    param([string] $Amount = '10000.50')
    Require-State @('DepositAccountId', 'DepositMethodId')
    return @{ amount = $Amount; lastSixTranId = '012345'; PaymentAccountId = $state.DepositAccountId
        PaymentMethodId = $state.DepositMethodId; clientReference = [guid]::NewGuid().ToString() }
}

function New-WithdrawalBody {
    param([string] $Amount = '1000.25')
    Require-State @('WithdrawalAccountId', 'WithdrawalMethodId')
    return @{ amount = $Amount; paymentAccountId = $state.WithdrawalAccountId
        paymentMethodId = $state.WithdrawalMethodId; clientReference = [guid]::NewGuid().ToString() }
}

function Assert-Pagination {
    param($Data, [int] $Total = -1)
    Assert-True ($Data.PSObject.Properties.Name -contains 'items') 'Paged response lacks items.'
    Assert-True ($Data.pagination.currentPage -ge 1) 'Pagination current page must be positive.'
    Assert-True ($Data.pagination.perPage -ge 1) 'Pagination page size must be positive.'
    if ($Total -ge 0) { Assert-Equal $Data.pagination.total $Total 'Pagination total' }
}

function Get-AdminAudit {
    param([string] $Query)
    $deadline = [DateTime]::UtcNow.AddSeconds(5)
    do {
        $response = Invoke-Api GET "/admin/audit-logs?$Query" $state.Admin.Access
        if ($response.Json.data.pagination.total -gt 0) { return $response }
        if ([DateTime]::UtcNow -ge $deadline) { throw 'Admin request audit was not persisted within five seconds.' }
        Start-Sleep -Milliseconds 200
    } while ($true)
}

function Get-RecoveryToken {
    param([string] $Email, [datetime] $RequestedAt, [long] $LogOffset)
    if ($ResetTokenProvider) {
        $values = @(& $ResetTokenProvider $Email $RequestedAt)
        Assert-True ($values.Count -eq 1 -and $values[0] -is [string]) 'ResetTokenProvider must return exactly one token string.'
        Require-Value $values[0] 'reset token from test mailbox'
        return $values[0].Trim()
    }
    $deadline = [DateTime]::UtcNow.AddSeconds($RecoveryTimeoutSeconds)
    do {
        if (Test-Path -LiteralPath $RecoveryMailLogPath) {
            $stream = [IO.File]::Open($RecoveryMailLogPath, 'Open', 'Read', 'ReadWrite')
            try {
                if ($stream.Length -lt $LogOffset) { throw 'Recovery mail log rotated during the test; rerun with a stable log or ResetTokenProvider.' }
                [void] $stream.Seek($LogOffset, 'Begin')
                $reader = [IO.StreamReader]::new($stream, [Text.Encoding]::UTF8)
                try { $newText = $reader.ReadToEnd() } finally { $reader.Dispose() }
            } finally { $stream.Dispose() }
            $recipientPattern = '(?im)^To:\s*[^\r\n]*' + [regex]::Escape($Email)
            $recipient = [regex]::Match($newText, $recipientPattern)
            if ($recipient.Success) {
                $mailText = $newText.Substring($recipient.Index)
                $token = [regex]::Match($mailText, 'Use this password reset token in the app:[\s\S]*?\b([a-f0-9]{64})\b')
                if ($token.Success) { return $token.Groups[1].Value }
            }
        }
        Start-Sleep -Milliseconds 500
    } while ([DateTime]::UtcNow -lt $deadline)
    throw 'No new password recovery message for the test customer appeared in the supplied mail log.'
}

function Invoke-BettorCases {
    param([string] $Game, [string] $WinningNumber, [string] $LosingNumber, [decimal] $Rate, [decimal] $NextRate,
        [string] $Session, [string] $CancelDate, [string] $CancelSession)
    $gameState = @{ WinningNumber = $WinningNumber; LosingNumber = $LosingNumber; Rate = $Rate }
    $state.Games[$Game] = $gameState
    $query = "drawDate=$DrawDate&timeType=$Session"
    $cancelQuery = "drawDate=$CancelDate&timeType=$CancelSession"

    Invoke-Case "$Game preflight: refuse occupied result/bet sessions" {
        Require-Value $state.Admin.Access 'admin login'
        foreach ($sessionQuery in @($query, $cancelQuery)) {
            $bets = Invoke-Api GET "/admin/$Game/bets?$sessionQuery" $state.Admin.Access
            Assert-Pagination $bets.Json.data 0
            $draws = Invoke-Api GET "/admin/$Game/results?$sessionQuery" $state.Admin.Access
            Assert-Pagination $draws.Json.data 0
        }
        $gameState.ClearSessions = $true
    }
    Invoke-Case "$Game configure odds and verify customer setting" {
        Require-Value $gameState['ClearSessions'] "$Game empty-session preflight"
        $old = Invoke-Api GET "/$Game/odds" $state.Customer.Access -ExpectedStatus @(200, 404)
        if ($old.Status -eq 200) { $state.OriginalOdds[$Game] = $old.Json.data }
        $odd = Invoke-Api POST "/admin/$Game/odds" $state.Admin.Access -Body @{
            name = "curl $runId $Game"; oddNumber = [string] $Rate; payoutMode = 'total'
        }
        $state.LastOdds[$Game] = $odd.Json.data.id
        $gameState.OddId = $odd.Json.data.id
        $customerOdd = Invoke-Api GET "/$Game/odds?$query" $state.Customer.Access
        Assert-Equal $customerOdd.Json.data.id $gameState.OddId 'Current odds ID'
        Assert-Money $customerOdd.Json.data.oddNumber $Rate 'Current odds rate'
        Assert-Equal $customerOdd.Json.data.payoutMode 'total' 'Payout mode'
        $all = Invoke-Api GET "/admin/$Game/odds?perPage=1" $state.Admin.Access
        Assert-Pagination $all.Json.data
        Assert-Equal $all.Json.data.items[0].id $gameState.OddId 'Newest odds row'
        Invoke-Api PATCH "/admin/$Game/odds/$($gameState.OddId)" $state.Admin.Access -Body @{
            name = 'Cannot edit'; oddNumber = '1'; payoutMode = 'total'
        } -ExpectedStatus 409
    }
    Invoke-Case "$Game reject invalid entries atomically" {
        Require-Value $gameState['OddId'] "$Game odds"
        $base = @{ drawDate = $DrawDate; timeType = $Session; clientReference = [guid]::NewGuid().ToString() }
        Assert-Validation POST "/$Game/bets" $state.Customer.Access @{} @('drawDate', 'timeType', 'clientReference', 'entries')
        $variants = @(
            @{ Entries = @(); Field = 'entries' },
            @{ Entries = @(@{ number = '5'; stakeAmount = '10.00' }); Field = 'entries.0.number' },
            @{ Entries = @(@{ number = 5; stakeAmount = '10.00' }); Field = 'entries.0.number' },
            @{ Entries = @(@{ number = $WinningNumber; stakeAmount = '0' }); Field = 'entries.0.stakeAmount' },
            @{ Entries = @(@{ number = $WinningNumber; stakeAmount = '-1' }); Field = 'entries.0.stakeAmount' },
            @{ Entries = @(@{ number = $WinningNumber; stakeAmount = '1.001' }); Field = 'entries.0.stakeAmount' },
            @{ Entries = @(@{ number = $WinningNumber; stakeAmount = '10'; extra = 'x' }); Field = 'entries.0' },
            @{ Entries = @(@{ number = $WinningNumber; stakeAmount = '10' }, @{ number = 'x'; stakeAmount = '10' }); Field = 'entries.1.number' },
            @{ Entries = @(1..1001 | ForEach-Object { @{ number = $WinningNumber; stakeAmount = '1.00' } }); Field = 'entries' }
        )
        foreach ($variant in $variants) {
            $payload = $base.Clone(); $payload.entries = $variant.Entries
            Assert-Validation POST "/$Game/bets" $state.Customer.Access $payload @($variant.Field)
        }
        $payload = $base.Clone(); $payload.entries = @(@{ number = $WinningNumber; stakeAmount = '10' }); $payload.clientReference = 'invalid'
        Assert-Validation POST "/$Game/bets" $state.Customer.Access $payload @('clientReference')
        $payload.clientReference = [guid]::NewGuid().ToString(); $payload.timeType = 'night'
        Assert-Validation POST "/$Game/bets" $state.Customer.Access $payload @('timeType')
        $payload.timeType = $Session; $payload.drawDate = 'not-a-date'
        Assert-Validation POST "/$Game/bets" $state.Customer.Access $payload @('drawDate')
        $bets = Invoke-Api GET "/$Game/bets?$query" $state.Customer.Access
        Assert-Pagination $bets.Json.data 0
        Assert-Wallet
    }
    Invoke-Case "$Game reject insufficient funds and administrator betting" {
        Require-Value $gameState['OddId'] "$Game odds"
        $payload = @{ drawDate = $DrawDate; timeType = $Session; clientReference = [guid]::NewGuid().ToString()
            entries = @(@{ number = $WinningNumber; stakeAmount = '999999999.00' }) }
        Invoke-Api POST "/$Game/bets" $state.Customer.Access $payload -ExpectedStatus 400
        $payload.entries[0].stakeAmount = '10.00'
        Invoke-Api POST "/$Game/bets" $state.Admin.Access $payload -ExpectedStatus 403
        Assert-Wallet
    }
    Invoke-Case "$Game place ordered winning/losing entries with separate stakes" {
        Require-Value $gameState['OddId'] "$Game odds"
        Require-State @('DepositApproved')
        $payload = @{ drawDate = $DrawDate; timeType = $Session; clientReference = [guid]::NewGuid().ToString()
            entries = @(@{ number = $WinningNumber; stakeAmount = '100.25' }, @{ number = $LosingNumber; stakeAmount = '50.50' }) }
        $response = Invoke-Api POST "/$Game/bets" $state.Customer.Access $payload
        $gameState.Body = $payload; $gameState.Batch = $response.Json.data
        $state.ExpectedAvailable -= [decimal] 150.75
        Add-Movement 'bet_placed' 'out' 150.75 "${Game}_bet_batch" $gameState.Batch.batchId
        Assert-Equal @($gameState.Batch.entries).Count 2 'Batch entry count'
        Assert-Money $gameState.Batch.totalStake 150.75 'Batch total stake'
        Assert-Equal $gameState.Batch.clientReference $payload.clientReference 'Batch client reference'
        for ($index = 0; $index -lt 2; $index++) {
            $entry = $gameState.Batch.entries[$index]
            Assert-True ($entry.number -is [string]) 'Bet number must remain a string.'
            Assert-Equal $entry.number $payload.entries[$index].number 'Fixed-width number/order'
            Assert-Equal $entry.entryIndex $index 'Entry index'
            Assert-Equal $entry.userId $state.Customer.Id 'Bet owner'
            Assert-Equal $entry.batchId $gameState.Batch.batchId 'Entry batch ID'
            Assert-Money $entry.stakeAmount ([decimal] $payload.entries[$index].stakeAmount) 'Entry stake'
            Assert-Money $entry.acceptedOdds $Rate 'Accepted odds snapshot'
            Assert-Money $entry.potentialPayout ([decimal] $payload.entries[$index].stakeAmount * $Rate) 'Potential payout'
            Assert-Equal $entry.status 'active' 'New bet status'
            Assert-Equal $entry.resultStatus 'pending' 'New result status'
        }
        Assert-Wallet
    }
    Invoke-Case "$Game idempotent batch retry and changed-payload conflict" {
        Require-Value $gameState['Batch'] "$Game batch"
        $retry = Invoke-Api POST "/$Game/bets" $state.Customer.Access $gameState.Body
        Assert-Equal $retry.Json.data.batchId $gameState.Batch.batchId 'Retry batch ID'
        for ($index = 0; $index -lt 2; $index++) {
            Assert-Equal $retry.Json.data.entries[$index].id $gameState.Batch.entries[$index].id 'Retry entry ID'
        }
        $changed = $gameState.Body.Clone(); $changed.entries = @(@{ number = $WinningNumber; stakeAmount = '101.25' })
        Invoke-Api POST "/$Game/bets" $state.Customer.Access $changed -ExpectedStatus 409
        Assert-Wallet
    }
    Invoke-Case "$Game customer history, detail, filters, and ownership" {
        Require-Value $gameState['Batch'] "$Game batch"
        $id = $gameState.Batch.entries[0].id
        $detail = Invoke-Api GET "/$Game/bets/$id" $state.Customer.Access
        Assert-Equal $detail.Json.data.number $WinningNumber 'Bet detail number'
        Invoke-Api GET "/$Game/bets/$id" $state.Other.Access -ExpectedStatus 404
        Invoke-Api GET "/$Game/bets/not-a-uuid" $state.Customer.Access -ExpectedStatus 404
        Invoke-Api GET "/$Game/bets/$([guid]::NewGuid())" $state.Customer.Access -ExpectedStatus 404
        $own = Invoke-Api GET "/$Game/bets?$query&status=active&resultStatus=pending&perPage=1" $state.Customer.Access
        Assert-Pagination $own.Json.data 2
        Assert-Equal @($own.Json.data.items).Count 1 'Page size'
        $secondPage = Invoke-Api GET "/$Game/bets?$query&perPage=1&page=2" $state.Customer.Access
        Assert-Pagination $secondPage.Json.data 2
        Assert-True ($own.Json.data.items[0].id -ne $secondPage.Json.data.items[0].id) 'Pagination repeated an entry.'
        $other = Invoke-Api GET "/$Game/bets?paymentUserId=$($state.Customer.Id)&$query" $state.Other.Access
        Assert-Pagination $other.Json.data 0
        Assert-Validation GET "/$Game/bets?perPage=101" $state.Customer.Access $null @('perPage')
        Assert-Validation GET "/$Game/results?timeType=night" $state.Customer.Access $null @('timeType')
    }
    Invoke-Case "$Game new odds apply only to new bets; repeated numbers keep separate stakes" {
        Require-Value $gameState['Batch'] "$Game batch"
        $odd = Invoke-Api POST "/admin/$Game/odds" $state.Admin.Access -Body @{
            name = "curl $runId changed $Game"; oddNumber = [string] $NextRate; payoutMode = 'total'
        }
        $state.LastOdds[$Game] = $odd.Json.data.id
        $current = Invoke-Api GET "/$Game/odds" $state.Customer.Access
        Assert-Equal $current.Json.data.id $odd.Json.data.id 'Newest settings apply'
        $old = Invoke-Api GET "/$Game/bets/$($gameState.Batch.entries[0].id)" $state.Customer.Access
        Assert-Money $old.Json.data.acceptedOdds $Rate 'Existing bet odds remain fixed'
        Assert-Money $old.Json.data.potentialPayout ([decimal] 100.25 * $Rate) 'Existing payout remains fixed'
        $response = Invoke-Api POST "/$Game/bets" $state.Customer.Access -Body @{
            drawDate = $DrawDate; timeType = $Session; clientReference = [guid]::NewGuid().ToString()
            entries = @(@{ number = $LosingNumber; stakeAmount = '10.00' }, @{ number = $LosingNumber; stakeAmount = '15.00' })
        }
        $gameState.NewBatch = $response.Json.data
        $state.ExpectedAvailable -= [decimal] 25
        Add-Movement 'bet_placed' 'out' 25 "${Game}_bet_batch" $gameState.NewBatch.batchId
        Assert-Equal @($gameState.NewBatch.entries).Count 2 'Repeated numbers create individual entries'
        Assert-True ($gameState.NewBatch.entries[0].id -ne $gameState.NewBatch.entries[1].id) 'Repeated numbers need distinct entry IDs.'
        for ($index = 0; $index -lt 2; $index++) {
            $stake = if ($index -eq 0) { [decimal] 10 } else { [decimal] 15 }
            Assert-Equal $gameState.NewBatch.entries[$index].number $LosingNumber 'Repeated fixed-width number'
            Assert-Money $gameState.NewBatch.entries[$index].stakeAmount $stake 'Individual repeated-number stake'
            Assert-Money $gameState.NewBatch.entries[$index].acceptedOdds $NextRate 'New bet odds'
            Assert-Money $gameState.NewBatch.entries[$index].potentialPayout ($stake * $NextRate) 'New bet payout'
        }
        Assert-Wallet
    }
    Invoke-Case "$Game record result and wait for queued calculation" {
        Require-Value $gameState['NewBatch'] "$Game second batch"
        $payload = @{ drawDate = $DrawDate; timeType = $Session; winningNumber = $WinningNumber; name = "curl $runId" }
        $saved = Invoke-Api POST "/admin/$Game/results" $state.Admin.Access $payload
        $gameState.DrawId = $saved.Json.data.id
        $deadline = [DateTime]::UtcNow.AddSeconds($CalculationTimeoutSeconds)
        do {
            $results = Invoke-Api GET "/admin/$Game/results?$query" $state.Admin.Access
            $draw = @($results.Json.data.items | Where-Object { $_.id -eq $gameState.DrawId })
            Assert-Equal $draw.Count 1 'Recorded result exists'
            if ($draw[0].calculationStatus -eq 'completed') { break }
            Assert-True ($draw[0].calculationStatus -ne 'failed') 'Result calculation failed; inspect the queue worker.'
            if ([DateTime]::UtcNow -ge $deadline) { throw 'Calculation timed out; start/check the queue worker, then rerun on unused sessions.' }
            Start-Sleep -Seconds 1
        } while ($true)
        $gameState.Calculated = $true
        $retry = Invoke-Api POST "/admin/$Game/results" $state.Admin.Access $payload
        Assert-Equal $retry.Json.data.id $gameState.DrawId 'Identical result retry'
        $payload.winningNumber = $LosingNumber
        Invoke-Api POST "/admin/$Game/results" $state.Admin.Access $payload -ExpectedStatus 409
        Invoke-Api POST "/admin/$Game/results/recalculate" $state.Admin.Access -Body @{ drawDate = $DrawDate; timeType = $Session }
        $public = Invoke-Api GET "/$Game/results?$query" $state.Customer.Access
        Assert-Pagination $public.Json.data 1
        Assert-Equal $public.Json.data.items[0].winningNumber $WinningNumber 'Customer result number'
        $won = Invoke-Api GET "/$Game/bets/$($gameState.Batch.entries[0].id)" $state.Customer.Access
        Assert-Equal $won.Json.data.resultStatus 'won' 'Winning bet result'
        Assert-Equal $won.Json.data.status 'active' 'Calculation alone must not pay'
        Assert-Wallet
    }
    Invoke-Case "$Game preview payouts and reject missing/invalid confirmation" {
        Require-Value $gameState['Calculated'] "$Game calculation"
        $payload = @{ drawDate = $DrawDate; timeType = $Session }
        Assert-Validation POST "/admin/$Game/results/settle" $state.Admin.Access $payload @('previewToken')
        $preview = Invoke-Api POST "/admin/$Game/results/preview" $state.Admin.Access $payload
        $gameState.Preview = $preview.Json.data
        Assert-Equal $gameState.Preview.betCount 4 'Preview bet count'
        Assert-Equal $gameState.Preview.winnerCount 1 'Preview winners'
        Assert-Equal $gameState.Preview.loserCount 3 'Preview losers'
        Assert-Money $gameState.Preview.totalStake 175.75 'Preview total stake'
        Assert-Money $gameState.Preview.totalPayout ([decimal] 100.25 * $Rate) 'Preview total payout'
        Assert-Equal $gameState.Preview.previewToken.Length 64 'Preview token length'
        $payload.previewToken = ('0' * 64)
        Invoke-Api POST "/admin/$Game/results/settle" $state.Admin.Access $payload -ExpectedStatus 409
        Assert-Wallet
    }
    Invoke-Case "$Game settle winners exactly once and finalize losers" {
        Require-Value $gameState['Preview'] "$Game preview"
        $payload = @{ drawDate = $DrawDate; timeType = $Session; previewToken = $gameState.Preview.previewToken }
        $settled = Invoke-Api POST "/admin/$Game/results/settle" $state.Admin.Access $payload
        $state.ExpectedAvailable += [decimal] 100.25 * $Rate
        Add-Movement 'bet_won' 'in' ([decimal] 100.25 * $Rate) "${Game}_bet" $gameState.Batch.entries[0].id
        Assert-True ($null -ne $settled.Json.data.settled_at) 'Result was not marked settled.'
        Invoke-Api POST "/admin/$Game/results/settle" $state.Admin.Access $payload
        Assert-Wallet
        $won = Invoke-Api GET "/$Game/bets/$($gameState.Batch.entries[0].id)" $state.Customer.Access
        Assert-Equal $won.Json.data.status 'settled' 'Winner status'
        Assert-Money $won.Json.data.settleAmount ([decimal] 100.25 * $Rate) 'Winner paid amount'
        foreach ($id in @($gameState.Batch.entries[1].id, $gameState.NewBatch.entries[0].id, $gameState.NewBatch.entries[1].id)) {
            $lost = Invoke-Api GET "/$Game/bets/$id" $state.Customer.Access
            Assert-Equal $lost.Json.data.status 'lost' 'Loser status'
            Assert-Equal $lost.Json.data.resultStatus 'lost' 'Loser result'
            Assert-Money $lost.Json.data.settleAmount 0 'Loser payout'
        }
        Invoke-Api POST "/$Game/bets" $state.Customer.Access -Body @{
            drawDate = $DrawDate; timeType = $Session; clientReference = [guid]::NewGuid().ToString()
            entries = @(@{ number = $WinningNumber; stakeAmount = '1.00' })
        } -ExpectedStatus 409
        Invoke-Api POST "/admin/$Game/results/cancel" $state.Admin.Access -Body @{
            drawDate = $DrawDate; timeType = $Session; reason = 'Paid results cannot be cancelled'
        } -ExpectedStatus 409
    }
    Invoke-Case "$Game profit-mode odds and cancellation return each stake once" {
        Require-Value $gameState['ClearSessions'] "$Game empty-session preflight"
        Require-State @('DepositApproved')
        $odd = Invoke-Api POST "/admin/$Game/odds" $state.Admin.Access -Body @{
            name = "curl $runId profit $Game"; oddNumber = '10'; payoutMode = 'profit'
        }
        $state.LastOdds[$Game] = $odd.Json.data.id
        $response = Invoke-Api POST "/$Game/bets" $state.Customer.Access -Body @{
            drawDate = $CancelDate; timeType = $CancelSession; clientReference = [guid]::NewGuid().ToString()
            entries = @(@{ number = $WinningNumber; stakeAmount = '40.00' }, @{ number = $LosingNumber; stakeAmount = '60.00' })
        }
        $gameState.CancelBatch = $response.Json.data
        $state.ExpectedAvailable -= [decimal] 100
        Add-Movement 'bet_placed' 'out' 100 "${Game}_bet_batch" $gameState.CancelBatch.batchId
        Assert-Money $gameState.CancelBatch.entries[0].potentialPayout 440 'Profit mode includes original stake'
        Assert-Money $gameState.CancelBatch.entries[1].potentialPayout 660 'Profit mode second stake'
        Assert-Wallet
        $payload = @{ drawDate = $CancelDate; timeType = $CancelSession; reason = "curl $runId refund test" }
        $cancelled = Invoke-Api POST "/admin/$Game/results/cancel" $state.Admin.Access $payload
        $state.ExpectedAvailable += [decimal] 100
        foreach ($entry in $gameState.CancelBatch.entries) {
            Add-Movement 'bet_refunded' 'in' ([decimal] $entry.stakeAmount) "${Game}_bet" $entry.id
        }
        Assert-True ($null -ne $cancelled.Json.data.cancelled_at) 'Draw cancellation timestamp'
        Invoke-Api POST "/admin/$Game/results/cancel" $state.Admin.Access $payload
        Assert-Wallet
        foreach ($entry in $gameState.CancelBatch.entries) {
            $detail = Invoke-Api GET "/$Game/bets/$($entry.id)" $state.Customer.Access
            Assert-Equal $detail.Json.data.status 'cancelled' 'Cancelled bet status'
            Assert-Money $detail.Json.data.settleAmount 0 'Cancelled bet payout'
        }
        $results = Invoke-Api GET "/$Game/results?$cancelQuery" $state.Customer.Access
        Assert-Pagination $results.Json.data 1
        Assert-True ($null -ne $results.Json.data.items[0].cancelled_at) 'Customer can see cancellation'
        Invoke-Api POST "/$Game/bets" $state.Customer.Access -Body @{
            drawDate = $CancelDate; timeType = $CancelSession; clientReference = [guid]::NewGuid().ToString()
            entries = @(@{ number = $WinningNumber; stakeAmount = '1.00' })
        } -ExpectedStatus 409
        Invoke-Api POST "/admin/$Game/results/settle" $state.Admin.Access -Body @{
            drawDate = $CancelDate; timeType = $CancelSession; previewToken = ('0' * 64)
        } -ExpectedStatus 409
    }
}

try {
    Invoke-Case 'Prerequisites and run configuration' {
        if ([string]::IsNullOrWhiteSpace($AdminEmail) -or [string]::IsNullOrWhiteSpace($AdminPassword)) {
            throw 'Set CUSTOMER_API_ADMIN_EMAIL and CUSTOMER_API_ADMIN_PASSWORD to an active Super Admin before running.'
        }
        $script:Secrets.Add($AdminPassword)
        if ([string]::IsNullOrWhiteSpace($CustomerPassword)) { $script:CustomerPassword = 'Curl!' + [guid]::NewGuid().ToString('N') }
        $script:Secrets.Add($CustomerPassword)
        Assert-True ($CustomerPassword.Length -ge 8) 'CustomerPassword must contain at least 8 characters.'
        $curlCommand = Get-Command curl.exe -CommandType Application -ErrorAction SilentlyContinue | Select-Object -First 1
        if ($null -eq $curlCommand) { $curlCommand = Get-Command curl -CommandType Application -ErrorAction Stop | Select-Object -First 1 }
        $script:CurlPath = $curlCommand.Source
        $baseUri = [uri] $BaseUrl
        Assert-True ($baseUri.IsAbsoluteUri -and $baseUri.Scheme -in @('http', 'https')) 'BaseUrl must be an absolute HTTP(S) URL.'
        Assert-True (-not $baseUri.Query -and -not $baseUri.Fragment -and -not $baseUri.UserInfo) 'BaseUrl must not contain credentials, a query, or a fragment.'
        $script:ApiRoot = $BaseUrl.TrimEnd('/')
        if (-not $script:ApiRoot.EndsWith('/api')) { $script:ApiRoot += '/api' }
        $state.Target = $script:ApiRoot
        if (-not $DrawDate) { $script:DrawDate = [DateTime]::UtcNow.AddDays((Get-Random -Minimum 366 -Maximum 3660)).ToString('yyyy-MM-dd') }
        $date = [DateTime]::ParseExact($DrawDate, 'yyyy-MM-dd', [Globalization.CultureInfo]::InvariantCulture)
        Assert-True ($date.Date -gt [DateTime]::UtcNow.Date) 'DrawDate must be a future, unused date.'
        $state.NextDate = $date.AddDays(1).ToString('yyyy-MM-dd')
        [void] [IO.Directory]::CreateDirectory((Join-Path $OutputDirectory $runId))
        $state.Configured = $true
        Write-Host "Target: $script:ApiRoot | Run: $runId | Draw: $DrawDate" -ForegroundColor Cyan
        Write-Host 'This run writes test records and temporarily changes global odds on the test target.' -ForegroundColor Cyan
    }
    Require-State @('Configured')

    Invoke-Case 'Admin login and role required for fixture setup and approvals' {
        Set-Login $state.Admin $AdminPassword
        Assert-Equal $state.Admin.RoleName 'Super Admin' 'Fixture setup requires Super Admin'
        $roles = Invoke-Api GET '/admin/roles' $state.Admin.Access
        $customerRole = @($roles.Json.data | Where-Object { $_.name -eq 'Customer' })
        Assert-Equal $customerRole.Count 1 'Active Customer role exists'
        $state.CustomerRoleId = $customerRole[0].id
    }
    Invoke-Case 'Unauthenticated customer routes reject access' {
        foreach ($path in @('/user', '/profile', '/master', '/support', '/paymentmethod/deposit', '/paymentmethod/withdrawal',
            '/paymentaccount/withdrawal', '/payment/deposit/history', '/payment/withdrawal/history', '/payment/withdrawal/request/history',
            '/wallet', '/wallet/transactions', '/twod/odds', '/threed/odds', '/twod/bets', '/threed/bets', '/twod/results', '/threed/results')) {
            Invoke-Api GET $path -ExpectedStatus 401
        }
        foreach ($path in @('/auth/refresh', '/auth/logout', '/paymentaccount/withdrawal', '/payment/deposit', '/payment/withdrawal', '/twod/bets', '/threed/bets', '/profile/password')) {
            Invoke-Api POST $path -Body @{} -ExpectedStatus 401
        }
        Invoke-Api PATCH '/profile' -Body @{} -ExpectedStatus 401
    }
    Invoke-Case 'Registration rejects missing/invalid fields' {
        Assert-Validation POST '/auth/register' '' @{ name = ''; email = 'invalid'; password = 'short' } @('name', 'email', 'password')
    }
    Invoke-Case 'Register two fresh customers; ignore role and approval injection' {
        Require-Value $state.Admin.RoleId 'admin login'
        foreach ($user in @($state.Customer, $state.Other)) {
            Invoke-Api POST '/auth/register' -Body @{ name = "Curl Customer $runId"; email = $user.Email; password = $CustomerPassword
                roleId = $state.Admin.RoleId; role_id = $state.Admin.RoleId; status = 'suspended'; avaliable_balance = '999999' }
            Set-Login $user $CustomerPassword
            Assert-Equal $user.RoleName 'Customer' 'Registered role cannot be elevated'
            Assert-Equal $user.RoleId $state.CustomerRoleId 'Registered Customer role ID'
            $profile = Invoke-Api GET '/profile' $user.Access
            Assert-Equal $profile.Json.data.status 'active' 'Registration ignores privileged status'
            Assert-True (-not ($profile.Json.data.PSObject.Properties.Name -contains 'password')) 'Profile exposed a password.'
            $wallet = Invoke-Api GET '/wallet' $user.Access
            Assert-Money $wallet.Json.data.avaliableBalance 0 'Registered customer available balance'
            Assert-Money $wallet.Json.data.lockedBalance 0 'Registered customer locked balance'
            $walletVersion = [guid]::Empty
            Assert-True ([guid]::TryParse([string] $wallet.Json.data.version, [ref] $walletVersion)) 'Registration must persist a wallet with a UUID version.'
            $user.InitialWalletVersion = $wallet.Json.data.version
        }
        Assert-Wallet
    }
    Invoke-Case 'Duplicate registration and invalid login are rejected' {
        Require-Value $state.Customer.Id 'customer registration'
        Assert-Validation POST '/auth/register' '' @{ name = 'Duplicate'; email = $state.Customer.Email; password = $CustomerPassword } @('email')
        Assert-Validation POST '/auth/login' '' @{} @('email', 'password')
        Invoke-Api POST '/auth/login' -Body @{ email = $state.Customer.Email; password = 'incorrect-password' } -ExpectedStatus 401
        Invoke-Api POST '/wallet' $state.Customer.Access -Body @{} -ExpectedStatus 405
        $wallet = Invoke-Api GET '/wallet' $state.Customer.Access
        Assert-Equal $wallet.Json.data.version $state.Customer.InitialWalletVersion 'Rejected registration/open attempts must not replace the wallet'
    }
    Invoke-Case 'Customer identity and profile update ignore privileged fields' {
        Require-Value $state.Customer.Access 'customer login'
        $user = Invoke-Api GET '/user' $state.Customer.Access -RawUser
        Assert-Equal $user.Json.id $state.Customer.Id 'Authenticated user ID'
        Assert-True (-not ($user.Json.PSObject.Properties.Name -contains 'password')) 'User endpoint exposed a password.'
        $profile = Invoke-Api PATCH '/profile' $state.Customer.Access -Body @{
            name = "Updated Curl $runId"; roleId = $state.Admin.RoleId; role_id = $state.Admin.RoleId; status = 'suspended'
        }
        Assert-Equal $profile.Json.data.name "Updated Curl $runId" 'Updated name'
        Assert-Equal $profile.Json.data.role.name 'Customer' 'Customer cannot elevate own role'
        Assert-Equal $profile.Json.data.status 'active' 'Customer cannot change own status'
        Assert-Validation PATCH '/profile' $state.Customer.Access @{ name = '' } @('name')
    }
    Invoke-Case 'Refresh token abilities, rotation, and old-pair revocation' {
        Require-Value $state.Customer.Access 'customer login'
        Invoke-Api GET '/wallet' $state.Customer.Refresh -ExpectedStatus 403
        Invoke-Api POST '/auth/refresh' $state.Customer.Access -ExpectedStatus 403
        $oldAccess = $state.Customer.Access; $oldRefresh = $state.Customer.Refresh
        $response = Invoke-Api POST '/auth/refresh' $oldRefresh
        $state.Customer.Access = $response.Json.data.access_token; $state.Customer.Refresh = $response.Json.data.refresh_token
        $script:Secrets.Add($state.Customer.Access); $script:Secrets.Add($state.Customer.Refresh)
        $script:TokenOwners[$state.Customer.Access] = $state.Customer.Id; $script:TokenOwners[$state.Customer.Refresh] = $state.Customer.Id
        Assert-True ($state.Customer.Access -ne $oldAccess -and $state.Customer.Refresh -ne $oldRefresh) 'Refresh did not rotate both tokens.'
        Invoke-Api GET '/wallet' $oldAccess -ExpectedStatus 401
        Invoke-Api POST '/auth/refresh' $oldRefresh -ExpectedStatus 401
        Assert-Wallet
    }
    Invoke-Case 'Admin wallet CRUD is limited to one wallet per customer; admin accounts have none' {
        Require-Value $state.Customer.Id 'customer registration'
        $path = "/admin/accounts/$($state.Customer.Id)/wallet"
        $wallet = Invoke-Api GET $path $state.Admin.Access
        $state.Customer.WalletId = $wallet.Json.data.id
        Assert-Equal $wallet.Json.data.userId $state.Customer.Id 'Customer wallet owner'
        Invoke-Api POST $path $state.Admin.Access -Body @{ reason = 'Duplicate wallet must be refused' } -ExpectedStatus 409
        foreach ($method in @('GET', 'POST', 'PATCH', 'DELETE')) {
            Invoke-Api $method $path $state.Customer.Access -Body $(if ($method -eq 'GET') { $null } else { @{ reason = 'Customer cannot manage wallets' } }) -ExpectedStatus 403
        }
        $adminPath = "/admin/accounts/$($state.Admin.Id)/wallet"
        Invoke-Api GET $adminPath $state.Admin.Access -ExpectedStatus 404
        Invoke-Api POST $adminPath $state.Admin.Access -Body @{ reason = 'Administrator accounts must not have wallets' } -ExpectedStatus 404
        $adminWallet = Invoke-Api GET '/wallet' $state.Admin.Access
        Assert-True ($null -eq $adminWallet.Json.data.version) 'Admin account must not own a wallet.'

        $otherPath = "/admin/accounts/$($state.Other.Id)/wallet"
        $old = Invoke-Api GET $otherPath $state.Admin.Access
        Invoke-Api DELETE $otherPath $state.Admin.Access -Body @{ reason = "curl $runId delete empty wallet" }
        Invoke-Api GET $otherPath $state.Admin.Access -ExpectedStatus 404
        $missing = Invoke-Api GET '/wallet' $state.Other.Access
        Assert-True ($null -eq $missing.Json.data.version) 'Reading a deleted wallet must not reopen it.'
        Invoke-Api POST '/wallet' $state.Other.Access -Body @{} -ExpectedStatus 405
        $created = Invoke-Api POST $otherPath $state.Admin.Access -Body @{ reason = "curl $runId recreate empty wallet" }
        Assert-True ($created.Json.data.id -ne $old.Json.data.id) 'Admin recreation must create a new wallet ID.'
        Assert-Money $created.Json.data.avaliableBalance 0 'Recreated wallet starts empty'
        Assert-Money $created.Json.data.lockedBalance 0 'Recreated wallet has no locked money'
        Invoke-Api POST $otherPath $state.Admin.Access -Body @{ reason = 'Second wallet' } -ExpectedStatus 409
    }
    Invoke-Case 'Manual wallet credits/debits are traced, validated, and applied once' {
        Require-Value $state.Customer.Id 'customer registration'
        $path = "/admin/accounts/$($state.Customer.Id)/wallet"
        $credit = @{ direction = 'in'; amount = '250.75'; reason = "curl $runId manual credit"; clientReference = [guid]::NewGuid().ToString() }
        $response = Invoke-Api PATCH $path $state.Admin.Access $credit
        $state.ExpectedAvailable += [decimal] 250.75
        $movement = $response.Json.data.transaction
        Add-Movement 'manual_adjustment' 'in' 250.75 'admin_adjustment' $movement.referenceId
        $state.ManualCreditId = $movement.id
        $state.ManualCreditReference = $credit.clientReference
        $state.ManualAdjustmentRequestId = $response.RequestId
        Assert-Equal $movement.actorUserId $state.Admin.Id 'Manual adjustment actor'
        Assert-Equal $movement.reason $credit.reason 'Manual adjustment reason'
        Assert-Equal $movement.clientReference $credit.clientReference 'Manual adjustment client reference'
        Assert-Money $movement.balanceBefore 0 'Manual credit balance before'
        Assert-Money $movement.balanceAfter 250.75 'Manual credit balance after'
        $retry = Invoke-Api PATCH $path $state.Admin.Access $credit
        Assert-Equal $retry.Json.data.transaction.id $movement.id 'Manual adjustment retry movement'
        $changed = $credit.Clone(); $changed.amount = '251.75'
        Invoke-Api PATCH $path $state.Admin.Access $changed -ExpectedStatus 409
        $invalid = $credit.Clone(); $invalid.clientReference = [guid]::NewGuid().ToString(); $invalid.reason = ''
        Assert-Validation PATCH $path $state.Admin.Access $invalid @('reason')
        $invalid.reason = 'Invalid amount'; $invalid.amount = '1.001'
        Assert-Validation PATCH $path $state.Admin.Access $invalid @('amount')
        $invalid.amount = '1.00'; $invalid.lockedBalance = '0'
        Assert-Validation PATCH $path $state.Admin.Access $invalid @('lockedBalance')
        $debit = @{ direction = 'out'; amount = '999.00'; reason = "curl $runId manual debit"; clientReference = [guid]::NewGuid().ToString() }
        Assert-Validation PATCH $path $state.Admin.Access $debit @('amount')
        Invoke-Api DELETE $path $state.Admin.Access -Body @{ reason = 'A funded wallet must be retained' } -ExpectedStatus 409
        Assert-Wallet
        $debit.amount = '250.75'
        $paid = Invoke-Api PATCH $path $state.Admin.Access $debit
        $state.ExpectedAvailable -= [decimal] 250.75
        Add-Movement 'manual_adjustment' 'out' 250.75 'admin_adjustment' $paid.Json.data.transaction.referenceId
        $retry = Invoke-Api PATCH $path $state.Admin.Access $debit
        Assert-Equal $retry.Json.data.transaction.id $paid.Json.data.transaction.id 'Manual debit retry movement'
        Invoke-Api DELETE $path $state.Admin.Access -Body @{ reason = 'Zero balance with history must be retained' } -ExpectedStatus 409
        Assert-Wallet
        $history = Invoke-Api GET '/wallet/transactions' $state.Customer.Access
        Assert-Pagination $history.Json.data 2
        $creditEntry = @($history.Json.data.items | Where-Object { $_.id -eq $state.ManualCreditId })
        Assert-Equal $creditEntry.Count 1 'Customer sees manual credit in transaction tracing'
        Assert-Equal $creditEntry[0].actorUserId $state.Admin.Id 'Transaction tracing contains admin ID'
        Assert-Equal $creditEntry[0].reason $credit.reason 'Transaction tracing contains correction reason'
        $adminHistory = Invoke-Api GET "/admin/accounts/$($state.Customer.Id)/history" $state.Admin.Access
        Assert-Equal $adminHistory.Json.data.movements.total 2 'Admin customer history includes manual adjustments'
    }
    Invoke-Case 'Admin API audits contain only administrator requests' {
        Require-State @('ManualAdjustmentRequestId')
        $audit = Get-AdminAudit "requestId=$($state.ManualAdjustmentRequestId)&method=PATCH&statusCode=200&actorUserId=$($state.Admin.Id)"
        Assert-Pagination $audit.Json.data 1
        Assert-Equal $audit.Json.data.items[0].request_id $state.ManualAdjustmentRequestId 'Adjustment request correlation'
        Assert-Equal $audit.Json.data.items[0].path "/api/admin/accounts/$($state.Customer.Id)/wallet" 'Audited adjustment endpoint'
        $denied = Invoke-Api GET '/admin/audit-logs' $state.Customer.Access -ExpectedStatus 403
        $anonymous = Invoke-Api GET '/admin/dashboard' -ExpectedStatus 401
        foreach ($request in @(@{ Response = $denied; Actor = $state.Customer.Id; Status = 403 }, @{ Response = $anonymous; Actor = $null; Status = 401 })) {
            $entry = Invoke-Api GET "/admin/audit-logs?requestId=$($request.Response.RequestId)" $state.Admin.Access
            Assert-Pagination $entry.Json.data 0
        }
        Assert-Validation GET '/admin/audit-logs?perPage=101' $state.Admin.Access $null @('perPage')
    }
    Invoke-Case 'Dedicated support type controller CRUD and customer permissions' {
        Require-Value $state.Admin.Access 'admin login'
        $created = Invoke-Api POST '/admin/support-types' $state.Admin.Access -Body @{ name = "curl $runId support"; imageLocation = 'support/curl.png' }
        $id = $created.Json.data.id
        try {
            $shown = Invoke-Api GET "/admin/support-types/$id" $state.Admin.Access
            Assert-Equal $shown.Json.data.id $id 'Support type detail'
            $list = Invoke-Api GET "/admin/support-types?search=$runId" $state.Admin.Access
            Assert-Pagination $list.Json.data 1
            $updated = Invoke-Api PATCH "/admin/support-types/$id" $state.Admin.Access -Body @{ name = "curl $runId updated" }
            Assert-Equal $updated.Json.data.name "curl $runId updated" 'Support type update'
            Assert-Validation POST '/admin/support-types' $state.Admin.Access @{} @('name', 'imageLocation')
            Invoke-Api GET '/admin/support-types' $state.Customer.Access -ExpectedStatus 403
        } finally {
            Invoke-Api DELETE "/admin/support-types/$id" $state.Admin.Access
        }
        Invoke-Api GET "/admin/support-types/$id" $state.Admin.Access -ExpectedStatus 404
    }
    Invoke-Case 'Dedicated payment method controller CRUD preserves payment types' {
        Require-Value $state.Admin.Access 'admin login'
        $created = Invoke-Api POST '/admin/payment-methods' $state.Admin.Access -Body @{
            name = "curl $runId unused provider"; type = 'deposit'; description = 'Temporary CRUD test'; status = 'active'
        }
        $id = $created.Json.data.id
        try {
            $shown = Invoke-Api GET "/admin/payment-methods/$id" $state.Admin.Access
            Assert-Equal $shown.Json.data.type 'deposit' 'Payment method type'
            $list = Invoke-Api GET "/admin/payment-methods?search=$runId" $state.Admin.Access
            Assert-Pagination $list.Json.data 1
            $updated = Invoke-Api PATCH "/admin/payment-methods/$id" $state.Admin.Access -Body @{ type = 'withdraw'; name = "curl $runId updated provider" }
            Assert-Equal $updated.Json.data.type 'withdraw' 'Unused payment method can change type'
            Assert-Validation POST '/admin/payment-methods' $state.Admin.Access @{} @('name', 'type', 'description')
            Invoke-Api GET '/admin/payment-methods' $state.Customer.Access -ExpectedStatus 403
        } finally {
            Invoke-Api DELETE "/admin/payment-methods/$id" $state.Admin.Access
        }
        Invoke-Api GET "/admin/payment-methods/$id" $state.Admin.Access -ExpectedStatus 404
    }
    Invoke-Case 'Create isolated active deposit and withdrawal methods' {
        Require-Value $state.Admin.Access 'admin login'
        foreach ($type in @('deposit', 'withdraw')) {
            $response = Invoke-Api POST '/admin/payment-methods' $state.Admin.Access -Body @{
                name = "curl $runId $type"; type = $type; description = "Automated customer API test $runId"; status = 'active'
            }
            $key = if ($type -eq 'deposit') { 'DepositMethodId' } else { 'WithdrawalMethodId' }
            $state[$key] = $response.Json.data.id
            $state.Created[$key] = $response.Json.data.id
            Assert-Equal $response.Json.data.type $type 'Method type'
        }
    }
    Invoke-Case 'Customer master version cache and support contacts' {
        Require-State @('DepositMethodId', 'WithdrawalMethodId')
        $master = Invoke-Api GET '/master' $state.Customer.Access
        Assert-True (@($master.Json.data.deposit_payment_methods.id) -contains $state.DepositMethodId) 'Deposit method missing from master.'
        Assert-True (@($master.Json.data.withdrawal_payment_methods.id) -contains $state.WithdrawalMethodId) 'Withdrawal method missing from master.'
        $version = $master.Json.data.master_version
        Assert-Equal $version.Length 64 'Master version hash'
        $cached = Invoke-Api GET "/master?version=$version" $state.Customer.Access
        Assert-True ($null -eq $cached.Json.data) 'Unchanged master must return null data.'
        Assert-Validation GET ('/master?version=' + ('x' * 65)) $state.Customer.Access $null @('version')
        $supports = Invoke-Api GET '/support' $state.Customer.Access
        Assert-True ($supports.Json.data -is [array]) 'Support endpoint must return an array.'
    }
    Invoke-Case 'Admin receiving account and customer withdrawal account setup' {
        Require-State @('DepositMethodId', 'WithdrawalMethodId')
        $deposit = Invoke-Api POST '/admin/paymentaccount/deposit' $state.Admin.Access -Body @{
            userName = "Curl Admin $runId"; phoneNumber = '09900112233'; paymentMethodId = $state.DepositMethodId; descriptions = $runId
        }
        $state.DepositAccountId = $deposit.Json.data.id; $state.Created.DepositAccountId = $state.DepositAccountId
        Assert-Equal $deposit.Json.data.type 'deposit' 'Admin account type'
        $state.AccountBody = @{ userName = "Curl Customer $runId"; phoneNumber = '09900445566'; paymentMethodId = $state.WithdrawalMethodId; descriptions = $runId }
        $withdraw = Invoke-Api POST '/paymentaccount/withdrawal' $state.Customer.Access $state.AccountBody
        $state.WithdrawalAccountId = $withdraw.Json.data.id; $state.Created.WithdrawalAccountId = $state.WithdrawalAccountId
        Assert-Equal $withdraw.Json.data.type 'withdraw' 'Customer account type'
        $other = Invoke-Api POST '/paymentaccount/withdrawal' $state.Other.Access -Body @{
            userName = 'Other test customer'; bankAccountNumber = "0000$runId"; paymentMethodId = $state.WithdrawalMethodId
        }
        $state.OtherAccountId = $other.Json.data.id; $state.Created.OtherAccountId = $state.OtherAccountId
        Assert-Equal $other.Json.data.bankAccountNumber "0000$runId" 'Bank account leading zeroes'
    }
    Invoke-Case 'Payment account validation, duplicates, and customer isolation' {
        Require-State @('WithdrawalAccountId', 'OtherAccountId', 'DepositMethodId')
        Invoke-Api POST '/paymentaccount/withdrawal' $state.Customer.Access $state.AccountBody -ExpectedStatus 409
        Assert-Validation POST '/paymentaccount/withdrawal' $state.Customer.Access @{
            userName = 'Missing contact'; paymentMethodId = $state.WithdrawalMethodId
        } @('phoneNumber', 'bankAccountNumber')
        Assert-Validation POST '/paymentaccount/withdrawal' $state.Customer.Access @{
            userName = 'Wrong type'; phoneNumber = '09900000000'; paymentMethodId = $state.DepositMethodId
        } @('paymentMethodId')
        foreach ($path in @('/paymentaccount/withdrawal', '/paymentmethod/withdrawal')) {
            $response = Invoke-Api GET $path $state.Customer.Access
            Assert-Equal @($response.Json.data).Count 1 'Only own withdrawal account is visible'
            Assert-Equal $response.Json.data[0].id $state.WithdrawalAccountId 'Own account ID'
        }
        Invoke-Api DELETE "/paymentaccount/withdrawal/$($state.OtherAccountId)" $state.Customer.Access -ExpectedStatus 404
        $body = New-WithdrawalBody '1.00'; $body.paymentAccountId = $state.OtherAccountId
        Invoke-Api POST '/payment/withdrawal' $state.Customer.Access $body -ExpectedStatus 404
    }
    Invoke-Case 'Deposit account discovery, inactive account, and mismatched method' {
        Require-State @('DepositAccountId', 'WithdrawalMethodId')
        $methods = Invoke-Api GET '/paymentmethod/deposit' $state.Customer.Access
        $method = @($methods.Json.data | Where-Object { $_.id -eq $state.DepositMethodId })
        Assert-Equal $method.Count 1 'Active deposit method visible'
        Assert-True (@($method[0].paymentAccounts.id) -contains $state.DepositAccountId) 'Receiving account missing.'
        Invoke-Api GET '/admin/paymentaccount/deposit' $state.Admin.Access
        Invoke-Api PATCH "/admin/paymentaccount/deposit/$($state.DepositAccountId)/status" $state.Admin.Access -Body @{ status = 'inactive' }
        try {
            Invoke-Api POST '/payment/deposit' $state.Customer.Access (New-DepositBody '10.00') -ExpectedStatus 400
            $hidden = Invoke-Api GET '/paymentmethod/deposit' $state.Customer.Access
            $ownMethod = @($hidden.Json.data | Where-Object { $_.id -eq $state.DepositMethodId })
            Assert-Equal @($ownMethod[0].paymentAccounts).Count 0 'Inactive receiving account must be hidden'
        } finally {
            Invoke-Api PATCH "/admin/paymentaccount/deposit/$($state.DepositAccountId)/status" $state.Admin.Access -Body @{ status = 'active' }
        }
        $mismatch = New-DepositBody '10.00'; $mismatch.PaymentMethodId = $state.WithdrawalMethodId
        Invoke-Api POST '/payment/deposit' $state.Customer.Access $mismatch -ExpectedStatus 400
        Assert-Wallet
    }
    Invoke-Case 'Deposit amount/reference validation makes no wallet changes' {
        Require-State @('DepositAccountId')
        foreach ($amount in @('0', '-1', '1.001')) {
            Assert-Validation POST '/payment/deposit' $state.Customer.Access (New-DepositBody $amount) @('amount')
        }
        $body = New-DepositBody '10'; $body.lastSixTranId = '123'
        Assert-Validation POST '/payment/deposit' $state.Customer.Access $body @('lastSixTranId')
        $body.lastSixTranId = '012345'; $body.clientReference = 'bad'
        Assert-Validation POST '/payment/deposit' $state.Customer.Access $body @('clientReference')
        Assert-Wallet
    }
    Invoke-Case 'Inactive payment methods disappear from master and reject new payments/accounts' {
        Require-State @('DepositAccountId', 'WithdrawalAccountId')
        $before = Invoke-Api GET '/master' $state.Customer.Access
        foreach ($methodId in @($state.DepositMethodId, $state.WithdrawalMethodId)) {
            Invoke-Api PATCH "/admin/master/payment-methods/$methodId" $state.Admin.Access -Body @{ status = 'inactive' }
        }
        try {
            $changed = Invoke-Api GET "/master?version=$($before.Json.data.master_version)" $state.Customer.Access
            Assert-True ($null -ne $changed.Json.data) 'Changing method status must invalidate the master version.'
            Assert-True ($changed.Json.data.master_version -ne $before.Json.data.master_version) 'Master version did not change.'
            Assert-True (-not (@($changed.Json.data.deposit_payment_methods | ForEach-Object { $_.id }) -contains $state.DepositMethodId)) 'Inactive deposit method is visible.'
            Assert-True (-not (@($changed.Json.data.withdrawal_payment_methods | ForEach-Object { $_.id }) -contains $state.WithdrawalMethodId)) 'Inactive withdrawal method is visible.'
            Invoke-Api POST '/payment/deposit' $state.Customer.Access (New-DepositBody '1.00') -ExpectedStatus 400
            Invoke-Api POST '/payment/withdrawal' $state.Customer.Access (New-WithdrawalBody '1.00') -ExpectedStatus 400
            Assert-Validation POST '/paymentaccount/withdrawal' $state.Customer.Access @{
                userName = 'Inactive method'; phoneNumber = '09900001111'; paymentMethodId = $state.WithdrawalMethodId
            } @('paymentMethodId')
        } finally {
            foreach ($methodId in @($state.DepositMethodId, $state.WithdrawalMethodId)) {
                Invoke-Api PATCH "/admin/master/payment-methods/$methodId" $state.Admin.Access -Body @{ status = 'active' }
            }
        }
        Assert-Wallet
    }
    Invoke-Case 'Deposit pending creation, untrusted fields, retries, and conflict' {
        Require-State @('DepositAccountId')
        $state.DepositBody = New-DepositBody
        $state.DepositBody.status = 'approved'; $state.DepositBody.approver_user_id = $state.Admin.Id
        $state.DepositBody.payment_user_id = $state.Other.Id
        $response = Invoke-Api POST '/payment/deposit' $state.Customer.Access $state.DepositBody
        $state.DepositId = $response.Json.data.id; $state.Created.DepositId = $state.DepositId
        Assert-Equal $response.Json.data.status 'pending' 'Deposit remains pending'
        Assert-Equal $response.Json.data.paymentUserId $state.Customer.Id 'Deposit belongs to authenticated customer'
        Assert-True ($null -eq $response.Json.data.approverUserId) 'Customer supplied an approver.'
        Assert-Equal $response.Json.data.lastSixTranId '012345' 'Transaction suffix keeps leading zero'
        $retry = Invoke-Api POST '/payment/deposit' $state.Customer.Access $state.DepositBody
        Assert-Equal $retry.Json.data.id $state.DepositId 'Deposit retry ID'
        $changed = $state.DepositBody.Clone(); $changed.amount = '10001.50'
        Invoke-Api POST '/payment/deposit' $state.Customer.Access $changed -ExpectedStatus 409
        Assert-Wallet
    }
    Invoke-Case 'Customer cannot perform admin payment/result/account actions' {
        Require-State @('DepositId', 'DepositAccountId')
        $routes = @(
            @{ Method = 'GET'; Path = '/admin/dashboard' }, @{ Method = 'GET'; Path = '/admin/payment/deposit' },
            @{ Method = 'GET'; Path = '/admin/payment/withdrawal' }, @{ Method = 'GET'; Path = '/admin/accounts' },
            @{ Method = 'POST'; Path = '/admin/paymentaccount/deposit' },
            @{ Method = 'PATCH'; Path = "/admin/payment/deposit/$($state.DepositId)/status" },
            @{ Method = 'PATCH'; Path = "/admin/paymentaccount/deposit/$($state.DepositAccountId)/status" },
            @{ Method = 'POST'; Path = '/admin/master/payment-methods' }
        )
        foreach ($game in @('twod', 'threed')) {
            foreach ($suffix in @('odds', 'results', 'results/preview', 'results/settle', 'results/recalculate', 'results/cancel')) {
                $routes += @{ Method = 'POST'; Path = "/admin/$game/$suffix" }
            }
        }
        foreach ($route in $routes) {
            Invoke-Api $route.Method $route.Path $state.Customer.Access -Body $(if ($route.Method -ne 'GET') { @{ status = 'approved' } } else { $null }) -ExpectedStatus 403
        }
        Assert-Wallet
    }
    Invoke-Case 'Admin deposit approval credits exactly once' {
        Require-State @('DepositId')
        $queue = Invoke-Api GET "/admin/payment/deposit?paymentUserId=$($state.Customer.Id)&status=pending&perPage=100" $state.Admin.Access
        Assert-Pagination $queue.Json.data 1
        Assert-Equal $queue.Json.data.items[0].id $state.DepositId 'Pending deposit queue'
        $approved = Invoke-Api PATCH "/admin/payment/deposit/$($state.DepositId)/status" $state.Admin.Access -Body @{ status = 'approved' }
        $state.ExpectedAvailable += [decimal] 10000.50
        Add-Movement 'deposit' 'in' 10000.50 'transaction' $state.DepositId
        $state.DepositApproved = $true
        Assert-Equal $approved.Json.data.status 'approved' 'Approved deposit status'
        Assert-Equal $approved.Json.data.approverUserId $state.Admin.Id 'Deposit approver'
        Invoke-Api PATCH "/admin/payment/deposit/$($state.DepositId)/status" $state.Admin.Access -Body @{ status = 'approved' }
        $retry = Invoke-Api POST '/payment/deposit' $state.Customer.Access $state.DepositBody
        Assert-Equal $retry.Json.data.id $state.DepositId 'Reviewed deposit retry ID'
        Invoke-Api PATCH "/admin/payment/deposit/$($state.DepositId)/status" $state.Admin.Access -Body @{ status = 'rejected' } -ExpectedStatus 409
        Assert-Wallet
    }
    Invoke-Case 'Admin deposit rejection gives no credit and cannot later approve' {
        Require-State @('DepositApproved')
        $response = Invoke-Api POST '/payment/deposit' $state.Customer.Access (New-DepositBody '500.25')
        $state.RejectedDepositId = $response.Json.data.id; $state.Created.RejectedDepositId = $state.RejectedDepositId
        $path = "/admin/payment/deposit/$($state.RejectedDepositId)/status"
        $rejected = Invoke-Api PATCH $path $state.Admin.Access -Body @{ status = 'rejected' }
        Assert-Equal $rejected.Json.data.status 'rejected' 'Rejected deposit'
        Assert-Equal $rejected.Json.data.declinedUserId $state.Admin.Id 'Deposit rejecting admin'
        Invoke-Api PATCH $path $state.Admin.Access -Body @{ status = 'rejected' }
        Invoke-Api PATCH $path $state.Admin.Access -Body @{ status = 'approved' } -ExpectedStatus 409
        Assert-Wallet
    }
    Invoke-Case 'Withdrawal validation and insufficient funds reserve nothing' {
        Require-State @('WithdrawalAccountId', 'DepositApproved')
        foreach ($amount in @('0', '-1', '1.001')) {
            Assert-Validation POST '/payment/withdrawal' $state.Customer.Access (New-WithdrawalBody $amount) @('amount')
        }
        Invoke-Api POST '/payment/withdrawal' $state.Customer.Access (New-WithdrawalBody '999999999.00') -ExpectedStatus 400
        $mismatch = New-WithdrawalBody '1.00'; $mismatch.paymentMethodId = $state.DepositMethodId
        Invoke-Api POST '/payment/withdrawal' $state.Customer.Access $mismatch -ExpectedStatus 400
        Assert-Wallet
    }
    Invoke-Case 'Withdrawal reserves once, retries, and rejects changed reference payload' {
        Require-State @('WithdrawalAccountId', 'DepositApproved')
        $state.WithdrawalBody = New-WithdrawalBody
        $state.WithdrawalBody.status = 'approved'; $state.WithdrawalBody.requested_payment_user_id = $state.Other.Id
        $state.WithdrawalBody.approve_user_id = $state.Admin.Id
        $response = Invoke-Api POST '/payment/withdrawal' $state.Customer.Access $state.WithdrawalBody
        $state.WithdrawalId = $response.Json.data.id; $state.Created.WithdrawalId = $state.WithdrawalId
        $state.ExpectedAvailable -= [decimal] 1000.25; $state.ExpectedLocked += [decimal] 1000.25
        Add-Movement 'withdraw_lock' 'out' 1000.25 'withdraw_request' $state.WithdrawalId
        Assert-Equal $response.Json.data.status 'requested' 'Requested withdrawal status'
        Assert-Equal $response.Json.data.requestedPaymentUserId $state.Customer.Id 'Withdrawal owner'
        Assert-True ($null -eq $response.Json.data.approverUserId -and $null -eq $response.Json.data.transaction_id) 'Customer must not approve a withdrawal or attach a payment.'
        $retry = Invoke-Api POST '/payment/withdrawal' $state.Customer.Access $state.WithdrawalBody
        Assert-Equal $retry.Json.data.id $state.WithdrawalId 'Withdrawal retry ID'
        $changed = $state.WithdrawalBody.Clone(); $changed.amount = '1001.25'
        Invoke-Api POST '/payment/withdrawal' $state.Customer.Access $changed -ExpectedStatus 409
        Invoke-Api PATCH "/admin/payment/withdrawal/$($state.WithdrawalId)/status" $state.Customer.Access -Body @{ status = 'approved'; lastSixTranId = '123456' } -ExpectedStatus 403
        Assert-Wallet
    }
    Invoke-Case 'Admin withdrawal approval requires confirmation and does not debit twice' {
        Require-State @('WithdrawalId')
        $path = "/admin/payment/withdrawal/$($state.WithdrawalId)/status"
        Assert-Validation PATCH $path $state.Admin.Access @{ status = 'approved' } @('lastSixTranId')
        Assert-Wallet
        $queue = Invoke-Api GET "/admin/payment/withdrawal?paymentUserId=$($state.Customer.Id)&status=requested" $state.Admin.Access
        Assert-Pagination $queue.Json.data 1
        Assert-Equal $queue.Json.data.items[0].id $state.WithdrawalId 'Pending withdrawal queue'
        $approved = Invoke-Api PATCH $path $state.Admin.Access -Body @{ status = 'approved'; lastSixTranId = '098765' }
        $state.ExpectedLocked -= [decimal] 1000.25
        $state.WithdrawalTransactionId = $approved.Json.data.transaction_id
        Assert-Equal $approved.Json.data.status 'approved' 'Approved withdrawal status'
        Assert-Equal $approved.Json.data.approverUserId $state.Admin.Id 'Withdrawal approver'
        Require-Value $state.WithdrawalTransactionId 'withdrawal payment transaction'
        Invoke-Api PATCH $path $state.Admin.Access -Body @{ status = 'approved'; lastSixTranId = '098765' }
        $retry = Invoke-Api POST '/payment/withdrawal' $state.Customer.Access $state.WithdrawalBody
        Assert-Equal $retry.Json.data.id $state.WithdrawalId 'Completed withdrawal retry'
        Invoke-Api PATCH $path $state.Admin.Access -Body @{ status = 'declined' } -ExpectedStatus 409
        Assert-Wallet
    }
    Invoke-Case 'Declined withdrawal unlocks and refunds exactly once' {
        Require-State @('WithdrawalTransactionId')
        $response = Invoke-Api POST '/payment/withdrawal' $state.Customer.Access (New-WithdrawalBody '500.25')
        $state.DeclinedWithdrawalId = $response.Json.data.id; $state.Created.DeclinedWithdrawalId = $state.DeclinedWithdrawalId
        $state.ExpectedAvailable -= [decimal] 500.25; $state.ExpectedLocked += [decimal] 500.25
        Add-Movement 'withdraw_lock' 'out' 500.25 'withdraw_request' $state.DeclinedWithdrawalId
        Assert-Wallet
        $path = "/admin/payment/withdrawal/$($state.DeclinedWithdrawalId)/status"
        $declined = Invoke-Api PATCH $path $state.Admin.Access -Body @{ status = 'declined' }
        $state.ExpectedAvailable += [decimal] 500.25; $state.ExpectedLocked -= [decimal] 500.25
        Add-Movement 'withdraw_rejected' 'in' 500.25 'withdraw_request' $state.DeclinedWithdrawalId
        Assert-Equal $declined.Json.data.status 'declined' 'Declined withdrawal status'
        Assert-Equal $declined.Json.data.declinedUserId $state.Admin.Id 'Declining administrator'
        Invoke-Api PATCH $path $state.Admin.Access -Body @{ status = 'declined' }
        Invoke-Api PATCH $path $state.Admin.Access -Body @{ status = 'approved'; lastSixTranId = '123456' } -ExpectedStatus 409
        Assert-Wallet
    }
    Invoke-Case 'Customer deposit/withdrawal histories and other-customer isolation' {
        Require-State @('RejectedDepositId', 'WithdrawalTransactionId', 'DeclinedWithdrawalId')
        $deposits = Invoke-Api GET '/payment/deposit/history' $state.Customer.Access
        Assert-Equal @($deposits.Json.data).Count 2 'Deposit history has both reviews'
        $approved = @($deposits.Json.data | Where-Object { $_.id -eq $state.DepositId })
        Assert-Equal $approved[0].status 'approved' 'Deposit history approval'
        $withdrawals = Invoke-Api GET '/payment/withdrawal/history' $state.Customer.Access
        Assert-Equal @($withdrawals.Json.data).Count 1 'Only approved withdrawal creates a payment'
        Assert-Equal $withdrawals.Json.data[0].id $state.WithdrawalTransactionId 'Approved payment transaction ID'
        Assert-Equal $withdrawals.Json.data[0].lastSixTranId '098765' 'Withdrawal confirmation suffix'
        $requests = Invoke-Api GET '/payment/withdrawal/request/history' $state.Customer.Access
        Assert-Equal @($requests.Json.data).Count 2 'Withdrawal request history'
        Assert-True (@($requests.Json.data.status) -contains 'approved') 'Missing approved request.'
        Assert-True (@($requests.Json.data.status) -contains 'declined') 'Missing declined request.'
        foreach ($path in @('/payment/deposit/history', '/payment/withdrawal/history', '/payment/withdrawal/request/history')) {
            $other = Invoke-Api GET "$($path)?paymentUserId=$($state.Customer.Id)" $state.Other.Access
            Assert-Equal @($other.Json.data).Count 0 'Other customer cannot read payment history'
        }
    }

    Invoke-BettorCases 'twod' '05' '99' 85 90 'am' $DrawDate 'pm'
    Invoke-BettorCases 'threed' '007' '999' 550 600 'pm' $state.NextDate 'pm'

    Invoke-Case 'Complete wallet ledger reconciles balances, references, and retry counts' {
        Require-State @('DepositApproved')
        $all = [Collections.Generic.List[object]]::new()
        $page = 1
        do {
            $response = Invoke-Api GET "/wallet/transactions?perPage=100&page=$page" $state.Customer.Access
            Assert-Pagination $response.Json.data $state.Movements.Count
            foreach ($movement in $response.Json.data.items) { $all.Add($movement) }
            $page++
        } while ($page -le $response.Json.data.pagination.lastPage)
        Assert-Equal $all.Count $state.Movements.Count 'Exactly the expected number of ledger rows'
        Assert-Equal @($all.id | Select-Object -Unique).Count $all.Count 'Unique ledger IDs'
        $net = [decimal] 0
        foreach ($expected in $state.Movements) {
            $matches = @($all | Where-Object { $_.type -eq $expected.Type -and $_.referenceType -eq $expected.ReferenceType -and $_.referenceId -eq $expected.ReferenceId })
            Assert-Equal $matches.Count 1 "Exactly one $($expected.Type) movement for $($expected.ReferenceId)"
            $movement = $matches[0]
            Assert-Equal $movement.direction $expected.Direction 'Ledger movement direction'
            Assert-Money $movement.amount $expected.Amount 'Ledger movement amount'
            $delta = if ($movement.direction -eq 'in') { $expected.Amount } else { -$expected.Amount }
            $before = [decimal]::Parse([string] $movement.balanceBefore, [Globalization.CultureInfo]::InvariantCulture)
            Assert-Money $movement.balanceAfter ($before + $delta) 'Ledger balance arithmetic'
            $net += $delta
        }
        Assert-Money $net $state.ExpectedAvailable 'Ledger net matches independent expected balance'
        Assert-Wallet
        $other = Invoke-Api GET "/wallet/transactions?paymentUserId=$($state.Customer.Id)" $state.Other.Access
        Assert-Pagination $other.Json.data 0
        Assert-Validation GET '/wallet/transactions?perPage=101' $state.Customer.Access $null @('perPage')
        Assert-Validation GET '/wallet/transactions?fromDate=2026-10-22&toDate=2026-10-21' $state.Customer.Access $null @('toDate')
    }
    Invoke-Case 'Admin wallet history contains every payment request and ledger entry with independent pages' {
        Require-State @('WithdrawalTransactionId', 'DeclinedWithdrawalId', 'ManualCreditId')
        $path = "/admin/accounts/$($state.Customer.Id)/wallet"
        $history = Invoke-Api GET "$path/history?perPage=100" $state.Admin.Access
        Assert-Pagination $history.Json.data.transactions 3
        Assert-Pagination $history.Json.data.withdrawalRequests 2
        Assert-Pagination $history.Json.data.walletTransactions $state.Movements.Count
        Assert-Money $history.Json.data.wallet.avaliableBalance $state.ExpectedAvailable 'Admin wallet history current balance'
        Assert-True (@($history.Json.data.transactions.items.id) -contains $state.RejectedDepositId) 'Rejected deposit is missing from wallet history.'
        Assert-True (@($history.Json.data.withdrawalRequests.items.id) -contains $state.DeclinedWithdrawalId) 'Declined withdrawal is missing from wallet history.'
        foreach ($entry in $history.Json.data.walletTransactions.items) {
            Assert-Equal $entry.walletId $history.Json.data.wallet.id 'Ledger belongs to the selected wallet'
        }
        $paged = Invoke-Api GET "$path/history?perPage=1&transactionsPage=2&withdrawalRequestsPage=2&walletTransactionsPage=2" $state.Admin.Access
        foreach ($key in @('transactions', 'withdrawalRequests', 'walletTransactions')) {
            Assert-Equal $paged.Json.data.$key.pagination.currentPage 2 "Independent page for $key"
            Assert-Equal @($paged.Json.data.$key.items).Count 1 "Independent page size for $key"
        }
        $filtered = Invoke-Api GET "$path/history?transactionType=deposit&transactionStatus=rejected&withdrawalStatus=declined&movementType=manual_adjustment&direction=in" $state.Admin.Access
        Assert-Pagination $filtered.Json.data.transactions 1
        Assert-Pagination $filtered.Json.data.withdrawalRequests 1
        Assert-Pagination $filtered.Json.data.walletTransactions 1
        Assert-Equal $filtered.Json.data.walletTransactions.items[0].id $state.ManualCreditId 'Filtered manual credit'
        $other = Invoke-Api GET "/admin/accounts/$($state.Other.Id)/wallet/history?paymentUserId=$($state.Customer.Id)" $state.Admin.Access
        foreach ($key in @('transactions', 'withdrawalRequests', 'walletTransactions')) {
            Assert-Pagination $other.Json.data.$key 0
        }
        Invoke-Api GET "$path/history" $state.Customer.Access -ExpectedStatus 403
        Invoke-Api GET "$path/history" -ExpectedStatus 401
        Assert-Validation GET "$path/history?perPage=101&transactionsPage=0" $state.Admin.Access $null @('perPage', 'transactionsPage')
    }
    Invoke-Case 'Admin wallet detail traces deposits withdrawals and manual adjustments without cross-customer access' {
        Require-State @('WithdrawalTransactionId', 'DeclinedWithdrawalId', 'ManualCreditId')
        $path = "/admin/accounts/$($state.Customer.Id)/wallet"
        $deposit = Invoke-Api GET "$path/transactions/$($state.DepositId)" $state.Admin.Access
        Assert-Equal $deposit.Json.data.transaction.status 'approved' 'Deposit detail status'
        Assert-Equal @($deposit.Json.data.walletTransactions).Count 1 'Deposit has one credit'
        $depositEntry = $deposit.Json.data.walletTransactions[0]
        $ledger = Invoke-Api GET "$path/wallet-transactions/$($depositEntry.id)" $state.Admin.Access
        Assert-Equal $ledger.Json.data.transaction.id $state.DepositId 'Ledger links to deposit transaction'
        $withdrawal = Invoke-Api GET "$path/withdrawal-requests/$($state.WithdrawalId)" $state.Admin.Access
        Assert-Equal $withdrawal.Json.data.transaction.id $state.WithdrawalTransactionId 'Approved request links to payment'
        Assert-Equal @($withdrawal.Json.data.walletTransactions).Count 1 'Approved withdrawal has one reservation'
        $transaction = Invoke-Api GET "$path/transactions/$($state.WithdrawalTransactionId)" $state.Admin.Access
        Assert-Equal $transaction.Json.data.withdrawalRequests[0].id $state.WithdrawalId 'Payment links back to request'
        Assert-Equal @($transaction.Json.data.walletTransactions).Count 1 'Payment traces reservation without another debit'
        $declined = Invoke-Api GET "$path/withdrawal-requests/$($state.DeclinedWithdrawalId)" $state.Admin.Access
        Assert-True ($null -eq $declined.Json.data.transaction) 'Declined request must not have a completed payment.'
        Assert-Equal @($declined.Json.data.walletTransactions).Count 2 'Declined withdrawal traces reservation and refund'
        foreach ($entry in $declined.Json.data.walletTransactions) {
            $trace = Invoke-Api GET "$path/wallet-transactions/$($entry.id)" $state.Admin.Access
            Assert-Equal $trace.Json.data.withdrawalRequest.id $state.DeclinedWithdrawalId 'Ledger links to declined request'
        }
        $manual = Invoke-Api GET "$path/wallet-transactions/$($state.ManualCreditId)" $state.Admin.Access
        Assert-Equal $manual.Json.data.walletTransaction.actorUserId $state.Admin.Id 'Manual adjustment detail actor'
        Assert-Equal $manual.Json.data.walletTransaction.clientReference $state.ManualCreditReference 'Manual adjustment retry reference'
        Assert-Money $manual.Json.data.walletTransaction.balanceBefore 0 'Manual adjustment prior balance'
        Assert-Money $manual.Json.data.walletTransaction.balanceAfter 250.75 'Manual adjustment resulting balance'
        $otherPath = "/admin/accounts/$($state.Other.Id)/wallet"
        foreach ($suffix in @("transactions/$($state.DepositId)", "withdrawal-requests/$($state.WithdrawalId)", "wallet-transactions/$($state.ManualCreditId)")) {
            Invoke-Api GET "$otherPath/$suffix" $state.Admin.Access -ExpectedStatus 404
            Invoke-Api GET "$path/$suffix" $state.Customer.Access -ExpectedStatus 403
        }
    }
    Invoke-Case 'Delete customer destination; hide it and reject future withdrawals' {
        Require-State @('WithdrawalAccountId')
        Invoke-Api DELETE "/paymentaccount/withdrawal/$($state.WithdrawalAccountId)" $state.Customer.Access
        foreach ($path in @('/paymentaccount/withdrawal', '/paymentmethod/withdrawal')) {
            $response = Invoke-Api GET $path $state.Customer.Access
            Assert-Equal @($response.Json.data).Count 0 'Deleted account is hidden'
        }
        Invoke-Api POST '/payment/withdrawal' $state.Customer.Access (New-WithdrawalBody '1.00') -ExpectedStatus 400
        Assert-Wallet
    }
    Invoke-Case 'Email change requires password, keeps uniqueness, and revokes sessions' {
        Require-Value $state.Customer.Access 'customer login'
        $newEmail = "curl.changed.$runId@example.test"
        Assert-Validation PATCH '/profile' $state.Customer.Access @{ email = $newEmail } @('currentPassword')
        Assert-Validation PATCH '/profile' $state.Customer.Access @{ email = $state.Other.Email; currentPassword = $CustomerPassword } @('email')
        Invoke-Api PATCH '/profile' $state.Customer.Access -Body @{ email = $newEmail; currentPassword = 'wrong-password' } -ExpectedStatus 400
        $oldAccess = $state.Customer.Access; $oldRefresh = $state.Customer.Refresh; $oldEmail = $state.Customer.Email
        $updated = Invoke-Api PATCH '/profile' $oldAccess -Body @{ email = $newEmail; currentPassword = $CustomerPassword }
        $state.Customer.Email = $newEmail
        Assert-Equal $updated.Json.data.email $newEmail 'Changed profile email'
        Invoke-Api GET '/profile' $oldAccess -ExpectedStatus 401
        Invoke-Api POST '/auth/refresh' $oldRefresh -ExpectedStatus 401
        Invoke-Api POST '/auth/login' -Body @{ email = $oldEmail; password = $CustomerPassword } -ExpectedStatus 401
        Set-Login $state.Customer $CustomerPassword
        Assert-Wallet
    }
    Invoke-Case 'Password change checks old password/confirmation and revokes all sessions' {
        Require-Value $state.Customer.Access 'customer login'
        $newPassword = 'Changed!' + [guid]::NewGuid().ToString('N'); $script:Secrets.Add($newPassword)
        Assert-Validation POST '/profile/password' $state.Customer.Access @{
            currentPassword = $CustomerPassword; password = $newPassword; password_confirmation = 'mismatch'
        } @('password')
        Invoke-Api POST '/profile/password' $state.Customer.Access -Body @{
            currentPassword = 'wrong-password'; password = $newPassword; password_confirmation = $newPassword
        } -ExpectedStatus 400
        $oldAccess = $state.Customer.Access; $oldRefresh = $state.Customer.Refresh
        # A second device/session must also be revoked by a password change.
        $secondSession = @{ Email = $state.Customer.Email }
        Set-Login $secondSession $CustomerPassword
        Invoke-Api POST '/profile/password' $oldAccess -Body @{
            currentPassword = $CustomerPassword; password = $newPassword; password_confirmation = $newPassword
        }
        $state.CurrentPassword = $newPassword
        Invoke-Api GET '/profile' $oldAccess -ExpectedStatus 401
        Invoke-Api GET '/profile' $secondSession.Access -ExpectedStatus 401
        Invoke-Api POST '/auth/refresh' $oldRefresh -ExpectedStatus 401
        Invoke-Api POST '/auth/login' -Body @{ email = $state.Customer.Email; password = $CustomerPassword } -ExpectedStatus 401
        Set-Login $state.Customer $state.CurrentPassword
        Assert-Wallet
    }
    Invoke-Case 'Recovery validates input and invalid tokens cannot change a password' {
        Require-Value $state.Customer.Id 'customer registration'
        Assert-Validation POST '/auth/forgot-password' '' @{ email = 'bad-email' } @('email')
        Assert-Validation POST '/auth/reset-password' '' @{} @('email', 'token', 'password')
        $password = 'InvalidReset!' + [guid]::NewGuid().ToString('N'); $script:Secrets.Add($password)
        Invoke-Api POST '/auth/reset-password' -Body @{
            email = $state.Customer.Email; token = ('0' * 64); password = $password; password_confirmation = $password
        } -ExpectedStatus 400
        Assert-Wallet
    }
    Invoke-Case 'Forgot-password gives the same response for known and unknown emails' {
        Require-Value $state.Customer.Id 'customer registration'
        $state.RecoveryRequestedAt = [DateTime]::UtcNow
        $state.RecoveryLogOffset = [long] 0
        if ($RecoveryMailLogPath -and (Test-Path -LiteralPath $RecoveryMailLogPath)) {
            $state.RecoveryLogOffset = (Get-Item -LiteralPath $RecoveryMailLogPath).Length
        }
        $known = Invoke-Api POST '/auth/forgot-password' -Body @{ email = $state.Customer.Email }
        $unknown = Invoke-Api POST '/auth/forgot-password' -Body @{ email = "curl.unknown.$runId@example.test" }
        Assert-Equal ($known.Json | ConvertTo-Json -Compress -Depth 10) ($unknown.Json | ConvertTo-Json -Compress -Depth 10) 'Recovery must not disclose account existence'
        $state.RecoverySent = $true
    }
    Invoke-Case 'Successful mailbox password reset is single-use and revokes sessions' {
        if (-not $ResetTokenProvider -and -not $RecoveryMailLogPath) {
            throw 'SKIP: Supply -RecoveryMailLogPath with MAIL_MAILER=log, or -ResetTokenProvider for your test mailbox.'
        }
        Require-State @('RecoverySent', 'CurrentPassword')
        $token = Get-RecoveryToken $state.Customer.Email $state.RecoveryRequestedAt $state.RecoveryLogOffset
        $script:Secrets.Add($token)
        $password = 'Recovered!' + [guid]::NewGuid().ToString('N'); $script:Secrets.Add($password)
        $oldAccess = $state.Customer.Access; $oldRefresh = $state.Customer.Refresh; $oldPassword = $state.CurrentPassword
        $body = @{ email = $state.Customer.Email; token = $token; password = $password; password_confirmation = $password }
        Invoke-Api POST '/auth/reset-password' -Body $body
        $state.CurrentPassword = $password
        Invoke-Api POST '/auth/reset-password' -Body $body -ExpectedStatus 400
        Invoke-Api GET '/profile' $oldAccess -ExpectedStatus 401
        Invoke-Api POST '/auth/refresh' $oldRefresh -ExpectedStatus 401
        Invoke-Api POST '/auth/login' -Body @{ email = $state.Customer.Email; password = $oldPassword } -ExpectedStatus 401
        Set-Login $state.Customer $password
        Assert-Wallet
    }
    Invoke-Case 'Logout revokes the current pair and preserves another device session' {
        Require-Value $state.Customer.Access 'customer login'
        Require-State @('CurrentPassword')
        $otherDevice = @{ Email = $state.Customer.Email }
        Set-Login $otherDevice $state.CurrentPassword
        $oldAccess = $state.Customer.Access; $oldRefresh = $state.Customer.Refresh
        Invoke-Api POST '/auth/logout' $oldAccess
        Invoke-Api GET '/profile' $oldAccess -ExpectedStatus 401
        Invoke-Api POST '/auth/refresh' $oldRefresh -ExpectedStatus 401
        $profile = Invoke-Api GET '/profile' $otherDevice.Access
        Assert-Equal $profile.Json.data.id $state.Customer.Id 'Other device remains authenticated'
        $state.Customer.Access = $otherDevice.Access; $state.Customer.Refresh = $otherDevice.Refresh
        Assert-Wallet
    }
} catch {
    $script:Cases.Add([pscustomobject]@{ Name = 'Suite stopped'; Result = 'BLOCKED'; Assertions = 0; DurationMs = 0; Detail = (Protect-Text $_.Exception.Message) })
    Write-Host (Protect-Text $_.Exception.Message) -ForegroundColor Red
} finally {
    foreach ($game in @($state.LastOdds.Keys)) {
        if ($state.OriginalOdds.ContainsKey($game)) {
            Invoke-Case "Cleanup: restore prior $game odds by adding a settings row" {
                $current = Invoke-Api GET "/$game/odds" $state.Admin.Access
                Assert-Equal $current.Json.data.id $state.LastOdds[$game] 'Odds changed by another client; refusing to overwrite their setting'
                $old = $state.OriginalOdds[$game]
                Invoke-Api POST "/admin/$game/odds" $state.Admin.Access -Body @{
                    name = "Restored after curl $runId"; oddNumber = $old.oddNumber; payoutMode = $old.payoutMode
                }
                $restored = Invoke-Api GET "/$game/odds" $state.Admin.Access
                Assert-Money $restored.Json.data.oddNumber ([decimal] $old.oddNumber) 'Restored odds rate'
                Assert-Equal $restored.Json.data.payoutMode $old.payoutMode 'Restored payout mode'
            }
        }
    }
    if ($state.ContainsKey('DepositAccountId')) {
        Invoke-Case 'Cleanup: deactivate the test receiving account' {
            Invoke-Api PATCH "/admin/paymentaccount/deposit/$($state.DepositAccountId)/status" $state.Admin.Access -Body @{ status = 'inactive' }
        }
    }
    foreach ($key in @('DepositMethodId', 'WithdrawalMethodId')) {
        if ($state.ContainsKey($key)) {
            Invoke-Case "Cleanup: deactivate $key" {
                Invoke-Api PATCH "/admin/master/payment-methods/$($state[$key])" $state.Admin.Access -Body @{ status = 'inactive' }
            }
        }
    }
    foreach ($entry in @(@{ Key = 'WithdrawalAccountId'; User = $state.Customer }, @{ Key = 'OtherAccountId'; User = $state.Other })) {
        if ($state.ContainsKey($entry.Key) -and $entry.User.Access) {
            Invoke-Case "Cleanup: delete test destination $($entry.Key)" {
                Invoke-Api DELETE "/paymentaccount/withdrawal/$($state[$entry.Key])" $entry.User.Access
            }
        }
    }
    foreach ($user in @($state.Customer, $state.Other, $state.Admin)) {
        if ($user.Access) {
            Invoke-Case "Cleanup: revoke test session for $($user.Email)" {
                Invoke-Api POST '/auth/logout' $user.Access
                $user.Access = ''; $user.Refresh = ''
            }
        }
    }

    $failed = @($script:Cases | Where-Object { $_.Result -in @('FAIL', 'BLOCKED') }).Count
    $skipped = @($script:Cases | Where-Object { $_.Result -eq 'SKIP' }).Count
    $passed = @($script:Cases | Where-Object { $_.Result -eq 'PASS' }).Count
    $exitCode = if ($failed -gt 0) { 1 } elseif ($skipped -gt 0) { 2 } else { 0 }
    $reportDirectory = Join-Path $OutputDirectory $runId
    try {
        [void] [IO.Directory]::CreateDirectory($reportDirectory)
        $report = [ordered]@{
            RunId = $runId; Target = $state.Target; DrawDate = $DrawDate
            StartedAtUtc = $script:StartedAt.ToString('o'); FinishedAtUtc = [DateTime]::UtcNow.ToString('o')
            Summary = @{ Passed = $passed; FailedOrBlocked = $failed; Skipped = $skipped; Assertions = $script:AssertionCount
                Requests = $script:Requests.Count; ExitCode = $exitCode }
            Customers = @(@{ Id = $state.Customer.Id; Email = $state.Customer.Email }, @{ Id = $state.Other.Id; Email = $state.Other.Email })
            Created = $state.Created
            BetFixtures = @($state.Games.Keys | ForEach-Object {
                $g = $state.Games[$_]
                @{ Game = $_; BatchId = $(if ($g.ContainsKey('Batch')) { $g.Batch.batchId } else { $null })
                    DrawId = $g['DrawId']; CancellationBatchId = $(if ($g.ContainsKey('CancelBatch')) { $g.CancelBatch.batchId } else { $null }) }
            })
            Notes = @('Test customers and financial/bet history remain on target.',
                'Prior odds restored through new rows when originally configured; initially unconfigured games retain test odds.',
                'Reports contain no passwords, bearer tokens, or raw response/request bodies.',
                'This is sequential API regression evidence; it does not verify concurrent locking or real provider transfers.')
            Cases = @($script:Cases.ToArray()); Requests = @($script:Requests.ToArray())
            Coverage = @($script:Requests | Group-Object Method, Path | ForEach-Object {
                @{ Method = $_.Group[0].Method; Path = $_.Group[0].Path; Requests = $_.Count }
            })
        }
        [IO.File]::WriteAllText((Join-Path $reportDirectory 'results.json'), (ConvertTo-Json -InputObject $report -Depth 30), $script:Utf8)
        $script:Cases | Export-Csv -LiteralPath (Join-Path $reportDirectory 'cases.csv') -NoTypeInformation -Encoding UTF8
        $script:Requests | Export-Csv -LiteralPath (Join-Path $reportDirectory 'requests.csv') -NoTypeInformation -Encoding UTF8
        $xml = [Xml.XmlDocument]::new()
        [void] $xml.AppendChild($xml.CreateXmlDeclaration('1.0', 'UTF-8', $null))
        $suite = $xml.CreateElement('testsuite'); [void] $xml.AppendChild($suite)
        $suite.SetAttribute('name', 'Customer API curl regression'); $suite.SetAttribute('tests', [string] $script:Cases.Count)
        $suite.SetAttribute('failures', [string] $failed); $suite.SetAttribute('skipped', [string] $skipped)
        foreach ($case in $script:Cases) {
            $test = $xml.CreateElement('testcase'); $test.SetAttribute('name', $case.Name)
            $test.SetAttribute('classname', 'CustomerApi'); $test.SetAttribute('time', ($case.DurationMs / 1000.0).ToString('0.000', [Globalization.CultureInfo]::InvariantCulture))
            [void] $suite.AppendChild($test)
            if ($case.Result -ne 'PASS') {
                $tag = if ($case.Result -eq 'SKIP') { 'skipped' } else { 'failure' }
                $failure = $xml.CreateElement($tag); $failure.SetAttribute('message', $case.Detail); $failure.SetAttribute('type', $case.Result)
                [void] $test.AppendChild($failure)
            }
        }
        $xml.Save((Join-Path $reportDirectory 'junit.xml'))
        Write-Host "Reports: $reportDirectory" -ForegroundColor Cyan
    } catch {
        $exitCode = 1
        Write-Host ("Could not save complete reports: " + (Protect-Text $_.Exception.Message)) -ForegroundColor Red
    }
    Write-Host "Passed: $passed | Failed/blocked: $failed | Skipped: $skipped | Assertions: $script:AssertionCount | HTTP requests: $($script:Requests.Count) | Exit: $exitCode" -ForegroundColor Cyan
}
exit $exitCode
