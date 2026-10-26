$ErrorActionPreference = 'Stop'
& "$PSScriptRoot/../agent-policy.ps1" -Agent 'walle-hooks' -Event 'PreToolUse'
exit $LASTEXITCODE
