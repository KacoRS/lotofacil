// ============================================================
// Edge Function: sync-lotofacil
// Busca concursos na API oficial da Caixa e grava/atualiza no Supabase.
//
// Modo automático (cron diário): sem body -> busca só o último concurso
// Modo importação manual: body { "importar_historico": true, "quantidade": 500 }
//   -> importa os últimos N concursos (limite 500 por chamada)
// ============================================================

import { createClient } from "npm:@supabase/supabase-js@2";

const CAIXA_BASE = "https://servicebus2.caixa.gov.br/portaldeloterias/api/lotofacil";

const supabaseUrl = Deno.env.get("SUPABASE_URL")!;
const serviceRoleKey = Deno.env.get("SUPABASE_SERVICE_ROLE_KEY")!;
const supabase = createClient(supabaseUrl, serviceRoleKey);

interface ResultadoCaixa {
  numero: number;
  dataApuracao: string; // "DD/MM/YYYY"
  listaDezenas: string[]; // ["01","05",...]
}

function toISODate(dataBR: string): string {
  const [dia, mes, ano] = dataBR.split("/");
  return `${ano}-${mes}-${dia}`;
}

function montarLinha(resultado: ResultadoCaixa) {
  const linha: Record<string, unknown> = {
    concurso: resultado.numero,
    data_sorteio: toISODate(resultado.dataApuracao),
  };
  const sorteadas = new Set(resultado.listaDezenas.map((d) => parseInt(d, 10)));
  for (let n = 1; n <= 25; n++) {
    linha[`d${String(n).padStart(2, "0")}`] = sorteadas.has(n);
  }
  return linha;
}

async function buscarConcurso(numero?: number): Promise<ResultadoCaixa> {
  const url = numero ? `${CAIXA_BASE}/${numero}` : CAIXA_BASE;
  const resp = await fetch(url, {
    headers: { "User-Agent": "Mozilla/5.0 (compatible; LotofacilSync/1.0)" },
  });
  if (!resp.ok) {
    throw new Error(`Falha ao buscar concurso ${numero ?? "(ultimo)"}: HTTP ${resp.status}`);
  }
  return await resp.json();
}

Deno.serve(async (req) => {
  try {
    let body: { importar_historico?: boolean; quantidade?: number } = {};
    try {
      body = await req.json();
    } catch {
      // sem body = modo automático diário
    }

    const ultimoResultado = await buscarConcurso();
    const ultimoConcursoCaixa = ultimoResultado.numero;

    let numerosParaBuscar: number[] = [];

    if (body.importar_historico) {
      const quantidade = Math.min(body.quantidade ?? 500, 500);
      const inicio = Math.max(1, ultimoConcursoCaixa - quantidade + 1);
      numerosParaBuscar = Array.from(
        { length: ultimoConcursoCaixa - inicio + 1 },
        (_, i) => inicio + i,
      );
    } else {
      // modo diário: só verifica se o último concurso já está no banco
      const { data: existente } = await supabase
        .from("concursos")
        .select("concurso")
        .eq("concurso", ultimoConcursoCaixa)
        .maybeSingle();

      if (existente) {
        return new Response(
          JSON.stringify({ ok: true, mensagem: "Nenhum concurso novo.", ultimo_concurso: ultimoConcursoCaixa }),
          { headers: { "Content-Type": "application/json" } },
        );
      }
      numerosParaBuscar = [ultimoConcursoCaixa];
    }

    const linhas = [];
    // já temos o último resultado buscado, reaproveita
    if (numerosParaBuscar.includes(ultimoConcursoCaixa)) {
      linhas.push(montarLinha(ultimoResultado));
      numerosParaBuscar = numerosParaBuscar.filter((n) => n !== ultimoConcursoCaixa);
    }

    // busca os demais em lotes pequenos para não sobrecarregar a API da Caixa
    const LOTE = 10;
    for (let i = 0; i < numerosParaBuscar.length; i += LOTE) {
      const lote = numerosParaBuscar.slice(i, i + LOTE);
      const resultados = await Promise.all(lote.map((n) => buscarConcurso(n)));
      linhas.push(...resultados.map(montarLinha));
    }

    const { error } = await supabase
      .from("concursos")
      .upsert(linhas, { onConflict: "concurso" });

    if (error) throw error;

    return new Response(
      JSON.stringify({ ok: true, concursos_gravados: linhas.length, ultimo_concurso: ultimoConcursoCaixa }),
      { headers: { "Content-Type": "application/json" } },
    );
  } catch (err) {
    return new Response(
      JSON.stringify({ ok: false, erro: String(err instanceof Error ? err.message : err) }),
      { status: 500, headers: { "Content-Type": "application/json" } },
    );
  }
});
