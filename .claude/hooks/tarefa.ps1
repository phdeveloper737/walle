# Ledger e Git das tarefas do walle: unico caminho do orquestrador para branch, commit, PR e merge.
# Versionado em .walle/tarefas/T-NNNN/ (so este script grava): pedido.json e estado.json. Uma tarefa cuja pasta
# esta no origin/main foi integrada (so chega la por merge); as demais vivem no branch tarefa/T-NNNN-<slug>.
# Local em .walle/local/T-NNNN/ (ignorado pelo git): pedido.json (antes de abrir), commit.txt, pr.md e notas.md,
# escritos pelo orquestrador, e revisao.md, escrito so pelo walle-arquiteto.
# Acoes: proxima | abrir | retomar | bloquear | commitar | publicar | integrar | status
# Saida: 0 = ok; 2 = recusado (pre-condicao); 3 = bloqueada (terceiro ciclo de correcao); 4 = CI ainda pendente
# (rode integrar de novo); 1 = erro inesperado.
# Sem acentos: o PowerShell 5.1 le este arquivo como ANSI.
param(
    [Parameter(Mandatory = $true)][ValidateSet('proxima', 'abrir', 'retomar', 'bloquear', 'commitar', 'publicar', 'integrar', 'status')][string]$Acao,
    [string]$Tarefa,
    # So para os testes (repositorio temporario); a politica dos agentes nao aceita este parametro.
    [string]$Raiz
)

