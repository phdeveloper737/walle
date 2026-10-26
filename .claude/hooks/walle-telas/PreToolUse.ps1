$ErrorActionPreference = 'Stop'
& "$PSScriptRoot/../agent-policy.ps1" -Agent 'walle-telas' -Event 'PreToolUse'
exit $LASTEXITCODE
