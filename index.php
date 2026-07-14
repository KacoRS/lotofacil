<?php
header('Content-Type: text/html; charset=utf-8');

$banco_dados = "todos_sorteios.json";
$intervalo_auto_sincronizar = 3600; // 1 hora (em segundos)

// =========================================================
// FUNÇÕES AUXILIARES DE CONEXÃO
// =========================================================
function buscar_dados_da_pauta($url) {
    $contexto = stream_context_create([
        "http" => [
            "header" => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64)\r\n",
            "timeout" => 4
        ]
    ]);
    $resposta = @file_get_contents($url, false, $contexto);
    return $resposta ? json_decode($resposta, true) : null;
}

// =========================================================
// 1. CARREGA O BANCO DE DADOS LOCAL
// =========================================================
$historico_completo = [];
if (file_exists($banco_dados)) {
    $historico_completo = json_decode(file_get_contents($banco_dados), true) ?: [];
}

// =========================================================
// 2. VERIFICAÇÃO AUTOMÁTICA DE ATUALIZAÇÃO (SINCRO AUTOMÁTICA)
// =========================================================
$precisa_atualizar = false;

if (empty($historico_completo)) {
    $precisa_atualizar = true;
} else {
    if (time() - filemtime($banco_dados) > $intervalo_auto_sincronizar) {
        $dados_ultimo_caixa = buscar_dados_da_pauta("https://loteriascaixa-api.herokuapp.com/api/lotofacil/latest");
        if ($dados_ultimo_caixa && isset($dados_ultimo_caixa['concurso'])) {
            $ultimo_id_caixa = (int)$dados_ultimo_caixa['concurso'];
            $ultimo_id_local = (int)$historico_completo[0]['concurso'];
            
            if ($ultimo_id_caixa > $ultimo_id_local) {
                $precisa_atualizar = true;
            } else {
                @touch($banco_dados);
            }
        }
    }
}

// Executa a carga de novos sorteios se necessário
if ($precisa_atualizar) {
    $dados_ultimo_caixa = buscar_dados_da_pauta("https://loteriascaixa-api.herokuapp.com/api/lotofacil/latest");
    if ($dados_ultimo_caixa && isset($dados_ultimo_caixa['concurso'])) {
        $num_ultimo = (int)$dados_ultimo_caixa['concurso'];
        
        $local_por_id = [];
        foreach ($historico_completo as $c) {
            if (isset($c['concurso'])) $local_por_id[(int)$c['concurso']] = $c;
        }

        for ($i = 0; $i < 35; $i++) {
            $concurso_alvo = $num_ultimo - $i;
            if ($concurso_alvo < 1) break;
            
            if (!isset($local_por_id[$concurso_alvo])) {
                $dados_c = buscar_dados_da_pauta("https://loteriascaixa-api.herokuapp.com/api/lotofacil/" . $concurso_alvo);
                if ($dados_c && isset($dados_c['dezenas'])) {
                    $local_por_id[$concurso_alvo] = [
                        'concurso' => (int)$dados_c['concurso'],
                        'data' => $dados_c['data'],
                        'dezenas' => $dados_c['dezenas'],
                        'premiacoes' => isset($dados_c['premiacoes']) ? $dados_c['premiacoes'] : [],
                        'acumulou' => isset($dados_c['acumulou']) ? $dados_c['acumulou'] : false,
                        'acumuladaProxConcurso' => isset($dados_c['acumuladaProxConcurso']) ? $dados_c['acumuladaProxConcurso'] : ''
                    ];
                }
                usleep(200000); // 0.2s de segurança
            }
        }
        krsort($local_por_id);
        $historico_completo = array_values($local_por_id);
        @file_put_contents($banco_dados, json_encode($historico_completo, JSON_PRETTY_PRINT));
    }
}

if (empty($historico_completo)) {
    $historico_completo[] = [
        'concurso' => 3730,
        'data' => '11/07/2026',
        'dezenas' => ['01','02','04','05','06','07','10','11','12','13','16','17','22','23','25'],
        'premiacoes' => [],
        'acumulou' => false,
        'acumuladaProxConcurso' => ''
    ];
}

