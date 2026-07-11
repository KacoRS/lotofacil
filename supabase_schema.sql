-- ============================================================
-- LOTOFÁCIL PRIVADO — SCHEMA SUPABASE
-- Execute este arquivo inteiro no SQL Editor do Supabase
-- (Dashboard > SQL Editor > New Query > colar > Run)
-- ============================================================

-- Extensões necessárias (cron + chamadas HTTP para a Edge Function)
create extension if not exists pg_cron;
create extension if not exists pg_net;

-- ============================================================
-- 1. TABELA PRINCIPAL: concursos
-- Segue a mesma convenção de matriz binária D01–D25 usada
-- nas suas planilhas (Laboratório RA-LDP, Ausentes, etc.)
-- ============================================================
create table if not exists public.concursos (
  concurso     integer primary key,
  data_sorteio date not null,
  d01 boolean not null default false, d02 boolean not null default false,
  d03 boolean not null default false, d04 boolean not null default false,
  d05 boolean not null default false, d06 boolean not null default false,
  d07 boolean not null default false, d08 boolean not null default false,
  d09 boolean not null default false, d10 boolean not null default false,
  d11 boolean not null default false, d12 boolean not null default false,
  d13 boolean not null default false, d14 boolean not null default false,
  d15 boolean not null default false, d16 boolean not null default false,
  d17 boolean not null default false, d18 boolean not null default false,
  d19 boolean not null default false, d20 boolean not null default false,
  d21 boolean not null default false, d22 boolean not null default false,
  d23 boolean not null default false, d24 boolean not null default false,
  d25 boolean not null default false,
  created_at   timestamptz not null default now()
);

comment on table public.concursos is 'Um registro por concurso da Lotofácil, matriz binária D01-D25 (true = dezena sorteada)';

-- ============================================================
-- 2. METADADOS ESTÁTICOS DAS DEZENAS (1-25)
-- primo, fibonacci, moldura/miolo — usados nas abas de Estatísticas
-- ============================================================
create table if not exists public.dezenas_metadata (
  n            integer primary key check (n between 1 and 25),
  is_primo     boolean not null default false,
  is_fibonacci boolean not null default false,
  is_moldura   boolean not null default false,
  is_miolo     boolean not null default false
);

insert into public.dezenas_metadata (n, is_primo, is_fibonacci, is_moldura, is_miolo)
select
  n,
  n in (2,3,5,7,11,13,17,19,23),
  n in (1,2,3,5,8,13,21),
  n not in (7,8,9,12,13,14,17,18,19),
  n in (7,8,9,12,13,14,17,18,19)
from generate_series(1,25) as n
on conflict (n) do nothing;

-- ============================================================
-- 3. ROW LEVEL SECURITY — só usuário autenticado enxerga
-- ============================================================
alter table public.concursos enable row level security;
alter table public.dezenas_metadata enable row level security;

drop policy if exists "authenticated_select_concursos" on public.concursos;
create policy "authenticated_select_concursos"
  on public.concursos for select
  to authenticated
  using (true);

drop policy if exists "authenticated_select_metadata" on public.dezenas_metadata;
create policy "authenticated_select_metadata"
  on public.dezenas_metadata for select
  to authenticated
  using (true);

-- Nenhuma policy de INSERT/UPDATE/DELETE para "authenticated":
-- só a Edge Function (com service_role key, que ignora RLS) escreve.

-- ============================================================
-- 4. VIEW BASE: concursos com array de dezenas (mais fácil de consumir)
-- ============================================================
create or replace view public.v_concursos as
select
  c.concurso,
  c.data_sorteio,
  array_remove(array[
    case when d01 then 1 end, case when d02 then 2 end, case when d03 then 3 end,
    case when d04 then 4 end, case when d05 then 5 end, case when d06 then 6 end,
    case when d07 then 7 end, case when d08 then 8 end, case when d09 then 9 end,
    case when d10 then 10 end, case when d11 then 11 end, case when d12 then 12 end,
    case when d13 then 13 end, case when d14 then 14 end, case when d15 then 15 end,
    case when d16 then 16 end, case when d17 then 17 end, case when d18 then 18 end,
    case when d19 then 19 end, case when d20 then 20 end, case when d21 then 21 end,
    case when d22 then 22 end, case when d23 then 23 end, case when d24 then 24 end,
    case when d25 then 25 end
  ], null) as dezenas
