# Ledger e Git das tarefas do walle: unico caminho do orquestrador para branch, commit, PR e merge.
# Versionado em .walle/tarefas/T-NNNN/ (so este script grava): pedido.json e estado.json.
# Local em .walle/local/T-NNNN/ (ignorado pelo git; escrito pelo orquestrador): pedido.json (antes de abrir),
# commit.txt, pr.md, revisao.md e notas.md. Assim os textos do orquestrador nao sujam a arvore que o gate confere.
# Acoes: proxima | abrir | ciclo | bloquear | commitar | publicar | integrar | status
# Saida: 0 = ok; 2 = recusado (pre-condicao); 3 = tarefa bloqueada (terceiro ciclo); 1 = erro inesperado.
# Sem acentos: o PowerShell 5.1 le este arquivo como ANSI.
param(
    [Parameter(Mandatory = $true)][ValidateSet('proxima', 'abrir', 'ciclo', 'bloquear', 'commitar', 'publicar', 'integrar', 'status')][string]$Acao,
    [string]$Tarefa,
    # So para os testes (repositorio temporario); a politica dos agentes nao aceita este parametro.
    [string]$Raiz
)

$ErrorActionPreference = 'Stop'
if (-not $Raiz) { $Raiz = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../..')) }
$utf8 = New-Object Text.UTF8Encoding($false)
$maxCiclos = 2

function Recusar([string]$Motivo) {
    [Console]::Error.WriteLine("[tarefa] Recusado: $Motivo")
    exit 2
}

# Comando externo com codigo conferido; devolve a saida como texto.
function Rodar([string]$Programa, [string[]]$Argumentos, [switch]$PodeFalhar) {
    $preferencia = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        $global:LASTEXITCODE = -1
        $executavel = Get-Command $Programa -CommandType Application -ErrorAction SilentlyContinue | Select-Object -First 1
        if (-not $executavel) { throw "programa nao encontrado: $Programa" }
        $saida = @(& $executavel.Source @Argumentos 2>&1 | ForEach-Object { [string]$_ })
        $codigo = $LASTEXITCODE
    } finally { $ErrorActionPreference = $preferencia }
    if ($codigo -ne 0 -and -not $PodeFalhar) { throw "$Programa $($Argumentos -join ' ') saiu com ${codigo}: $($saida -join ' | ')" }
    return @{ codigo = $codigo; texto = ($saida -join "`n").Trim() }
}
function Repo([string[]]$Argumentos, [switch]$PodeFalhar) { return Rodar 'git' (@('-C', $Raiz) + $Argumentos) -PodeFalhar:$PodeFalhar }

function LerJson([string]$Caminho) { return [IO.File]::ReadAllText($Caminho, $utf8) | ConvertFrom-Json }
function GravarJson([string]$Caminho, $Objeto) {
    [IO.Directory]::CreateDirectory((Split-Path -Parent $Caminho)) | Out-Null
    [IO.File]::WriteAllText($Caminho, ($Objeto | ConvertTo-Json -Depth 6), $utf8)
}
function Agora { return (Get-Date).ToString('yyyy-MM-dd HH:mm:ss') }

function Slug([string]$Texto) {
    $sem = ($Texto.Normalize([Text.NormalizationForm]::FormD).ToCharArray() | Where-Object { [Globalization.CharUnicodeInfo]::GetUnicodeCategory($_) -ne 'NonSpacingMark' }) -join ''
    $slug = ($sem.ToLowerInvariant() -replace '[^a-z0-9]+', '-').Trim('-')
    if ($slug.Length -gt 40) { $slug = $slug.Substring(0, 40).Trim('-') }
    return $slug
}

function ExigirTarefa {
    if ($Tarefa -cnotmatch '^T-[0-9]{4}\z') { Recusar 'informe -Tarefa T-NNNN.' }
}
$pastaLedger = Join-Path $Raiz ".walle/tarefas/$Tarefa"
$pastaLocal = Join-Path $Raiz ".walle/local/$Tarefa"
$arquivoEstado = Join-Path $pastaLedger 'estado.json'

