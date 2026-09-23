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

/**
 * Só negrito, itálico e quebra de linha, sem atributo nenhum: o texto vai para uma página pública.
 * Com $spans, mantém também <span class="..."> (os destaques em cor da página de aplicação),
 * só com o atributo class.
 */
function nmp_limpar(string $html, bool $spans = false): string
{
    $html = preg_replace('#<(script|style)\b.*?</\1\s*>#is', '', $html);
    if ($spans) {
        $html = preg_replace_callback('#<span\b([^>]*)>#i', static function ($m) {
            return preg_match('#\bclass="([a-z0-9 _-]+)"#i', $m[1], $c) ? '<span class="' . $c[1] . '">' : '<span>';
        }, $html);
        $html = strip_tags($html, '<strong><em><br><b><i><span>');
        if (substr_count($html, '<span') !== substr_count($html, '</span>')) {
            $html = preg_replace('#</?span\b[^>]*>#i', '', $html);
        }
    }
    $html = preg_replace('#<(/?)(?:b|strong)\b[^>]*>#i', '<$1strong>', $html);
    $html = preg_replace('#<(/?)(?:i|em)\b[^>]*>#i', '<$1em>', $html);
    $html = preg_replace('#<br\b[^>]*>#i', '<br>', $html);
    $html = strip_tags($html, $spans ? '<strong><em><br><span>' : '<strong><em><br>');
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
    return nmc_gravar_por_cima(NMP_PUBLICA, $html);
}

/**
 * Grava por cima do arquivo que já existe (em vez de criar outro e renomear): assim o dono
 * continua sendo o Arlindo e ele segue editando o arquivo pelo servidor. O servidor web só
 * precisa de escrita pelo grupo www-data.
 */
