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
nm-pocket/confirmar/index.html        página pública (HTML + JS, sem dependências)
nm-pocket-app/confirmar.php           recebe o envio e grava
nm-pocket-app/confirmacoes.php        painel: login, números, lista, check-in, planilha
nm-pocket-app/confirmacao-comum.php   caminhos e funções usados pelos dois
nm-pocket-app/config.exemplo.php      modelo do arquivo de senha
```

No servidor, cada pasta vai para o mesmo caminho dentro de `/home/arlindo/sites/iuv.com.br/`.
O Nginx do site já executa PHP em `/nm-pocket-app/`, então não precisa mudar nada nele.

## Onde ficam os dados

Tudo fora do site, em `/home/arlindo/data/nm-pocket/`, junto das aplicações:

- `confirmacoes.ndjson`: uma linha por resposta. Quem responde de novo não é barrado: no painel vale a **última** resposta de cada WhatsApp.
- `checkin.json`: quem chegou no dia (WhatsApp → hora).
- `confirmacao-config.php`: **hash da senha do painel**. Não vai para o repositório.

O painel cruza com `aplicacoes.ndjson` (pelo WhatsApp ou pelo e-mail) e avisa quem confirmou sem ter aplicado.

## Trocar a senha do painel

```bash
php -r 'echo password_hash("NOVA-SENHA", PASSWORD_DEFAULT), "\n";'
```

Cole o resultado em `NMC_SENHA_HASH` dentro de `/home/arlindo/data/nm-pocket/confirmacao-config.php`.

## Publicar uma alteração

```bash
rsync -av nm-pocket/confirmar/ root@SERVIDOR:/home/arlindo/sites/iuv.com.br/nm-pocket/confirmar/
rsync -av --exclude config.exemplo.php nm-pocket-app/ root@SERVIDOR:/home/arlindo/sites/iuv.com.br/nm-pocket-app/
```
