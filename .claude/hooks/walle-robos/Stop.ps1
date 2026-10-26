$ErrorActionPreference = 'Stop'
& "$PSScriptRoot/../agent-policy.ps1" -Agent 'walle-robos' -Event 'Stop'
exit $LASTEXITCODE
