# Lotofácil Privado — Backend (Fase 1)

## O que já foi criado nesta fase
- `supabase_schema.sql` — tabelas, RLS, e todas as views de análise
- `supabase/functions/sync-lotofacil/index.ts` — Edge Function de sincronização
- `supabase_cron.sql` — agendamento do cron diário (rodar por último)

## Passo a passo para colocar no ar

### 1. Rodar o schema
No dashboard do Supabase: **SQL Editor → New Query** → cole todo o conteúdo de
`supabase_schema.sql` → **Run**.

### 2. Instalar a Supabase CLI (na sua máquina)
```bash
npm install -g supabase
supabase login
```

### 3. Linkar o projeto
```bash
cd lotofacil-privado
supabase link --project-ref SEU_PROJECT_REF
```
(o `PROJECT_REF` fica em Project Settings → General → Reference ID)

### 4. Deploy da Edge Function
```bash
supabase functions deploy sync-lotofacil
```

### 5. Testar manualmente (importação inicial do histórico)
No terminal, com sua `SERVICE_ROLE_KEY` (Project Settings → API):
```bash
curl -X POST 'https://SEU_PROJECT_REF.supabase.co/functions/v1/sync-lotofacil' \
  -H "Authorization: Bearer SUA_SERVICE_ROLE_KEY" \
  -H "Content-Type: application/json" \
  -d '{"importar_historico": true, "quantidade": 500}'
```
Isso importa os últimos 500 concursos de uma vez (o limite por clique que você pediu).

### 6. Agendar o cron diário
Edite `supabase_cron.sql`, troque `PROJECT_REF` e `SERVICE_ROLE_KEY` pelos valores
reais, depois rode o conteúdo no SQL Editor.

### 7. Conferir
```sql
select count(*) from concursos;
select * from v_frequencia limit 5;
select * from v_atraso limit 5;
```

## Próxima fase
Com o backend rodando e populado, o próximo passo é o frontend (HTML/JS) com
autenticação (Supabase Auth) e o dashboard com as 6 abas, hospedado no
Cloudflare Pages via GitHub. Me avisa quando o backend estiver validado que eu
já parto para essa parte.
