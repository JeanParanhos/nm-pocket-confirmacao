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
nm-pocket-app/confirmacoes.php        painel: página, confirmações, grupo, check-in, planilhas
nm-pocket-app/confirmacao-comum.php   caminhos e funções usados por todos
nm-pocket-app/config.exemplo.php      modelo do arquivo de senha
nm-pocket/confirmar/uelicon.mp4       vídeo da página (e a capa, uelicon-capa.jpg)
```

O `nm-pocket/confirmar/index.html` que o público abre **não fica no repositório**: ele é gerado
pelo painel a partir do modelo.

## Editar a página

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
- `pagina.json`: os textos editados no painel (rascunho e publicado) e o histórico de publicações.
- `pagina-backups/`: cada versão da página antes de uma publicação.

Os telefones são comparados por DDD + 8 últimos dígitos: o WhatsApp mostra o celular sem o 9 da frente e a pessoa digita com ele. Número de fora do Brasil começa com `+`.

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
