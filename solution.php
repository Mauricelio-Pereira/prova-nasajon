<?php

// ============================================================
// CONFIGURAÇÕES
// ============================================================
define('ACCESS_TOKEN',      'eyJhbGciOiJIUzI1NiIsImtpZCI6ImR0TG03UVh1SkZPVDJwZEciLCJ0eXAiOiJKV1QifQ.eyJpc3MiOiJodHRwczovL215bnhsdWJ5a3lsbmNpbnR0Z2d1LnN1cGFiYXNlLmNvL2F1dGgvdjEiLCJzdWIiOiI2NjMxZDg0Ny1iYzNhLTQ2ZWMtYTlkMy0xMDZiOTUyY2FiNzEiLCJhdWQiOiJhdXRoZW50aWNhdGVkIiwiZXhwIjoxNzc1ODU3MjExLCJpYXQiOjE3NzU4NTM2MTEsImVtYWlsIjoibWF1cmljZWxpb3BlcmVpcmEyNDA0QGdtYWlsLmNvbSIsInBob25lIjoiIiwiYXBwX21ldGFkYXRhIjp7InByb3ZpZGVyIjoiZW1haWwiLCJwcm92aWRlcnMiOlsiZW1haWwiXX0sInVzZXJfbWV0YWRhdGEiOnsiZW1haWwiOiJtYXVyaWNlbGlvcGVyZWlyYTI0MDRAZ21haWwuY29tIiwiZW1haWxfdmVyaWZpZWQiOnRydWUsIm5vbWUiOiJtYXVyaWNlbGlvIHBlcmVpcmEgc2lsdmVzdHJlIiwicGhvbmVfdmVyaWZpZWQiOmZhbHNlLCJzdWIiOiI2NjMxZDg0Ny1iYzNhLTQ2ZWMtYTlkMy0xMDZiOTUyY2FiNzEifSwicm9sZSI6ImF1dGhlbnRpY2F0ZWQiLCJhYWwiOiJhYWwxIiwiYW1yIjpbeyJtZXRob2QiOiJwYXNzd29yZCIsInRpbWVzdGFtcCI6MTc3NTg1MzYxMX1dLCJzZXNzaW9uX2lkIjoiZjdkYzA5NGUtYjQ1Zi00YjFiLTk3NjAtM2E3YWRmYzg5OGYyIiwiaXNfYW5vbnltb3VzIjpmYWxzZX0.GEGY42RWIXm0rV9fiJ1kjAF7FpsAKbugul-OTAsITU0');
define('EDGE_FUNCTION_URL', 'https://mynxlubykylncinttggu.functions.supabase.co/ibge-submit');
define('IBGE_API_URL',      'https://servicodados.ibge.gov.br/api/v1/localidades/municipios');
define('INPUT_CSV',         __DIR__ . '/input.csv');
define('OUTPUT_CSV',        __DIR__ . '/resultado.csv');

// Máximo de distância Levenshtein aceita para fuzzy matching
define('LEVENSHTEIN_MAX', 3);

// ============================================================
// HELPERS
// ============================================================

/**
 * Remove acentos e converte para minúsculas.
 */
function normalizar(string $str): string
{
    $str  = mb_strtolower(trim($str), 'UTF-8');
    $from = ['á','à','ã','â','ä','é','è','ê','ë','í','ì','î','ï',
             'ó','ò','õ','ô','ö','ú','ù','û','ü','ç','ñ'];
    $to   = ['a','a','a','a','a','e','e','e','e','i','i','i','i',
             'o','o','o','o','o','u','u','u','u','c','n'];
    return str_replace($from, $to, $str);
}

/**
 * Faz GET e retorna array decodificado.
 */
function httpGet(string $url): array
{
    $ctx  = stream_context_create(['http' => [
        'timeout' => 30,
        'header'  => "User-Agent: PHP-Prova-Nasajon\r\n",
    ]]);
    $json = @file_get_contents($url, false, $ctx);
    if ($json === false) {
        throw new RuntimeException("Falha ao acessar: $url");
    }
    return json_decode($json, true) ?? [];
}

