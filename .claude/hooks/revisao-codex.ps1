# Revisao obrigatoria do Codex sobre o diff do branch da tarefa (decisao do usuario de 27/10/2026): roda o Codex CLI
# em sandbox somente leitura, com resposta em JSON por esquema, e grava .walle/local/T-NNNN/revisao-codex.md (ignorado
# pelo git) com o commit revisado e a contagem de achados P0/P1. O integrar exige esse parecer do HEAD e so aceita
# P0/P1 que o walle-arquiteto descartou no revisao.md ("Codex descartado: <P0|P1> <arquivo:linha> - <motivo>", um por achado).
# Saida: 0 = ok; 2 = recusado (pre-condicao); 1 = erro (Codex falhou, estourou o tempo ou respondeu fora do esquema).
# Sem acentos: o PowerShell 5.1 le este arquivo como ANSI.
param(
    [string]$Tarefa,
    # So para os testes (repositorio temporario); a politica dos agentes nao aceita este parametro.
    [string]$Raiz
)

$ErrorActionPreference = 'Stop'
if (-not $Raiz) { $Raiz = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../..')) }
$utf8 = New-Object Text.UTF8Encoding($false)
# Cabe no timeout de 10 min da ferramenta, com folga para o fetch.
$limiteSegundos = 540

function Recusar([string]$Motivo, [int]$Codigo = 2) {
    [Console]::Error.WriteLine("[revisao-codex] Recusado: $Motivo")
    exit $Codigo
}
function Falhar([string]$Motivo) {
    [Console]::Error.WriteLine("[revisao-codex] Erro: $Motivo")
    exit 1
}
# Programa externo (nunca uma funcao com o mesmo nome): o PowerShell nao diferencia Git de git.
function Repo([string[]]$Argumentos) {
    $preferencia = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        $git = (Get-Command git -CommandType Application | Select-Object -First 1).Source
        $saida = @(& $git -C $Raiz @Argumentos 2>&1 | ForEach-Object { [string]$_ })
        $codigo = $LASTEXITCODE
    } finally { $ErrorActionPreference = $preferencia }
    return @{ codigo = $codigo; texto = ($saida -join "`n").Trim() }
}

if ($Tarefa -cnotmatch '^T-[0-9]{4}\z') { Recusar 'informe -Tarefa T-NNNN.' }
$arquivoEstado = Join-Path $Raiz ".walle/tarefas/$Tarefa/estado.json"
if (-not (Test-Path -LiteralPath $arquivoEstado)) { Recusar "tarefa $Tarefa sem estado no ledger." }
$estado = [IO.File]::ReadAllText($arquivoEstado, $utf8) | ConvertFrom-Json
$branchAtual = (Repo @('rev-parse', '--abbrev-ref', 'HEAD')).texto
if ($branchAtual -cne [string]$estado.branch) { Recusar "o branch atual ($branchAtual) nao e o da tarefa ($($estado.branch))." }

# PRE-CHECK: parecer preexistente valido
$pastaLocal = Join-Path $Raiz ".walle/local/$Tarefa"
$destino = Join-Path $pastaLocal 'revisao-codex.md'
$shaHead = (Repo @('rev-parse', 'HEAD')).texto
if (Test-Path -LiteralPath $destino) {
    $existente = [IO.File]::ReadAllText($destino, $utf8)
    if ($existente -match "Commit revisado: $shaHead" -and $existente -match 'Bloqueantes do Codex: 0') {
        Write-Output "[revisao-codex] Parecer preexistente valido para $shaHead, pulando Codex."
        exit 0
    }
}

$codex = $env:WALLE_CODEX
if (-not $codex) {
    $comando = Get-Command codex -CommandType Application -ErrorAction SilentlyContinue | Select-Object -First 1
    if ($comando) { $codex = $comando.Source }
}
if (-not $codex -or -not (Test-Path -LiteralPath $codex)) { Recusar 'Codex indisponivel: instale com npm i -g @openai/codex e rode codex login.' }

$base = 'main'
if (@((Repo @('remote')).texto -split "`n") -contains 'origin') {
    Repo @('fetch', '-q', 'origin') | Out-Null
    $base = 'origin/main'
}
$shaBase = (Repo @('rev-parse', $base)).texto
$shaHead = (Repo @('rev-parse', 'HEAD')).texto

$pastaLocal = Join-Path $Raiz ".walle/local/$Tarefa"
[IO.Directory]::CreateDirectory($pastaLocal) | Out-Null
$destino = Join-Path $pastaLocal 'revisao-codex.md'
$temporario = Join-Path $pastaLocal 'revisao-codex.tmp'
$esquema = Join-Path $pastaLocal 'esquema-codex.json'
# Um parecer antigo nunca sobra de uma revisao que falhou.
foreach ($arquivo in @($destino, $temporario, $esquema)) { Remove-Item -LiteralPath $arquivo -ErrorAction SilentlyContinue }
function Limpar { foreach ($arquivo in @($temporario, $esquema)) { Remove-Item -LiteralPath $arquivo -ErrorAction SilentlyContinue } }