function Estado {
    if (-not (Test-Path -LiteralPath $arquivoEstado)) { Recusar "a tarefa $Tarefa nao foi aberta neste branch (.walle/tarefas/$Tarefa/estado.json)." }
    return LerJson $arquivoEstado
}
function Registrar($Estado, [string]$Tipo, [string]$Detalhe) {
    $Estado.eventos = @($Estado.eventos) + @([ordered]@{ quando = Agora; tipo = $Tipo; detalhe = $Detalhe })
    GravarJson $arquivoEstado $Estado
}
function ExigirBranch($Estado) {
    $atual = (Repo @('branch', '--show-current')).texto
    if ($atual -cne $Estado.branch) { Recusar "o branch atual ($atual) nao e o da tarefa ($($Estado.branch))." }
}
function TextoLocal([string]$Nome) {
    $caminho = Join-Path $pastaLocal $Nome
    if (-not (Test-Path -LiteralPath $caminho)) { return '' }
    return [IO.File]::ReadAllText($caminho, $utf8).Trim()
}
function CommitarLedger([string]$Mensagem) {
    Repo @('add', '--', ".walle/tarefas/$Tarefa") | Out-Null
    $staged = Repo @('diff', '--cached', '--quiet') -PodeFalhar
    if ($staged.codigo -ne 0) { Repo @('commit', '-q', '-m', $Mensagem) | Out-Null }
}
function NumeroDoPr([string]$Texto) {
    if ($Texto -match '/pull/([0-9]+)') { return [int]$Matches[1] }
    return 0
}

