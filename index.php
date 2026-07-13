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
    // Se o arquivo não é modificado há mais de 1 hora, verifica se há concurso novo
    if (time() - filemtime($banco_dados) > $intervalo_auto_sincronizar) {
        $dados_ultimo_caixa = buscar_dados_da_pauta("https://loteriascaixa-api.herokuapp.com/api/lotofacil/latest");
        if ($dados_ultimo_caixa && isset($dados_ultimo_caixa['concurso'])) {
            $ultimo_id_caixa = (int)$dados_ultimo_caixa['concurso'];
            $ultimo_id_local = (int)$historico_completo[0]['concurso'];
            
            if ($ultimo_id_caixa > $ultimo_id_local) {
                $precisa_atualizar = true;
            } else {
                // Se já está na versão mais recente, apenas "toca" o arquivo para renovar a contagem de 1 hora
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

        // Popula ou atualiza até 35 registros para garantir folga na amostra de 30
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
                        'premiacoes' => isset($dados_c['premiacoes']) ? $dados_c['premiacoes'] : []
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

// Fallback de segurança extrema caso a API esteja fora do ar e o banco esteja zerado
if (empty($historico_completo)) {
    $historico_completo[] = [
        'concurso' => 3730,
        'data' => '11/07/2026',
        'dezenas' => ['01','02','04','05','06','07','10','11','12','13','16','17','22','23','25'],
        'premiacoes' => []
    ];
}

// =========================================================
// 3. CAPTURA PARÂMETROS DA URL
// =========================================================
$concurso_atual = isset($_GET['concurso']) ? (int)$_GET['concurso'] : 0;
$aba_ativa = isset($_GET['aba']) ? $_GET['aba'] : 'resultado';

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

// Mapeamento simples de premiações
$ganhadores = 0; $premio = 0; $acumulou = false;
if (isset($dados_api['premiacoes'])) {
    foreach ($dados_api['premiacoes'] as $p) {
        if (isset($p['faixa']) && $p['faixa'] == 1) {
            $ganhadores = (int)$p['ganhadores'];
            $premio = (float)$p['valorPremio'];
            if ($ganhadores === 0) { $acumulou = true; }
            break;
        }
    }
}

$dados = [
    'data' => isset($dados_api['data']) ? $dados_api['data'] : 'Disponível',
    'dezenas' => isset($dados_api['dezenas']) ? $dados_api['dezenas'] : [],
    'ganhadores_15' => $ganhadores,
    'premio_15' => $premio > 0 ? number_format($premio, 2, ',', '.') : 'Apurando valor...',
    'acumulou' => $acumulou
];

// Cálculo de Estatísticas do concurso exibido
$pares = 0; $impares = 0; $soma = 0;
foreach ($dados['dezenas'] as $num) {
    $n = (int)$num; $soma += $n;
    if ($n % 2 == 0) { $pares++; } else { $impares++; }
}

// Localiza concurso anterior para cálculo de repetidas
$concurso_anterior = $concurso_atual - 1;
$repetidas = 0; $dados_anterior = null;
foreach ($historico_completo as $c) {
    if ((int)$c['concurso'] === $concurso_anterior) {
        $dados_anterior = $c;
        break;
    }
}
if ($dados_anterior && isset($dados_anterior['dezenas'])) {
    foreach ($dados['dezenas'] as $dezena_atual) {
        if (in_array($dezena_atual, $dados_anterior['dezenas'])) { $repetidas++; }
    }
} else {
    $repetidas = "Carregando próximo...";
}

// =========================================================
// 4. PROCESSAMENTO DOS RANKINGS (AMOSTRA FIXA DE 30 CONCURSOS)
// =========================================================
$total_amostra = min(30, count($historico_completo));
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
arsort($frequencia_globais);

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

        .info-table, .estatisticas-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        .info-table td, .estatisticas-table td { padding: 10px; border-bottom: 1px solid #eee; text-align: left; }
        .info-table td:last-child, .estatisticas-table td:last-child { text-align: right; font-weight: bold; }
        
        .ranking-item { display: flex; justify-content: space-between; padding: 8px; border-bottom: 1px solid #eee; font-size: 0.95em; }
        .barra-progresso { background: #eee; border-radius: 4px; height: 10px; width: 50%; margin: auto 10px; overflow: hidden; }
        .barra-preenchida { background: #931f7c; height: 100%; }
        .barra-preenchida.atraso { background: #d9534f; }
        
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
        <a href="?concurso=<?= $concurso_atual ?>&aba=frequencia" class="tab-link <?= $aba_ativa == 'frequencia' ? 'active' : '' ?>">📊 Frequência</a>
        <a href="?concurso=<?= $concurso_atual ?>&aba=atraso" class="tab-link <?= $aba_ativa == 'atraso' ? 'active' : '' ?>">⏱️ Atraso</a>
        <a href="?concurso=<?= $concurso_atual ?>&aba=ausentes" class="tab-link <?= $aba_ativa == 'ausentes' ? 'active' : '' ?>">⭕ Ausentes</a>
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
            <tr><td>Prêmio Pago</td><td>R$ <?= $dados['premio_15'] ?></td></tr>
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

    <?php elseif ($aba_ativa == 'frequencia'): ?>
        <h2>Ranking de Frequência (Últimos <?= $total_amostra ?> Concursos)</h2>
        <?php foreach ($frequencia_globais as $dezena => $qtd): $pct = ($qtd / $total_amostra) * 100; ?>
            <div class="ranking-item">
                <strong>Dezena <?= str_pad($dezena, 2, '0', STR_PAD_LEFT) ?></strong>
                <div class="barra-progresso"><div class="barra-preenchida" style="width: <?= $pct ?>%;"></div></div>
                <span><?= $qtd ?> sorteadas</span>
            </div>
        <?php endforeach; ?>

    <?php elseif ($aba_ativa == 'atraso'): ?>
        <h2>Ranking de Atraso</h2>
        <?php 
        $exibir_atraso = $atraso_globais;
        arsort($exibir_atraso);
        foreach ($exibir_atraso as $dezena => $atraso): $pct_atraso = min(($atraso / $total_amostra) * 100, 100); ?>
            <div class="ranking-item">
                <strong>Dezena <?= str_pad($dezena, 2, '0', STR_PAD_LEFT) ?></strong>
                <div class="barra-progresso"><div class="barra-preenchida atraso" style="width: <?= $pct_atraso ?>%;"></div></div>
                <span><?= $atraso ?> conc. atrás</span>
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
        <h2>Ranking de Atraso entre as Ausentes</h2>
        <?php foreach ($ausentes_ordenadas_por_atraso as $da_ord): 
            $atr = isset($atraso_globais[(int)$da_ord]) ? $atraso_globais[(int)$da_ord] : 0; 
            $pct_da = min(($atr / $total_amostra) * 100, 100); ?>
            <div class="ranking-item">
                <strong>Dezena <?= $da_ord ?></strong>
                <div class="barra-progresso"><div class="barra-preenchida atraso" style="width: <?= $pct_da ?>%;"></div></div>
                <span><?= $atr ?> conc. atrás</span>
            </div>
        <?php endforeach; ?>

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
