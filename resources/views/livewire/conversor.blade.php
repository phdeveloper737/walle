{{--
    Conversor de certificado (.pfx → PEM). Tudo em memória:
    - o .pfx é lido no navegador (FileReader) e vai em base64, com a senha, como argumento de $wire.converter();
    - não há <form>, wire:model nem upload do Livewire: o arquivo e a senha ficam só no estado do Alpine;
    - o PEM volta no retorno da ação e o download é gerado no navegador (Blob), sem passar de novo pelo servidor.
    wire:ignore: o Livewire não redesenha este bloco (a janela de senha re-renderiza o componente).
--}}
<div class="flex max-w-3xl flex-col gap-3">
    <div wire:ignore class="flex flex-col gap-3"
        x-data="{
            arquivo: null,
            nome: '',
            senha: '',
            pem: '',
            titular: '',
            validoAte: '',
            erro: '',
            convertendo: false,
            aguardandoSenha: false,
            limite: {{ $tamanhoMaximo }},
            escolher(evento) {
                let arquivo = evento.target.files[0]
                evento.target.value = ''
                this.erro = ''
                this.pem = ''
                if (! arquivo) { return }
                if (arquivo.size > this.limite) {
                    this.arquivo = null
                    this.nome = ''
                    this.erro = 'O arquivo é maior que ' + Math.floor(this.limite / 1024) + ' KB, o que não é um certificado A1 comum. Confira se escolheu o .pfx certo.'
                    return
                }
                this.arquivo = arquivo
                this.nome = arquivo.name
            },
            ler() {
                return new Promise((resolver, falhar) => {
                    let leitor = new FileReader()
                    leitor.onload = () => resolver(String(leitor.result).split(',')[1] ?? '')
                    leitor.onerror = () => falhar(leitor.error)
                    leitor.readAsDataURL(this.arquivo)
                })
            },
            async converter() {
                if (! this.arquivo) { this.erro = 'Escolha o arquivo .pfx do certificado.'; return }
                this.convertendo = true
                this.erro = ''
                this.pem = ''
                try {
                    let resposta = await $wire.converter(await this.ler(), this.senha)
                    if (resposta && resposta.ok) {
                        this.pem = resposta.pem
                        this.titular = resposta.titular
                        this.validoAte = resposta.validoAte ?? ''
                        this.arquivo = null
                        this.senha = ''
                        this.aguardandoSenha = false
                    } else {
                        this.aguardandoSenha = !! (resposta && resposta.pedirSenha)
                        this.erro = resposta && ! resposta.pedirSenha ? resposta.erro : ''
                    }
                } catch (falha) {
                    this.erro = 'Não foi possível converter o certificado agora. Tente de novo; se continuar, avise o suporte.'
                } finally {
                    this.convertendo = false
                }
            },
            nomeDoPem() {
                return (this.nome.replace(/\.(pfx|p12)$/i, '').replace(/[^A-Za-z0-9._-]/g, '_') || 'certificado') + '.pem'
            },
            baixar() {
                let endereco = URL.createObjectURL(new Blob([this.pem], { type: 'application/x-pem-file' }))
                let link = document.createElement('a')
                link.href = endereco
                link.download = this.nomeDoPem()
                link.click()
                URL.revokeObjectURL(endereco)
            },
            limpar() {
                this.arquivo = null
                this.nome = ''
                this.senha = ''
                this.pem = ''
                this.titular = ''
                this.validoAte = ''
                this.erro = ''
                this.aguardandoSenha = false
            },
        }"
        x-on:conversor-liberado.window="if (aguardandoSenha && arquivo) { converter() }">

        <x-ui.card titulo="Certificado A1 (.pfx)" icone="enhanced_encryption">
            <p class="mb-4 text-sm text-on-surface-variant">
                Converte o .pfx em PEM com o certificado, a chave privada e a cadeia (para mTLS, cURL e Sefaz).
                O arquivo e a senha não ficam gravados no walle: a conversão acontece em memória e só a auditoria registra que houve a conversão.
            </p>

            <div class="flex flex-col gap-4">
                <div class="flex flex-wrap items-center gap-3">
                    <input type="file" accept=".pfx,.p12,application/x-pkcs12" class="sr-only" x-ref="arquivo" x-on:change="escolher($event)" tabindex="-1" aria-hidden="true" />
                    <x-ui.botao variante="contorno" icone="upload_file" x-on:click="$refs.arquivo.click()">Escolher arquivo .pfx</x-ui.botao>
                    <span class="text-sm text-on-surface-variant" x-text="nome || 'Nenhum arquivo escolhido.'"></span>
                </div>

                <x-ui.campo label="Senha do certificado" tipo="password" icone="key" id="conversor-senha" x-model="senha"
                    autocomplete="off" spellcheck="false" x-on:keydown.enter="converter()" />

                <div class="flex flex-wrap items-center gap-2">
                    <x-ui.botao icone="sync_alt" x-on:click="converter()" x-bind:disabled="convertendo">
                        <span x-text="convertendo ? 'Convertendo...' : 'Converter para PEM'">Converter para PEM</span>
                    </x-ui.botao>
                </div>
            </div>
        </x-ui.card>

        <x-ui.aviso tipo="erro" x-show="erro" x-cloak><span x-text="erro"></span></x-ui.aviso>
        <x-ui.aviso x-show="aguardandoSenha" x-cloak>Confirme a sua senha do Help Desk; a conversão continua em seguida.</x-ui.aviso>

        <template x-if="pem">
            <x-ui.card titulo="PEM convertido" icone="verified_user">
                <x-slot:acoes>
                    <x-ui.botao variante="tonal" icone="download" x-on:click="baixar()">Baixar .pem</x-ui.botao>
                    <x-ui.botao variante="texto" icone="delete" x-on:click="limpar()">Limpar</x-ui.botao>
                </x-slot:acoes>

                <x-ui.aviso tipo="sucesso" class="mb-4">
                    Certificado convertido.
                    <span x-show="titular">Titular: <span x-text="titular"></span>.</span>
                    <span x-show="validoAte">Válido até <span x-text="validoAte"></span>.</span>
                </x-ui.aviso>

                <x-ui.area-texto label="Certificado, chave privada e cadeia" id="conversor-pem" readonly rows="12" spellcheck="false"
                    class="font-mono text-xs" x-bind:value="pem"
                    ajuda="A chave privada está em aberto: guarde o arquivo em local seguro e clique em Limpar ao terminar." />
            </x-ui.card>
        </template>
    </div>

    <x-ui.confirmar-senha :aberta="$pedindoSenha" texto="O PEM traz a chave privada do certificado em aberto." />
</div>
