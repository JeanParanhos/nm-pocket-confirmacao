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

/** Só os dígitos do WhatsApp, sem o 55 da frente: é a chave da pessoa. */
function nmc_fone(string $s): string
{
    $d = preg_replace('/\D/', '', $s);
    if (strlen($d) > 11 && str_starts_with($d, '55')) {
        $d = substr($d, 2);
    }
    return $d;
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
