# NM Pocket: confirmação de presença

Página onde o aprovado na **Imersão Novos Milionários Pocket** (26/09, AlphaPark Hotel, Goiânia) confirma se vai, e o painel do admin para acompanhar as confirmações e marcar a chegada no dia.

Faz parte do site `iuv.com.br`, ao lado da página do evento (`/nm-pocket/`) e do formulário de aplicação (`/nm-pocket-app/`).

## Endereços

| O quê | URL |
|---|---|
| Página de confirmação | https://iuv.com.br/nm-pocket/confirmar/ |
| Painel (com senha) | https://iuv.com.br/nm-pocket-app/confirmacoes.php |

A página aceita os dados já preenchidos pelo link, para mandar pronto no WhatsApp:
`https://iuv.com.br/nm-pocket/confirmar/?nome=Maria%20Silva&whatsapp=62999990000`

## Arquivos

```
nm-pocket-app/pagina-modelo.html      modelo da página (estrutura, visual e textos padrão)
nm-pocket-app/pagina.php              monta a página: modelo + textos editados no painel
nm-pocket-app/confirmar.php           recebe o envio e grava
nm-pocket-app/acesso.php              registra quem abriu a página pelo link pessoal (?c=código)
nm-pocket-app/confirmacoes.php        painel: página, confirmações, grupo, check-in, planilhas
nm-pocket-app/confirmacao-comum.php   caminhos e funções usados por todos
nm-pocket-app/config.exemplo.php      modelo do arquivo de senha
nm-pocket/confirmar/uelicon.mp4       vídeo da página (e a capa, uelicon-capa.jpg)
```

O `nm-pocket/confirmar/index.html` que o público abre **não fica no repositório**: ele é gerado
pelo painel a partir do modelo.

## Abas do painel

1. **Página de aplicação** (`/nm-pocket/`): textos editáveis direto na página.
2. **Aplicações**: o painel de quem preencheu o formulário (`painel-*.php`, que fica só no servidor; o painel acha o arquivo pelo nome).
3. **Página de confirmação**: textos editáveis direto na página.
4. **Confirmações**: quem respondeu, check-in e planilha.
5. **Grupo do WhatsApp**: envio, acompanhamento, fora do grupo e lista para o ManyChat. Cruza com as aplicações pelo telefone: quem está no grupo sem nome aparece com o nome da aplicação (vale também para `{nome}` e para o ManyChat; a lista do grupo não é alterada) e quem aplicou mas não está no grupo tem filtro próprio. Aplicação na lixeira do painel de aplicações fica de fora.

## Editar a página de aplicação

Os textos da `/nm-pocket/index.html` levam uma marca `data-ed="aNNN"` (invisível para o visitante).
**Publicar troca só os textos editados dentro do arquivo que está no ar**, então editar o arquivo
direto no servidor continua valendo e o painel não apaga isso. Cada publicação guarda a versão
anterior em `/home/arlindo/data/nm-pocket/pagina-aplicacao-backups/`.

Texto novo que você acrescentar no arquivo só vira editável depois de marcado:

```bash
sudo -u www-data php /home/arlindo/sites/iuv.com.br/nm-pocket-app/pagina.php marcar-aplicacao
```

Ele marca só título, parágrafo, item, botão e afins que ainda não tenham marca, e não mexe em
mais nada do arquivo. A pasta `nm-pocket/` e o `index.html` dela ficam no grupo `www-data` com
escrita (dono continua `arlindo`), porque é o painel que grava.

## Editar a página de confirmação

**Textos:** pelo painel, aba **Página de confirmação** (a primeira). Clica no texto, escreve por
cima, **Salvar rascunho** guarda sem mexer no ar e **Publicar** leva para a página. Cada
publicação guarda a versão anterior em `/home/arlindo/data/nm-pocket/pagina-backups/`.

