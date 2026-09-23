<?php
/**
 * NM Pocket — painel das confirmações de presença.
 * Entra com senha (o hash mora fora do repositório, em NMC_CONFIG).
 * Uma linha por pessoa (WhatsApp): vale a resposta mais recente.
 * Cruza com as aplicações para mostrar quem confirmou sem ter aplicado.
 */

declare(strict_types=1);

require __DIR__ . '/confirmacao-comum.php';
require __DIR__ . '/pagina.php';

header('X-Robots-Tag: noindex, nofollow, noarchive');
header('Cache-Control: no-store');

session_name('nmc_painel');
session_set_cookie_params([
    'lifetime' => 60 * 60 * 24 * 7,
    'path'     => '/nm-pocket-app/',
    'secure'   => true,
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_start();

$eu = basename(__FILE__);

// ─────────────── Login ───────────────
if (!is_file(NMC_CONFIG)) {
    http_response_code(500);
    exit('Falta o arquivo de senha do painel no servidor (ver config.exemplo.php).');
}
require NMC_CONFIG;

if (isset($_GET['sair'])) {
    $_SESSION = [];
    session_destroy();
    header('Location: ' . $eu, true, 303);
    exit;
}

$erroLogin = false;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['senha'])) {
    usleep(400000); // freia tentativa em série
    if (password_verify((string) $_POST['senha'], NMC_SENHA_HASH)) {
        session_regenerate_id(true);
        $_SESSION['ok']    = true;
        $_SESSION['token'] = bin2hex(random_bytes(16));
        header('Location: ' . $eu, true, 303);
        exit;
    }
    $erroLogin = true;
}