from public.concursos c;

-- ============================================================
-- 5. VIEW: FREQUÊNCIA (aba Frequência — ranking quentes/frios)
-- ============================================================
create or replace view public.v_frequencia as
select
  t.n,
  count(*) filter (where t.v) as frequencia,
  round(100.0 * count(*) filter (where t.v) / (select count(*) from public.concursos), 2) as percentual
from public.concursos c
cross join lateral (values
  (1,c.d01),(2,c.d02),(3,c.d03),(4,c.d04),(5,c.d05),
  (6,c.d06),(7,c.d07),(8,c.d08),(9,c.d09),(10,c.d10),
  (11,c.d11),(12,c.d12),(13,c.d13),(14,c.d14),(15,c.d15),
  (16,c.d16),(17,c.d17),(18,c.d18),(19,c.d19),(20,c.d20),
  (21,c.d21),(22,c.d22),(23,c.d23),(24,c.d24),(25,c.d25)
) as t(n, v)
group by t.n
order by frequencia desc;

-- ============================================================
-- 6. VIEW: ATRASO (aba Atraso — heatmap de dezenas sem sair)
-- ============================================================
create or replace view public.v_atraso as
with ultimas_aparicoes as (
  select t.n, max(c.concurso) as ultimo_concurso
  from public.concursos c
  cross join lateral (values
    (1,c.d01),(2,c.d02),(3,c.d03),(4,c.d04),(5,c.d05),
    (6,c.d06),(7,c.d07),(8,c.d08),(9,c.d09),(10,c.d10),
    (11,c.d11),(12,c.d12),(13,c.d13),(14,c.d14),(15,c.d15),
    (16,c.d16),(17,c.d17),(18,c.d18),(19,c.d19),(20,c.d20),
    (21,c.d21),(22,c.d22),(23,c.d23),(24,c.d24),(25,c.d25)
  ) as t(n, v)
  where t.v
  group by t.n
),
concurso_atual as (select max(concurso) as atual from public.concursos)
select
  u.n,
  u.ultimo_concurso,
  (ca.atual - u.ultimo_concurso) as atraso_atual
from ultimas_aparicoes u, concurso_atual ca
order by atraso_atual desc;

-- ============================================================
-- 7. VIEW: ESTATÍSTICAS POR CONCURSO (aba Estatísticas)
-- soma, pares/ímpares, primos, fibonacci, moldura/miolo, repetição vs anterior
-- ============================================================
create or replace view public.v_estatisticas_concurso as
select
  vc.concurso,
  vc.data_sorteio,
  vc.dezenas,
  (select sum(x) from unnest(vc.dezenas) as x) as soma,
  (select count(*) from unnest(vc.dezenas) as x where x % 2 = 0) as pares,
  (select count(*) from unnest(vc.dezenas) as x where x % 2 <> 0) as impares,
  (select count(*) from unnest(vc.dezenas) as x join public.dezenas_metadata dm on dm.n = x where dm.is_primo) as primos,
  (select count(*) from unnest(vc.dezenas) as x join public.dezenas_metadata dm on dm.n = x where dm.is_fibonacci) as fibonacci,
  (select count(*) from unnest(vc.dezenas) as x join public.dezenas_metadata dm on dm.n = x where dm.is_moldura) as moldura,
  (select count(*) from unnest(vc.dezenas) as x join public.dezenas_metadata dm on dm.n = x where dm.is_miolo) as miolo,
  (
    select count(*)
    from unnest(vc.dezenas) as x
    where x = any(
      coalesce((select dezenas from public.v_concursos prev where prev.concurso = vc.concurso - 1), array[]::int[])
    )
  ) as repeticao_anterior
