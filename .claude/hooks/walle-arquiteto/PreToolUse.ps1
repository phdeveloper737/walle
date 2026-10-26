$ErrorActionPreference = 'Stop'
& "$PSScriptRoot/../agent-policy.ps1" -Agent 'walle-arquiteto' -Event 'PreToolUse'
exit $LASTEXITCODE