if (empty($_SESSION['ok'])) {
    ?><!DOCTYPE html>
<html lang="pt-BR"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow"><title>Entrar — Confirmações NM Pocket</title>
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@800;900&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{background:#060d1a;color:#fff;font-family:'Inter',system-ui,sans-serif;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
form{background:#0d1830;border:1px solid rgba(255,255,255,.08);border-radius:14px;padding:30px 26px;width:100%;max-width:360px}
h1{font-family:'Montserrat',sans-serif;font-size:20px;font-weight:900;margin-bottom:6px}
p{color:#8fa4bb;font-size:13.5px;margin-bottom:20px}
input{width:100%;background:#060d1a;border:1px solid rgba(255,255,255,.16);border-radius:10px;padding:13px 14px;color:#fff;font-size:16px;outline:none;margin-bottom:14px}
input:focus{border-color:#00d4ff}
button{width:100%;background:#00d4ff;color:#040d18;border:none;border-radius:100px;padding:13px;font-family:'Montserrat',sans-serif;font-weight:800;font-size:14px;cursor:pointer}
.err{color:#ff8a87;font-size:13.5px;margin-bottom:12px}
</style></head><body>
<form method="post">
  <h1>Confirmações</h1>
  <p>Imersão Novos Milionários Pocket</p>
  <?php if ($erroLogin): ?><div class="err">Senha incorreta.</div><?php endif; ?>
  <input type="password" name="senha" placeholder="Senha" autofocus autocomplete="current-password" aria-label="Senha">
  <button type="submit">Entrar</button>
</form>
</body></html><?php
    exit;
}

// ─────────────── Check-in (fetch) ───────────────
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['acao'])) {
    if ($_POST['acao'] !== 'salvar_grupo') {
        header('Content-Type: application/json; charset=utf-8');
    }
    if (!hash_equals((string) ($_SESSION['token'] ?? ''), (string) ($_POST['token'] ?? ''))) {
        http_response_code(403);
        exit(json_encode(['ok' => false]));
    }
    // Lista do grupo: vem do formulário da aba Grupo e volta para ela.
    if ($_POST['acao'] === 'salvar_grupo') {
        $texto = str_replace("\r\n", "\n", (string) ($_POST['grupo'] ?? ''));
        if (is_file(NMC_GRUPO)) {
            @copy(NMC_GRUPO, NMC_GRUPO . '.bak-' . date('Ymd-His'));
        }
        $ok = file_put_contents(NMC_GRUPO, $texto, LOCK_EX) !== false;
        header('Location: ' . $eu . '?aba=grupo&salvo=' . ($ok ? count(nmc_parse_grupo($texto)) : 'erro'), true, 303);
        exit;
    }

    // Grupo: editar, acrescentar e tirar uma pessoa (linha do grupo.txt).
    if (in_array($_POST['acao'], ['editar_pessoa', 'adicionar_pessoa', 'remover_pessoa'], true)) {
        $nome = str_replace('|', '/', nmc_corta(trim((string) ($_POST['nome'] ?? '')), 120));
        $tel  = nmc_corta(trim((string) ($_POST['fone'] ?? '')), 30);
        if ($_POST['acao'] !== 'remover_pessoa' && $nome === '' && $tel === '') {
            exit(json_encode(['ok' => false, 'erro' => 'Preencha o nome ou o telefone.']));
        }
        if ($tel !== '' && !nmc_fone_valido($tel)) {
            exit(json_encode(['ok' => false, 'erro' => 'Telefone inválido: use DDD + número, ou + e o código do país.']));
        }
        $linhas = is_file(NMC_GRUPO) ? preg_split('/\R/', (string) file_get_contents(NMC_GRUPO)) : [];
        $nova   = trim($nome . ' | ' . $tel);
        if ($_POST['acao'] === 'adicionar_pessoa') {
            $linhas[] = $nova;
        } else {
            // A linha precisa ser a mesma que a tela mostrou: se alguém mexeu no meio, recusa.
            $i = (int) ($_POST['linha'] ?? -1);
            if (!isset($linhas[$i]) || trim($linhas[$i]) !== trim((string) ($_POST['antes'] ?? ''))) {
                exit(json_encode(['ok' => false, 'erro' => 'A lista mudou enquanto você editava. Recarregue a página.']));
            }
            if ($_POST['acao'] === 'remover_pessoa') {
                unset($linhas[$i]);
            } else {
                $linhas[$i] = $nova;
            }
        }
        @copy(NMC_GRUPO, NMC_GRUPO . '.bak-' . date('Ymd-His'));
        $ok = file_put_contents(NMC_GRUPO, rtrim(implode("\n", $linhas)) . "\n", LOCK_EX) !== false;
        // Devolve o que a linha passa a ser, para a tela se atualizar sem recarregar.
        $ch = $tel !== '' ? nmc_chave($tel) : '';
        exit(json_encode([
            'ok'    => $ok,
            'antes' => $nova,
            'chave' => $ch,
            'wa'    => $ch !== '' ? nmc_wa($tel) : '',
            'link'  => $ch !== '' ? 'https://iuv.com.br/nm-pocket/confirmar/?c=' . nmc_codigo($ch) : '',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    if ($_POST['acao'] === 'salvar_mensagem') {
        $msg = trim(str_replace("\r\n", "\n", (string) ($_POST['mensagem'] ?? '')));
        exit(json_encode(['ok' => file_put_contents(NMC_MENSAGEM, nmc_corta($msg, 3000), LOCK_EX) !== false]));
    }

    if (in_array($_POST['acao'], ['marcar_enviado', 'desmarcar_enviado'], true)) {
        $chave = preg_replace('/[^0-9x]/', '', (string) ($_POST['chave'] ?? ''));
        $mapa  = nmc_ler_json(NMC_ENVIOS);
        if ($_POST['acao'] === 'marcar_enviado' && $chave !== '') {
            $antes = $mapa[$chave] ?? ['vezes' => 0];
            $mapa[$chave] = ['em' => (new DateTimeImmutable('now'))->format('c'), 'vezes' => (int) $antes['vezes'] + 1];
        } else {
            unset($mapa[$chave]);
        }
        exit(json_encode(['ok' => nmc_salvar_json(NMC_ENVIOS, $mapa), 'em' => date('d/m H:i')]));
    }

    // Página de confirmação: rascunho, publicar, descartar.
    if (in_array($_POST['acao'], ['salvar_pagina', 'publicar_pagina', 'descartar_pagina'], true)) {
        $d = nmp_dados();
        if ($_POST['acao'] === 'descartar_pagina') {
            $d['rascunho'] = $d['publicado'];
            exit(json_encode(['ok' => nmp_salvar_dados($d)]));
        }
        $textos = json_decode((string) ($_POST['textos'] ?? ''), true);
        if (is_array($textos)) {
            $d['rascunho'] = nmp_diferencas($textos);
            nmp_salvar_dados($d);
        }
        if ($_POST['acao'] === 'salvar_pagina') {
            exit(json_encode(['ok' => true, 'pendente' => $d['rascunho'] !== $d['publicado']]));
        }
        [$ok, $n] = nmp_publicar();
        exit(json_encode(['ok' => $ok, 'textos' => $n, 'em' => date('d/m H:i')]));
    }

    $fone = preg_replace('/[^0-9x]/', '', (string) ($_POST['fone'] ?? ''));
    $mapa = nmc_ler_json(NMC_CHECKIN);
    if ($_POST['acao'] === 'checkin' && $fone !== '') {
        $mapa[$fone] = (new DateTimeImmutable('now'))->format('c');
    } elseif ($_POST['acao'] === 'desfazer' && $fone !== '') {
        unset($mapa[$fone]);
    }
    $ok = nmc_salvar_json(NMC_CHECKIN, $mapa);
    exit(json_encode(['ok' => $ok, 'em' => isset($mapa[$fone]) ? date('d/m H:i', strtotime($mapa[$fone])) : null]));
}

// ─────────────── Prévia editável (vai dentro do iframe da aba Página) ───────────────
if (isset($_GET['previa'])) {
    $html = nmp_montar(nmp_dados()['rascunho']);
    // As telas de depois do envio ficam escondidas na página; na prévia aparecem para editar.
    $extra = '<style>
      .fim{display:block!important;margin-top:18px;position:relative}
      .fim::before{position:absolute;top:-11px;left:18px;background:#00d4ff;color:#040d18;font:800 10.5px/1 Montserrat,sans-serif;
        letter-spacing:.8px;text-transform:uppercase;padding:5px 10px;border-radius:100px}
      #fim-sim::before{content:"Tela depois de responder: vou"}
      #fim-nao::before{content:"Tela depois de responder: não vou"}
    </style>';
    header('Content-Type: text/html; charset=utf-8');
    exit(str_replace('</head>', $extra . '</head>', $html));
}

// ─────────────── Dados ───────────────
// Uma pessoa = um WhatsApp. O arquivo está em ordem de chegada, então a última linha vence.
$pessoas = [];
$porGrupo = []; // resposta que veio pelo link pessoal: chave do grupo => presença
foreach (nmc_ler_ndjson(NMC_ARQUIVO) as $reg) {
    $chave = (string) ($reg['chave'] ?? nmc_chave((string) ($reg['whatsapp'] ?? '')));
    if ($chave === '') {
        continue;
    }
    if (($reg['grupo_chave'] ?? '') !== '') {
        $porGrupo[$reg['grupo_chave']] = (string) $reg['presenca'];
    }
    $respostas = ($pessoas[$chave]['respostas'] ?? 0) + 1;
    $pessoas[$chave] = $reg;
    $pessoas[$chave]['respostas'] = $respostas;
}

// Quem aplicou (por WhatsApp e por e-mail).
$aplicouFone  = [];
$aplicouEmail = [];
foreach (nmc_ler_ndjson(NMC_APLICACOES) as $ap) {
    $r = $ap['respostas'] ?? [];
    $f = nmc_chave((string) ($r['whatsapp'] ?? ''));
    if ($f !== '') {
        $aplicouFone[$f] = true;
    }
    $m = strtolower(trim((string) ($r['email'] ?? '')));
    if ($m !== '') {
        $aplicouEmail[$m] = true;
    }
}

$checkin = nmc_ler_json(NMC_CHECKIN);

$vao = $naoVao = $chegaram = $semAplicacao = 0;
$linhas = [];
foreach ($pessoas as $fone => $p) {
    $vai     = ($p['presenca'] ?? '') === NMC_PRESENCA[0];
    $aplicou = isset($aplicouFone[$fone]) || ($p['email'] !== '' && isset($aplicouEmail[$p['email']]));
    $chegou  = isset($checkin[$fone]);

    $vai ? $vao++ : $naoVao++;
    if ($chegou) {
        $chegaram++;
    }
    if ($vai && !$aplicou) {
        $semAplicacao++;
    }

    $linhas[] = [
        'fone'      => $fone,
        'wa'        => (string) ($p['fone'] ?? nmc_wa((string) $p['whatsapp'])),
        'data'      => date('d/m H:i', strtotime((string) $p['criado_em'])),
        'ts'        => strtotime((string) $p['criado_em']),
        'nome'      => (string) $p['nome'],
        'whatsapp'  => (string) $p['whatsapp'],
        'email'     => (string) $p['email'],
        'vai'       => $vai,
        'aplicou'   => $aplicou,
        'respostas' => (int) $p['respostas'],
        'grupo'     => (string) ($p['grupo_chave'] ?? ''),
        'checkin'   => $chegou ? date('d/m H:i', strtotime($checkin[$fone])) : '',
    ];
}
usort($linhas, static fn($a, $b) => $b['ts'] <=> $a['ts']);

// ─────────────── Grupo do WhatsApp ───────────────
// Cada pessoa anda por: mensagem enviada → acessou a página → vai / não vai.
$linkConfirmar = 'https://iuv.com.br/nm-pocket/confirmar/';
$grupo   = nmc_ler_grupo();
$envios  = nmc_ler_json(NMC_ENVIOS);
$acessos = nmc_ler_json(NMC_ACESSOS);
$gVai = $gNao = $gEnviados = $gAcessaram = 0;
$faltam = [];
$gente  = [];
foreach ($grupo as $g) {
    $ch = $g['chave'];
    $presenca = '';
    if ($ch !== '') {
        $presenca = $porGrupo[$ch] ?? (string) ($pessoas[$ch]['presenca'] ?? '');
    }
    $etapa = match (true) {
        $ch === ''                    => 'sem_numero',
        $presenca === NMC_PRESENCA[0] => 'vai',
        $presenca !== ''              => 'nao',
        isset($acessos[$ch])          => 'acessou',
        isset($envios[$ch])           => 'enviado',
        default                       => 'falta_enviar',
    };
    if ($etapa === 'vai') {
        $gVai++;
    } elseif ($etapa === 'nao') {
        $gNao++;
    }
    if ($ch !== '' && isset($envios[$ch])) {
        $gEnviados++;
    }
    if ($ch !== '' && isset($acessos[$ch])) {
        $gAcessaram++;
    }
    $item = $g + [
        'wa'      => $ch !== '' ? nmc_wa($g['fone']) : '',
        'link'    => $ch !== '' ? $linkConfirmar . '?c=' . nmc_codigo($ch) : '',
        'etapa'   => $etapa,
        'enviado' => isset($envios[$ch]) ? date('d/m H:i', strtotime($envios[$ch]['em'])) : '',
        'acessou' => isset($acessos[$ch]) ? date('d/m H:i', strtotime($acessos[$ch]['ultimo'])) : '',
    ];
    $gente[] = $item;
    if (!in_array($etapa, ['vai', 'nao'], true)) {
        $faltam[] = $item;
    }
}
$foraDoGrupo = 0;
$chavesGrupo = array_flip(array_filter(array_column($grupo, 'chave')));
foreach ($linhas as &$l) {
    $l['no_grupo'] = isset($chavesGrupo[$l['fone']]) || $l['grupo'] !== '';
    if (!$l['no_grupo']) {
        $foraDoGrupo++;
    }
}
unset($l);
$mensagem = nmc_mensagem();

if (($_GET['csv'] ?? '') === 'faltam') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="nm-pocket-faltam-confirmar-' . date('Y-m-d') . '.csv"');
    $saida = fopen('php://output', 'w');
    fwrite($saida, "\xEF\xBB\xBF");
    fputcsv($saida, ['Nome no grupo', 'Telefone', 'Número (com país)', 'Link de confirmação'], ';');
    foreach ($faltam as $f) {
        fputcsv($saida, [$f['nome'], $f['fone'], $f['wa'], $f['link']], ';');
    }
    fclose($saida);
    exit;
}

$aba = in_array($_GET['aba'] ?? '', ['grupo', 'confirmacoes'], true) ? $_GET['aba'] : 'pagina';
$pag = nmp_dados();
$pagPendente = $pag['rascunho'] !== $pag['publicado'];

// ─────────────── CSV ───────────────
if (isset($_GET['csv'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="nm-pocket-confirmacoes-' . date('Y-m-d') . '.csv"');
    $saida = fopen('php://output', 'w');
    fwrite($saida, "\xEF\xBB\xBF");
    fputcsv($saida, ['Respondeu em', 'Nome', 'WhatsApp', 'E-mail', 'Presença', 'Aplicou antes', 'Respostas', 'Check-in'], ';');
    foreach ($linhas as $l) {
        fputcsv($saida, [
            $l['data'], $l['nome'], $l['whatsapp'], $l['email'],
            $l['vai'] ? 'Vai' : 'Não vai', $l['aplicou'] ? 'Sim' : 'Não', $l['respostas'], $l['checkin'],
        ], ';');
    }
    fclose($saida);
    exit;
}

$token = (string) $_SESSION['token'];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Confirmações — NM Pocket</title>
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@700;800;900&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<style>
  *,*::before,*::after{margin:0;padding:0;box-sizing:border-box}
  :root{--bg:#060d1a;--card:#0d1830;--card2:#111e38;--accent:#00d4ff;--text:#fff;--muted:#8fa4bb;--border:rgba(255,255,255,.08)}
  body{background:var(--bg);color:var(--text);font-family:'Inter',system-ui,sans-serif;line-height:1.55;-webkit-font-smoothing:antialiased}
  .wrap{max-width:1180px;margin:0 auto;padding:0 20px}
  .topo{padding:32px 0 24px;display:flex;flex-wrap:wrap;gap:16px;align-items:center;justify-content:space-between}
  .topo h1{font-family:'Montserrat',sans-serif;font-size:25px;font-weight:900;letter-spacing:-.3px}
  .topo .sub{font-size:13.5px;color:var(--muted);margin-top:3px}
  .acoes{display:flex;gap:10px;flex-wrap:wrap}
  .bt{display:inline-block;padding:10px 18px;border-radius:100px;font-size:13px;font-weight:700;font-family:'Montserrat',sans-serif;text-decoration:none;cursor:pointer;border:1px solid var(--border);background:var(--card);color:var(--text)}
  .bt:hover{border-color:rgba(0,212,255,.45)}
  .bt.pri{background:var(--accent);color:#040d18;border-color:transparent}

  .cards{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:24px}
  .kpi{background:var(--card);border:1px solid var(--border);border-radius:14px;padding:18px 20px}
  .kpi .n{font-family:'Montserrat',sans-serif;font-size:30px;font-weight:900;color:var(--accent);line-height:1.1}
  .kpi .n.verde{color:#4ade80}.kpi .n.verm{color:#ff8a87}.kpi .n.amar{color:#ffc94d}
  .kpi .l{font-size:12.5px;color:var(--muted);margin-top:5px}

  .filtros{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px;align-items:center}
  .filtros input{flex:1;min-width:220px;background:var(--card);border:1px solid var(--border);border-radius:10px;padding:11px 14px;color:var(--text);font-size:14px;outline:none}
  .filtros input:focus{border-color:rgba(0,212,255,.5)}
  .abas{display:flex;gap:6px;flex-wrap:wrap}
  .aba{background:var(--card);border:1px solid var(--border);color:var(--muted);border-radius:100px;padding:9px 15px;font-size:13px;font-weight:600;cursor:pointer}
  .aba[aria-pressed=true]{background:rgba(0,212,255,.12);border-color:rgba(0,212,255,.45);color:var(--text)}

  .tabela-box{background:var(--card);border:1px solid var(--border);border-radius:14px;overflow:hidden;margin-bottom:50px}
  .rolagem{overflow-x:auto}
  table{width:100%;border-collapse:collapse;font-size:13.5px;min-width:820px}
  th{background:var(--card2);text-align:left;padding:12px 14px;font-size:11.5px;font-weight:700;letter-spacing:.7px;text-transform:uppercase;color:var(--muted);white-space:nowrap}
  td{padding:12px 14px;border-top:1px solid var(--border);vertical-align:middle}
  .nome{font-weight:600}
  .sub2{font-size:12px;color:var(--muted)}
  a.wa{color:var(--accent);text-decoration:none}
  a.wa:hover{text-decoration:underline}
  .tag{display:inline-block;padding:3px 10px;border-radius:100px;font-size:11.5px;font-weight:600;white-space:nowrap}
  .tag.sim{background:rgba(37,211,102,.15);color:#4ade80}
  .tag.nao{background:rgba(239,83,80,.15);color:#ff8a87}
  .tag.alerta{background:rgba(255,193,7,.15);color:#ffc94d}
  .ck{border:1px solid var(--border);background:var(--card2);color:var(--text);border-radius:100px;padding:7px 13px;font-size:12px;font-weight:700;cursor:pointer;white-space:nowrap}
  .ck:hover{border-color:rgba(37,211,102,.6)}
  .ck.feito{background:rgba(37,211,102,.15);border-color:transparent;color:#4ade80}
  .vazio{padding:56px 24px;text-align:center;color:var(--muted);font-size:15px}
  .link-conf{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:12px 16px;margin-bottom:22px;font-size:13.5px;color:var(--muted);display:flex;gap:10px;flex-wrap:wrap;align-items:center}
  .link-conf code{color:var(--text);font-size:13px;word-break:break-all}
  .menu{display:flex;gap:4px;border-bottom:1px solid var(--border);margin-bottom:22px;overflow-x:auto}
  .menu a{padding:11px 16px;color:var(--muted);text-decoration:none;font-weight:600;font-size:14px;border-bottom:2px solid transparent;margin-bottom:-1px;white-space:nowrap}
  .menu a span{font-size:12px;background:var(--card2);border-radius:100px;padding:2px 8px;margin-left:4px}
  .menu a[aria-current]{color:var(--text);border-bottom-color:var(--accent)}
  .aviso{background:rgba(0,212,255,.08);border:1px solid rgba(0,212,255,.28);border-radius:12px;padding:12px 16px;margin-bottom:20px;font-size:14px}
  .aviso.alerta{background:rgba(239,83,80,.1);border-color:rgba(239,83,80,.3)}
  .barra-grupo{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:12px}
  .barra-grupo h2,.painel-lista h2{font-family:'Montserrat',sans-serif;font-size:16px;font-weight:800}
  .barra-grupo h2 small{font-weight:500;color:var(--muted);font-size:12.5px;margin-left:6px}
  .nota-grupo{font-size:13px;color:var(--muted);margin-bottom:12px}
  table.estreita{min-width:560px}
  a.ck{text-decoration:none;display:inline-block}
  .painel-lista{background:var(--card);border:1px solid var(--border);border-radius:14px;padding:20px 22px;margin-bottom:50px}
  .painel-lista p{font-size:13px;color:var(--muted);margin:6px 0 12px}
  .painel-lista code{color:var(--text)}
  .painel-lista textarea{width:100%;background:var(--bg);border:1px solid rgba(255,255,255,.16);border-radius:10px;padding:12px 14px;color:var(--text);font:13px/1.6 ui-monospace,Menlo,monospace;margin-bottom:12px;resize:vertical}
  .painel-lista textarea:focus{outline:none;border-color:var(--accent)}
  .menu a span.pend{background:rgba(255,193,7,.18);color:#ffc94d}
  .ed-barra{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:10px}
  .ed-estado{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
  .larguras{display:flex;gap:6px}
  .ed-dica{font-size:13px;color:var(--muted);margin-bottom:14px}
  .ed-palco{background:var(--card2);border:1px solid var(--border);border-radius:14px;padding:18px;display:flex;justify-content:center;margin-bottom:50px}
  .ed-palco iframe{height:78vh;min-height:560px;max-width:100%;border:1px solid var(--border);border-radius:12px;background:#060d1a;transition:width .2s}
  .bt.perigo{background:rgba(239,83,80,.13);border-color:rgba(239,83,80,.32);color:#ff8a87}
  .bt[hidden]{display:none}
  .ed-dialogo{background:var(--card);color:var(--text);border:1px solid var(--border);border-radius:14px;padding:24px;max-width:440px;margin:auto}
  .ed-dialogo::backdrop{background:rgba(0,0,0,.6)}
  .ed-dialogo h3{font-family:'Montserrat',sans-serif;font-size:18px;font-weight:900;margin-bottom:8px}
  .ed-dialogo p{font-size:14px;color:var(--muted);margin-bottom:18px;word-break:break-word}
  .ed-dialogo .acoes{justify-content:flex-end}
  .cards-6{grid-template-columns:repeat(6,1fr)}
  .tag.azul{background:rgba(0,212,255,.14);color:#6fe3ff}
  .tag.cinza{background:rgba(255,255,255,.08);color:#c9d6e3}
  td.acoes-linha{white-space:nowrap;text-align:right}
  .lapis{background:none;border:1px solid transparent;color:var(--muted);font-size:15px;border-radius:8px;padding:6px 9px;cursor:pointer;margin-left:4px}
  .lapis:hover{color:var(--text);border-color:var(--border);background:var(--card2)}
  .inline{display:inline-flex;align-items:center;gap:6px;max-width:100%}
  .inline .v{overflow:hidden;text-overflow:ellipsis}
  .lapis-in{background:none;border:none;color:var(--muted);font-size:13px;cursor:pointer;padding:3px 6px;border-radius:6px;opacity:.45;transition:opacity .12s,background .12s}
  tr:hover .lapis-in,.lapis-in:focus-visible{opacity:1}
  .lapis-in:hover{background:var(--card2);color:var(--text)}
  .inline.editando .v,.inline.editando .lapis-in{display:none}
  .campo-in{background:var(--bg);border:1px solid var(--accent);border-radius:8px;padding:7px 10px;color:var(--text);font:14px 'Inter',sans-serif;width:230px;max-width:100%;outline:none}
  .campo-in.erro{border-color:#ef5350}
  .erro-in{color:#ff8a87;font-size:12px;font-weight:500}
  .inline.salvo .v{color:#4ade80;transition:color .3s}
  tr.acabou-de-enviar{background:rgba(0,212,255,.05)}
  details.painel-msg,details.painel-lista{background:var(--card);border:1px solid var(--border);border-radius:14px;padding:16px 20px;margin-bottom:20px}
  details summary{cursor:pointer;font-family:'Montserrat',sans-serif;font-weight:800;font-size:15px}
  details[open] summary{margin-bottom:6px}
  .painel-msg p{font-size:13px;color:var(--muted);margin:6px 0 12px}
  .painel-msg code{color:var(--text)}
  .painel-msg textarea,.ed-dialogo textarea,.ed-dialogo input{width:100%;background:var(--bg);border:1px solid rgba(255,255,255,.16);border-radius:10px;padding:12px 14px;color:var(--text);font:14px/1.55 'Inter',sans-serif;margin-bottom:12px;resize:vertical;outline:none}
  .painel-msg textarea:focus,.ed-dialogo textarea:focus,.ed-dialogo input:focus{border-color:var(--accent)}
  .ed-dialogo.largo{max-width:560px;width:calc(100vw - 32px)}
  .ed-dialogo .rot-dlg{display:block;font-size:13px;font-weight:600;margin-bottom:6px}
  .ed-dialogo .sub2{margin-bottom:10px}
  .erro-dlg{color:#ff8a87;font-size:13.5px;margin-bottom:10px}
  .barra-grupo{margin-top:-30px;margin-bottom:24px}
  .painel-lista{margin-bottom:50px}
  @media (max-width:1100px){.cards-6{grid-template-columns:repeat(3,1fr)}}
  @media (max-width:760px){.cards{grid-template-columns:repeat(2,1fr)}.topo h1{font-size:21px}}
</style>
</head>
<body>
<div class="wrap">
  <div class="topo">
    <div>
      <h1>Confirmações de presença</h1>
      <div class="sub">Imersão Novos Milionários Pocket · 26/09 · AlphaPark Hotel</div>
    </div>
    <div class="acoes">
      <a class="bt" href="?sair=1">Sair</a>
    </div>
  </div>

  <nav class="menu" aria-label="Seções">
    <a href="<?= e($eu) ?>" <?= $aba === 'pagina' ? 'aria-current="page"' : '' ?>>Página de confirmação<?php if ($pagPendente): ?> <span class="pend">rascunho</span><?php endif; ?></a>
    <a href="?aba=confirmacoes" <?= $aba === 'confirmacoes' ? 'aria-current="page"' : '' ?>>Confirmações <span><?= count($linhas) ?></span></a>
    <a href="?aba=grupo" <?= $aba === 'grupo' ? 'aria-current="page"' : '' ?>>Grupo do WhatsApp <span><?= count($faltam) ?> faltam</span></a>
  </nav>

<?php if ($aba === 'pagina'): ?>
  <div class="ed-barra">
    <div class="ed-estado">
      <span id="ed-selo" class="tag <?= $pagPendente ? 'alerta' : 'sim' ?>"><?= $pagPendente ? 'rascunho não publicado' : 'no ar igual ao painel' ?></span>
      <span class="sub2" id="ed-quando"><?= $pag['publicado_em'] ? 'publicado ' . date('d/m H:i', strtotime($pag['publicado_em'])) : 'nunca publicado pelo painel' ?></span>
    </div>
    <div class="acoes">
      <div class="larguras" role="group" aria-label="Largura da prévia">
        <button class="aba" data-larg="100%" aria-pressed="false">Computador</button>
        <button class="aba" data-larg="390px" aria-pressed="true">Celular</button>
      </div>
      <a class="bt" href="<?= e($linkConfirmar) ?>" target="_blank" rel="noopener">Ver no ar ↗</a>
      <button class="bt perigo" id="ed-descartar" type="button" <?= $pagPendente ? '' : 'hidden' ?>>Descartar rascunho</button>
      <button class="bt" id="ed-salvar" type="button">Salvar rascunho</button>
      <button class="bt pri" id="ed-publicar" type="button">Publicar</button>
    </div>
  </div>
  <p class="ed-dica">Clique em qualquer texto da página e escreva por cima. <b>Ctrl/⌘+B</b> deixa em negrito. <b>Salvar rascunho</b> guarda sem mexer no ar; <b>Publicar</b> leva para a página que o pessoal abre.</p>
  <div class="ed-palco"><iframe id="ed-tela" src="?previa=1" title="Prévia editável da página de confirmação" style="width:390px"></iframe></div>

  <dialog id="ed-confirma" class="ed-dialogo">
    <h3>Publicar a página?</h3>
    <p>Quem abrir <b><?= e($linkConfirmar) ?></b> passa a ver os textos novos na hora. A versão anterior fica guardada.</p>
    <div class="acoes"><button class="bt" value="nao" id="ed-cancela">Cancelar</button><button class="bt pri" id="ed-vai">Publicar agora</button></div>
  </dialog>

  <script>
  (function () {
    var TOKEN = <?= json_encode($token) ?>;
    var tela = document.getElementById('ed-tela');
    var selo = document.getElementById('ed-selo');
    var btDesc = document.getElementById('ed-descartar');
    var mudou = false;

    function doc() { return tela.contentDocument; }
    function textos() {
      var t = {};
      doc().querySelectorAll('[data-ed]').forEach(function (el) { t[el.getAttribute('data-ed')] = el.innerHTML; });
      return t;
    }
    function estado(pendente) {
      selo.className = 'tag ' + (pendente ? 'alerta' : 'sim');
      selo.textContent = pendente ? 'rascunho não publicado' : 'no ar igual ao painel';
      btDesc.hidden = !pendente;
    }

    tela.addEventListener('load', function () {
      var d = doc();
      var st = d.createElement('style');
      st.textContent = '[data-ed]{outline:1px dashed rgba(0,212,255,.35);outline-offset:4px;border-radius:4px;cursor:text;transition:outline-color .12s,background .12s}'
        + '[data-ed]:hover{outline-color:#00d4ff;background:rgba(0,212,255,.06)}'
        + '[data-ed]:focus{outline:2px solid #00d4ff;background:rgba(0,212,255,.08)}'
        + '[data-ed].ed-mudado{outline-color:#4ade80}';
      d.head.appendChild(st);
      d.querySelectorAll('[data-ed]').forEach(function (el) {
        el.setAttribute('contenteditable', 'true');
        el.addEventListener('input', function () { el.classList.add('ed-mudado'); mudou = true; estado(true); });
        el.addEventListener('keydown', function (e) {
          if (e.key === 'Enter') { e.preventDefault(); d.execCommand('insertLineBreak'); }
        });
        el.addEventListener('paste', function (e) {
          e.preventDefault();
          d.execCommand('insertText', false, (e.clipboardData || window.clipboardData).getData('text/plain'));
        });
      });
      // Na prévia nada navega nem envia: link e formulário ficam parados.
      d.addEventListener('click', function (e) { if (e.target.closest('a')) e.preventDefault(); }, true);
      d.addEventListener('submit', function (e) { e.preventDefault(); e.stopImmediatePropagation(); }, true);
    });

    function enviar(acao, extra) {
      var f = new FormData();
      f.append('acao', acao); f.append('token', TOKEN);
      if (extra) f.append('textos', JSON.stringify(extra));
      return fetch(location.pathname, { method: 'POST', body: f, credentials: 'same-origin' }).then(function (r) { return r.json(); });
    }

    document.getElementById('ed-salvar').addEventListener('click', function () {
      var bt = this; bt.disabled = true;
      enviar('salvar_pagina', textos()).then(function (j) {
        bt.disabled = false; mudou = false; estado(j.pendente);
        bt.textContent = '✓ Rascunho salvo'; setTimeout(function () { bt.textContent = 'Salvar rascunho'; }, 2000);
      }).catch(function () { bt.disabled = false; bt.textContent = 'Falhou, tente de novo'; });
    });

    var dlg = document.getElementById('ed-confirma');
    document.getElementById('ed-publicar').addEventListener('click', function () { dlg.showModal(); });
    document.getElementById('ed-cancela').addEventListener('click', function () { dlg.close(); });
    document.getElementById('ed-vai').addEventListener('click', function () {
      var bt = this; bt.disabled = true; bt.textContent = 'Publicando…';
      enviar('publicar_pagina', textos()).then(function (j) {
        bt.disabled = false; bt.textContent = 'Publicar agora'; dlg.close();
        if (!j.ok) { alert('Não consegui publicar. Nada mudou no ar.'); return; }
        mudou = false; estado(false);
        document.getElementById('ed-quando').textContent = 'publicado ' + j.em;
      }).catch(function () { bt.disabled = false; bt.textContent = 'Publicar agora'; });
    });

    btDesc.addEventListener('click', function () {
      if (!confirm('Descartar o rascunho e voltar aos textos que estão no ar?')) return;
      enviar('descartar_pagina').then(function () { mudou = false; estado(false); tela.src = '?previa=1&t=' + Date.now(); });
    });

    document.querySelectorAll('.larguras .aba').forEach(function (b) {
      b.addEventListener('click', function () {
        document.querySelectorAll('.larguras .aba').forEach(function (x) { x.setAttribute('aria-pressed', 'false'); });
        b.setAttribute('aria-pressed', 'true');
        tela.style.width = b.dataset.larg;
      });
    });

    window.addEventListener('beforeunload', function (e) { if (mudou) { e.preventDefault(); e.returnValue = ''; } });
  })();
  </script>
<?php elseif ($aba === 'grupo'): ?>
  <?php $salvo = (string) ($_GET['salvo'] ?? ''); ?>
  <?php if ($salvo === 'erro'): ?>
    <div class="aviso alerta">Não consegui salvar a lista. Tente de novo.</div>
  <?php elseif ($salvo !== ''): ?>
    <div class="aviso">Lista salva: <?= (int) $salvo ?> pessoas no grupo.</div>
  <?php endif; ?>

  <div class="cards cards-6">
    <div class="kpi"><div class="n"><?= count($grupo) ?></div><div class="l">pessoas no grupo</div></div>
    <div class="kpi"><div class="n"><?= $gEnviados ?></div><div class="l">receberam a mensagem</div></div>
    <div class="kpi"><div class="n"><?= $gAcessaram ?></div><div class="l">abriram a página</div></div>
    <div class="kpi"><div class="n verde"><?= $gVai ?></div><div class="l">confirmaram que vão</div></div>
    <div class="kpi"><div class="n verm"><?= $gNao ?></div><div class="l">avisaram que não vão</div></div>
    <div class="kpi"><div class="n amar"><?= count($faltam) ?></div><div class="l">faltam responder</div></div>
  </div>

  <details class="painel-msg" <?= is_file(NMC_MENSAGEM) ? '' : 'open' ?>>
    <summary>Mensagem para enviar</summary>
    <p>Escreva a mensagem que vai para cada pessoa. <code>{link}</code> vira o link pessoal da página de confirmação (é ele que mostra aqui quem abriu). <code>{nome}</code> vira o nome como está na lista.</p>
    <textarea id="msg-modelo" rows="6"><?= e($mensagem) ?></textarea>
    <button class="bt pri" id="msg-salvar" type="button">Salvar mensagem</button>
  </details>

  <div class="filtros">
    <input type="search" id="g-busca" placeholder="Buscar por nome ou telefone" aria-label="Buscar no grupo">
    <div class="abas" role="group" aria-label="Filtrar o grupo">
      <?php
        $contaEtapa = array_count_values(array_column($gente, 'etapa'));
        $filtrosG = [
            'faltam'       => ['Faltam responder', count($faltam)],
            'falta_enviar' => ['Falta enviar', $contaEtapa['falta_enviar'] ?? 0],
            'enviado'      => ['Enviado, não abriu', $contaEtapa['enviado'] ?? 0],
            'acessou'      => ['Abriu, não respondeu', $contaEtapa['acessou'] ?? 0],
            'vai'          => ['Vão', $gVai],
            'nao'          => ['Não vão', $gNao],
            'fora'         => ['Fora do grupo', $foraDoGrupo],
            'todos'        => ['Todos', count($gente) + $foraDoGrupo],
        ];
      ?>
      <?php foreach ($filtrosG as $k => [$rot, $n]): ?>
        <button class="aba" data-g="<?= $k ?>" aria-pressed="<?= $k === 'faltam' ? 'true' : 'false' ?>"><?= e($rot) ?> (<?= $n ?>)</button>
      <?php endforeach; ?>
    </div>
  </div>
  <?php if ($foraDoGrupo > 0): ?>
    <p class="nota-grupo aviso-fora"><b><?= $foraDoGrupo ?></b> <?= $foraDoGrupo === 1 ? 'pessoa respondeu' : 'pessoas responderam' ?> a página sem estar na lista do grupo. Estão no filtro <b>Fora do grupo</b>, com o botão para colocar no grupo.</p>
  <?php endif; ?>

  <div class="tabela-box">
    <div class="rolagem">
      <table class="estreita" id="g-tabela">
        <thead><tr><th>Nome no grupo</th><th>Telefone</th><th>Situação</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($gente as $p): ?>
          <tr data-etapa="<?= e($p['etapa']) ?>" data-linha="<?= (int) $p['linha'] ?>"
              data-antes="<?= e(trim($p['nome'] . ' | ' . $p['fone'])) ?>"
              data-nome="<?= e($p['nome']) ?>" data-fone="<?= e($p['fone']) ?>"
              data-chave="<?= e($p['chave']) ?>" data-wa="<?= e($p['wa']) ?>" data-link="<?= e($p['link']) ?>"
              data-busca="<?= e(strtolower($p['nome'] . ' ' . preg_replace('/\D/', '', $p['fone']))) ?>">
            <td class="nome"><span class="inline" data-campo="nome"><span class="v"><?= $p['nome'] !== '' ? e($p['nome']) : '<span class="sub2">sem nome</span>' ?></span><button class="lapis-in" type="button" title="Editar nome" aria-label="Editar nome">✎</button></span></td>
            <td><span class="inline" data-campo="fone"><span class="v"><?= $p['fone'] !== '' ? e($p['fone']) : '<span class="tag alerta">sem número</span>' ?></span><button class="lapis-in" type="button" title="Editar telefone" aria-label="Editar telefone">✎</button></span></td>
            <td class="situacao">
              <?php
                echo match ($p['etapa']) {
                    'vai'          => '<span class="tag sim">✓ Vai</span>',
                    'nao'          => '<span class="tag nao">✕ Não vai</span>',
                    'acessou'      => '<span class="tag azul">Abriu a página</span>',
                    'enviado'      => '<span class="tag cinza">Mensagem enviada</span>',
                    'sem_numero'   => '<span class="sub2">complete o telefone</span>',
                    default        => '<span class="sub2">falta enviar</span>',
                };
              ?>
              <div class="sub2 passos">
                <?= $p['enviado'] !== '' ? 'enviada ' . e($p['enviado']) : '' ?>
                <?= $p['acessou'] !== '' ? ($p['enviado'] !== '' ? ' · ' : '') . 'abriu ' . e($p['acessou']) : '' ?>
              </div>
            </td>
            <td class="acoes-linha">
              <?php if ($p['wa'] !== '' && !in_array($p['etapa'], ['vai', 'nao'], true)): ?>
                <button class="ck g-enviar" type="button"><?= $p['enviado'] !== '' ? 'Enviar de novo' : 'Enviar' ?></button>
              <?php endif; ?>
              <button class="lapis g-remover" type="button" title="Tirar do grupo" aria-label="Tirar do grupo">🗑</button>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php foreach ($linhas as $l): if ($l['no_grupo']) continue; ?>
          <tr data-etapa="fora" data-fora="1" data-nome="<?= e($l['nome']) ?>" data-fone="<?= e($l['whatsapp']) ?>"
              data-busca="<?= e(strtolower($l['nome'] . ' ' . preg_replace('/\D/', '', $l['whatsapp']))) ?>">
            <td class="nome"><?= e($l['nome']) ?><?php if ($l['email'] !== ''): ?><div class="sub2"><?= e($l['email']) ?></div><?php endif; ?></td>
            <td><a class="wa" href="https://wa.me/<?= e($l['wa']) ?>" target="_blank" rel="noopener"><?= e($l['whatsapp']) ?></a></td>
            <td class="situacao">
              <?= $l['vai'] ? '<span class="tag sim">✓ Vai</span>' : '<span class="tag nao">✕ Não vai</span>' ?>
              <span class="tag alerta">fora do grupo</span>
              <div class="sub2 passos">respondeu <?= e($l['data']) ?></div>
            </td>
            <td class="acoes-linha"><button class="ck g-por-no-grupo" type="button">Colocar no grupo</button></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <div class="vazio" id="g-nada" hidden>Ninguém nesta situação.</div>
    </div>
  </div>

  <div class="barra-grupo">
    <button class="bt" id="g-adicionar" type="button">+ Adicionar pessoa</button>
    <div class="acoes">
      <button class="bt" id="copiar" type="button">Copiar números de quem falta</button>
      <a class="bt" href="?csv=faltam">Baixar planilha de quem falta</a>
    </div>
  </div>

  <details class="painel-lista">
    <summary>Colar a lista inteira de uma vez</summary>
    <form method="post">
      <p>Uma pessoa por linha, no formato <code>nome | telefone</code>. Pode colar só o telefone. Número repetido conta uma vez. Número de fora do Brasil começa com + e o código do país.</p>
      <textarea name="grupo" rows="14" spellcheck="false"><?= e(is_file(NMC_GRUPO) ? (string) file_get_contents(NMC_GRUPO) : '') ?></textarea>
      <input type="hidden" name="acao" value="salvar_grupo">
      <input type="hidden" name="token" value="<?= e($token) ?>">
      <button class="bt pri" type="submit">Salvar lista</button>
    </form>
  </details>

  <dialog id="g-dlg-enviar" class="ed-dialogo largo">
    <h3>Enviar para <span id="g-env-nome"></span></h3>
    <p class="sub2" id="g-env-fone"></p>
    <label class="rot-dlg" for="g-env-texto">Mensagem que vai ser enviada (pode ajustar só para esta pessoa)</label>
    <textarea id="g-env-texto" rows="8"></textarea>
    <p class="sub2">Vai abrir o WhatsApp Web com a mensagem pronta; lá é só apertar enviar. Aqui fica marcado como enviado.</p>
    <div class="acoes"><button class="bt" type="button" data-fechar>Cancelar</button><button class="bt pri" type="button" id="g-env-vai">Enviar pelo WhatsApp Web</button></div>
  </dialog>

  <dialog id="g-dlg-editar" class="ed-dialogo">
    <h3 id="g-ed-titulo">Editar pessoa</h3>
    <label class="rot-dlg" for="g-ed-nome">Nome</label>
    <input id="g-ed-nome" type="text" maxlength="120">
    <label class="rot-dlg" for="g-ed-fone">Telefone</label>
    <input id="g-ed-fone" type="tel" maxlength="30" placeholder="+55 62 99999-0000">
    <p class="erro-dlg" id="g-ed-erro" hidden></p>
    <div class="acoes">
      <button class="bt perigo" type="button" id="g-ed-remover">Tirar do grupo</button>
      <span style="flex:1"></span>
      <button class="bt" type="button" data-fechar>Cancelar</button>
      <button class="bt pri" type="button" id="g-ed-salvar">Salvar</button>
    </div>
  </dialog>

  <script>
  (function () {
    var TOKEN = <?= json_encode($token) ?>;
    var faltamNumeros = <?= json_encode(array_values(array_filter(array_column($faltam, 'wa')))) ?>;
    var tabela = document.getElementById('g-tabela');
    var filtro = 'faltam';
    var busca = document.getElementById('g-busca');

    function post(dados) {
      var f = new FormData();
      f.append('token', TOKEN);
      Object.keys(dados).forEach(function (k) { f.append(k, dados[k]); });
      return fetch(location.pathname, { method: 'POST', body: f, credentials: 'same-origin' }).then(function (r) { return r.json(); });
    }

    // ── filtro e busca ──
    function aplicar() {
      var t = busca.value.trim().toLowerCase();
      if (/^[\d()\s+\-]+$/.test(t)) t = t.replace(/\D/g, '');
      var vis = 0;
      tabela.querySelectorAll('tbody tr').forEach(function (tr) {
        var e = tr.dataset.etapa;
        var ok = filtro === 'todos' ? true
          : filtro === 'faltam' ? (e !== 'vai' && e !== 'nao' && e !== 'fora')
          : e === filtro;
        if (ok && t) ok = tr.dataset.busca.indexOf(t) !== -1;
        tr.hidden = !ok;
        if (ok) vis++;
      });
      document.getElementById('g-nada').hidden = vis > 0;
    }
    busca.addEventListener('input', aplicar);
    document.querySelectorAll('[data-g]').forEach(function (b) {
      b.addEventListener('click', function () {
        document.querySelectorAll('[data-g]').forEach(function (x) { x.setAttribute('aria-pressed', 'false'); });
        b.setAttribute('aria-pressed', 'true');
        filtro = b.dataset.g;
        aplicar();
      });
    });
    aplicar();

    document.querySelectorAll('dialog [data-fechar]').forEach(function (b) {
      b.addEventListener('click', function () { b.closest('dialog').close(); });
    });

    // ── mensagem-modelo ──
    var modelo = document.getElementById('msg-modelo');
    document.getElementById('msg-salvar').addEventListener('click', function () {
      var bt = this; bt.disabled = true;
      post({ acao: 'salvar_mensagem', mensagem: modelo.value }).then(function (j) {
        bt.disabled = false;
        bt.textContent = j.ok ? '✓ Mensagem salva' : 'Falhou, tente de novo';
        setTimeout(function () { bt.textContent = 'Salvar mensagem'; }, 2200);
      });
    });

    // ── enviar ──
    var dlgEnv = document.getElementById('g-dlg-enviar');
    var trEnv = null;
    function montar(tr) {
      return modelo.value.replace(/\{link\}/g, tr.dataset.link).replace(/\{nome\}/g, tr.dataset.nome || '').trim();
    }
    tabela.addEventListener('click', function (e) {
      var bt = e.target.closest('.g-enviar');
      if (bt) {
        trEnv = bt.closest('tr');
        document.getElementById('g-env-nome').textContent = trEnv.dataset.nome || trEnv.dataset.fone;
        document.getElementById('g-env-fone').textContent = trEnv.dataset.fone;
        document.getElementById('g-env-texto').value = montar(trEnv);
        dlgEnv.showModal();
        return;
      }
      var pg = e.target.closest('.g-por-no-grupo');
      if (pg) {
        var trf = pg.closest('tr');
        pg.disabled = true;
        post({ acao: 'adicionar_pessoa', nome: trf.dataset.nome, fone: trf.dataset.fone }).then(function (j) {
          if (!j.ok) { pg.disabled = false; pg.textContent = j.erro || 'Não consegui'; return; }
          location.reload();
        });
        return;
      }
      var lap = e.target.closest('.lapis-in');
      if (lap) { editarInline(lap.closest('.inline')); return; }
      var rem = e.target.closest('.g-remover');
      if (rem) {
        var tr = rem.closest('tr');
        if (!confirm('Tirar ' + (tr.dataset.nome || tr.dataset.fone) + ' da lista do grupo?')) return;
        post({ acao: 'remover_pessoa', linha: tr.dataset.linha, antes: tr.dataset.antes }).then(function (j) {
          if (!j.ok) { alert(j.erro || 'Não consegui tirar.'); return; }
          location.reload(); // as linhas de baixo mudam de posição no arquivo
        });
      }
    });

    // ── edição direta: clica no lápis, o texto vira campo; Enter salva, Esc cancela ──
    function editarInline(caixa) {
      if (caixa.classList.contains('editando')) return;
      var tr = caixa.closest('tr');
      var campo = caixa.dataset.campo;
      var v = caixa.querySelector('.v');
      var inp = document.createElement('input');
      inp.type = campo === 'fone' ? 'tel' : 'text';
      inp.maxLength = campo === 'fone' ? 30 : 120;
      inp.value = tr.dataset[campo];
      inp.placeholder = campo === 'fone' ? '+55 62 99999-0000' : 'Nome';
      inp.className = 'campo-in';
      caixa.classList.add('editando');
      caixa.appendChild(inp);
      inp.focus(); inp.select();
      var feito = false;

      function fechar() {
        feito = true;
        inp.remove();
        var m = caixa.querySelector('.erro-in'); if (m) m.remove();
        caixa.classList.remove('editando');
      }
      function salvar() {
        if (feito) return;
        var novo = { nome: tr.dataset.nome, fone: tr.dataset.fone };
        novo[campo] = inp.value.trim();
        if (novo[campo] === tr.dataset[campo]) { fechar(); return; }
        feito = true; inp.disabled = true;
        post({ acao: 'editar_pessoa', linha: tr.dataset.linha, antes: tr.dataset.antes, nome: novo.nome, fone: novo.fone })
          .then(function (j) {
            if (!j.ok) {
              feito = false; inp.disabled = false; inp.classList.add('erro');
              var msg = caixa.querySelector('.erro-in') || caixa.appendChild(document.createElement('span'));
              msg.className = 'erro-in'; msg.textContent = (j.erro || 'Não consegui salvar.') + ' Esc desfaz.';
              return;
            }
            var velho = caixa.querySelector('.erro-in'); if (velho) velho.remove();
            tr.dataset.antes = j.antes;
            tr.dataset[campo] = novo[campo];
            v.textContent = novo[campo] || (campo === 'nome' ? 'sem nome' : 'sem número');
            if (campo === 'fone') {
              // Telefone novo muda o link pessoal e o número do envio.
              if (!tr.querySelector('.g-enviar') && j.wa) { location.reload(); return; }
              tr.dataset.chave = j.chave; tr.dataset.wa = j.wa; tr.dataset.link = j.link;
            }
            tr.dataset.busca = (tr.dataset.nome + ' ' + tr.dataset.fone.replace(/\D/g, '')).toLowerCase();
            fechar();
            caixa.classList.add('salvo');
            setTimeout(function () { caixa.classList.remove('salvo'); }, 1500);
          })
          .catch(function () { feito = false; inp.disabled = false; inp.classList.add('erro'); });
      }
      inp.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); salvar(); }
        if (e.key === 'Escape') { e.preventDefault(); fechar(); }
      });
      inp.addEventListener('blur', salvar);
    }
    document.getElementById('g-env-vai').addEventListener('click', function () {
      var texto = document.getElementById('g-env-texto').value;
      // Mesma aba do WhatsApp Web para todos os envios, em vez de abrir uma nova a cada pessoa.
      window.open('https://web.whatsapp.com/send?phone=' + trEnv.dataset.wa + '&text=' + encodeURIComponent(texto), 'whatsapp-web');
      var tr = trEnv;
      dlgEnv.close();
      post({ acao: 'marcar_enviado', chave: tr.dataset.chave }).then(function (j) {
        if (!j.ok) return;
        if (tr.dataset.etapa === 'falta_enviar') {
          tr.dataset.etapa = 'enviado';
          tr.querySelector('.situacao').firstElementChild.outerHTML = '<span class="tag cinza">Mensagem enviada</span>';
        }
        var passos = tr.querySelector('.passos');
        passos.textContent = 'enviada ' + j.em + (passos.textContent.indexOf('abriu') !== -1 ? ' · ' + passos.textContent.split('· ').pop() : '');
        tr.querySelector('.g-enviar').textContent = 'Enviar de novo';
        tr.classList.add('acabou-de-enviar');
      });
    });

    // ── editar, adicionar, remover ──
    var dlgEd = document.getElementById('g-dlg-editar');
    var trEd = null;
    var erroEd = document.getElementById('g-ed-erro');
    function abrirEdicao(tr) {
      trEd = tr;
      document.getElementById('g-ed-titulo').textContent = tr ? 'Editar pessoa' : 'Adicionar pessoa';
      document.getElementById('g-ed-nome').value = tr ? tr.dataset.nome : '';
      document.getElementById('g-ed-fone').value = tr ? tr.dataset.fone : '';
      document.getElementById('g-ed-remover').hidden = !tr;
      erroEd.hidden = true;
      dlgEd.showModal();
    }
    document.getElementById('g-adicionar').addEventListener('click', function () { abrirEdicao(null); });
    function gravar(acao) {
      var dados = { acao: acao, nome: document.getElementById('g-ed-nome').value, fone: document.getElementById('g-ed-fone').value };
      if (trEd) { dados.linha = trEd.dataset.linha; dados.antes = trEd.dataset.antes; }
      post(dados).then(function (j) {
        if (!j.ok) { erroEd.textContent = j.erro || 'Não consegui salvar.'; erroEd.hidden = false; return; }
        location.reload();
      });
    }
    document.getElementById('g-ed-salvar').addEventListener('click', function () { gravar(trEd ? 'editar_pessoa' : 'adicionar_pessoa'); });
    document.getElementById('g-ed-remover').addEventListener('click', function () {
      if (confirm('Tirar ' + (trEd.dataset.nome || trEd.dataset.fone) + ' da lista do grupo?')) gravar('remover_pessoa');
    });

    // ── copiar números ──
    var bc = document.getElementById('copiar');
    bc.addEventListener('click', function () {
      navigator.clipboard.writeText(faltamNumeros.join('\n')).then(function () {
        bc.textContent = '✓ ' + faltamNumeros.length + ' números copiados';
        setTimeout(function () { bc.textContent = 'Copiar números de quem falta'; }, 2500);
      });
    });
  })();
  </script>
<?php else: ?>

  <div class="link-conf">Link para mandar aos aprovados: <code><?= e($linkConfirmar) ?></code>
    <a class="bt" href="?csv=1" style="margin-left:auto">Baixar planilha</a></div>

  <div class="cards">
    <div class="kpi"><div class="n verde"><?= $vao ?></div><div class="l">vão participar</div></div>
    <div class="kpi"><div class="n verm"><?= $naoVao ?></div><div class="l">não vão conseguir</div></div>
    <div class="kpi"><div class="n" id="kpi-chegaram"><?= $chegaram ?></div><div class="l">fizeram check-in no dia</div></div>
    <div class="kpi"><div class="n amar"><?= $semAplicacao ?></div><div class="l">confirmaram sem ter aplicado</div></div>
  </div>

  <div class="filtros">
    <input type="search" id="busca" placeholder="Buscar por nome, WhatsApp ou e-mail" aria-label="Buscar">
    <div class="abas" role="group" aria-label="Filtrar">
      <button class="aba" data-f="todos" aria-pressed="true">Todos (<?= count($linhas) ?>)</button>
      <button class="aba" data-f="vai" aria-pressed="false">Vão (<?= $vao ?>)</button>
      <button class="aba" data-f="nao" aria-pressed="false">Não vão (<?= $naoVao ?>)</button>
      <button class="aba" data-f="sem" aria-pressed="false">Sem aplicação (<?= $semAplicacao ?>)</button>
    </div>
  </div>

  <div class="tabela-box">
    <?php if ($linhas === []): ?>
      <div class="vazio">Ninguém confirmou ainda. Assim que alguém responder, aparece aqui.</div>
    <?php else: ?>
    <div class="rolagem">
      <table>
        <thead><tr><th>Respondeu</th><th>Nome</th><th>WhatsApp</th><th>Presença</th><th>Aplicou antes?</th><th>Check-in</th></tr></thead>
        <tbody id="corpo">
        <?php foreach ($linhas as $l): ?>
          <tr data-vai="<?= $l['vai'] ? '1' : '0' ?>" data-aplicou="<?= $l['aplicou'] ? '1' : '0' ?>"
              data-busca="<?= e(strtolower($l['nome'] . ' ' . $l['wa'] . ' ' . preg_replace('/\D/', '', $l['whatsapp']) . ' ' . $l['email'])) ?>">
            <td><?= e($l['data']) ?><?php if ($l['respostas'] > 1): ?><div class="sub2">respondeu <?= $l['respostas'] ?> vezes</div><?php endif; ?></td>
            <td><div class="nome"><?= e($l['nome']) ?></div><?php if ($l['email'] !== ''): ?><div class="sub2"><?= e($l['email']) ?></div><?php endif; ?></td>
            <td><a class="wa" href="https://wa.me/<?= e($l['wa']) ?>" target="_blank" rel="noopener"><?= e($l['whatsapp']) ?></a>
              <?php if ($grupo !== [] && !$l['no_grupo']): ?><div class="sub2">fora do grupo</div><?php endif; ?></td>
            <td><?= $l['vai'] ? '<span class="tag sim">✓ Vai</span>' : '<span class="tag nao">✕ Não vai</span>' ?></td>
            <td><?= $l['aplicou'] ? '<span class="tag sim">Sim</span>' : '<span class="tag alerta">⚠ Não achamos</span>' ?></td>
            <td>
              <button class="ck<?= $l['checkin'] !== '' ? ' feito' : '' ?>" data-fone="<?= e($l['fone']) ?>">
                <?= $l['checkin'] !== '' ? '✓ Chegou ' . e($l['checkin']) : 'Marcar chegada' ?>
              </button>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <div class="vazio" id="nada" hidden>Nenhuma confirmação com esse filtro.</div>
    </div>
    <?php endif; ?>
  </div>
<?php endif; ?>
</div>

<script>
(function () {
  var TOKEN = <?= json_encode($token) ?>;
  var corpo = document.getElementById('corpo');
  if (!corpo) return;
  var filtro = 'todos';
  var busca = document.getElementById('busca');

  function aplicar() {
    var t = busca.value.trim().toLowerCase();
    // Número digitado com máscara, "(62) 99999-0000", vira só dígitos para casar com o WhatsApp.
    if (/^[\d()\s+\-]+$/.test(t)) t = t.replace(/\D/g, '').replace(/^55(?=\d{10,11}$)/, '');
    var visiveis = 0;
    corpo.querySelectorAll('tr').forEach(function (tr) {
      var ok = true;
      if (filtro === 'vai') ok = tr.dataset.vai === '1';
      if (filtro === 'nao') ok = tr.dataset.vai === '0';
      if (filtro === 'sem') ok = tr.dataset.vai === '1' && tr.dataset.aplicou === '0';
      if (ok && t) ok = tr.dataset.busca.indexOf(t) !== -1;
      tr.hidden = !ok;
      if (ok) visiveis++;
    });
    document.getElementById('nada').hidden = visiveis > 0;
  }
  busca.addEventListener('input', aplicar);
  document.querySelectorAll('.aba').forEach(function (b) {
    b.addEventListener('click', function () {
      document.querySelectorAll('.aba').forEach(function (x) { x.setAttribute('aria-pressed', 'false'); });
      b.setAttribute('aria-pressed', 'true');
      filtro = b.dataset.f;
      aplicar();
    });
  });

  var kpi = document.getElementById('kpi-chegaram');
  corpo.addEventListener('click', function (ev) {
    var bt = ev.target.closest('.ck');
    if (!bt) return;
    var feito = bt.classList.contains('feito');
    var dados = new FormData();
    dados.append('acao', feito ? 'desfazer' : 'checkin');
    dados.append('fone', bt.dataset.fone);
    dados.append('token', TOKEN);
    bt.disabled = true;
    fetch(location.pathname, { method: 'POST', body: dados, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        bt.disabled = false;
        if (!j.ok) { bt.textContent = 'Falhou, tente de novo'; return; }
        if (j.em) {
          bt.classList.add('feito'); bt.textContent = '✓ Chegou ' + j.em;
          kpi.textContent = +kpi.textContent + 1;
        } else {
          bt.classList.remove('feito'); bt.textContent = 'Marcar chegada';
          kpi.textContent = +kpi.textContent - 1;
        }
      })
      .catch(function () { bt.disabled = false; bt.textContent = 'Falhou, tente de novo'; });
  });
})();
</script>
</body>
</html>
