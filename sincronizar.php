header('Content-Type: application/json; charset=utf-8');

$banco_dados = "todos_sorteios.json";
$url_api = "https://loteriascaixa-api.herokuapp.com/api/lotofacil/";

// Função para buscar dados na API
function extrair_api($url) {
    $contexto = stream_context_create(["http" => ["header" => "User-Agent: Mozilla/5.0\r\n", "timeout" => 5]]);
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
    
    // Se o último concurso não está no nosso banco, precisamos atualizar!
    if (!isset($local_por_id[$num_ultimo])) {
        
        // Vamos buscar os últimos 30 concursos para garantir que o banco está populado
        for ($i = 0; $i < 30; $i++) {
            $concurso_alvo = $num_ultimo - $i;
            if ($concurso_alvo < 1) break;
            
            // Só busca na API se já não tivermos ele salvo localmente
            if (!isset($local_por_id[$concurso_alvo])) {
                $dados_c = extrair_api($url_api . $concurso_alvo);
                if ($dados_c && isset($dados_c['dezenas'])) {
                    $local_por_id[$concurso_alvo] = [
                        'concurso' => (int)$dados_c['concurso'],
                        'data' => $dados_c['data'],
                        'dezenas' => $dados_c['dezenas'],
                        'premiacoes' => isset($dados_c['premiacoes']) ? $dados_c['premiacoes'] : []
                    ];
                }
                usleep(300000); // Pausa de 0.3 segundos para não bloquear a API
            }
        }
        
        // Reordena do mais novo para o mais antigo e salva no arquivo
        krsort($local_por_id);
        file_put_contents($banco_dados, json_encode(array_values($local_por_id), JSON_PRETTY_PRINT));
        echo json_encode(["status" => "sucesso", "mensagem" => "Banco de dados atualizado com novos sorteios."]);
        exit;
    }
}

echo json_encode(["status" => "sucesso", "mensagem" => "Banco de dados já estava atualizado."]);
