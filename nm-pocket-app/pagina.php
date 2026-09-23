<?php
/**
 * NM Pocket — página de confirmação editável pelo painel.
 *
 * Mesmo processo do painel das LPs: o modelo (pagina-modelo.html) é a verdade da estrutura
 * e marca cada texto editável com data-ed="chave". O que se edita no painel fica em
 * pagina.json como rascunho; Publicar monta modelo + textos e grava o index.html público
 * (guardando o anterior em pagina-backups/).
 *
 * Mudou o modelo pelo código? Rode `php nm-pocket-app/pagina.php regenerar` no servidor:
 * remonta o index.html com os textos já publicados por cima.
 */

declare(strict_types=1);

require_once __DIR__ . '/confirmacao-comum.php';

const NMP_MODELO     = __DIR__ . '/pagina-modelo.html';
const NMP_PUBLICA    = __DIR__ . '/../nm-pocket/confirmar/index.html';
const NMP_DADOS      = NMC_DIR . '/pagina.json';
const NMP_BACKUPS    = NMC_DIR . '/pagina-backups';

/** Elemento com data-ed: abre-tag, conteúdo, fecha-tag. Só marcamos elementos sem filho da mesma tag. */
const NMP_RE = '#(<(\w+)\b[^>]*\bdata-ed="([a-z0-9-]+)"[^>]*>)(.*?)(</\2>)#s';

function nmp_modelo(): string
{
    return (string) file_get_contents(NMP_MODELO);
}

/** Textos do modelo: chave => html. */
function nmp_textos_modelo(): array
{
    preg_match_all(NMP_RE, nmp_modelo(), $m, PREG_SET_ORDER);
    $t = [];
    foreach ($m as $x) {
        $t[$x[3]] = nmp_normalizar($x[4]);
    }
    return $t;
}

function nmp_normalizar(string $html): string
{
    return trim(preg_replace('/\s+/', ' ', $html));
}

/** Só negrito, itálico e quebra de linha, sem atributo nenhum: o texto vai para uma página pública. */
function nmp_limpar(string $html): string
{
    $html = preg_replace('#<(script|style)\b.*?</\1\s*>#is', '', $html);
    $html = preg_replace('#<(/?)(?:b|strong)\b[^>]*>#i', '<$1strong>', $html);
    $html = preg_replace('#<(/?)(?:i|em)\b[^>]*>#i', '<$1em>', $html);
    $html = preg_replace('#<br\b[^>]*>#i', '<br>', $html);
    $html = strip_tags($html, '<strong><em><br>');
    // Tag aberta sem fechar (ou o contrário) desmontaria a página: nesse caso sai a formatação.
    foreach (['strong', 'em'] as $tag) {
        if (substr_count($html, "<$tag>") !== substr_count($html, "</$tag>")) {
            $html = str_replace(["<$tag>", "</$tag>"], '', $html);
        }
    }
    $html = str_replace('&nbsp;', ' ', $html);
    return nmp_corta_html(nmp_normalizar($html), 2000);
}

function nmp_corta_html(string $s, int $max): string
{
    return nmc_corta($s, $max);
}

function nmp_dados(): array
{
    $d = nmc_ler_json(NMP_DADOS);
    return $d + ['rascunho' => [], 'publicado' => [], 'publicado_em' => null, 'historico' => []];
}

function nmp_salvar_dados(array $d): bool
{
    return nmc_salvar_json(NMP_DADOS, $d);
}

/** Recebe chave => html do editor e devolve só o que difere do modelo, limpo. */
function nmp_diferencas(array $recebidos): array
{
    $modelo = nmp_textos_modelo();
    $saida = [];
    foreach ($recebidos as $chave => $html) {
        if (!is_string($chave) || !isset($modelo[$chave]) || !is_string($html)) {
            continue;
        }
        $limpo = nmp_limpar($html);
        if ($limpo !== nmp_normalizar($modelo[$chave])) {
            $saida[$chave] = $limpo;
        }
    }
    return $saida;
}

/** Modelo com os textos por cima. */
function nmp_montar(array $textos): string
{
    return (string) preg_replace_callback(NMP_RE, static function ($x) use ($textos) {
        return isset($textos[$x[3]]) ? $x[1] . $textos[$x[3]] . $x[5] : $x[0];
    }, nmp_modelo());
}

/** Grava o index.html público: arquivo temporário + rename, com backup do anterior. */
function nmp_gravar_publica(string $html): bool
{
    if (is_file(NMP_PUBLICA)) {
        if (!is_dir(NMP_BACKUPS)) {
            @mkdir(NMP_BACKUPS, 0777, true);
        }
        @copy(NMP_PUBLICA, NMP_BACKUPS . '/index-' . date('Ymd-His') . '.html');
    }
    $tmp = NMP_PUBLICA . '.tmp-' . bin2hex(random_bytes(4));
    if (@file_put_contents($tmp, $html) === false) {
        error_log('nm-pocket pagina: não consegui escrever ' . $tmp);
        return false;
    }
    @chmod($tmp, 0664);
    return @rename($tmp, NMP_PUBLICA);
}

/** Publica o rascunho. Devolve [ok, quantos textos mudados]. */
function nmp_publicar(string $quem = 'painel'): array
{
    $d = nmp_dados();
    if (!nmp_gravar_publica(nmp_montar($d['rascunho']))) {
        return [false, 0];
    }
    $d['publicado']    = $d['rascunho'];
    $d['publicado_em'] = (new DateTimeImmutable('now'))->format('c');
    array_unshift($d['historico'], ['em' => $d['publicado_em'], 'por' => $quem, 'textos' => array_keys($d['rascunho'])]);
    $d['historico'] = array_slice($d['historico'], 0, 30);
    nmp_salvar_dados($d);
    return [true, count($d['rascunho'])];
}

// CLI: php pagina.php regenerar
if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    if (($argv[1] ?? '') !== 'regenerar') {
        fwrite(STDERR, "uso: php pagina.php regenerar\n");
        exit(1);
    }
    $d = nmp_dados();
    $ok = nmp_gravar_publica(nmp_montar($d['publicado']));
    echo $ok ? "index.html remontado com " . count($d['publicado']) . " texto(s) editado(s)\n" : "falhou\n";
    exit($ok ? 0 : 1);
}
