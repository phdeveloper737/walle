$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot '../../hooks/rag.ps1')

function Assert-Rag([bool]$Condition, [string]$Message) {
    if (-not $Condition) { throw $Message }
}

$tempBase = [IO.Path]::GetFullPath([IO.Path]::GetTempPath()).TrimEnd('\', '/')
$testRoot = Join-Path $tempBase ('walle-rag-test-' + [guid]::NewGuid().ToString('N'))
$project = Join-Path $testRoot 'projeto'
$account = Join-Path $testRoot 'conta'
$encoded = $project -replace '[^a-zA-Z0-9]', '-'
$allowed = Join-Path $account "projects/$encoded"
$utf8 = New-Object Text.UTF8Encoding($false)
$null = New-Item -ItemType Directory -Path $allowed -Force
try {
    $file = Join-Path $allowed 'sessao.jsonl'
    $unicode = 'Decis' + [char]0xe3 + 'o local do agente.'
    $lines = @(
        (@{ type = 'user'; message = @{ content = @(@{ type = 'text'; text = 'DADO DO USUARIO' }) } } | ConvertTo-Json -Depth 7 -Compress),
        (@{ type = 'assistant'; message = @{ content = @(@{ type = 'text'; text = $unicode }, @{ type = 'thinking'; thinking = 'PENSAMENTO PRIVADO' }) } } | ConvertTo-Json -Depth 7 -Compress),
        (@{ type = 'assistant'; message = @{ content = @(@{ type = 'tool_use'; name = 'Bash'; input = @{ command = 'SEGREDO DA FERRAMENTA' } }, @{ type = 'tool_use'; name = 'SubagentHandback'; input = @{ message = 'Relatorio especifico do agente.' } }) } } | ConvertTo-Json -Depth 7 -Compress),
        '{json-incompleto'
    )
    [IO.File]::WriteAllLines($file, $lines, $utf8)
    $messages = Get-RagMessages $file $project $account
    Assert-Rag ($messages.Count -eq 2) 'Deveria extrair somente duas mensagens.'
    Assert-Rag ($messages[0] -ceq $unicode) 'UTF-8 nao preservado.'
    Assert-Rag (($messages -join ' ') -notmatch 'DADO DO USUARIO|PENSAMENTO PRIVADO|SEGREDO DA FERRAMENTA') 'Conteudo nao textual vazou.'

    $null = New-Item -ItemType Directory -Path (Join-Path $allowed 'sessao/subagents') -Force
    $agentFile = Join-Path $allowed 'sessao/subagents/agent-a1.jsonl'
    [IO.File]::WriteAllText($agentFile, (@{ type = 'assistant'; message = @{ content = @(@{ type = 'text'; text = 'SO O AGENTE' }) } } | ConvertTo-Json -Depth 6 -Compress), $utf8)
    $agentMessages = Get-RagMessages $agentFile $project $account
    Assert-Rag ($agentMessages.Count -eq 1 -and $agentMessages[0] -eq 'SO O AGENTE') 'Transcript do agente misturou a sessao.'

    $blocked = $false
    try { $null = Get-RagMessages (Join-Path $testRoot 'fora.jsonl') $project $account } catch { $blocked = $true }
    Assert-Rag $blocked 'Arquivo fora do projeto deveria ser recusado.'
    $blocked = $false
    try { $null = Get-RagMessages (Join-Path $allowed '.env') $project $account } catch { $blocked = $true }
    Assert-Rag $blocked 'Arquivo que nao e transcript deveria ser recusado.'

    $large = @()
    foreach ($number in 1..12) {
        $large += (@{ type = 'assistant'; message = @{ content = @(@{ type = 'text'; text = (($number.ToString() + ':') + ('a' * 23980)) }) } } | ConvertTo-Json -Depth 6 -Compress)
    }
    [IO.File]::WriteAllLines($file, $large, $utf8)
    $recent = Get-RagMessages $file $project $account
    Assert-Rag ((($recent | ForEach-Object Length | Measure-Object -Sum).Sum) -le 120000) 'Limite de memoria excedido.'
    Assert-Rag ($recent[-1].StartsWith('12:')) 'Perdeu a mensagem mais recente.'
    $merged = Merge-RagMessages @('Anterior') 'Resposta final ainda nao gravada no transcript.'
    Assert-Rag ($merged.Count -eq 2 -and $merged[-1] -eq 'Resposta final ainda nao gravada no transcript.') 'Perdeu a mensagem final do evento.'
    $merged = Merge-RagMessages @('Resposta final') 'Resposta final'
    Assert-Rag ($merged.Count -eq 1) 'Duplicou a mensagem final.'
    $merged = Merge-RagMessages @(('x' * 24000)) ('x' * 25000)
    Assert-Rag ($merged.Count -eq 1) 'Duplicou uma resposta truncada.'
    $missing = Get-RagMessages (Join-Path $allowed 'ainda-nao-gravado.jsonl') $project $account
    $merged = Merge-RagMessages $missing 'Resposta final recebida no evento.'
    Assert-Rag ($merged.Count -eq 1 -and $merged[0] -eq 'Resposta final recebida no evento.') 'Perdeu a resposta quando o transcript ainda nao existe.'
    'OK: wrapper Windows - UTF-8, textos de assistente, Handback, isolamento, caminhos, limite e mensagem final sem duplicacao.'
} finally {
    $resolved = [IO.Path]::GetFullPath($testRoot)
    if (-not $resolved.StartsWith($tempBase + [IO.Path]::DirectorySeparatorChar, [StringComparison]::OrdinalIgnoreCase) -or [IO.Path]::GetFileName($resolved) -notlike 'walle-rag-test-*') { throw 'Limpeza fora da pasta de testes recusada.' }
    Remove-Item -LiteralPath $resolved -Recurse -Force
}
