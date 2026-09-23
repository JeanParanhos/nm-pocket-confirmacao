<?php
/**
 * NM Pocket — contador de acessos das duas páginas (aplicação e confirmação).
 * A página chama por sendBeacon ao abrir. Guarda por dia: visitas e pessoas (hash do IP +
 * navegador do dia, sem guardar o IP). Robô e prévia de link (WhatsApp, Facebook) não contam.
 */

declare(strict_types=1);

require_once __DIR__ . '/confirmacao-comum.php';

const NMV_ARQUIVO = NMC_DIR . '/visitas.json';
const NMV_PAGINAS = ['aplicacao', 'confirmacao'];
const NMV_ROBO    = '/bot|crawl|spider|slurp|facebookexternalhit|whatsapp|preview|headless|curl|wget|python|go-http|monitor|uptime/i';

/** Soma uma visita (ou várias, na importação dos logs) ao dia da página. */
function nmv_somar(array &$mapa, string $pagina, string $dia, string $pessoa, int $n = 1): void
{
    $d = $mapa[$pagina][$dia] ?? ['v' => 0, 'u' => []];
    $d['v'] += $n;
    $d['u'][$pessoa] = 1;
    $mapa[$pagina][$dia] = $d;
}

function nmv_pessoa(string $ip, string $ua, string $dia): string
{
    return substr(hash('sha256', $ip . '|' . $ua . '|' . $dia . '|' . trim((string) @file_get_contents(NMC_SEGREDO))), 0, 12);
}

/** Lê, muda e grava o arquivo inteiro sob o mesmo lock. */
function nmv_mexer(callable $f): bool
{
    $fh = @fopen(NMV_ARQUIVO, 'c+b');
    if ($fh === false) {
        return false;
    }
    $ok = false;
    if (flock($fh, LOCK_EX)) {
        $mapa = json_decode((string) stream_get_contents($fh), true);
        $mapa = is_array($mapa) ? $mapa : [];
        $f($mapa);
        ftruncate($fh, 0);
        rewind($fh);
        $ok = fwrite($fh, json_encode($mapa, JSON_UNESCAPED_SLASHES)) !== false;
        fflush($fh);
        flock($fh, LOCK_UN);
    }
    fclose($fh);
    return $ok;
}

/**
 * Resumo para o painel: hoje, ontem, 7 dias e total (visitas e pessoas somadas por dia)
 * e a série dos últimos 7 dias.
 */
function nmv_resumo(string $pagina): array
{
    $mapa = json_decode((string) @file_get_contents(NMV_ARQUIVO), true);
    $dias = is_array($mapa[$pagina] ?? null) ? $mapa[$pagina] : [];
    $hoje  = date('Y-m-d');
    $ontem = date('Y-m-d', strtotime('-1 day'));
    $r = ['hoje' => [0, 0], 'ontem' => [0, 0], 'sete' => [0, 0], 'total' => [0, 0], 'serie' => [], 'desde' => ''];
    $limite = date('Y-m-d', strtotime('-6 days'));
    foreach ($dias as $dia => $d) {
        $v = (int) ($d['v'] ?? 0);
        $u = count($d['u'] ?? []);
        $r['total'][0] += $v;
        $r['total'][1] += $u;
        if ($dia >= $limite) {
            $r['sete'][0] += $v;
            $r['sete'][1] += $u;
        }
        if ($dia === $hoje) {
            $r['hoje'] = [$v, $u];
        }
        if ($dia === $ontem) {
            $r['ontem'] = [$v, $u];
        }
        if ($r['desde'] === '' || $dia < $r['desde']) {
            $r['desde'] = $dia;
        }
    }
    for ($i = 6; $i >= 0; $i--) {
        $dia = date('Y-m-d', strtotime("-$i days"));
        $r['serie'][$dia] = [(int) ($dias[$dia]['v'] ?? 0), count($dias[$dia]['u'] ?? [])];
    }
    return $r;
}

