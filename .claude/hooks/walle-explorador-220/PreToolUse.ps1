$ErrorActionPreference = 'Stop'
& "$PSScriptRoot/../agent-policy.ps1" -Agent 'walle-explorador-220' -Event 'PreToolUse'
exit $LASTEXITCODE