$ErrorActionPreference = 'Stop'
if (-not $Raiz) { $Raiz = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../..')) }
$utf8 = New-Object Text.UTF8Encoding($false)
$maxCiclos = 2
# Espera maxima pelo CI dentro do integrar (cabe no timeout de 10 min da ferramenta); 0 nos testes.
$esperaCi = if ($env:WALLE_TAREFA_ESPERA_CI_S -match '^[0-9]+$') { [int]$env:WALLE_TAREFA_ESPERA_CI_S } else { 480 }

function Recusar([string]$Motivo, [int]$Codigo = 2) {
    [Console]::Error.WriteLine("[tarefa] Recusado: $Motivo")
    exit $Codigo
}

# Programa externo (nunca uma funcao com o mesmo nome) com codigo conferido; devolve a saida como texto.
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
function Hash([string]$Texto) {
    $sha = [Security.Cryptography.SHA256]::Create()
    try { return (($sha.ComputeHash($utf8.GetBytes($Texto)) | ForEach-Object { $_.ToString('x2') }) -join '') } finally { $sha.Dispose() }
}
# Estados antigos podem nao ter o campo: Add-Member cria ou substitui.
function Definir($Objeto, [string]$Nome, $Valor) { $Objeto | Add-Member -NotePropertyName $Nome -NotePropertyValue $Valor -Force }

function SemAcento([string]$Texto) {
    return (($Texto.Normalize([Text.NormalizationForm]::FormD).ToCharArray() | Where-Object { [Globalization.CharUnicodeInfo]::GetUnicodeCategory($_) -ne 'NonSpacingMark' }) -join '').ToLowerInvariant()
}
function Slug([string]$Texto) {
    $slug = ((SemAcento $Texto) -replace '[^a-z0-9]+', '-').Trim('-')
    if ($slug.Length -gt 40) { $slug = $slug.Substring(0, 40).Trim('-') }
    return $slug
}
# Classificacao Mercadologica: fora de toda automacao (regra do projeto, decisao de 26/10/2026).
function EhMercadologica($Pedido) {
    if ([string]$Pedido.origem -match '(?i)\bPLAN\s*(3\.3|6\.4|7\.2)\b') { return $true }
    return (SemAcento ("$($Pedido.titulo) $($Pedido.resumo) $($Pedido.origem)")) -match 'mercadolog'
}

function ExigirTarefa { if ($Tarefa -cnotmatch '^T-[0-9]{4}\z') { Recusar 'informe -Tarefa T-NNNN.' } }
$pastaLedger = Join-Path $Raiz ".walle/tarefas/$Tarefa"
$pastaLocal = Join-Path $Raiz ".walle/local/$Tarefa"
$arquivoEstado = Join-Path $pastaLedger 'estado.json'

function TemOrigem { return @((Repo @('remote') -PodeFalhar).texto -split "`n") -contains 'origin' }
function Buscar { if (TemOrigem) { Repo @('fetch', '-q', '--prune', 'origin') -PodeFalhar | Out-Null } }
function NoMain([string]$Id) { return (Repo @('cat-file', '-e', "origin/main:.walle/tarefas/$Id/estado.json") -PodeFalhar).codigo -eq 0 }
function BranchesRemotos([string]$Id) {
    $saida = (Repo @('branch', '-r', '--list', "origin/tarefa/$Id-*") -PodeFalhar).texto
    return @($saida -split "`n" | ForEach-Object { $_.Trim() } | Where-Object { $_ })
}

function Estado {
    if (-not (Test-Path -LiteralPath $arquivoEstado)) { Recusar "a tarefa $Tarefa nao esta aberta neste branch (use retomar)." }
    return LerJson $arquivoEstado
}
function Registrar($Estado, [string]$Tipo, [string]$Detalhe) {
    $Estado.eventos = @($Estado.eventos) + @([ordered]@{ quando = Agora; tipo = $Tipo; detalhe = $Detalhe })
    GravarJson $arquivoEstado $Estado
}
function ExigirBranch($Estado) {
    $atual = (Repo @('branch', '--show-current')).texto
    if ($atual -cne $Estado.branch) { Recusar "o branch atual ($atual) nao e o da tarefa ($($Estado.branch)); use retomar." }
}
function TextoLocal([string]$Nome) {
    $caminho = Join-Path $pastaLocal $Nome
    if (-not (Test-Path -LiteralPath $caminho)) { return '' }
    return [IO.File]::ReadAllText($caminho, $utf8).Trim()
}
# Commit de tudo (inclusive o ledger) e push do branch, para nada se perder num bloqueio.
function CommitarTudo([string]$Mensagem, $Estado) {
    Repo @('add', '-A') | Out-Null
    if ((Repo @('diff', '--cached', '--quiet') -PodeFalhar).codigo -ne 0) { Repo @('commit', '-q', '-m', $Mensagem) | Out-Null }
    if (TemOrigem) { Repo @('push', '-q', '-u', 'origin', $Estado.branch) | Out-Null }
}
# Commit so do ledger da tarefa (o resto da arvore fica como esta) e push do branch.
function CommitarLedger([string]$Mensagem, $Estado) {
    $caminho = ".walle/tarefas/$Tarefa"
    Repo @('add', '--', $caminho) | Out-Null
    if ((Repo @('diff', '--cached', '--quiet', '--', $caminho) -PodeFalhar).codigo -ne 0) { Repo @('commit', '-q', '-m', $Mensagem, '--', $caminho) | Out-Null }
    if (TemOrigem) { Repo @('push', '-q', '-u', 'origin', $Estado.branch) | Out-Null }
}
function NumeroDoPr([string]$Texto) {
    if ($Texto -match '/pull/([0-9]+)') { return [int]$Matches[1] }
    return 0
}
function Finalizar($Estado, [string]$Head) {
    if ((Repo @('branch', '--show-current')).texto -cne 'main') { Repo @('switch', '-q', 'main') | Out-Null }
    Repo @('pull', '-q', '--ff-only', 'origin', 'main') | Out-Null
    GravarJson (Join-Path $pastaLocal 'integracao.json') ([ordered]@{ tarefa = $Tarefa; pr = $Estado.pr; commit = $Head; integrada_em = Agora })
    Write-Output "[tarefa] $Tarefa integrada: PR #$($Estado.pr) no main"
    exit 0
}

switch ($Acao) {
    'proxima' {
        Buscar
        $ids = @()
        $pasta = Join-Path $Raiz '.walle/tarefas'
        if (Test-Path -LiteralPath $pasta) { $ids += @(Get-ChildItem -LiteralPath $pasta -Directory | ForEach-Object { $_.Name }) }
        $ids += @((Repo @('ls-tree', '--name-only', 'origin/main', '.walle/tarefas/') -PodeFalhar).texto -split "`n" | Where-Object { $_.Trim() } | ForEach-Object { Split-Path -Leaf $_.Trim() })
        $ids += @((Repo @('branch', '-a', '--list', '*tarefa/T-*') -PodeFalhar).texto -split "`n" | ForEach-Object { if ($_ -match 'tarefa/(T-[0-9]{4})') { $Matches[1] } })
        $maior = @($ids | Where-Object { $_ -cmatch '^T-[0-9]{4}$' } | ForEach-Object { [int]$_.Substring(2) } | Sort-Object -Descending | Select-Object -First 1)
        $proximo = if ($maior.Count) { $maior[0] + 1 } else { 1 }
        Write-Output ('T-{0:D4}' -f $proximo)
        exit 0
    }

    'abrir' {
        ExigirTarefa
        if (Test-Path -LiteralPath $arquivoEstado) { Recusar "a tarefa $Tarefa ja foi aberta." }
        $arquivoPedido = Join-Path $pastaLocal 'pedido.json'
        if (-not (Test-Path -LiteralPath $arquivoPedido)) { Recusar "grave antes .walle/local/$Tarefa/pedido.json (titulo, origem, tipo, dono, resumo)." }
        $pedido = LerJson $arquivoPedido
        foreach ($campo in @('titulo', 'origem', 'tipo')) { if (-not [string]$pedido.$campo) { Recusar "pedido.json sem '$campo'." } }
        if ($pedido.tipo -cnotin @('plano', 'feature', 'bug', 'harness')) { Recusar "tipo invalido no pedido.json: $($pedido.tipo)." }
        if ([string]$pedido.titulo -match '["`$\r\n]') { Recusar 'o titulo nao pode ter aspas duplas, crase, cifrao nem quebra de linha.' }
        if (EhMercadologica $pedido) { Recusar 'a Classificacao Mercadologica e manual, fora da automacao: nao abra tarefa para ela.' }
        $pendente = (Repo @('status', '--porcelain')).texto
        if ($pendente) { Recusar "ha alteracoes pendentes; abra a tarefa a partir de um main limpo: $pendente" }
        Buscar
        if (TemOrigem) {
            if (NoMain $Tarefa) { Recusar "$Tarefa ja existe no main (integrada); use proxima." }
            if (@(BranchesRemotos $Tarefa).Count) { Recusar "$Tarefa ja tem branch no origin; use retomar." }
        }
        $branch = "tarefa/$Tarefa-$(Slug ([string]$pedido.titulo))"
        Repo @('switch', '-q', 'main') | Out-Null
        if (TemOrigem) { Repo @('merge', '-q', '--ff-only', 'origin/main') | Out-Null }
        if ((Repo @('rev-parse', '--verify', '--quiet', "refs/heads/$branch") -PodeFalhar).codigo -eq 0) { Recusar "o branch $branch ja existe; use retomar." }
        Repo @('switch', '-q', '-c', $branch) | Out-Null
        GravarJson (Join-Path $pastaLedger 'pedido.json') $pedido
        GravarJson $arquivoEstado ([ordered]@{ tarefa = $Tarefa; titulo = [string]$pedido.titulo; origem = [string]$pedido.origem; tipo = [string]$pedido.tipo; dono = [string]$pedido.dono; estado = 'em_execucao'; ciclos = 0; branch = $branch; pr = 0; base = (Repo @('rev-parse', 'HEAD')).texto; notas_bloqueio = ''; eventos = @() })
        $estado = Estado
        Registrar $estado 'aberta' "branch $branch a partir de $($estado.base)"
        # Ja no origin: a tarefa aberta aparece no status e no proxima de qualquer maquina.
        CommitarLedger "Ledger: $Tarefa aberta" $estado
        Write-Output "[tarefa] $Tarefa aberta no branch $branch"
        exit 0
    }

    'retomar' {
        ExigirTarefa
        # No proprio branch, alteracoes pendentes sao o trabalho interrompido: ficam como estao.
        $noBranch = (Repo @('branch', '--show-current')).texto -clike "tarefa/$Tarefa-*"
        if (-not $noBranch -and (Repo @('status', '--porcelain')).texto) { Recusar 'ha alteracoes pendentes em outro branch; retome a partir de uma arvore limpa.' }
        Buscar
        if ((TemOrigem) -and (NoMain $Tarefa)) { Recusar "$Tarefa ja foi integrada (esta no main)." }
        $locais = @((Repo @('branch', '--list', "tarefa/$Tarefa-*", '--format=%(refname:short)') -PodeFalhar).texto -split "`n" | Where-Object { $_ })
        $remotos = @(BranchesRemotos $Tarefa)
        if ($noBranch) { if ($remotos.Count) { Repo @('merge', '-q', '--ff-only', $remotos[0]) -PodeFalhar | Out-Null } }
        elseif ($locais.Count) { Repo @('switch', '-q', $locais[0]) | Out-Null; if ($remotos.Count) { Repo @('merge', '-q', '--ff-only', $remotos[0]) -PodeFalhar | Out-Null } }
        elseif ($remotos.Count) { Repo @('switch', '-q', '--track', $remotos[0]) | Out-Null }
        else { Recusar "nao achei o branch da tarefa $Tarefa (local nem no origin)." }
        $estado = Estado
        if ($estado.estado -ceq 'bloqueada') {
            # O texto que explicou o bloqueio nao vale como decisao: notas.md tem de mudar.
            $decisao = TextoLocal 'notas.md'
            if (-not $decisao -or (Hash $decisao) -ceq [string]$estado.notas_bloqueio) { Recusar "a tarefa esta bloqueada; registre em .walle/local/$Tarefa/notas.md a decisao do usuario que resolve o bloqueio (o texto do bloqueio nao vale)." }
            $estado.estado = if ([int]$estado.pr -gt 0) { 'em_revisao' } else { 'em_execucao' }
            $estado.ciclos = 0
            Registrar $estado 'desbloqueada' $decisao
            CommitarLedger "Ledger: $Tarefa desbloqueada" $estado
        }
        Write-Output "[tarefa] $Tarefa retomada no branch $($estado.branch) (estado $($estado.estado), PR #$($estado.pr), ciclos $($estado.ciclos))"
        exit 0
    }

    'bloquear' {
        ExigirTarefa
        $estado = Estado
        ExigirBranch $estado
        $motivo = TextoLocal 'notas.md'
        if (-not $motivo) { Recusar "explique o bloqueio em .walle/local/$Tarefa/notas.md." }
        $estado.estado = 'bloqueada'
        Definir $estado 'notas_bloqueio' (Hash $motivo)
        Registrar $estado 'bloqueada' $motivo
        CommitarTudo "Ledger: $Tarefa bloqueada" $estado
        Write-Output "[tarefa] $Tarefa bloqueada e enviada ao origin: $motivo"
        exit 0
    }

    'commitar' {
        ExigirTarefa
        $estado = Estado
        ExigirBranch $estado
        if ($estado.estado -ceq 'bloqueada') { Recusar "a tarefa $Tarefa esta bloqueada (use retomar depois da decisao)." }
        $mensagem = TextoLocal 'commit.txt'
        if (-not $mensagem) { Recusar "grave a mensagem em .walle/local/$Tarefa/commit.txt." }
        # Depois do PR, todo commit e uma correcao: conta o ciclo aqui, sem depender do orquestrador lembrar.
        # Se o commit falhar, o estado volta ao que era: o ciclo so conta com o commit feito.
        $estadoOriginal = [IO.File]::ReadAllText($arquivoEstado, $utf8)
        $bloqueou = $false
        if ([int]$estado.pr -gt 0) {
            $estado.ciclos = [int]$estado.ciclos + 1
            if ($estado.ciclos -gt $maxCiclos) {
                $estado.estado = 'bloqueada'
                $bloqueou = $true
                Definir $estado 'notas_bloqueio' (Hash (TextoLocal 'notas.md'))
                Registrar $estado 'bloqueada' "passou de $maxCiclos ciclos de correcao: escalar ao usuario. $(TextoLocal 'notas.md')"
            } else {
                $estado.estado = 'em_revisao'
                Registrar $estado 'ciclo' "correcao $($estado.ciclos) de ${maxCiclos}: $(($mensagem -split "`n")[0])"
            }
        } else {
            Registrar $estado 'commit' (($mensagem -split "`n")[0])
        }
        try {
            Repo @('add', '-A') | Out-Null
            Repo @('commit', '-q', '-F', (Join-Path $pastaLocal 'commit.txt')) | Out-Null
        } catch {
            [IO.File]::WriteAllText($arquivoEstado, $estadoOriginal, $utf8)
            Repo @('reset', '-q') -PodeFalhar | Out-Null
            throw
        }
        Remove-Item -LiteralPath (Join-Path $pastaLocal 'commit.txt') -Force
        if ($bloqueou) {
            if (TemOrigem) { Repo @('push', '-q', '-u', 'origin', $estado.branch) | Out-Null }
            [Console]::Error.WriteLine("[tarefa] $Tarefa bloqueada: passou de $maxCiclos ciclos de correcao. O trabalho foi enviado ao branch; leve as tentativas ao usuario.")
            exit 3
        }
        Write-Output "[tarefa] ${Tarefa}: commit $((Repo @('rev-parse', '--short', 'HEAD')).texto) (ciclos $($estado.ciclos) de $maxCiclos)"
        exit 0
    }

    'publicar' {
        ExigirTarefa
        $estado = Estado
        ExigirBranch $estado
        if ($estado.estado -ceq 'bloqueada') { Recusar "a tarefa $Tarefa esta bloqueada." }
        if ((Repo @('status', '--porcelain')).texto) { Recusar 'ha alteracoes sem commit; use commitar antes.' }
        Repo @('push', '-q', '-u', 'origin', $estado.branch) | Out-Null
        if ([int]$estado.pr -eq 0) {
            # Idempotente: se uma queda deixou o PR criado sem registro, reaproveita o PR do branch.
            $existente = Rodar 'gh' @('pr', 'view', $estado.branch, '--json', 'number', '-q', '.number') -PodeFalhar
            $numero = if ($existente.codigo -eq 0 -and $existente.texto -match '^[0-9]+$') { [int]$existente.texto } else { 0 }
            if (-not $numero) {
                if (-not (TextoLocal 'pr.md')) { Recusar "grave a descricao do PR em .walle/local/$Tarefa/pr.md." }
                $criado = Rodar 'gh' @('pr', 'create', '--base', 'main', '--head', $estado.branch, '--title', "$($Tarefa): $($estado.titulo)", '--body-file', (Join-Path $pastaLocal 'pr.md'))
                $numero = NumeroDoPr $criado.texto
                if (-not $numero) { throw "gh pr create nao devolveu o numero do PR: $($criado.texto)" }
            }
            $estado.pr = $numero
            $estado.estado = 'em_revisao'
            Registrar $estado 'pr' "PR #$numero aberto"
            CommitarTudo "Ledger: $Tarefa PR #$numero" $estado
        }
        Write-Output "[tarefa] $Tarefa publicada: PR #$($estado.pr), HEAD $((Repo @('rev-parse', 'HEAD')).texto)"
        exit 0
    }

    'integrar' {
        ExigirTarefa
        # Sem ledger neste branch, mas ja no origin/main: o merge aconteceu e a finalizacao caiu no meio.
        if (-not (Test-Path -LiteralPath $arquivoEstado)) {
            Buscar
            if ((TemOrigem) -and (NoMain $Tarefa)) { Finalizar ((Repo @('show', "origin/main:.walle/tarefas/$Tarefa/estado.json")).texto | ConvertFrom-Json) '' }
        }
        $estado = Estado
        if (EhMercadologica $estado) { Recusar 'a Classificacao Mercadologica e manual: o merge e do usuario.' }
        # Idempotente: se o merge ja aconteceu (queda depois do gh pr merge), so finaliza.
        if ([int]$estado.pr -gt 0) {
            $jaFeito = Rodar 'gh' @('pr', 'view', [string]$estado.pr, '--json', 'state', '-q', '.state') -PodeFalhar
            if ($jaFeito.codigo -eq 0 -and $jaFeito.texto -ceq 'MERGED') { Finalizar $estado '' }
        }
        ExigirBranch $estado
        if ($estado.estado -ceq 'bloqueada') { Recusar "a tarefa $Tarefa esta bloqueada." }
        if ([int]$estado.pr -eq 0) { Recusar 'publique o PR antes.' }
        if ((Repo @('status', '--porcelain')).texto) { Recusar 'ha alteracoes sem commit.' }
        $head = (Repo @('rev-parse', 'HEAD')).texto
        Repo @('fetch', '-q', 'origin', $estado.branch) | Out-Null
        if ((Repo @('rev-parse', "origin/$($estado.branch)")).texto -cne $head) { Recusar 'o HEAD local nao e o do PR; use publicar.' }

        # Evidencia do gate: completo, definitivo, verde e da mesma arvore deste commit.
        $arquivoGate = Join-Path $Raiz '.walle/gate/ultimo.json'
        if (-not (Test-Path -LiteralPath $arquivoGate)) { Recusar 'sem evidencia do gate (.walle/gate/ultimo.json): peca o gate ao walle-verificador.' }
        $gate = LerJson $arquivoGate
        if ($gate.commit -cne $head -or $gate.valida_para_commit -ne $true -or $gate.completo -ne $true -or $gate.preliminar -eq $true) { Recusar "o gate nao e valido para o HEAD $head (commit do gate: $($gate.commit), valida_para_commit: $($gate.valida_para_commit))." }

        # Revisao do walle-arquiteto (so ele grava revisao.md), do mesmo commit, com um unico veredito: APROVADO.
        $revisao = TextoLocal 'revisao.md'
        if (-not $revisao) { Recusar "sem o parecer do walle-arquiteto em .walle/local/$Tarefa/revisao.md." }
        if ($revisao -notmatch '(?im)^\s*Commit revisado:\s*([0-9a-f]{7,40})\b' -or -not $head.StartsWith($Matches[1])) { Recusar "a revisao precisa da linha 'Commit revisado: <sha>' com o HEAD $head." }
        # Todo parecer deste HEAD vai para o PR, inclusive o que reprova; cada texto uma vez so.
        $comentado = Join-Path $pastaLocal "comentado-$head-$((Hash $revisao).Substring(0, 12))"
        if (-not (Test-Path -LiteralPath $comentado)) {
            Rodar 'gh' @('pr', 'comment', [string]$estado.pr, '--body-file', (Join-Path $pastaLocal 'revisao.md')) | Out-Null
            [IO.File]::WriteAllText($comentado, (Agora), $utf8)
        }
        $vereditos = @([regex]::Matches($revisao, '(?im)^\s*Veredito:\s*(.+?)\s*$') | ForEach-Object { $_.Groups[1].Value })
        if ($vereditos.Count -ne 1 -or $vereditos[0] -notmatch '^(?i)APROVADO\b') { Recusar "a revisao precisa de exatamente uma linha 'Veredito: APROVADO' (achadas: $($vereditos -join ' / '))." }

        # CI: verde segue; vermelho recusa; pendente espera ate $esperaCi segundos e devolve 4 (tente de novo).
        $relogio = [Diagnostics.Stopwatch]::StartNew()
        while ($true) {
            $checks = Rodar 'gh' @('pr', 'checks', [string]$estado.pr) -PodeFalhar
            if ($checks.codigo -eq 0) { break }
            $pendente = $checks.codigo -eq 8 -or $checks.texto -match '(?i)no checks reported'
            if (-not $pendente) { Recusar "o CI do PR #$($estado.pr) falhou (gh pr checks saiu com $($checks.codigo)): corrija e publique de novo." }
            if ($relogio.Elapsed.TotalSeconds -ge $esperaCi) { Recusar "o CI do PR #$($estado.pr) ainda esta rodando; rode integrar de novo daqui a pouco." 4 }
            Start-Sleep -Seconds 20
        }
        # O merge so vale para o HEAD conferido: um push no meio do caminho faz o gh recusar.
        Rodar 'gh' @('pr', 'merge', [string]$estado.pr, '--squash', '--delete-branch', '--match-head-commit', $head) | Out-Null
        $situacao = (Rodar 'gh' @('pr', 'view', [string]$estado.pr, '--json', 'state', '-q', '.state')).texto
        if ($situacao -cne 'MERGED') { throw "o PR #$($estado.pr) nao ficou MERGED (estado: $situacao)." }
        Finalizar $estado $head
    }

    'status' {
        Buscar
        $ids = @()
        $pasta = Join-Path $Raiz '.walle/tarefas'
        if (Test-Path -LiteralPath $pasta) { $ids += @(Get-ChildItem -LiteralPath $pasta -Directory | ForEach-Object { $_.Name }) }
        $ids += @((Repo @('ls-tree', '--name-only', 'origin/main', '.walle/tarefas/') -PodeFalhar).texto -split "`n" | Where-Object { $_.Trim() } | ForEach-Object { Split-Path -Leaf $_.Trim() })
        $remotos = @((Repo @('branch', '-r', '--list', 'origin/tarefa/T-*') -PodeFalhar).texto -split "`n" | ForEach-Object { $_.Trim() } | Where-Object { $_ })
        $ids += @($remotos | ForEach-Object { if ($_ -match 'tarefa/(T-[0-9]{4})') { $Matches[1] } })
        $ids = @($ids | Where-Object { $_ -cmatch '^T-[0-9]{4}$' } | Sort-Object -Unique)
        if ($Tarefa) { ExigirTarefa; $ids = @($Tarefa) }
        foreach ($id in $ids) {
            $texto = $null
            $situacao = $null
            if ((TemOrigem) -and (NoMain $id)) { $texto = (Repo @('show', "origin/main:.walle/tarefas/$id/estado.json")).texto; $situacao = 'integrada' }
            elseif (Test-Path -LiteralPath (Join-Path $pasta "$id/estado.json")) { $texto = [IO.File]::ReadAllText((Join-Path $pasta "$id/estado.json"), $utf8) }
            else {
                $remoto = @($remotos | Where-Object { $_ -match "tarefa/$id-" } | Select-Object -First 1)
                if ($remoto.Count) { $texto = (Repo @('show', "$($remoto[0]):.walle/tarefas/$id/estado.json") -PodeFalhar).texto }
            }
            if (-not $texto) { Write-Output "$id | sem ledger encontrado"; continue }
            $e = $texto | ConvertFrom-Json
            if (-not $situacao) { $situacao = $e.estado }
            Write-Output ("{0} | {1} | ciclos {2} | {3} | PR #{4} | {5}" -f $id, $situacao, $e.ciclos, $e.branch, $e.pr, $e.titulo)
        }
        exit 0
    }
}