/**
 * Faz POST JSON com Authorization Bearer.
 */
function httpPost(string $url, array $payload): array
{
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
    $ctx  = stream_context_create(['http' => [
        'method'        => 'POST',
        'timeout'       => 30,
        'ignore_errors' => true,
        'header'        => implode("\r\n", [
            'Content-Type: application/json',
            'Authorization: Bearer ' . ACCESS_TOKEN,
        ]),
        'content' => $body,
    ]]);
    $json = @file_get_contents($url, false, $ctx);
    if ($json === false) {
        throw new RuntimeException("Falha ao POST em: $url");
    }
    return json_decode($json, true) ?? [];
}

/**
 * Lê o input.csv e retorna array de linhas.
 */
function lerInputCsv(string $path): array
{
    $rows = [];
    $fh   = fopen($path, 'r');
    if (!$fh) throw new RuntimeException("Não foi possível abrir: $path");
    fgetcsv($fh); // pula cabeçalho
    while (($line = fgetcsv($fh)) !== false) {
        if (count($line) < 2) continue;
        $rows[] = [
            'municipio' => trim($line[0]),
            'populacao' => (int) trim($line[1]),
        ];
    }
    fclose($fh);
    return $rows;
}

/**
 * Busca o melhor match IBGE para um nome.
 *
 * Estratégia:
 *  1. Busca por nome exato normalizado (sem acentos/case).
 *     Se houver múltiplos (ex: Santo André em SP, PB, RN),
 *     desempata pelo maior ID do IBGE — cidades em estados mais
 *     populosos tendem a ter códigos IBGE maiores.
 *  2. Se não houver exato, usa Levenshtein (máx LEVENSHTEIN_MAX).
 *     Desempata também pelo maior ID.
 *
 * Retorna: ['match' => array|null, 'dist' => int, 'status' => string]
 */
function buscarMunicipio(string $nome, array $ibge): array
{
    $nomeNorm = normalizar($nome);

    // --- 1. Busca exata normalizada ---
    $exatos = [];
    foreach ($ibge as $m) {
        if (normalizar($m['nome']) === $nomeNorm) {
            $exatos[] = $m;
        }
    }
    if (!empty($exatos)) {
        // Desempata pelo maior ID (estado mais populoso)
        usort($exatos, fn($a, $b) => $b['id'] <=> $a['id']);
        return ['match' => $exatos[0], 'dist' => 0, 'status' => 'OK'];
    }

    // --- 2. Fuzzy por Levenshtein ---
    $candidatos = [];
    foreach ($ibge as $m) {
        $dist = levenshtein($nomeNorm, normalizar($m['nome']));
        if ($dist <= LEVENSHTEIN_MAX) {
            $candidatos[] = ['municipio' => $m, 'dist' => $dist];
        }
    }

    if (empty($candidatos)) {
        return ['match' => null, 'dist' => 999, 'status' => 'NAO_ENCONTRADO'];
    }

    // Ordena: menor distância primeiro; empate → maior ID
    usort($candidatos, function ($a, $b) {
        if ($a['dist'] !== $b['dist']) return $a['dist'] <=> $b['dist'];
        return $b['municipio']['id'] <=> $a['municipio']['id'];
    });

    return [
        'match'  => $candidatos[0]['municipio'],
        'dist'   => $candidatos[0]['dist'],
        'status' => 'OK',
    ];
}

/**
 * Grava o resultado.csv conforme especificação.
 */