switch ($Acao) {
    'proxima' {
        $ids = @()
        $pasta = Join-Path $Raiz '.walle/tarefas'
        if (Test-Path -LiteralPath $pasta) { $ids += @(Get-ChildItem -LiteralPath $pasta -Directory | ForEach-Object { $_.Name }) }
        $remotos = Repo @('branch', '-r', '--list', 'origin/tarefa/T-*') -PodeFalhar
        if ($remotos.codigo -eq 0) { $ids += @($remotos.texto -split "`n" | ForEach-Object { if ($_ -match 'tarefa/(T-[0-9]{4})') { $Matches[1] } }) }
        $maior = @($ids | Where-Object { $_ -cmatch '^T-[0-9]{4}$' } | ForEach-Object { [int]$_.Substring(2) } | Sort-Object -Descending | Select-Object -First 1)
        $proximo = if ($maior.Count) { $maior[0] + 1 } else { 1 }
        Write-Output ('T-{0:D4}' -f $proximo)
        exit 0
    }

    'abrir' {
        ExigirTarefa
        if (Test-Path -LiteralPath $arquivoEstado) { Recusar "a tarefa $Tarefa ja foi aberta." }
        $arquivoPedido = Join-Path $pastaLocal 'pedido.json'
        if (-not (Test-Path -LiteralPath $arquivoPedido)) { Recusar "grave antes .walle/local/$Tarefa/pedido.json (titulo, origem, tipo, resumo)." }
        $pedido = LerJson $arquivoPedido
        foreach ($campo in @('titulo', 'origem', 'tipo')) { if (-not [string]$pedido.$campo) { Recusar "pedido.json sem '$campo'." } }
        if ($pedido.tipo -cnotin @('plano', 'feature', 'bug', 'harness')) { Recusar "tipo invalido no pedido.json: $($pedido.tipo)." }
        $pendente = (Repo @('status', '--porcelain')).texto
        if ($pendente) { Recusar "ha alteracoes pendentes; abra a tarefa a partir de um main limpo: $pendente" }
        $branch = "tarefa/$Tarefa-$(Slug ([string]$pedido.titulo))"
        $temOrigem = (Repo @('remote') -PodeFalhar).texto -split "`n" -contains 'origin'
        if ($temOrigem) { Repo @('fetch', '-q', 'origin', 'main') | Out-Null }
        Repo @('switch', '-q', 'main') | Out-Null
        if ($temOrigem) { Repo @('merge', '-q', '--ff-only', 'origin/main') | Out-Null }
        if ((Repo @('rev-parse', '--verify', '--quiet', "refs/heads/$branch") -PodeFalhar).codigo -eq 0) { Recusar "o branch $branch ja existe." }
        Repo @('switch', '-q', '-c', $branch) | Out-Null
        GravarJson (Join-Path $pastaLedger 'pedido.json') $pedido
        $estado = [ordered]@{ tarefa = $Tarefa; titulo = [string]$pedido.titulo; origem = [string]$pedido.origem; tipo = [string]$pedido.tipo; estado = 'em_execucao'; ciclos = 0; branch = $branch; pr = 0; base = (Repo @('rev-parse', 'HEAD')).texto; eventos = @() }
        GravarJson $arquivoEstado $estado
        $estado = Estado
        Registrar $estado 'aberta' "branch $branch a partir de $($estado.base)"
        Write-Output "[tarefa] $Tarefa aberta no branch $branch"
        exit 0
    }

    'ciclo' {
        ExigirTarefa
        $estado = Estado
        ExigirBranch $estado
        $estado.ciclos = [int]$estado.ciclos + 1
        $motivo = TextoLocal 'notas.md'
        if ($estado.ciclos -gt $maxCiclos) {
            $estado.estado = 'bloqueada'
            Registrar $estado 'bloqueada' "terceiro ciclo de correcao: escalar ao usuario. $motivo"
            CommitarLedger "Ledger: $Tarefa bloqueada (ciclos de correcao esgotados)"
            [Console]::Error.WriteLine("[tarefa] $Tarefa bloqueada: passou de $maxCiclos ciclos de correcao. Pare e leve as tentativas ao usuario.")
            exit 3
        }
        Registrar $estado 'ciclo' "ciclo de correcao $($estado.ciclos) de $maxCiclos. $motivo"
        Write-Output "[tarefa] ${Tarefa}: ciclo de correcao $($estado.ciclos) de $maxCiclos"
        exit 0
    }

    'bloquear' {
        ExigirTarefa
        $estado = Estado
        ExigirBranch $estado
        $motivo = TextoLocal 'notas.md'
        if (-not $motivo) { Recusar "explique o bloqueio em .walle/local/$Tarefa/notas.md." }
        $estado.estado = 'bloqueada'
        Registrar $estado 'bloqueada' $motivo
        CommitarLedger "Ledger: $Tarefa bloqueada"
        Write-Output "[tarefa] $Tarefa bloqueada: $motivo"
        exit 0
    }

    'commitar' {
        ExigirTarefa
        $estado = Estado
        ExigirBranch $estado
        if ($estado.estado -ceq 'bloqueada') { Recusar "a tarefa $Tarefa esta bloqueada." }
        $mensagem = TextoLocal 'commit.txt'
        if (-not $mensagem) { Recusar "grave a mensagem em .walle/local/$Tarefa/commit.txt." }
        Registrar $estado 'commit' (($mensagem -split "`n")[0])
        Repo @('add', '-A') | Out-Null
        Repo @('commit', '-q', '-F', (Join-Path $pastaLocal 'commit.txt')) | Out-Null
        Remove-Item -LiteralPath (Join-Path $pastaLocal 'commit.txt') -Force
        Write-Output "[tarefa] ${Tarefa}: commit $((Repo @('rev-parse', '--short', 'HEAD')).texto)"
        exit 0
    }

    'publicar' {
        ExigirTarefa
        $estado = Estado
        ExigirBranch $estado
        if ((Repo @('status', '--porcelain')).texto) { Recusar 'ha alteracoes sem commit; use commitar antes.' }
        Repo @('push', '-q', '-u', 'origin', $estado.branch) | Out-Null
        if ([int]$estado.pr -eq 0) {
            $corpo = Join-Path $pastaLocal 'pr.md'
            if (-not (TextoLocal 'pr.md')) { Recusar "grave a descricao do PR em .walle/local/$Tarefa/pr.md." }
            $criado = Rodar 'gh' @('pr', 'create', '--base', 'main', '--head', $estado.branch, '--title', "$($Tarefa): $($estado.titulo)", '--body-file', $corpo)
            $numero = NumeroDoPr $criado.texto
            if (-not $numero) { throw "gh pr create nao devolveu o numero do PR: $($criado.texto)" }
            $estado.pr = $numero
            $estado.estado = 'em_revisao'
            Registrar $estado 'pr' "PR #$numero aberto"
            CommitarLedger "Ledger: $Tarefa PR #$numero"
            Repo @('push', '-q', 'origin', $estado.branch) | Out-Null
        }
        Write-Output "[tarefa] $Tarefa publicada: PR #$($estado.pr), HEAD $((Repo @('rev-parse', '--short', 'HEAD')).texto)"
        exit 0
    }

    'integrar' {
        ExigirTarefa
        $estado = Estado
        ExigirBranch $estado
        if ($estado.estado -ceq 'bloqueada') { Recusar "a tarefa $Tarefa esta bloqueada." }
        if ([int]$estado.pr -eq 0) { Recusar 'publique o PR antes.' }
        if ((Repo @('status', '--porcelain')).texto) { Recusar 'ha alteracoes sem commit.' }
        $head = (Repo @('rev-parse', 'HEAD')).texto
        Repo @('fetch', '-q', 'origin', $estado.branch) | Out-Null
        if ((Repo @('rev-parse', "origin/$($estado.branch)")).texto -cne $head) { Recusar 'o HEAD local nao foi enviado ao PR; use publicar.' }

        # Evidencia do gate: completo, verde e da mesma arvore deste commit.
        $arquivoGate = Join-Path $Raiz '.walle/gate/ultimo.json'
        if (-not (Test-Path -LiteralPath $arquivoGate)) { Recusar 'sem evidencia do gate (.walle/gate/ultimo.json): peca o gate ao walle-verificador.' }
        $gate = LerJson $arquivoGate
        if ($gate.commit -cne $head -or $gate.valida_para_commit -ne $true) { Recusar "o gate nao e valido para o HEAD $head (commit do gate: $($gate.commit), valida_para_commit: $($gate.valida_para_commit))." }

        # Revisao independente do mesmo commit, aprovada.
        $revisao = TextoLocal 'revisao.md'
        if (-not $revisao) { Recusar "grave o parecer do walle-arquiteto em .walle/local/$Tarefa/revisao.md." }
        if ($revisao -notmatch '(?im)^\s*Commit revisado:\s*([0-9a-f]{7,40})\b' -or -not $head.StartsWith($Matches[1])) { Recusar "a revisao precisa da linha 'Commit revisado: <sha>' com o HEAD $head." }
        if ($revisao -notmatch '(?im)^\s*Veredito:\s*APROVADO\b') { Recusar "a revisao nao tem 'Veredito: APROVADO'." }

        Rodar 'gh' @('pr', 'comment', [string]$estado.pr, '--body-file', (Join-Path $pastaLocal 'revisao.md')) | Out-Null
        $checks = Rodar 'gh' @('pr', 'checks', [string]$estado.pr, '--watch', '--fail-fast', '--interval', '20') -PodeFalhar
        if ($checks.codigo -ne 0) { Recusar "o CI do PR #$($estado.pr) nao ficou verde (gh pr checks saiu com $($checks.codigo)). Corrija e publique de novo." }
        Rodar 'gh' @('pr', 'merge', [string]$estado.pr, '--squash', '--delete-branch') | Out-Null
        $situacao = (Rodar 'gh' @('pr', 'view', [string]$estado.pr, '--json', 'state', '-q', '.state')).texto
        if ($situacao -cne 'MERGED') { throw "o PR #$($estado.pr) nao ficou MERGED (estado: $situacao)." }
        $atual = (Repo @('branch', '--show-current')).texto
        if ($atual -cne 'main') { Repo @('switch', '-q', 'main') | Out-Null }
        Repo @('pull', '-q', '--ff-only', 'origin', 'main') | Out-Null
        GravarJson (Join-Path $pastaLocal 'integracao.json') ([ordered]@{ tarefa = $Tarefa; pr = $estado.pr; commit = $head; integrada_em = Agora })
        Write-Output "[tarefa] $Tarefa integrada: PR #$($estado.pr) no main"
        exit 0
    }

    'status' {
        $pasta = Join-Path $Raiz '.walle/tarefas'
        $alvos = if ($Tarefa) { ExigirTarefa; @($Tarefa) } elseif (Test-Path -LiteralPath $pasta) { @(Get-ChildItem -LiteralPath $pasta -Directory | ForEach-Object { $_.Name }) } else { @() }
        foreach ($id in $alvos) {
            $caminho = Join-Path $pasta "$id/estado.json"
            if (-not (Test-Path -LiteralPath $caminho)) { Write-Output "$id | nao aberta neste branch"; continue }
            $e = LerJson $caminho
            $integrada = Test-Path -LiteralPath (Join-Path $Raiz ".walle/local/$id/integracao.json")
            $situacao = if ($integrada) { 'integrada' } else { $e.estado }
            Write-Output ("{0} | {1} | ciclos {2} | {3} | PR #{4} | {5}" -f $id, $situacao, $e.ciclos, $e.branch, $e.pr, $e.titulo)
        }
        exit 0
    }
}
