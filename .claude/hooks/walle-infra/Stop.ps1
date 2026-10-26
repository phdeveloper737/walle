$ErrorActionPreference = 'Stop'
& "$PSScriptRoot/../agent-policy.ps1" -Agent 'walle-infra' -Event 'Stop'
exit $LASTEXITCODE