function gravarResultCsv(string $path, array $linhas): void
{
    $fh = fopen($path, 'w');
    if (!$fh) throw new RuntimeException("Não foi possível gravar: $path");
    fwrite($fh, "\xEF\xBB\xBF"); // BOM UTF-8 (para Excel)
    fputcsv($fh, ['municipio_input','populacao_input','municipio_ibge','uf','regiao','id_ibge','status']);
    foreach ($linhas as $l) {
        fputcsv($fh, [
            $l['municipio_input'],
            $l['populacao_input'],
            $l['municipio_ibge'],
            $l['uf'],
            $l['regiao'],
            $l['id_ibge'],
            $l['status'],
        ]);
    }
    fclose($fh);
}

/**
 * Calcula as estatísticas conforme especificação.
 * Apenas status = "OK" contribui para pop_total_ok e medias_por_regiao.
 */
function calcularEstatisticas(array $linhas): array
{
    $totalMunicipios    = count($linhas);
    $totalOk            = 0;
    $totalNaoEncontrado = 0;
    $totalErroApi       = 0;
    $popTotalOk         = 0;
    $somasPorRegiao     = [];
    $contagemPorRegiao  = [];

    foreach ($linhas as $l) {
        switch ($l['status']) {
            case 'OK':
                $totalOk++;
                $popTotalOk += $l['populacao_input'];
                $reg = $l['regiao'] ?: 'Desconhecida';
                $somasPorRegiao[$reg]    = ($somasPorRegiao[$reg]    ?? 0) + $l['populacao_input'];
                $contagemPorRegiao[$reg] = ($contagemPorRegiao[$reg] ?? 0) + 1;
                break;
            case 'NAO_ENCONTRADO':
                $totalNaoEncontrado++;
                break;
            case 'ERRO_API':
                $totalErroApi++;
                break;
        }
    }

    $mediasPorRegiao = [];
    foreach ($somasPorRegiao as $reg => $soma) {
        $mediasPorRegiao[$reg] = round($soma / $contagemPorRegiao[$reg], 2);
    }

    return [
        'total_municipios'     => $totalMunicipios,
        'total_ok'             => $totalOk,
        'total_nao_encontrado' => $totalNaoEncontrado,
        'total_erro_api'       => $totalErroApi,
        'pop_total_ok'         => $popTotalOk,
        'medias_por_regiao'    => $mediasPorRegiao,
    ];
}

// ============================================================
// FLUXO PRINCIPAL
// ============================================================

echo "=== Prova Técnica Nasajon ===\n\n";

// 1. Lê o input.csv
echo "[1/5] Lendo input.csv...\n";
$inputRows = lerInputCsv(INPUT_CSV);
echo "      " . count($inputRows) . " municípios lidos.\n\n";

// 2. Busca todos os municípios do IBGE (uma única chamada)
echo "[2/5] Buscando municípios na API do IBGE...\n";
try {
    $ibgeRaw = httpGet(IBGE_API_URL);
} catch (RuntimeException $e) {
    echo "      ERRO: " . $e->getMessage() . "\n";
    exit(1);
}

$ibgeMunicipios = [];
foreach ($ibgeRaw as $m) {
    // Alguns registros do IBGE podem ter estrutura incompleta — ignoramos
    $uf     = $m['microrregiao']['mesorregiao']['UF']['sigla']         ?? null;
    $regiao = $m['microrregiao']['mesorregiao']['UF']['regiao']['nome'] ?? null;
    if ($uf === null || $regiao === null) continue;

    $ibgeMunicipios[] = [
        'nome'   => $m['nome'],
        'id'     => (int) $m['id'],
        'uf'     => $uf,
        'regiao' => $regiao,
    ];
}
echo "      " . count($ibgeMunicipios) . " municípios carregados.\n\n";

// 3. Fase 1 — Faz matching individual para cada linha do input
echo "[3/5] Fazendo matching e enriquecendo dados...\n";

$matches = []; // guarda resultado bruto de cada linha (com dist para deduplicação)
foreach ($inputRows as $idx => $row) {
    $res = buscarMunicipio($row['municipio'], $ibgeMunicipios);
    $matches[$idx] = [
        'municipio_input' => $row['municipio'],
        'populacao_input' => $row['populacao'],
        'match'           => $res['match'],
        'dist'            => $res['dist'],
        'status'          => $res['status'],
    ];
}

