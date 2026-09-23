<?php
/**
 * NM Pocket — painel das confirmações de presença.
 * Entra com senha (o hash mora fora do repositório, em NMC_CONFIG).
 * Uma linha por pessoa (WhatsApp): vale a resposta mais recente.
 * Cruza com as aplicações para mostrar quem confirmou sem ter aplicado.
 */

declare(strict_types=1);

require __DIR__ . '/confirmacao-comum.php';

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
    header('Content-Type: application/json; charset=utf-8');
    if (!hash_equals((string) ($_SESSION['token'] ?? ''), (string) ($_POST['token'] ?? ''))) {
        http_response_code(403);
        exit(json_encode(['ok' => false]));
    }
    $fone = preg_replace('/\D/', '', (string) ($_POST['fone'] ?? ''));
    $mapa = nmc_ler_json(NMC_CHECKIN);
    if ($_POST['acao'] === 'checkin' && $fone !== '') {
        $mapa[$fone] = (new DateTimeImmutable('now'))->format('c');
    } elseif ($_POST['acao'] === 'desfazer' && $fone !== '') {
        unset($mapa[$fone]);
    }
    $ok = nmc_salvar_json(NMC_CHECKIN, $mapa);
    exit(json_encode(['ok' => $ok, 'em' => isset($mapa[$fone]) ? date('d/m H:i', strtotime($mapa[$fone])) : null]));
}

// ─────────────── Dados ───────────────
// Uma pessoa = um WhatsApp. O arquivo está em ordem de chegada, então a última linha vence.
$pessoas = [];
foreach (nmc_ler_ndjson(NMC_ARQUIVO) as $reg) {
    $fone = (string) ($reg['fone'] ?? '');
    if ($fone === '') {
        continue;
    }
    $respostas = ($pessoas[$fone]['respostas'] ?? 0) + 1;
    $pessoas[$fone] = $reg + ['respostas' => $respostas];
    $pessoas[$fone]['respostas'] = $respostas;
}

// Quem aplicou (por WhatsApp e por e-mail).
$aplicouFone  = [];
$aplicouEmail = [];
foreach (nmc_ler_ndjson(NMC_APLICACOES) as $ap) {
    $r = $ap['respostas'] ?? [];
    $f = nmc_fone((string) ($r['whatsapp'] ?? ''));
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
        'data'      => date('d/m H:i', strtotime((string) $p['criado_em'])),
        'ts'        => strtotime((string) $p['criado_em']),
        'nome'      => (string) $p['nome'],
        'whatsapp'  => (string) $p['whatsapp'],
        'email'     => (string) $p['email'],
        'vai'       => $vai,
        'aplicou'   => $aplicou,
        'respostas' => (int) $p['respostas'],
        'checkin'   => $chegou ? date('d/m H:i', strtotime($checkin[$fone])) : '',
    ];
}
usort($linhas, static fn($a, $b) => $b['ts'] <=> $a['ts']);

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
      <a class="bt" href="?csv=1">Baixar planilha</a>
      <a class="bt" href="?sair=1">Sair</a>
    </div>
  </div>

  <div class="link-conf">Link para mandar aos aprovados: <code>https://iuv.com.br/nm-pocket/confirmar/</code></div>

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
              data-busca="<?= e(strtolower($l['nome'] . ' ' . $l['fone'] . ' ' . $l['email'])) ?>">
            <td><?= e($l['data']) ?><?php if ($l['respostas'] > 1): ?><div class="sub2">respondeu <?= $l['respostas'] ?> vezes</div><?php endif; ?></td>
            <td><div class="nome"><?= e($l['nome']) ?></div><?php if ($l['email'] !== ''): ?><div class="sub2"><?= e($l['email']) ?></div><?php endif; ?></td>
            <td><a class="wa" href="https://wa.me/55<?= e($l['fone']) ?>" target="_blank" rel="noopener"><?= e($l['whatsapp']) ?></a></td>
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