// =========================================================
// 3. CAPTURA PARÂMETROS DA URL
// =========================================================
$concurso_atual = isset($_GET['concurso']) ? (int)$_GET['concurso'] : 0;
$aba_ativa = isset($_GET['aba']) ? $_GET['aba'] : 'resultado';

if ($aba_ativa == 'atraso') {
    $aba_ativa = 'ausentes';
}

$ultimo_concurso_na_caixa = (int)$historico_completo[0]['concurso'];

if ($concurso_atual == 0) {
    $concurso_atual = $ultimo_concurso_na_caixa;
    $dados_api = $historico_completo[0];
} else {
    $dados_api = null;
    foreach ($historico_completo as $c) {
        if ((int)$c['concurso'] === $concurso_atual) {
            $dados_api = $c;
            break;
        }
    }
    if (!$dados_api) {
        $dados_api = $historico_completo[0];
        $concurso_atual = (int)$dados_api['concurso'];
    }
}

// Mapeamento de Premiação e Acúmulo
$ganhadores = 0; 
$premio = 0;
$acumulou = isset($dados_api['acumulou']) ? $dados_api['acumulou'] : false;
$valor_acumulado = isset($dados_api['acumuladaProxConcurso']) ? $dados_api['acumuladaProxConcurso'] : '';

if (isset($dados_api['premiacoes'])) {
    foreach ($dados_api['premiacoes'] as $p) {
        if (isset($p['faixa']) && $p['faixa'] == 1) {
            $ganhadores = (int)$p['ganhadores'];
            $premio = (float)$p['valorPremio'];
            break;
        }
    }
}

if ($ganhadores === 0) {
    $acumulou = true;
}

$premio_exibivel = $premio > 0 ? 'R$ ' . number_format($premio, 2, ',', '.') : 'Apurando valor...';

$dados = [
    'data' => isset($dados_api['data']) ? $dados_api['data'] : 'Disponível',
    'dezenas' => isset($dados_api['dezenas']) ? $dados_api['dezenas'] : [],
    'ganhadores_15' => $ganhadores,
    'premio_15' => $premio_exibivel,
    'acumulou' => $acumulou,
    'valor_acumulado' => $valor_acumulado
];

// Cálculo de Estatísticas do concurso exibido
$pares = 0; $impares = 0; $soma = 0;
foreach ($dados['dezenas'] as $num) {
    $n = (int)$num; $soma += $n;
    if ($n % 2 == 0) { $pares++; } else { $impares++; }
}

// Localiza concurso anterior e calcula suas métricas
$concurso_anterior_id = $concurso_atual - 1;
$dados_anterior = null;
foreach ($historico_completo as $c) {
    if ((int)$c['concurso'] === $concurso_anterior_id) {
        $dados_anterior = $c;
        break;
    }
}

$anterior_data = "";
$anterior_dezenas = [];
$anterior_pares = 0;
$anterior_impares = 0;
$anterior_soma = 0;
$repetidas = 0;

if ($dados_anterior) {
    $anterior_data = isset($dados_anterior['data']) ? $dados_anterior['data'] : '';
    $anterior_dezenas = isset($dados_anterior['dezenas']) ? $dados_anterior['dezenas'] : [];
    foreach ($anterior_dezenas as $num) {
        $n = (int)$num; $anterior_soma += $n;
        if ($n % 2 == 0) { $anterior_pares++; } else { $anterior_impares++; }
    }
    foreach ($dados['dezenas'] as $dezena_atual) {
        if (in_array($dezena_atual, $anterior_dezenas)) { $repetidas++; }
    }
} else {
    $repetidas = "Sem dados do anterior";
}

// =========================================================
// 4. PROCESSAMENTO UNIFICADO DE AMOSTRA (EXATAMENTE 25 SORTEIOS)
// =========================================================
$total_amostra = min(25, count($historico_completo));
$frequencia_globais = array_fill(1, 25, 0);
$atraso_globais = array_fill(1, 25, 0);
$dezenas_vistas = [];