from public.v_concursos vc
order by vc.concurso desc;

-- ============================================================
-- 8. VIEW: RANKING PARES E PRIMOS (frequência de pares/ímpares/primos ao longo do tempo)
-- ============================================================
create or replace view public.v_ranking_pares_primos as
select
  t.n,
  (t.n % 2 = 0) as par,
  dm.is_primo,
  count(*) filter (where t.v) as frequencia
from public.concursos c
cross join lateral (values
  (1,c.d01),(2,c.d02),(3,c.d03),(4,c.d04),(5,c.d05),
  (6,c.d06),(7,c.d07),(8,c.d08),(9,c.d09),(10,c.d10),
  (11,c.d11),(12,c.d12),(13,c.d13),(14,c.d14),(15,c.d15),
  (16,c.d16),(17,c.d17),(18,c.d18),(19,c.d19),(20,c.d20),
  (21,c.d21),(22,c.d22),(23,c.d23),(24,c.d24),(25,c.d25)
) as t(n, v)
join public.dezenas_metadata dm on dm.n = t.n
group by t.n, dm.is_primo
order by frequencia desc;

-- ============================================================
-- 9. VIEW: DEZENAS DO SORTEIO ANTERIOR — estatísticas globais delas
-- (aba nova solicitada)
-- ============================================================
create or replace view public.v_sorteio_anterior_stats as
with ultimo as (select concurso, dezenas from public.v_concursos order by concurso desc limit 1)
select
  u.concurso as concurso_referencia,
  f.n,
  f.frequencia,
  f.percentual,
  a.atraso_atual,
  dm.is_primo,
  dm.is_fibonacci,
  dm.is_moldura,
  dm.is_miolo
from ultimo u
cross join lateral unnest(u.dezenas) as x(n)
join public.v_frequencia f on f.n = x.n
join public.v_atraso a on a.n = x.n
join public.dezenas_metadata dm on dm.n = x.n
order by f.frequencia desc;

-- ============================================================
-- 10. VIEW: DEZENAS AUSENTES DO SORTEIO ANTERIOR — estatísticas globais delas
-- (aba nova solicitada)
-- ============================================================
create or replace view public.v_ausentes_anterior_stats as
with ultimo as (select concurso, dezenas from public.v_concursos order by concurso desc limit 1)
select
  u.concurso as concurso_referencia,
  f.n,
  f.frequencia,
  f.percentual,
  a.atraso_atual,
  dm.is_primo,
  dm.is_fibonacci,
  dm.is_moldura,
  dm.is_miolo
from ultimo u
cross join lateral (select n from public.dezenas_metadata where n <> all(u.dezenas)) as x(n)
join public.v_frequencia f on f.n = x.n
join public.v_atraso a on a.n = x.n
join public.dezenas_metadata dm on dm.n = x.n
order by a.atraso_atual desc;

-- ============================================================
-- 11. GRANTS — views herdam RLS das tabelas base automaticamente
-- via security_invoker, garante que só authenticated leia
-- ============================================================
alter view public.v_concursos set (security_invoker = true);
alter view public.v_frequencia set (security_invoker = true);
alter view public.v_atraso set (security_invoker = true);
alter view public.v_estatisticas_concurso set (security_invoker = true);
alter view public.v_ranking_pares_primos set (security_invoker = true);
alter view public.v_sorteio_anterior_stats set (security_invoker = true);
alter view public.v_ausentes_anterior_stats set (security_invoker = true);

-- ============================================================
-- FIM DO SCHEMA BASE
-- O agendamento do pg_cron (passo 12) fica em arquivo separado
-- porque precisa da URL do projeto e da service_role key,
-- que só existem depois que você criar a Edge Function.
-- ============================================================
