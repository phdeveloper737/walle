# Travas entre processos: o gate e o Stop compartilham o mesmo banco Docker.
# Nunca espera segurando outro recurso: banco ocupado gera validacao pendente.
function NomeDaTravaWalle([string]$Recurso) {
    $sha = [Security.Cryptography.SHA256]::Create()
    try {
        $bytes = [Text.Encoding]::UTF8.GetBytes($Recurso.ToLowerInvariant())
        $nome = (($sha.ComputeHash($bytes) | ForEach-Object { $_.ToString('x2') }) -join '') + '.lock'
        return Join-Path ([IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../../.walle/gate/travas'))) $nome
    } finally { $sha.Dispose() }
}

function AbrirTravaWalle([string]$Recurso) {
    $caminho = NomeDaTravaWalle $Recurso
    [IO.Directory]::CreateDirectory((Split-Path -Parent $caminho)) | Out-Null
    try {
        return [IO.File]::Open($caminho, [IO.FileMode]::OpenOrCreate, [IO.FileAccess]::ReadWrite, [IO.FileShare]::None)
    } catch {
        throw 'Recurso ocupado ou trava indisponivel; aguarde terminar e tente novamente.'
    }
}

function FecharTravaWalle($Trava) {
    if ($null -ne $Trava) { $Trava.Dispose() }
}