for ($i = 0; $i < $total_amostra; $i++) {
    $dados_c = $historico_completo[$i];
    foreach ($dados_c['dezenas'] as $d) {
        $frequencia_globais[(int)$d]++;
    }
    foreach (range(1, 25) as $dezena_check) {
        $check_formatado = str_pad($dezena_check, 2, '0', STR_PAD_LEFT);
        if (in_array($check_formatado, $dados_c['dezenas']) && !isset($dezenas_vistas[$dezena_check])) {
            $atraso_globais[$dezena_check] = $i;
            $dezenas_vistas[$dezena_check] = true;
        }
    }
}
foreach (range(1, 25) as $d_check) {
    if (!isset($dezenas_vistas[$d_check])) { $atraso_globais[$d_check] = $total_amostra; }
}

$frequencia_globais_ordenada = $frequencia_globais;
arsort($frequencia_globais_ordenada);

// =========================================================
// 5. CÁLCULO DE RANKINGS DE REPETIÇÃO
// =========================================================
$repetidas_ultimo_ranking = [];
$repetidas_pares_ranking = [];
$repetidas_impares_ranking = [];

foreach ($dados['dezenas'] as $dezena_str) {
    if (in_array($dezena_str, $anterior_dezenas)) {
        $dezena_int = (int)$dezena_str;
        $qtd_sorteios = $frequencia_globais[$dezena_int];
        
        $repetidas_ultimo_ranking[$dezena_int] = $qtd_sorteios;
        if ($dezena_int % 2 == 0) {
            $repetidas_pares_ranking[$dezena_int] = $qtd_sorteios;
        } else {
            $repetidas_impares_ranking[$dezena_int] = $qtd_sorteios;
        }
    }
}

arsort($repetidas_ultimo_ranking);
arsort($repetidas_pares_ranking);
arsort($repetidas_impares_ranking);

// Lógica de Dezenas Ausentes
$dezenas_ausentes = [];
$ausentes_pares = 0; $ausentes_impares = 0; $soma_atrasos_ausentes = 0;
for ($num = 1; $num <= 25; $num++) {
    $num_formatado = str_pad($num, 2, '0', STR_PAD_LEFT);
    if (!in_array($num_formatado, $dados['dezenas'])) {
        $dezenas_ausentes[] = $num_formatado;
        if ($num % 2 == 0) { $ausentes_pares++; } else { $ausentes_impares++; }
        $soma_atrasos_ausentes += isset($atraso_globais[$num]) ? $atraso_globais[$num] : 0;
    }
}

$ausentes_ordenadas_por_atraso = $dezenas_ausentes;
usort($ausentes_ordenadas_por_atraso, function($a, $b) use ($atraso_globais) {
    $atrasoA = isset($atraso_globais[(int)$a]) ? $atraso_globais[(int)$a] : 0;
    $atrasoB = isset($atraso_globais[(int)$b]) ? $atraso_globais[(int)$b] : 0;
    if ($atrasoA == $atrasoB) return 0;
    return ($atrasoA < $atrasoB) ? 1 : -1;
});

$ausentes_pares_ordenadas = [];
$ausentes_impares_ordenadas = [];

foreach ($ausentes_ordenadas_por_atraso as $aus_dez) {
    $aus_int = (int)$aus_dez;
    if ($aus_int % 2 == 0) {
        $ausentes_pares_ordenadas[] = $aus_dez;
    } else {
        $ausentes_impares_ordenadas[] = $aus_dez;
    }
}

// =========================================================
// 6. PROCESSAMENTO DA NOVA ABA DE MÉDIAS (HISTÓRICA VS 25 SORTEIOS)
// =========================================================
$total_concursos_banco = count($historico_completo);

// Inicializadores para o Histórico Completo
$hist_total_repetidas = 0;
$hist_total_pares = 0;
$hist_total_impares = 0;
$hist_total_soma = 0;
$hist_total_retorno_ausentes = 0;
$hist_concursos_com_anterior = 0;

// Inicializadores para os Últimos 25 Sorteios
$u25_total_repetidas = 0;
$u25_total_pares = 0;
$u25_total_impares = 0;
$u25_total_soma = 0;
$u25_total_retorno_ausentes = 0;
$u25_concursos_com_anterior = 0;

