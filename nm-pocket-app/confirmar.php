<?php
/**
 * NM Pocket — recebe a confirmação de presença e grava uma linha no NDJSON.
 * Quem responde de novo não é barrado: vale a resposta mais recente (o painel agrupa por WhatsApp).
 */

declare(strict_types=1);

require __DIR__ . '/confirmacao-comum.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');

const HOSTS_PERMITIDOS = ['iuv.com.br', 'www.iuv.com.br'];

function responder(int $status, array $corpo): never
{
    http_response_code($status);
    echo json_encode($corpo, JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    responder(405, ['ok' => false, 'erro' => 'método não permitido']);
}

$refHost = parse_url($_SERVER['HTTP_REFERER'] ?? '', PHP_URL_HOST) ?: '';
if ($refHost !== '' && !in_array($refHost, HOSTS_PERMITIDOS, true)) {
    responder(403, ['ok' => false, 'erro' => 'origem não permitida']);
}

// Honeypot: robô preencheu o campo invisível.
if (trim((string) ($_POST['website'] ?? '')) !== '') {
    responder(200, ['ok' => true]);
}

$nome     = nmc_corta(trim((string) ($_POST['nome'] ?? '')), 120);
$whatsapp = nmc_corta(trim((string) ($_POST['whatsapp'] ?? '')), 30);
$email    = strtolower(nmc_corta(trim((string) ($_POST['email'] ?? '')), 160));
$presenca = trim((string) ($_POST['presenca'] ?? ''));

$erros = [];
if ($nome === '') {
    $erros['nome'] = 'Digite seu nome.';
}
$digitos = nmc_fone($whatsapp);
if (strlen($digitos) < 10 || strlen($digitos) > 11) {
    $erros['whatsapp'] = 'Digite o WhatsApp com DDD.';
}
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $erros['email'] = 'E-mail inválido.';
}
if (!in_array($presenca, NMC_PRESENCA, true)) {
    $erros['presenca'] = 'Escolha uma opção.';
}
if ($erros !== []) {
    responder(422, ['ok' => false, 'erros' => $erros]);
}

$registro = [
    'id'        => bin2hex(random_bytes(8)),
    'criado_em' => (new DateTimeImmutable('now'))->format('c'),
    'nome'      => $nome,
    'whatsapp'  => $whatsapp,
    'fone'      => $digitos,
    'email'     => $email,
    'presenca'  => $presenca,
    'origem'    => [
        'ip'          => $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '',
        'user_agent'  => nmc_corta((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 300),
        'landing_url' => nmc_corta((string) ($_POST['landing_url'] ?? ''), 500),
    ],
];

if (!nmc_gravar_linha(NMC_ARQUIVO, $registro)) {
    responder(500, ['ok' => false, 'erro' => 'falha ao salvar']);
}

responder(200, ['ok' => true, 'vai' => $presenca === NMC_PRESENCA[0]]);