// Fase 2 — Deduplicação: se dois inputs resolvem para o MESMO id_ibge,
// mantém apenas o de menor distância (melhor match); os demais → NAO_ENCONTRADO.
// Isso trata casos como "Santo Andre" + "Santoo Andre" apontando pro mesmo município.
$ibgeIdUsado = []; // id_ibge => idx vencedor
foreach ($matches as $idx => $m) {
    if ($m['status'] !== 'OK' || $m['match'] === null) continue;
    $id = $m['match']['id'];
    if (!isset($ibgeIdUsado[$id])) {
        $ibgeIdUsado[$id] = $idx;
    } else {
        $vencedorIdx  = $ibgeIdUsado[$id];
        $vencedorDist = $matches[$vencedorIdx]['dist'];
        if ($m['dist'] < $vencedorDist) {
            // Este match é melhor → o antigo vira NAO_ENCONTRADO
            $matches[$vencedorIdx]['status'] = 'NAO_ENCONTRADO';
            $matches[$vencedorIdx]['match']  = null;
            $ibgeIdUsado[$id] = $idx;
        } else {
            // O vencedor atual é melhor (ou empate → primeiro vence) → este vira NAO_ENCONTRADO
            $matches[$idx]['status'] = 'NAO_ENCONTRADO';
            $matches[$idx]['match']  = null;
        }
    }
}

// Monta linhas finais do resultado
$linhasResultado = [];
foreach ($matches as $m) {
    $match = $m['match'];
    $linhasResultado[] = [
        'municipio_input' => $m['municipio_input'],
        'populacao_input' => $m['populacao_input'],
        'municipio_ibge'  => $match ? $match['nome']   : '',
        'uf'              => $match ? $match['uf']      : '',
        'regiao'          => $match ? $match['regiao']  : '',
        'id_ibge'         => $match ? $match['id']      : '',
        'status'          => $m['status'],
    ];

    $label  = str_pad($m['status'], 16);
    $oficial = $match
        ? "\"{$match['nome']}\" ({$match['uf']}) [dist={$m['dist']}]"
        : 'não encontrado';
    echo "      [$label] \"{$m['municipio_input']}\" → $oficial\n";
}
echo "\n";

// 4. Grava resultado.csv
echo "[4/5] Gravando resultado.csv...\n";
gravarResultCsv(OUTPUT_CSV, $linhasResultado);
echo "      Gravado em: " . OUTPUT_CSV . "\n\n";

// 5. Calcula estatísticas, exibe e envia
echo "[5/5] Calculando estatísticas e enviando para a API de correção...\n\n";
$stats = calcularEstatisticas($linhasResultado);

echo "--- Estatísticas ---\n";
echo "total_municipios    : {$stats['total_municipios']}\n";
echo "total_ok            : {$stats['total_ok']}\n";
echo "total_nao_encontrado: {$stats['total_nao_encontrado']}\n";
echo "total_erro_api      : {$stats['total_erro_api']}\n";
echo "pop_total_ok        : " . number_format($stats['pop_total_ok'], 0, ',', '.') . "\n";
echo "medias_por_regiao   :\n";
foreach ($stats['medias_por_regiao'] as $reg => $media) {
    echo "  $reg: " . number_format($media, 2, ',', '.') . "\n";
}
echo "\n";

$payload = ['stats' => $stats];
echo "Payload enviado:\n" . json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";

try {
    $response = httpPost(EDGE_FUNCTION_URL, $payload);
    echo "Resposta da API de correção:\n";
    echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
} catch (RuntimeException $e) {
    echo "ERRO ao enviar para Edge Function: " . $e->getMessage() . "\n";
}

echo "\n=== Concluído ===\n";
