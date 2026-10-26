$ErrorActionPreference = 'Stop'
& "$PSScriptRoot/../agent-policy.ps1" -Agent 'walle-dados' -Event 'Stop'
exit $LASTEXITCODE
