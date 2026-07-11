-- ============================================================
-- AGENDAMENTO DO CRON DIÁRIO
-- Rode isto DEPOIS de fazer o deploy da Edge Function sync-lotofacil
-- Substitua PROJECT_REF e SERVICE_ROLE_KEY pelos seus valores reais
-- (Dashboard > Project Settings > API)
-- ============================================================

select cron.schedule(
  'sync-lotofacil-diario',
  '0 22 * * *',  -- 22h UTC = ~19h horário de Brasília
  $$
  select net.http_post(
    url := 'https://PROJECT_REF.supabase.co/functions/v1/sync-lotofacil',
    headers := jsonb_build_object(
      'Content-Type', 'application/json',
      'Authorization', 'Bearer SERVICE_ROLE_KEY'
    ),
    body := '{}'::jsonb
  ) as request_id;
  $$
);

-- Para conferir se o job foi criado:
-- select * from cron.job;

-- Para ver o histórico de execuções:
-- select * from cron.job_run_details order by start_time desc limit 10;

-- Para remover o agendamento, se precisar:
-- select cron.unschedule('sync-lotofacil-diario');