// Varre todo o banco de dados de trás para frente (ou indexado) para calcular as transições de ciclo
for ($idx = 0; $idx < $total_concursos_banco; $idx++) {
    $sorteio_atual_analise = $historico_completo[$idx];
    $dezenas_atuais = $sorteio_atual_analise['dezenas'];
    
    // Contagem de Pares, Ímpares e Soma
    $local_pares = 0; $local_impares = 0; $local_soma = 0;
    foreach ($dezenas_atuais as $d_str) {
        $d_int = (int)$d_str;
        $local_soma += $d_int;
        if ($d_int % 2 == 0) { $local_pares++; } else { $local_impares++; }
    }
    
    // Acumula Histórico Global
    $hist_total_pares += $local_pares;
    $hist_total_impares += $local_impares;
    $hist_total_soma += $local_soma;
    
    // Acumula Últimos 25 (se estiver no intervalo correto)
    if ($idx < 25) {
        $u25_total_pares += $local_pares;
        $u25_total_impares += $local_impares;
        $u25_total_soma += $local_soma;
    }
    
    // Cálculos dependentes do concurso anterior (repetidas e retorno de ausentes)
    $proximo_indice = $idx + 1;
    if ($proximo_indice < $total_concursos_banco) {
        $sorteio_anterior_analise = $historico_completo[$proximo_indice];
        $dezenas_anteriores = $sorteio_anterior_analise['dezenas'];
        
        // 1. Quantas repetiram do anterior
        $local_repetidas = 0;
        foreach ($dezenas_atuais as $d_atual) {
            if (in_array($d_atual, $dezenas_anteriores)) {
                $local_repetidas++;
            }
        }
        
        // 2. Quantas ausentes voltaram (Retorno de Ausentes)
        // Ausentes do anterior = dezenas de 1 a 25 que não estavam no anterior
        $local_retorno_ausentes = 0;
        for ($n_check = 1; $n_check <= 25; $n_check++) {
            $n_format = str_pad($n_check, 2, '0', STR_PAD_LEFT);
            if (!in_array($n_format, $dezenas_anteriores)) {
                // Se era ausente no anterior e foi sorteada no atual, retornou!
                if (in_array($n_format, $dezenas_atuais)) {
                    $local_retorno_ausentes++;
                }
            }
        }
        
        // Acumuladores globais
        $hist_total_repetidas += $local_repetidas;
        $hist_total_retorno_ausentes += $local_retorno_ausentes;
        $hist_concursos_com_anterior++;
        
        // Acumuladores dos últimos 25
        if ($idx < 25) {
            $u25_total_repetidas += $local_repetidas;
            $u25_total_retorno_ausentes += $local_retorno_ausentes;
            $u25_concursos_com_anterior++;
        }
    }
}

// Cálculo das Médias Finais Históricas
$media_hist_repetidas = $hist_concursos_com_anterior > 0 ? $hist_total_repetidas / $hist_concursos_com_anterior : 0;
$media_hist_pares = $total_concursos_banco > 0 ? $hist_total_pares / $total_concursos_banco : 0;
$media_hist_impares = $total_concursos_banco > 0 ? $hist_total_impares / $total_concursos_banco : 0;
$media_hist_soma = $total_concursos_banco > 0 ? $hist_total_soma / $total_concursos_banco : 0;
$media_hist_retorno_ausentes = $hist_concursos_com_anterior > 0 ? $hist_total_retorno_ausentes / $hist_concursos_com_anterior : 0;

// Cálculo das Médias Finais dos Últimos 25
$media_u25_repetidas = $u25_concursos_com_anterior > 0 ? $u25_total_repetidas / $u25_concursos_com_anterior : 0;
$media_u25_pares = $total_amostra > 0 ? $u25_total_pares / $total_amostra : 0;
$media_u25_impares = $total_amostra > 0 ? $u25_total_impares / $total_amostra : 0;
$media_u25_soma = $total_amostra > 0 ? $u25_total_soma / $total_amostra : 0;
$media_u25_retorno_ausentes = $u25_concursos_com_anterior > 0 ? $u25_total_retorno_ausentes / $u25_concursos_com_anterior : 0;

