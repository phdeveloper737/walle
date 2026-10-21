<?php

namespace App\Sactus\Ssh;

/**
 * Um jeito de entrar no servidor do cliente: método (NORMAL, DOCKER ou VPN), host, porta e credenciais.
 * A senha nunca vai para log, tela ou banco (só o usuário).
 */
final readonly class AlvoSsh
{
    public function __construct(
        public string $metodo,
        public string $host,
        public int $porta,
        public string $usuario,
        #[\SensitiveParameter] public string $senha,
    ) {}

    /** Sem TTY o sudo precisa de NOPASSWD; com -n ele falha na hora em vez de travar pedindo senha. */
    public function sudo(): string
    {
        return strtolower(trim($this->usuario)) === 'root' ? '' : 'sudo -n ';
    }

    public function descricao(): string
    {
        return "{$this->metodo} {$this->usuario}@{$this->host}:{$this->porta}";
    }

    public function chave(): string
    {
        return $this->host.'|'.$this->porta;
    }

    /** Para não aparecer a senha num dump ou num log de exceção. */
    public function __debugInfo(): array
    {
        return ['metodo' => $this->metodo, 'host' => $this->host, 'porta' => $this->porta, 'usuario' => $this->usuario, 'senha' => '***'];
    }
}
