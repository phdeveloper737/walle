param([ValidateSet('capture', 'search')][string]$Mode = 'search')

$ErrorActionPreference = 'Stop'
[Console]::InputEncoding = New-Object System.Text.UTF8Encoding($false)
[Console]::OutputEncoding = New-Object System.Text.UTF8Encoding($false)

function Get-RagMessages([string]$Transcript, [string]$ProjectRoot, [string]$ConfigRoot) {
    $encodedProject = $ProjectRoot -replace '[^a-zA-Z0-9]', '-'
    $allowed = [IO.Path]::GetFullPath((Join-Path $ConfigRoot "projects/$encodedProject"))
    if ($Transcript.StartsWith('~')) { $Transcript = Join-Path $env:USERPROFILE $Transcript.Substring(2) }
    $path = [IO.Path]::GetFullPath($Transcript)
    if (-not $path.StartsWith($allowed + [IO.Path]::DirectorySeparatorChar, [StringComparison]::OrdinalIgnoreCase) -or [IO.Path]::GetExtension($path) -ne '.jsonl') { throw 'Transcript fora do projeto.' }
    if (-not (Test-Path -LiteralPath $path -PathType Leaf)) { return ,@() }
    $parent = $path
    while ($parent.Length -ge $allowed.Length) {
        if ((Get-Item -LiteralPath $parent -Force).Attributes -band [IO.FileAttributes]::ReparsePoint) { throw 'Link de transcript recusado.' }
        $parent = Split-Path -Parent $parent
    }
    $messages = New-Object 'System.Collections.Generic.List[string]'
    $reader = New-Object IO.StreamReader($path, [Text.Encoding]::UTF8)
    $characters = 0
    try {
        while (-not $reader.EndOfStream) {
            $line = $reader.ReadLine()
            if ($line -notmatch '"type"\s*:\s*"assistant"') { continue }
            try { $entry = $line | ConvertFrom-Json } catch { continue }
            if ($entry.type -ne 'assistant' -and $entry.message.role -ne 'assistant') { continue }
            $parts = @()
            foreach ($block in $entry.message.content) {
                if ($block.type -eq 'text' -and $block.text) { $parts += [string]$block.text }
                if ($block.type -eq 'tool_use' -and $block.name -eq 'SubagentHandback' -and $block.input.message) { $parts += [string]$block.input.message }
            }
            $text = ($parts -join "`n")
            if (-not $text) { continue }
            if ($text.Length -gt 24000) { $text = $text.Substring(0, 24000) }
            $messages.Add($text)
            $characters += $text.Length
            while ($characters -gt 120000 -and $messages.Count -gt 1) { $characters -= $messages[0].Length; $messages.RemoveAt(0) }
        }
    } finally { $reader.Dispose() }
    return ,$messages.ToArray()
}

function Merge-RagMessages([string[]]$Messages, [string]$LastMessage) {
    $result = @($Messages)
    if ($LastMessage.Length -gt 24000) { $LastMessage = $LastMessage.Substring(0, 24000) }
    if ($LastMessage -and $result -notcontains $LastMessage) {
        $result += $LastMessage
    }
    while ((($result | ForEach-Object Length | Measure-Object -Sum).Sum) -gt 120000 -and $result.Count -gt 1) { $result = @($result | Select-Object -Skip 1) }
    return ,$result
}

if ($MyInvocation.InvocationName -eq '.') { return }

try {
    $raw = [Console]::In.ReadToEnd()
    if ($raw.Length -gt 524288) { exit 0 }
    $inputEvent = $raw | ConvertFrom-Json
    $root = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../..')).TrimEnd('\', '/')
    if (-not $inputEvent.cwd) { exit 0 }
    if (-not [IO.Path]::GetFullPath([string]$inputEvent.cwd).TrimEnd('\', '/').Equals($root, [StringComparison]::OrdinalIgnoreCase)) { exit 0 }
    $settings = Get-Content -LiteralPath (Join-Path $root '.claude/settings.json') -Raw | ConvertFrom-Json
    $agent = if ($inputEvent.agent_type) { [string]$inputEvent.agent_type } elseif ($inputEvent.agent) { [string]$inputEvent.agent } else { [string]$settings.agent }
    if (-not $agent) { $agent = 'walle-orquestrador' }
    if ($Mode -eq 'search') {
        if ($inputEvent.hook_event_name -ne 'UserPromptSubmit' -or -not $inputEvent.prompt) { exit 0 }
        $payload = @{ question = [string]$inputEvent.prompt; agent = $agent }
    } else {
        if ($inputEvent.stop_hook_active -eq $true -or $inputEvent.hook_event_name -notin @('Stop', 'SubagentStop')) { exit 0 }
        $transcript = if ($inputEvent.hook_event_name -eq 'SubagentStop') { [string]$inputEvent.agent_transcript_path } else { [string]$inputEvent.transcript_path }
        $messages = @()
        $revision = [DateTimeOffset]::UtcNow.ToUnixTimeMilliseconds()
        if ($transcript) {
            $configRoot = if ($env:CLAUDE_CONFIG_DIR) { $env:CLAUDE_CONFIG_DIR } else { Join-Path $env:USERPROFILE '.claude' }
            $messages = Get-RagMessages $transcript $root $configRoot
            if (Test-Path -LiteralPath $transcript -PathType Leaf) {
                $revision = [Math]::Max($revision, ([DateTimeOffset](Get-Item -LiteralPath $transcript).LastWriteTimeUtc).ToUnixTimeMilliseconds())
            }
        }
        $messages = Merge-RagMessages $messages ([string]$inputEvent.last_assistant_message)
        if (-not $messages.Count) { exit 0 }
        $payload = @{ messages = @($messages); agent = $agent; sessionId = [string]$inputEvent.session_id; agentId = [string]$inputEvent.agent_id; event = [string]$inputEvent.hook_event_name; revision = $revision }
    }
    $info = New-Object Diagnostics.ProcessStartInfo
    $info.FileName = 'docker'
    $info.Arguments = "exec -i walle-rag node /app/hook.ts $Mode"
    $info.UseShellExecute = $false
    $info.CreateNoWindow = $true
    $info.RedirectStandardInput = $true
    $info.RedirectStandardOutput = $true
    $info.RedirectStandardError = $true
    $utf8 = New-Object Text.UTF8Encoding($false)
    $info.StandardOutputEncoding = $utf8
    $process = New-Object Diagnostics.Process
    $process.StartInfo = $info
    $null = $process.Start()
    $stdout = $process.StandardOutput.ReadToEndAsync()
    $stderr = $process.StandardError.ReadToEndAsync()
    $process.StandardInput.Write(($payload | ConvertTo-Json -Depth 8 -Compress))
    $process.StandardInput.Close()
    if (-not $process.WaitForExit(12000)) { $process.Kill(); exit 0 }
    if ($Mode -eq 'search' -and $process.ExitCode -eq 0) { [Console]::Out.Write($stdout.GetAwaiter().GetResult()) }
    $process.Dispose()
} catch {
    [Console]::Error.WriteLine('[rag] Memoria indisponivel; a sessao continua.')
}
exit 0