$anterior = $concurso_atual - 1;
$proximo = $concurso_atual + 1;
if ($anterior < 1) { $anterior = 1; }
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Central Lotofácil - Estatísticas</title>
    <style>
        body { font-family: Arial, sans-serif; background-color: #f4f4f9; color: #333; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; padding: 20px 0; }
        .container { background: #fff; padding: 30px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); text-align: center; max-width: 550px; width: 100%; }
        h1 { color: #931f7c; margin-bottom: 5px; }
        h2 { color: #931f7c; font-size: 1.2em; margin-top: 25px; margin-bottom: 15px; border-bottom: 2px solid #931f7c; padding-bottom: 5px; text-align: left; }
        .data-sorteio { color: #666; font-size: 0.9em; margin-bottom: 25px; }
        
        .abas-menu { display: flex; justify-content: space-around; border-bottom: 2px solid #eee; margin-bottom: 20px; flex-wrap: wrap; gap: 5px; }
        .tab-link { padding: 10px 10px; text-decoration: none; color: #666; font-weight: bold; border-bottom: 3px solid transparent; transition: all 0.2s; font-size: 0.9em; }
        .tab-link.active { color: #931f7c; border-bottom-color: #931f7c; }
        
        .dezenas-container { display: flex; flex-wrap: wrap; justify-content: center; gap: 10px; margin-bottom: 30px; }
        .dezena { background-color: #931f7c; color: white; font-weight: bold; font-size: 1.2em; width: 45px; height: 45px; border-radius: 50%; display: flex; align-items: center; justify-content: center; box-shadow: 0 2px 5px rgba(0,0,0,0.2); }
        .dezena.ausente { background-color: #d9534f; }
        .dezena.azul { background-color: #2a9d8f; }

        .info-table, .estatisticas-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        .info-table td, .estatisticas-table td { padding: 10px; border-bottom: 1px solid #eee; text-align: left; }
        .info-table td:last-child, .estatisticas-table td:last-child { text-align: right; font-weight: bold; }
        
        .ranking-item { display: flex; justify-content: space-between; padding: 8px; border-bottom: 1px solid #eee; font-size: 0.95em; }
        .barra-progresso { background: #eee; border-radius: 4px; height: 10px; width: 50%; margin: auto 10px; overflow: hidden; }
        .barra-preenchida { background: #931f7c; height: 100%; }
        .barra-preenchida.atraso { background: #d9534f; }
        .barra-preenchida.verde { background: #2a9d8f; }
        
        .navegacao { display: flex; justify-content: space-between; align-items: center; margin-top: 25px; }
        .btn { background-color: #931f7c; color: white; padding: 10px 20px; text-decoration: none; border-radius: 6px; font-weight: bold; }
        .btn.disabled { background-color: #ccc; pointer-events: none; }
    </style>
</head>
<body>

<div class="container">
    <h1>Central Lotofácil</h1>
    
    <div class="abas-menu">
        <a href="?concurso=<?= $concurso_atual ?>&aba=resultado" class="tab-link <?= $aba_ativa == 'resultado' ? 'active' : '' ?>">🏠 Resultado</a>
        <a href="?concurso=<?= $concurso_atual ?>&aba=repetidas" class="tab-link <?= $aba_ativa == 'repetidas' ? 'active' : '' ?>">🔁 Repetidas</a>
        <a href="?concurso=<?= $concurso_atual ?>&aba=frequencia" class="tab-link <?= $aba_ativa == 'frequencia' ? 'active' : '' ?>">📊 Frequência</a>
        <a href="?concurso=<?= $concurso_atual ?>&aba=ausentes" class="tab-link <?= $aba_ativa == 'ausentes' ? 'active' : '' ?>">⭕ Ausentes</a>
        <a href="?concurso=<?= $concurso_atual ?>&aba=media" class="tab-link <?= $aba_ativa == 'media' ? 'active' : '' ?>">📈 Médias</a>
        <a href="?concurso=<?= $concurso_atual ?>&aba=historico" class="tab-link <?= $aba_ativa == 'historico' ? 'active' : '' ?>">📅 Histórico</a>
    </div>

    <?php if ($aba_ativa == 'resultado'): ?>
        <div class="data-sorteio">Concurso <?= $concurso_atual ?> (<?= $dados['data'] ?>)</div>
        <div class="dezenas-container">
            <?php foreach ($dados['dezenas'] as $dezena): ?>
                <div class="dezena"><?= str_pad($dezena, 2, '0', STR_PAD_LEFT) ?></div>
            <?php endforeach; ?>
        </div>
        <table class="info-table">
            <tr><td>Ganhadores (15 acertos)</td><td><?= $dados['ganhadores_15'] ?></td></tr>
            
            <?php if ($dados['acumulou']): ?>
                <tr>
                    <td style="color: #d9534f; font-weight: bold;">Acumulou!</td>
                    <td style="color: #d9534f; font-weight: bold;">
                        <?= !empty($dados['valor_acumulado']) ? 'Est. ' . $dados['valor_acumulado'] : 'Acumulado' ?>
                    </td>
                </tr>
            <?php else: ?>
                <tr>
                    <td>Prêmio Pago</td>
                    <td><?= $dados['premio_15'] ?></td>
                </tr>
            <?php endif; ?>
        </table>
        
        <h2>Análise Estatística</h2>
        <table class="estatisticas-table">
            <tr><td>Pares / Ímpares</td><td><?= $pares ?> pares / <?= $impares ?> ímpares</td></tr>
            <tr><td>Repetidas do Anterior</td><td><?= $repetidas ?> dezenas</td></tr>
            <tr><td>Soma das Dezenas</td><td><?= $soma ?></td></tr>
        </table>
        <div class="navegacao">
            <a href="?concurso=<?= $anterior ?>&aba=resultado" class="btn <?= ($concurso_atual <= 1) ? 'disabled' : '' ?>">&lt; Anterior</a>
            <span style="font-weight:bold;">Concurso <?= $concurso_atual ?></span>
            <a href="?concurso=<?= $proximo ?>&aba=resultado" class="btn <?= ($concurso_atual >= $ultimo_concurso_na_caixa) ? 'disabled' : '' ?>">Próximo &gt;</a>
        </div>

    <?php elseif ($aba_ativa == 'repetidas'): ?>
        <?php if ($dados_anterior): ?>
            <h2>Concurso Anterior: <?= $concurso_anterior_id ?> (<?= $anterior_data ?>)</h2>
            <div class="dezenas-container">
                <?php foreach ($anterior_dezenas as $dezena_ant): ?>
                    <div class="dezena azul"><?= str_pad($dezena_ant, 2, '0', STR_PAD_LEFT) ?></div>
                <?php endforeach; ?>
            </div>
            <table class="estatisticas-table">
                <tr><td>Pares / Ímpares (Anterior)</td><td><?= $anterior_pares ?> pares / <?= $anterior_impares ?> ímpares</td></tr>
                <tr><td>Soma das Dezenas (Anterior)</td><td><?= $anterior_soma ?></td></tr>
                <tr><td>Repetiram no Sorteio Atual (<?= $concurso_atual ?>)</td><td style="color: #2a9d8f; font-size: 1.1em;"><?= $repetidas ?> dezenas</td></tr>
            </table>
        <?php else: ?>
            <p>Aguardando sincronização do concurso anterior...</p>
        <?php endif; ?>

        <h2>Frequência das Repetidas (Últimos <?= $total_amostra ?> Sorteios)</h2>
        <p style="font-size: 0.85em; color: #666; margin-top:-10px; margin-bottom:15px; text-align:left;">
            Análise de força das dezenas repetidas do concurso anterior com base em sua frequência histórica nos últimos <?= $total_amostra ?> concursos.
        </p>
        
        <?php if (!empty($repetidas_ultimo_ranking)): ?>
            <?php foreach ($repetidas_ultimo_ranking as $dezena => $qtd): $pct = ($qtd / $total_amostra) * 100; ?>
                <div class="ranking-item">
                    <strong>Dezena <?= str_pad($dezena, 2, '0', STR_PAD_LEFT) ?></strong>
                    <div class="barra-progresso"><div class="barra-preenchida verde" style="width: <?= $pct ?>%;"></div></div>
                    <span><?= $qtd ?> / <?= $total_amostra ?></span>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <p style="color: #666; font-size: 0.9em; text-align: left;">Nenhuma dezena repetida identificada neste concurso.</p>
        <?php endif; ?>

        <h2>Apenas das Dezenas Pares repetidas do concurso anterior vs Frequência (Últimos <?= $total_amostra ?>)</h2>
        <?php if (!empty($repetidas_pares_ranking)): ?>
            <?php foreach ($repetidas_pares_ranking as $dezena => $qtd): $pct = ($qtd / $total_amostra) * 100; ?>
                <div class="ranking-item">
                    <strong>Dezena <?= str_pad($dezena, 2, '0', STR_PAD_LEFT) ?> (Par)</strong>
                    <div class="barra-progresso"><div class="barra-preenchida" style="width: <?= $pct ?>%; background: #931f7c;"></div></div>
                    <span><?= $qtd ?> de <?= $total_amostra ?></span>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <p style="color: #666; font-size: 0.9em; text-align: left;">Nenhuma dezena par repetida do anterior.</p>
        <?php endif; ?>

        <h2>Apenas das Dezenas impares repetidas do concurso anterior vs Frequência (Últimos <?= $total_amostra ?>)</h2>
        <?php if (!empty($repetidas_impares_ranking)): ?>
            <?php foreach ($repetidas_impares_ranking as $dezena => $qtd): $pct = ($qtd / $total_amostra) * 100; ?>
                <div class="ranking-item">
                    <strong>Dezena <?= str_pad($dezena, 2, '0', STR_PAD_LEFT) ?> (Ímpar)</strong>
                    <div class="barra-progresso"><div class="barra-preenchida" style="width: <?= $pct ?>%; background: #f0ad4e;"></div></div>
                    <span><?= $qtd ?> de <?= $total_amostra ?></span>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <p style="color: #666; font-size: 0.9em; text-align: left;">Nenhuma dezena ímpar repetida do anterior.</p>
        <?php endif; ?>

    <?php elseif ($aba_ativa == 'frequencia'): ?>
        <h2>Ranking de Frequência (Últimos <?= $total_amostra ?> Concursos)</h2>
        <?php foreach ($frequencia_globais_ordenada as $dezena => $qtd): $pct = ($qtd / $total_amostra) * 100; ?>
            <div class="ranking-item">
                <strong>Dezena <?= str_pad($dezena, 2, '0', STR_PAD_LEFT) ?></strong>
                <div class="barra-progresso"><div class="barra-preenchida" style="width: <?= $pct ?>%;"></div></div>
                <span><?= $qtd ?> sorteadas</span>
            </div>
        <?php endforeach; ?>

    <?php elseif ($aba_ativa == 'ausentes'): ?>
        <h2>Dezenas Ausentes no Concurso <?= $concurso_atual ?></h2>
        <div class="dezenas-container">
            <?php foreach ($dezenas_ausentes as $da): ?>
                <div class="dezena ausente"><?= $da ?></div>
            <?php endforeach; ?>
        </div>
        <table class="estatisticas-table">
            <tr><td>Pares / Ímpares das Ausentes</td><td><?= $ausentes_pares ?> pares / <?= $ausentes_impares ?> ímpares</td></tr>
            <tr><td>Soma total dos atrasos acumulados</td><td><?= $soma_atrasos_ausentes ?> concursos</td></tr>
        </table>

        <h2>Ranking Geral de Atraso das Ausentes (Últimos <?= $total_amostra ?> Sorteios)</h2>
        <?php foreach ($ausentes_ordenadas_por_atraso as $da_ord): 
            $atr = isset($atraso_globais[(int)$da_ord]) ? $atraso_globais[(int)$da_ord] : 0; 
            $pct_da = min(($atr / $total_amostra) * 100, 100); ?>
            <div class="ranking-item">
                <strong>Dezena <?= $da_ord ?></strong>
                <div class="barra-progresso"><div class="barra-preenchida atraso" style="width: <?= $pct_da ?>%;"></div></div>
                <span><?= $atr ?> conc. atrás</span>
            </div>
        <?php endforeach; ?>

        <h2>Ranking das Pares Ausentes (Últimos <?= $total_amostra ?> Sorteios)</h2>
        <?php if (!empty($ausentes_pares_ordenadas)): ?>
            <?php foreach ($ausentes_pares_ordenadas as $da_par): 
                $atr = isset($atraso_globais[(int)$da_par]) ? $atraso_globais[(int)$da_par] : 0; 
                $pct_da = min(($atr / $total_amostra) * 100, 100); ?>
                <div class="ranking-item">
                    <strong>Dezena <?= $da_par ?> (Par)</strong>
                    <div class="barra-progresso"><div class="barra-preenchida atraso" style="width: <?= $pct_da ?>%; background: #931f7c;"></div></div>
                    <span><?= $atr ?> conc. atrás</span>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <p style="color: #666; font-size: 0.9em; text-align: left;">Nenhuma dezena par ausente.</p>
        <?php endif; ?>

        <h2>Ranking das Ímpares Ausentes (Últimos <?= $total_amostra ?> Sorteios)</h2>
        <?php if (!empty($ausentes_impares_ordenadas)): ?>
            <?php foreach ($ausentes_impares_ordenadas as $da_imp): 
                $atr = isset($atraso_globais[(int)$da_imp]) ? $atraso_globais[(int)$da_imp] : 0; 
                $pct_da = min(($atr / $total_amostra) * 100, 100); ?>
                <div class="ranking-item">
                    <strong>Dezena <?= $da_imp ?> (Ímpar)</strong>
                    <div class="barra-progresso"><div class="barra-preenchida atraso" style="width: <?= $pct_da ?>%; background: #f0ad4e;"></div></div>
                    <span><?= $atr ?> conc. atrás</span>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <p style="color: #666; font-size: 0.9em; text-align: left;">Nenhuma dezena ímpar ausente.</p>
        <?php endif; ?>

    <?php elseif ($aba_ativa == 'media'): ?>
        <h2>📈 Médias Gerais de Desempenho</h2>
        <p style="font-size: 0.85em; color: #666; margin-top:-10px; margin-bottom:20px; text-align:left;">
            Comparativo robusto de comportamento das dezenas no histórico total vs. ciclo recente.
        </p>

        <h2>1. Média Histórica (Todos os <?= $total_concursos_banco ?> Concursos)</h2>
        <table class="estatisticas-table">
            <tr>
                <td>Dezenas Repetidas</td>
                <td><?= number_format($media_hist_repetidas, 2, ',', '.') ?> dezenas</td>
            </tr>
            <tr>
                <td>Par / Ímpar (Média)</td>
                <td><?= number_format($media_hist_pares, 1, ',', '.') ?> Pares / <?= number_format($media_hist_impares, 1, ',', '.') ?> Ímpares</td>
            </tr>
            <tr>
                <td>Soma das Dezenas</td>
                <td><?= number_format($media_hist_soma, 1, ',', '.') ?> (Soma média)</td>
            </tr>
            <tr>
                <td>Retorno das Dezenas Ausentes</td>
                <td><?= number_format($media_hist_retorno_ausentes, 2, ',', '.') ?> dezenas que voltam</td>
            </tr>
        </table>

        <h2>2. Média Recente (Últimos <?= $total_amostra ?> Sorteios)</h2>
        <table class="estatisticas-table">
            <tr>
                <td>Dezenas Repetidas</td>
                <td><?= number_format($media_u25_repetidas, 2, ',', '.') ?> dezenas</td>
            </tr>
            <tr>
                <td>Par / Ímpar (Média)</td>
                <td><?= number_format($media_u25_pares, 1, ',', '.') ?> Pares / <?= number_format($media_u25_impares, 1, ',', '.') ?> Ímpares</td>
            </tr>
            <tr>
                <td>Soma das Dezenas</td>
                <td><?= number_format($media_u25_soma, 1, ',', '.') ?> (Soma média)</td>
            </tr>
            <tr>
                <td>Retorno das Dezenas Ausentes</td>
                <td><?= number_format($media_u25_retorno_ausentes, 2, ',', '.') ?> dezenas que voltam</td>
            </tr>
        </table>

    <?php elseif ($aba_ativa == 'historico'): ?>
        <h2>Histórico de Sorteios Recentes (Amostra de <?= $total_amostra ?>)</h2>
        <div style="text-align: left; max-height: 400px; overflow-y: auto; padding-right: 5px;">
            <?php foreach (array_slice($historico_completo, 0, $total_amostra) as $c): ?>
                <div style="padding: 10px; border-bottom: 1px solid #eee;">
                    <strong>Concurso <?= $c['concurso'] ?></strong> (<?= $c['data'] ?>)<br>
                    <span style="font-size: 0.9em; color: #931f7c; letter-spacing: 2px;">
                        <?= implode(' ', $c['dezenas']) ?>
                    </span>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

</body>
</html>
