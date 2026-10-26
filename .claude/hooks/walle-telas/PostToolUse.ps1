$ErrorActionPreference = 'Stop'
& "$PSScriptRoot/../agent-policy.ps1" -Agent 'walle-telas' -Event 'PostToolUse'
exit $LASTEXITCODE
