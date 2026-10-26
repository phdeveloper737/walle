$ErrorActionPreference = 'Stop'
& "$PSScriptRoot/../agent-policy.ps1" -Agent 'walle-paridade' -Event 'Stop'
exit $LASTEXITCODE
