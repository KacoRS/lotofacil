<?php
header('Content-Type: text/html; charset=utf-8');

$arquivo_cache = "cache_lotofacil.txt";
$tempo_expiracao = 1800; // 30 minutos

// 1. Captura o concurso solicitado na URL
$concurso_atual = isset($_GET['concurso']) ? (int)$_GET['concurso'] : 0;

// Função auxiliar para buscar na API externa
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

// 2. Tenta descobrir o último concurso real
$dados_ultimo = null;
if (file_exists($arquivo_cache) && (time() - filemtime($arquivo_cache) < $tempo_expiracao)) {
    $dados_ultimo = json_decode(file_get_contents($arquivo_cache), true);
}

if (!$dados_ultimo) {
    $dados_ultimo = buscar_dados_da_pauta("https://loteriascaixa-api.herokuapp.com/api/lotofacil/latest");
    if ($dados_ultimo) {
        @file_put_contents($arquivo_cache, json_encode($dados_ultimo));
    }
}

// Define o limite dinâmico de segurança
$ultimo_concurso_na_caixa = $dados_ultimo ? (int)$dados_ultimo['concurso'] : 3730; 

// 3. Define qual concurso carregar
if ($concurso_atual == 0) {
    $concurso_atual = $ultimo_concurso_na_caixa;
    $dados_api = $dados_ultimo;
} else {
    $dados_api = buscar_dados_da_pauta("https://loteriascaixa-api.herokuapp.com/api/lotofacil/" . $concurso_atual);
}

// 4. Plano B (Caso falte conexão)
if (!$dados_api) {
    $dados_api = [
        'concurso' => $concurso_atual,
        'data' => 'Sorteado',
        'dezenas' => ['02','03','05','06','08','11','12','13','14','15','16','19','20','21','24'], 
        'premiacoes' => [['faixa' => 1, 'ganhadores' => 0, 'valorPremio' => 1500000.00]]
    ];
}

// 5. Mapeamento dos prêmios e Ganhadores
$ganhadores = 0;
$premio = 0;
$acumulou = false;

if (isset($dados_api['premiacoes'])) {
    foreach ($dados_api['premiacoes'] as $p) {
        if (isset($p['faixa']) && $p['faixa'] == 1) {
            $ganhadores = (int)$p['ganhadores'];
            $premio = (float)$p['valorPremio'];
            if ($ganhadores === 0) {
                $acumulou = true;
            }
            break;
        }
    }
}

$dados = [
    'data' => isset($dados_api['data']) ? $dados_api['data'] : 'Dispon&iacute;vel',
    'dezenas' => isset($dados_api['dezenas']) ? $dados_api['dezenas'] : [],
    'ganhadores_15' => $ganhadores,
    'premio_15' => $premio > 0 ? number_format($premio, 2, ',', '.') : 'Apurando valor...',
    'acumulou' => $acumulou
];

// 6. CÁLCULO DAS ESTATÍSTICAS DO CONCURSO
$pares = 0;
$impares = 0;
$primos = 0;
$soma = 0;
$lista_primos = [2, 3, 5, 7, 11, 13, 17, 19, 23];

foreach ($dados['dezenas'] as $num) {
    $n = (int)$num;
    $soma += $n;
    
    if ($n % 2 == 0) {
        $pares++;
    } else {
        $impares++;
    }
    
    if (in_array($n, $lista_primos)) {
        $primos++;
    }
}

// Controles de Navegação
$anterior = $concurso_atual - 1;
$proximo = $concurso_atual + 1;
if ($anterior < 1) { $anterior = 1; }
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Resultado Lotof&aacute;cil - Concurso <?= $concurso_atual ?></title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f4f4f9;
            color: #333;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
            padding: 20px 0;
        }
        .container {
            background: #fff;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            text-align: center;
            max-width: 500px;
            width: 100%;
        }
        h1 {
            color: #931f7c;
            margin-bottom: 5px;
        }
        h2 {
            color: #931f7c;
            font-size: 1.2em;
            margin-top: 25px;
            margin-bottom: 15px;
            border-bottom: 2px solid #931f7c;
            padding-bottom: 5px;
            text-align: left;
        }
        .data-sorteio {
            color: #666;
            font-size: 0.9em;
            margin-bottom: 25px;
        }
        .dezenas-container {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 10px;
            margin-bottom: 30px;
        }
        .dezena {
            background-color: #931f7c;
            color: white;
            font-weight: bold;
            font-size: 1.2em;
            width: 45px;
            height: 45px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 2px 5px rgba(0,0,0,0.2);
        }
        .info-table, .estatisticas-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        .info-table td, .estatisticas-table td {
            padding: 10px;
            border-bottom: 1px solid #eee;
            text-align: left;
        }
        .info-table td:last-child, .estatisticas-table td:last-child {
            text-align: right;
            font-weight: bold;
        }
        .txt-acumulou {
            color: #d9534f;
            font-weight: bold;
        }
        .navegacao {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 25px;
        }
        .btn {
            background-color: #931f7c;
            color: white;
            padding: 10px 20px;
            text-decoration: none;
            border-radius: 6px;
            font-weight: bold;
            transition: background 0.2s;
        }
        .btn:hover {
            background-color: #70135d;
        }
        .btn.disabled {
            background-color: #ccc;
            cursor: not-allowed;
            pointer-events: none;
        }
        .concurso-info {
            font-weight: bold;
            color: #444;
        }
    </style>
</head>
<body>

<div class="container">
    <h1>Lotof&aacute;cil</h1>
    <div class="data-sorteio">Concurso <?= $concurso_atual ?> (<?= $dados['data'] ?>)</div>

    <div class="dezenas-container">
        <?php foreach ($dados['dezenas'] as $dezena): ?>
            <div class="dezena"><?= str_pad($dezena, 2, '0', STR_PAD_LEFT) ?></div>
        <?php endforeach; ?>
    </div>

    <table class="info-table">
        <tr>
            <td>Ganhadores (15 acertos)</td>
            <td><?= $dados['ganhadores_15'] ?></td>
        </tr>
        <tr>
            <?php if ($dados['acumulou']): ?>
                <td class="txt-acumulou">Pr&ecirc;mio Estimado (ACUMULOU!)</td>
                <td class="txt-acumulou">R$ <?= $dados['premio_15'] ?></td>
            <?php else: ?>
                <td>Pr&ecirc;mio Pago</td>
                <td>R$ <?= $dados['premio_15'] ?></td>
            <?php endif; ?>
        </tr>
    </table>

    <h2>An&aacute;lise Estat&iacute;stica</h2>
    <table class="estatisticas-table">
        <tr>
            <td>Pares / &Iacute;mpares</td>
            <td><?= $pares ?> pares / <?= $impares ?> &iacute;mpares</td>
        </tr>
        <tr>
            <td>N&uacute;meros Primos</td>
            <td><?= $primos ?> dezenas</td>
        </tr>
        <tr>
            <td>Soma das Dezenas</td>
            <td><?= $soma ?></td>
        </tr>
    </table>

    <div class="navegacao">
        <a href="?concurso=<?= $anterior ?>" class="btn <?= ($concurso_atual <= 1) ? 'disabled' : '' ?>">
            &lt; Anterior
        </a>

        <span class="concurso-info">Concurso <?= $concurso_atual ?></span>

        <a href="?concurso=<?= $proximo ?>" class="btn <?= ($concurso_atual >= $ultimo_concurso_na_caixa) ? 'disabled' : '' ?>">
            Pr&oacute;ximo &gt;
        </a>
    </div>
</div>

</body>
</html>