/**
 * Importa o histórico dos logs do Nginx (roda uma vez, como root, antes de o contador ir
 * ao ar): cada GET 200/304 de /nm-pocket/ e /nm-pocket/confirmar/ até o horário de corte.
 */
function nmv_importar_logs(string $corte): array
{
    $arquivos = array_merge(glob('/var/log/nginx/access.log*') ?: []);
    $re = '#^(\S+) \S+ \S+ \[([^\]]+)\] "GET (/nm-pocket/(?:confirmar/)?)(?:\?\S*)? HTTP/[\d.]+" (200|304) \S+ "[^"]*" "([^"]*)"#';
    $limite = strtotime($corte);
    $cont = ['aplicacao' => 0, 'confirmacao' => 0];
    $mapa = [];
    foreach ($arquivos as $arq) {
        $fh = str_ends_with($arq, '.gz') ? @gzopen($arq, 'rb') : @fopen($arq, 'rb');
        if (!$fh) {
            continue;
        }
        while (($linha = (str_ends_with($arq, '.gz') ? gzgets($fh) : fgets($fh))) !== false) {
            if (!str_contains($linha, 'GET /nm-pocket/') || !preg_match($re, $linha, $m)) {
                continue;
            }
            if (preg_match(NMV_ROBO, $m[5])) {
                continue;
            }
            $ts = strtotime(str_replace('/', ' ', preg_replace('#^(\d+)/(\w+)/(\d+):#', '$1 $2 $3 ', $m[2])));
            if ($ts === false || $ts >= $limite) {
                continue;
            }
            $dia = date('Y-m-d', $ts);
            $pagina = $m[3] === '/nm-pocket/confirmar/' ? 'confirmacao' : 'aplicacao';
            nmv_somar($mapa, $pagina, $dia, nmv_pessoa($m[1], $m[5], $dia));
            $cont[$pagina]++;
        }
        str_ends_with($arq, '.gz') ? gzclose($fh) : fclose($fh);
    }
    nmv_mexer(static function (array &$atual) use ($mapa) {
        foreach ($mapa as $pagina => $dias) {
            foreach ($dias as $dia => $d) {
                $a = $atual[$pagina][$dia] ?? ['v' => 0, 'u' => []];
                $a['v'] += $d['v'];
                $a['u'] += $d['u'];
                $atual[$pagina][$dia] = $a;
            }
        }
    });
    return $cont;
}

// CLI: php visita.php importar-logs "2026-09-23 13:20:00"
if (PHP_SAPI === 'cli') {
    if (isset($argv[0]) && realpath($argv[0]) === __FILE__) {
        if (($argv[1] ?? '') !== 'importar-logs' || empty($argv[2])) {
            fwrite(STDERR, "uso: php visita.php importar-logs \"AAAA-MM-DD HH:MM:SS\"\n");
            exit(1);
        }
        print_r(nmv_importar_logs($argv[2]));
    }
    return;
}

// Chamado pelo painel só para usar as funções.
if (defined('NMV_SO_FUNCOES')) {
    return;
}

// ── Web: a página avisa que abriu ──
header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    exit('{"ok":false}');
}
$pagina = (string) ($_POST['p'] ?? '');
$ua     = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
$ref    = parse_url((string) ($_SERVER['HTTP_REFERER'] ?? ''), PHP_URL_HOST) ?: '';
if (!in_array($pagina, NMV_PAGINAS, true) || preg_match(NMV_ROBO, $ua) || !in_array($ref, ['iuv.com.br', 'www.iuv.com.br'], true)) {
    exit('{"ok":false}');
}
$ip  = trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? ''))[0]);
$dia = date('Y-m-d');
nmv_mexer(static function (array &$mapa) use ($pagina, $dia, $ip, $ua) {
    nmv_somar($mapa, $pagina, $dia, nmv_pessoa($ip, $ua, $dia));
});
echo '{"ok":true}';
