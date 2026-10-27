# Revisao consultiva do Codex sobre o diff do branch da tarefa: roda o Codex CLI em sandbox somente leitura e grava
# o parecer em .walle/local/T-NNNN/revisao-codex.md (ignorado pelo git). O orquestrador entrega esse parecer ao
# walle-arquiteto, que confirma ou descarta cada achado no revisao.md; o integrar continua exigindo so o arquiteto.
# Saida: 0 = ok; 2 = recusado (pre-condicao); 1 = erro (Codex falhou, estourou o tempo ou nao respondeu).
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
$temporario = Join-Path $pastaLocal 'revisao-codex.tmp'
Remove-Item -LiteralPath $temporario -ErrorAction SilentlyContinue

# .cmd/.ps1 do npm passam pelo cmd; o .exe vai direto. Nunca sandbox de escrita nem --dangerously-*.
$argumentos = "exec review --base $base -c sandbox_mode=read-only --ephemeral -o `"$temporario`""
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
$processo.StandardInput.Close()
# Leitura assincrona: o Codex escreve bastante e um buffer cheio travaria o processo.
$tarefaSaida = $processo.StandardOutput.ReadToEndAsync()
$tarefaErro = $processo.StandardError.ReadToEndAsync()
if (-not $processo.WaitForExit($limiteSegundos * 1000)) {
    & taskkill /PID $processo.Id /T /F 2>&1 | Out-Null
    Remove-Item -LiteralPath $temporario -ErrorAction SilentlyContinue
    Falhar "o Codex passou de $limiteSegundos s."
}
$processo.WaitForExit()
$erro = $tarefaErro.Result
$null = $tarefaSaida.Result
if ($processo.ExitCode -ne 0) {
    Remove-Item -LiteralPath $temporario -ErrorAction SilentlyContinue
    $primeira = @($erro -split "`n" | Where-Object { $_.Trim() }) | Select-Object -Last 1
    Falhar "o Codex saiu com $($processo.ExitCode): $primeira"
}
if (-not (Test-Path -LiteralPath $temporario)) { Falhar 'o Codex nao gravou o parecer.' }
$parecer = [IO.File]::ReadAllText($temporario, $utf8).Trim()
Remove-Item -LiteralPath $temporario
if (-not $parecer) { Falhar 'o parecer do Codex veio vazio.' }

$destino = Join-Path $pastaLocal 'revisao-codex.md'
$conteudo = "Commit revisado: $shaHead`nBase: $base @ $shaBase`n`n$parecer`n"
[IO.File]::WriteAllText($destino, $conteudo, $utf8)
Write-Output "[revisao-codex] Parecer de $Tarefa gravado em .walle/local/$Tarefa/revisao-codex.md (commit $shaHead)."
exit 0
