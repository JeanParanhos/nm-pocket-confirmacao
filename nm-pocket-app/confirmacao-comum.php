<?php
/**
 * NM Pocket — confirmação de presença.
 * Caminhos e funções usados pelo confirmar.php (grava) e pelo confirmacoes.php (painel).
 * Sem banco: NDJSON com flock, fora do webroot, na mesma pasta das aplicações.
 */

declare(strict_types=1);

date_default_timezone_set('America/Sao_Paulo');

const NMC_DIR          = '/home/arlindo/data/nm-pocket';
const NMC_ARQUIVO      = NMC_DIR . '/confirmacoes.ndjson';
const NMC_CHECKIN      = NMC_DIR . '/checkin.json';
const NMC_APLICACOES   = NMC_DIR . '/aplicacoes.ndjson';

/**
 * A senha do painel NÃO fica no repositório: mora neste arquivo, só no servidor.
 * Ele define NMC_SENHA_HASH (gerado com password_hash). Modelo: config.exemplo.php.
 */
const NMC_CONFIG       = NMC_DIR . '/confirmacao-config.php';

const NMC_PRESENCA = ['Sim, vou estar lá', 'Não vou conseguir ir'];

/** Corta contando caracteres (o servidor não tem mbstring). */
function nmc_corta(string $s, int $max): string
{
    return preg_match('/^.{0,' . $max . '}/us', $s, $m) === 1 ? $m[0] : substr($s, 0, $max);
}

/**
 * Lista de quem está no grupo do WhatsApp: uma pessoa por linha, "nome | telefone".
 * Fica fora do repositório (são dados pessoais); o painel edita.
 */
const NMC_GRUPO        = NMC_DIR . '/grupo.txt';

/**
 * Chave de comparação do telefone. O WhatsApp mostra o celular sem o 9 da frente
 * ("62 9912-5180") e a pessoa digita com ele ("62 99912-5180"): no Brasil a chave é
 * DDD + 8 últimos dígitos. Número de fora (começa com + e não é +55) vale inteiro.
 */
function nmc_chave(string $s): string
{
    $s = trim($s);
    $d = preg_replace('/\D/', '', $s);
    if ($d === '') {
        return '';
    }
    if (str_starts_with($s, '+') && !str_starts_with($d, '55')) {
        return 'x' . $d;
    }
    if (strlen($d) >= 12 && str_starts_with($d, '55')) {
        $d = substr($d, 2);
    }
    if (strlen($d) === 10 || strlen($d) === 11) {
        return substr($d, 0, 2) . substr($d, -8);
    }
    return 'x' . $d;
}

/** Número para o wa.me (com país). */
function nmc_wa(string $s): string
{
    $s = trim($s);
    $d = preg_replace('/\D/', '', $s);
    if (str_starts_with($s, '+') || (strlen($d) >= 12 && str_starts_with($d, '55'))) {
        return $d;
    }
    return '55' . $d;
}

/** Lê o grupo: [['nome' => ..., 'fone' => texto, 'chave' => ...], ...] sem repetir número. */
function nmc_ler_grupo(): array
{
    if (!is_file(NMC_GRUPO)) {
        return [];
    }
    return nmc_parse_grupo((string) file_get_contents(NMC_GRUPO));
}

function nmc_parse_grupo(string $texto): array
{
    $itens = [];
    $vistos = [];
    foreach (preg_split('/\R/', $texto) as $linha) {
        $linha = trim($linha);
        if ($linha === '') {
            continue;
        }
        // "nome | telefone", ou só o telefone, ou só o nome.
        if (str_contains($linha, '|')) {
            [$nome, $fone] = array_map('trim', explode('|', $linha, 2));
        } elseif (preg_match('/^\+?[\d\s().\-]{8,}$/', $linha)) {
            [$nome, $fone] = ['', $linha];
        } else {
            [$nome, $fone] = [$linha, ''];
        }
        $chave = $fone !== '' ? nmc_chave($fone) : '';
        if ($chave !== '') {
            if (isset($vistos[$chave])) {
                continue;
            }
            $vistos[$chave] = true;
        }
        $itens[] = ['nome' => nmc_corta($nome, 120), 'fone' => nmc_corta($fone, 30), 'chave' => $chave];
    }
    return $itens;
}

/** Lê um NDJSON inteiro (linhas inválidas são puladas). */
function nmc_ler_ndjson(string $arquivo): array
{
    if (!is_file($arquivo)) {
        return [];
    }
    $itens = [];
    foreach (file($arquivo, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $linha) {
        $reg = json_decode($linha, true);
        if (is_array($reg)) {
            $itens[] = $reg;
        }
    }
    return $itens;
}

/** Acrescenta uma linha com lock exclusivo. */
function nmc_gravar_linha(string $arquivo, array $registro): bool
{
    $fh = @fopen($arquivo, 'ab');
    if ($fh === false) {
        error_log('nm-pocket confirmacao: não consegui abrir ' . $arquivo);
        return false;
    }
    $ok = false;
    if (flock($fh, LOCK_EX)) {
        $ok = fwrite($fh, json_encode($registro, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n") !== false;
        fflush($fh);
        flock($fh, LOCK_UN);
    }
    fclose($fh);
    return $ok;
}

function nmc_ler_json(string $arquivo): array
{
    if (!is_file($arquivo)) {
        return [];
    }
    $mapa = json_decode((string) file_get_contents($arquivo), true);
    return is_array($mapa) ? $mapa : [];
}

function nmc_salvar_json(string $arquivo, array $mapa): bool
{
    $fh = @fopen($arquivo, 'c+b');
    if ($fh === false) {
        return false;
    }
    $ok = false;
    if (flock($fh, LOCK_EX)) {
        ftruncate($fh, 0);
        rewind($fh);
        $ok = fwrite($fh, json_encode($mapa, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_FORCE_OBJECT)) !== false;
        fflush($fh);
        flock($fh, LOCK_UN);
    }
    fclose($fh);
    return $ok;
}

function e(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