[IO.File]::WriteAllText($esquema, '{"type":"object","additionalProperties":false,"required":["achados"],"properties":{"achados":{"type":"array","items":{"type":"object","additionalProperties":false,"required":["prioridade","arquivo","linha","titulo","detalhe"],"properties":{"prioridade":{"type":"string","enum":["P0","P1","P2","P3"]},"arquivo":{"type":"string"},"linha":{"type":"integer"},"titulo":{"type":"string"},"detalhe":{"type":"string"}}}}}}', $utf8)
$pedido = "Voce e revisor de codigo do projeto walle. Revise somente o diff de git diff $shaBase...$shaHead (rode esse comando; nao altere arquivos) contra as regras do AGENTS.md. " +
    'Aponte apenas defeitos acionaveis introduzidos por esse diff: P0 = quebra grave (seguranca, perda de dados, regra obrigatoria violada); ' +
    'P1 = deve ser corrigido antes do merge; P2 e P3 = melhorias opcionais. Use o caminho relativo do arquivo e a linha no codigo novo. ' +
    'Nao abra, nao leia nem cite arquivos de ambiente (o arquivo de variaveis da raiz do projeto e as variantes dele), chaves, certificados, credenciais nem segredos, ' +
    'e nao copie valores sensiveis no parecer, porque o parecer vira comentario publico no PR. ' +
    'Sem achados, devolva a lista vazia. Escreva titulo e detalhe em portugues do Brasil.'

# O pedido vai pelo stdin (-): sem aspas na linha de comando. .cmd do npm passa pelo cmd; o .exe vai direto.
# Nunca sandbox de escrita nem --dangerously-*.
$argumentos = "exec -s read-only --ephemeral --output-schema `"$esquema`" -o `"$temporario`" -"
$inicio = New-Object Diagnostics.ProcessStartInfo
if ($codex -match '\.(cmd|bat)$') {
    $inicio.FileName = $env:ComSpec
    $inicio.Arguments = "/d /s /c `"`"$codex`" $argumentos`""
} else {
    $inicio.FileName = $codex
    $inicio.Arguments = $argumentos
}
$inicio.WorkingDirectory = $Raiz
$inicio.UseShellExecute = $false
$inicio.CreateNoWindow = $true
$inicio.RedirectStandardInput = $true
$inicio.RedirectStandardOutput = $true
$inicio.RedirectStandardError = $true
$processo = [Diagnostics.Process]::Start($inicio)
$processo.StandardInput.Write($pedido)
$processo.StandardInput.Close()
# Leitura assincrona: o Codex escreve bastante e um buffer cheio travaria o processo.
$tarefaSaida = $processo.StandardOutput.ReadToEndAsync()
$tarefaErro = $processo.StandardError.ReadToEndAsync()
if (-not $processo.WaitForExit($limiteSegundos * 1000)) {
    & taskkill /PID $processo.Id /T /F 2>&1 | Out-Null
    Limpar
    Falhar "o Codex passou de $limiteSegundos s."
}
$processo.WaitForExit()
$erro = $tarefaErro.Result
$null = $tarefaSaida.Result
if ($processo.ExitCode -ne 0) {
    Limpar
    $ultima = @($erro -split "`n" | Where-Object { $_.Trim() }) | Select-Object -Last 1
    Falhar "o Codex saiu com $($processo.ExitCode): $ultima"
}
if (-not (Test-Path -LiteralPath $temporario)) { Limpar; Falhar 'o Codex nao gravou o parecer.' }
$resposta = [IO.File]::ReadAllText($temporario, $utf8).Trim()
Limpar

# Fora do esquema e erro, nunca aprovacao: sem lista de achados valida, nao ha parecer.
try { $json = $resposta | ConvertFrom-Json } catch { Falhar 'o Codex respondeu fora do esquema (JSON invalido).' }
if (-not $json -or $null -eq $json.PSObject.Properties['achados']) { Falhar 'o Codex respondeu fora do esquema (sem achados).' }
if ($json.achados -isnot [array]) { Falhar 'o Codex respondeu fora do esquema (achados nao e uma lista).' }
$achados = @($json.achados | Where-Object { $null -ne $_ })
foreach ($achado in $achados) {
    if ([string]$achado.prioridade -cnotmatch '^P[0-3]\z' -or -not [string]$achado.arquivo -or -not [string]$achado.titulo) { Falhar 'o Codex respondeu fora do esquema (achado incompleto).' }
}
$bloqueantes = @($achados | Where-Object { [string]$_.prioridade -cmatch '^P[01]\z' }).Count
$veredito = if ($bloqueantes -eq 0) { 'APROVADO' } else { 'MUDANCAS NECESSARIAS' }

$linhas = @("Commit revisado: $shaHead", "Base: $base @ $shaBase", "Bloqueantes do Codex: $bloqueantes", "Veredito do Codex: $veredito", '', '## Achados do Codex', '')
if ($achados.Count -eq 0) { $linhas += 'Sem achados.' }
# Um achado por linha: quebras do texto do Codex viram espaco, para nenhum texto forjar outra linha de achado,
# e o arquivo perde crases e espacos, porque o integrar le prioridade e arquivo:linha dessa linha.
foreach ($achado in ($achados | Sort-Object { [string]$_.prioridade })) {
    $arquivo = ([string]$achado.arquivo) -replace '[`\s]', ''
    $titulo = (([string]$achado.titulo) -replace '\s+', ' ').Trim()
    $detalhe = (([string]$achado.detalhe) -replace '\s+', ' ').Trim()
    $linhas += "- **$($achado.prioridade)** ``${arquivo}:$([int]$achado.linha)`` - $titulo"
    if ($detalhe) { $linhas += "  $detalhe" }
}
[IO.File]::WriteAllText($destino, (($linhas -join "`n") + "`n"), $utf8)
Write-Output "[revisao-codex] Parecer de $Tarefa gravado em .walle/local/$Tarefa/revisao-codex.md (commit $shaHead, P0/P1: $bloqueantes)."
exit 0