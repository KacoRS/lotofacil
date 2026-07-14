<?php
// Define tempo limite de segurança do PHP
set_time_limit(120);
ini_set('memory_limit', '256M');

header('Content-Type: text/html; charset=utf-8');

$banco_dados = "todos_sorteios.json";
$url_api = "https://loteriascaixa-api.herokuapp.com/api/lotofacil/";

// Função para buscar dados na API
function extrair_api($url) {
    $contexto = stream_context_create([
        "http" => [
            "header" => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64)\r\n", 
            "timeout" => 8
        ]
    ]);
    $resposta = @file_get_contents($url, false, $contexto);
    return $resposta ? json_decode($resposta, true) : null;
}

// 1. Carrega o banco de dados atual
$historico_local = [];
if (file_exists($banco_dados)) {
    $historico_local = json_decode(file_get_contents($banco_dados), true) ?: [];
}

// Organiza o histórico local pelo número do concurso para facilitar checagem
$local_por_id = [];
foreach ($historico_local as $c) {
    if (isset($c['concurso'])) $local_por_id[(int)$c['concurso']] = $c;
}

// 2. Pega o último resultado direto da Caixa
$ultimo_caixa = extrair_api($url_api . "latest");

if ($ultimo_caixa && isset($ultimo_caixa['concurso'])) {
    $num_ultimo = (int)$ultimo_caixa['concurso'];
    
    $concursos_que_faltam = [];
    
    // Varre do último até o primeiro para ver quais concursos NÃO temos salvos
    for ($i = $num_ultimo; $i >= 1; $i--) {
        if (!isset($local_por_id[$i])) {
            $concursos_que_faltam[] = $i;
        }
    }
    
    $total_faltando = count($concursos_que_faltam);
    
    if ($total_faltando > 0) {
        // Para não dar timeout de 504, vamos baixar apenas um LOTE de 100 concursos por vez
        $lote_importacao = array_slice($concursos_que_faltam, 0, 100);
        $novos_salvos = 0;
        
        echo "<div style='font-family: Arial, sans-serif; padding: 20px; max-width: 600px; margin: 0 auto;'>";
        echo "<h3 style='color: #931f7c;'>Sincronizando Banco de Dados...</h3>";
        echo "<p>Faltam <b>{$total_faltando}</b> concursos para baixar.</p>";
        echo "<p>Baixando lote atual de " . count($lote_importacao) . " concursos. Aguarde...</p>";
        echo "<hr style='border: 1px solid #eee;'>";
        
        // Descarrega o buffer de saída para mostrar o texto na tela enquanto processa
        ob_start();
        echo " ";
        ob_flush();
        flush();
        
        foreach ($lote_importacao as $concurso_alvo) {
            $dados_c = extrair_api($url_api . $concurso_alvo);
            if ($dados_c && isset($dados_c['dezenas'])) {
                $local_por_id[$concurso_alvo] = [
                    'concurso' => (int)$dados_c['concurso'],
                    'data' => $dados_c['data'],
                    'dezenas' => $dados_c['dezenas'],
                    'premiacoes' => isset($dados_c['premiacoes']) ? $dados_c['premiacoes'] : []
                ];
                $novos_salvos++;
            }
            // Pausa de 0.2 segundos para não sobrecarregar
            usleep(200000); 
        }
        
        if ($novos_salvos > 0) {
            krsort($local_por_id);
            file_put_contents($banco_dados, json_encode(array_values($local_por_id), JSON_PRETTY_PRINT));
        }
        
        echo "<p style='color: green;'><b>Lote concluído! Salvou {$novos_salvos} novos resultados.</b></p>";
        echo "<p id='contador'>Reiniciando em 3 segundos para o próximo lote... Não feche esta janela!</p>";
        echo "</div>";
        
        // Redirecionamento forçado via JavaScript (burlar bloqueio do servidor)
        echo "
        <script>
            var segundos = 3;
            var intervalo = setInterval(function() {
                segundos--;
                if (segundos <= 0) {
                    clearInterval(intervalo);
                    window.location.href = 'sincronizar.php';
                } else {
                    document.getElementById('contador').innerHTML = 'Reiniciando em ' + segundos + ' segundos para o próximo lote... Não feche esta janela!';
                }
            }, 1000);
        </script>
        ";
        exit;
    }
}

// Se não falta mais nenhum concurso
echo "<div style='font-family: Arial, sans-serif; padding: 20px; max-width: 600px; margin: 0 auto; text-align: center;'>";
echo "<h2 style='color: green;'>Sincronização Concluída com Sucesso!</h2>";
echo "<p>Todos os concursos existentes estão no seu banco local.</p>";
echo "<p>Total de registros salvos: <b>" . count($local_por_id) . "</b></p>";
echo "<br><a href='index.php' style='display: inline-block; background: #931f7c; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; font-weight: bold;'>Voltar para o Painel da Lotofácil</a>";
echo "</div>";
