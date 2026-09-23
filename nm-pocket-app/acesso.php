<?php
/**
 * NM Pocket — a página de confirmação avisa aqui quando alguém abre o link pessoal (?c=código).
 * Registra o acesso da pessoa do grupo e devolve o WhatsApp dela para preencher o formulário.
 */

declare(strict_types=1);

require __DIR__ . '/confirmacao-comum.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    exit('{"ok":false}');
}

$g = nmc_grupo_por_codigo((string) ($_POST['c'] ?? ''));
if ($g === null) {
    exit('{"ok":false}');
}

$agora = (new DateTimeImmutable('now'))->format('c');
$fh = @fopen(NMC_ACESSOS, 'c+b');
if ($fh !== false && flock($fh, LOCK_EX)) {
    $mapa = json_decode((string) stream_get_contents($fh), true);
    $mapa = is_array($mapa) ? $mapa : [];
    $a = $mapa[$g['chave']] ?? ['primeiro' => $agora, 'vezes' => 0];
    $a['ultimo'] = $agora;
    $a['vezes']  = (int) $a['vezes'] + 1;
    $mapa[$g['chave']] = $a;
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, json_encode($mapa, JSON_UNESCAPED_UNICODE | JSON_FORCE_OBJECT));
    fflush($fh);
    flock($fh, LOCK_UN);
}
if ($fh !== false) {
    fclose($fh);
}

echo json_encode(['ok' => true, 'whatsapp' => nmc_fone_formulario($g['fone'])], JSON_UNESCAPED_UNICODE);