**Estrutura, visual, vídeo ou um texto novo:** no código, em `nm-pocket-app/pagina-modelo.html`.
Todo elemento com `data-ed="nome-unico"` vira editável no painel (use em elemento de texto que
não tenha dentro outro elemento da mesma tag). Depois de subir o modelo, remonte a página no
servidor, que mantém por cima os textos já publicados pelo painel:

```bash
sudo -u www-data php /home/arlindo/sites/iuv.com.br/nm-pocket-app/pagina.php regenerar
```

## Onde ficam os dados

Tudo fora do site, em `/home/arlindo/data/nm-pocket/`, junto das aplicações:

- `confirmacoes.ndjson`: uma linha por resposta. Quem responde de novo não é barrado: no painel vale a **última** resposta de cada WhatsApp.
- `checkin.json`: quem chegou no dia (WhatsApp → hora).
- `grupo.txt`: quem está no grupo do WhatsApp, uma pessoa por linha (`nome | telefone`). Editável na aba **Grupo do WhatsApp** do painel, que mostra quantos confirmaram e a lista de quem falta, com botão de mensagem, cópia dos números e planilha para disparo. Não vai para o repositório (são telefones).
- `confirmacao-config.php`: **hash da senha do painel**. Não vai para o repositório.
- `mensagem.txt`: a mensagem que o painel manda para o grupo (`{link}` e `{nome}` são trocados por pessoa).
- `envios.json`: para quem a mensagem foi enviada pelo painel e quando.
- `acessos.json`: quem abriu a página pelo link pessoal e quando.
- `segredo.txt`: segredo que gera o código de cada link pessoal. Trocar invalida os links já enviados.
- `pagina.json`: os textos editados no painel (rascunho e publicado) e o histórico de publicações.
- `pagina-backups/`: cada versão da página antes de uma publicação.

Os telefones são comparados por DDD + 8 últimos dígitos: o WhatsApp mostra o celular sem o 9 da frente e a pessoa digita com ele. Número de fora do Brasil começa com `+`.

**Link pessoal:** a mensagem enviada pelo painel leva `.../confirmar/?c=<código>`. O código sai do telefone da pessoa com o `segredo.txt`, então não dá para adivinhar o de outra pessoa. Ao abrir, a página avisa o `acesso.php` (registra o acesso e preenche o WhatsApp) e a resposta vai com o código, ligada à pessoa do grupo mesmo que ela digite outro número. Na aba do grupo, cada pessoa aparece em uma etapa: falta enviar → enviada → abriu a página → vai / não vai.

**ManyChat:** na aba do grupo, "Baixar lista para o ManyChat" gera um CSV de quem ainda não respondeu (`phone` com + e país, `first_name`, `last_name`, `link_confirmacao`, `situacao`). Ligando `link_confirmacao` a um campo do contato na importação e usando esse campo na mensagem, quem abrir a página aparece no painel como "abriu". Opcionalmente marca todos como "mensagem enviada".

O painel cruza com `aplicacoes.ndjson` (pelo WhatsApp ou pelo e-mail) e avisa quem confirmou sem ter aplicado.

## Trocar a senha do painel

```bash
php -r 'echo password_hash("NOVA-SENHA", PASSWORD_DEFAULT), "\n";'
```

Cole o resultado em `NMC_SENHA_HASH` dentro de `/home/arlindo/data/nm-pocket/confirmacao-config.php`.

## Publicar uma alteração de código

```bash
rsync -av nm-pocket/confirmar/ root@SERVIDOR:/home/arlindo/sites/iuv.com.br/nm-pocket/confirmar/
rsync -av --exclude config.exemplo.php nm-pocket-app/ root@SERVIDOR:/home/arlindo/sites/iuv.com.br/nm-pocket-app/
ssh root@SERVIDOR 'sudo -u www-data php /home/arlindo/sites/iuv.com.br/nm-pocket-app/pagina.php regenerar'
```

A pasta `nm-pocket/confirmar/` no servidor pertence ao grupo `www-data` com escrita, porque é o
painel que grava o `index.html` dela.