function nmc_gravar_por_cima(string $arquivo, string $html): bool
{
    $fh = @fopen($arquivo, 'c+b');
    if ($fh === false) {
        error_log('nm-pocket: não consegui abrir ' . $arquivo . ' para gravar');
        return false;
    }
    $ok = false;
    if (flock($fh, LOCK_EX)) {
        ftruncate($fh, 0);
        rewind($fh);
        $ok = fwrite($fh, $html) === strlen($html);
        fflush($fh);
        flock($fh, LOCK_UN);
    }
    fclose($fh);
    return $ok;
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

// ═════════════════ Página de aplicação (/nm-pocket/) ═════════════════
// Ela é do Arlindo e muda direto no servidor. Por isso aqui não há modelo separado: as marcas
// data-ed ficam no próprio index.html publicado e Publicar troca só os textos editados dentro
// do arquivo que está no ar naquele momento. Edição feita no arquivo nunca é apagada pelo painel.

const NMA_ARQUIVO = __DIR__ . '/../nm-pocket/index.html';
const NMA_DADOS   = NMC_DIR . '/pagina-aplicacao.json';
const NMA_BACKUPS = NMC_DIR . '/pagina-aplicacao-backups';

function nma_html(): string
{
    return (string) file_get_contents(NMA_ARQUIVO);
}

function nma_dados(): array
{
    return nmc_ler_json(NMA_DADOS) + ['rascunho' => [], 'publicado_em' => null, 'historico' => []];
}

/** Textos marcados do arquivo no ar: chave => html. */
function nma_textos(?string $html = null): array
{
    preg_match_all(NMP_RE, $html ?? nma_html(), $m, PREG_SET_ORDER);
    $t = [];
    foreach ($m as $x) {
        $t[$x[3]] = nmp_normalizar($x[4]);
    }
    return $t;
}

/** Arquivo no ar com o rascunho por cima. */
function nma_montar(array $textos, ?string $html = null): string
{
    return (string) preg_replace_callback(NMP_RE, static function ($x) use ($textos) {
        return isset($textos[$x[3]]) ? $x[1] . $textos[$x[3]] . $x[5] : $x[0];
    }, $html ?? nma_html());
}

function nma_diferencas(array $recebidos): array
{
    $atual = nma_textos();
    $saida = [];
    foreach ($recebidos as $chave => $html) {
        if (!is_string($chave) || !isset($atual[$chave]) || !is_string($html)) {
            continue;
        }
        $limpo = nmp_limpar($html, true);
        // O navegador devolve "S&amp;N" onde o arquivo tem "S&N": mesma coisa, não é edição.
        if (html_entity_decode($limpo) !== html_entity_decode($atual[$chave])) {
            $saida[$chave] = $limpo;
        }
    }
    return $saida;
}

/** Grava por cima do arquivo no ar: temporário + rename, com cópia do anterior. */
function nma_gravar(string $html): bool
{
    if (!is_dir(NMA_BACKUPS)) {
        @mkdir(NMA_BACKUPS, 0777, true);
    }
    @copy(NMA_ARQUIVO, NMA_BACKUPS . '/index-' . date('Ymd-His') . '.html');
    return nmc_gravar_por_cima(NMA_ARQUIVO, $html);
}

function nma_publicar(): array
{
    $d = nma_dados();
    $n = count($d['rascunho']);
    if (!nma_gravar(nma_montar($d['rascunho']))) {
        return [false, 0];
    }
    $d['publicado_em'] = (new DateTimeImmutable('now'))->format('c');
    array_unshift($d['historico'], ['em' => $d['publicado_em'], 'textos' => array_keys($d['rascunho'])]);
    $d['historico'] = array_slice($d['historico'], 0, 30);
    $d['rascunho'] = [];
    nmc_salvar_json(NMA_DADOS, $d);
    return [true, $n];
}

/**
 * Marca como editável (data-ed="aNNN") cada texto ainda sem marca: título, parágrafo, item,
 * botão... Só o bloco de fora (o destaque dentro de um título continua dentro dele), sem
 * símbolo solto (✗, ›, ·) e sem bloco que tenha link, imagem ou outro bloco dentro.
 */
function nma_marcar(string $h): array
{
    $ini = strpos($h, '<body');
    if ($ini === false) {
        return [$h, 0];
    }
    preg_match_all('#<(script|style|noscript)\b.*?</\1>#s', $h, $ig, PREG_OFFSET_CAPTURE, $ini);
    $fora = array_map(static fn($x) => [$x[1], $x[1] + strlen($x[0])], $ig[0]);

    preg_match_all('/data-ed="a(\d+)"/', $h, $ja);
    $prox = $ja[1] ? max(array_map('intval', $ja[1])) + 1 : 1;

    preg_match_all('#<(h1|h2|h3|h4|p|li|a|span|strong|div|summary|label)\b[^>]*>#', $h, $ab, PREG_OFFSET_CAPTURE | PREG_SET_ORDER, $ini);
    $marcar = [];   // posição do fim da abre-tag => chave
    $ocupadoAte = -1;
    foreach ($ab as $x) {
        $pos = $x[0][1];
        $tag = $x[1][0];
        if ($pos < $ocupadoAte) {
            continue; // está dentro de um bloco já escolhido (ou já marcado)
        }
        foreach ($fora as [$a, $b]) {
            if ($pos >= $a && $pos < $b) {
                continue 2;
            }
        }
        if (!preg_match("#\\G<$tag\\b[^>]*>(.*?)</$tag>#s", $h, $mm, 0, $pos)) {
            continue;
        }
        $c = $mm[1];
        if (preg_match("#<(div|img|ul|ol|iframe|button|section|h[1-6]|p|li|video|svg|a|picture|source|input|form)\\b#", $c)
            || preg_match("#<$tag\\b#", $c)
            || !preg_match('/[\p{L}\p{N}].*[\p{L}\p{N}]/us', html_entity_decode(strip_tags($c)))) {
            continue;
        }
        $ocupadoAte = $pos + strlen($mm[0]);
        if (str_contains($x[0][0], 'data-ed=')) {
            continue;
        }
        $marcar[$pos + strlen($x[0][0]) - 1] = sprintf('a%03d', $prox++);
    }
    // De trás para frente, para as posições não andarem.
    krsort($marcar);
    foreach ($marcar as $fim => $chave) {
        $h = substr($h, 0, $fim) . ' data-ed="' . $chave . '"' . substr($h, $fim);
    }
    return [$h, count($marcar)];
}

// CLI: php pagina.php regenerar | marcar-aplicacao
if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    if (($argv[1] ?? '') === 'marcar-aplicacao') {
        [$novo, $n] = nma_marcar(nma_html());
        if ($n === 0) {
            echo "nada novo para marcar\n";
            exit(0);
        }
        $ok = nma_gravar($novo);
        echo $ok ? "$n texto(s) da página de aplicação marcados como editáveis\n" : "falhou\n";
        exit($ok ? 0 : 1);
    }
    if (($argv[1] ?? '') !== 'regenerar') {
        fwrite(STDERR, "uso: php pagina.php regenerar | marcar-aplicacao\n");
        exit(1);
    }
    $d = nmp_dados();
    $ok = nmp_gravar_publica(nmp_montar($d['publicado']));
    echo $ok ? "index.html remontado com " . count($d['publicado']) . " texto(s) editado(s)\n" : "falhou\n";
    exit($ok ? 0 : 1);
}
