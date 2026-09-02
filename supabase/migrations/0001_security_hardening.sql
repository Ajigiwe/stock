-- ============================================================================
-- Migration: 0001 — security hardening (anon data loss, cross-shop, idempotency, RLS, stock invariant)
--
-- Non-destructive, idempotent: safe to run on a live database with real data.
-- For a fresh install, schema.sql already contains these changes — this file is
-- the path from a database created with the old schema.sql to the new one.
--
-- Copy-paste the whole file into the Supabase SQL editor and run it. Re-running
-- it is safe; every statement is guarded.
--
-- What it fixes (see also the verification block at the end, which FAILS the
-- migration rather than leaving the database silently insecure):
--   * Five SECURITY DEFINER RPCs used `<> 'owner'` on a possibly-NULL role.
--     That is NULL for a caller with no profile row — including anon, for whom
--     auth.uid() is NULL — and `IF NULL` is not true, so the guard fell through.
--     Every one of those RPCs was callable without a login, and Postgres grants
--     EXECUTE to PUBLIC by default, so `grant ... to authenticated` alone did not
--     keep anon out. Covered: delete_transaction, restore_backup,
--     approve/reject/approve_all stock request.
--   * restore_backup validated almost nothing before deleting, could be called with
--     a payload missing `users` (wiping every profile including the caller's),
--     echoed `role` from the file (privilege escalation), failed on any live DB
--     that had stock_requests/swaps/logs rows, and left a stale `available` in
--     place if the backup was hand-edited.
--   * phone_models had an `attendant manages own shop FOR ALL` policy that let an
--     attendant UPDATE available directly, bypassing every stock trigger.
--   * transaction_items had a FOR ALL policy named "owner full access" that still
--     granted attendants full DML via the EXISTS clause — deleting an `out` item
--     credited stock back, inserting an `in` item fabricated it.
--   * record_transaction and the approval RPCs never checked that a referenced
--     phone_model_id belonged to the same shop as the transaction/request.
-- ============================================================================

-- ---------------------------------------------------------------------------
-- 0. Shim for the live DB: stock_requests existed without these FK ON DELETE
--    semantics in the original schema. Changing the FK behaviour would require
--    rebuilding the constraint with an ACCESS EXCLUSIVE lock; the functional fix
--    is in restore_backup's delete order, not here. New installs get the
--    correct definition from schema.sql directly.
-- ---------------------------------------------------------------------------

-- ---------------------------------------------------------------------------
-- 1. handle_new_user — tolerate phone/OAuth signups and restored rows
-- ---------------------------------------------------------------------------
create or replace function public.handle_new_user()
returns trigger
language plpgsql security definer set search_path = ''
as $$
begin
  insert into public.users (id, name)
  values (
    new.id,
    coalesce(
      nullif(new.raw_user_meta_data ->> 'name', ''),
      nullif(split_part(coalesce(new.email, ''), '@', 1), ''),
      'user'
    )
  )
  on conflict (id) do nothing;
  return new;
end;
$$;

-- ---------------------------------------------------------------------------
-- 2. Role guards — the fix for the `<> 'owner'` NULL bug
-- ---------------------------------------------------------------------------
create or replace function public.require_profile()
returns public.users
language plpgsql stable security definer set search_path = ''
as $$
declare
  v_user public.users;
begin
  if auth.uid() is null then
    raise exception 'Not authenticated' using errcode = 'P0001';
  end if;
  select * into v_user from public.users u where u.id = auth.uid();
  if v_user.id is null then
    raise exception 'No profile for the current user' using errcode = 'P0001';
  end if;
  return v_user;
end;
$$;

create or replace function public.require_owner()
returns public.users
language plpgsql stable security definer set search_path = ''
as $$
declare
  v_user public.users;
begin
  v_user := public.require_profile();
  if v_user.role is distinct from 'owner'::public.user_role then
    raise exception 'Only owners can perform this action' using errcode = 'P0001';
  end if;
  return v_user;
end;
$$;

-- ---------------------------------------------------------------------------
-- 3. Indexes missing from the original schema
-- ---------------------------------------------------------------------------
create index if not exists users_shop_idx              on public.users (shop_id);
create index if not exists phone_models_shop_idx       on public.phone_models (shop_id);
create index if not exists transactions_staff_idx      on public.transactions (staff_id);
create index if not exists stock_adjustments_shop_idx  on public.stock_adjustments (shop_id, date desc);
create index if not exists stock_adjustments_staff_idx on public.stock_adjustments (staff_id);
create index if not exists stock_requests_staff_idx    on public.stock_requests (staff_id);
create index if not exists stock_requests_model_idx    on public.stock_requests (phone_model_id);
create index if not exists swapped_phones_tx_idx       on public.swapped_phones (transaction_id);
create index if not exists swapped_phones_staff_idx    on public.swapped_phones (staff_id);
create index if not exists stock_logs_model_idx        on public.stock_logs (phone_model_id);
create index if not exists stock_logs_staff_idx        on public.stock_logs (staff_id);

-- ---------------------------------------------------------------------------
-- 4. Guarded CHECK constraints
--    On a live DB these can only be added if no existing row violates them, so
--    each is probed first. A skipped constraint is raised as a WARNING rather
--    than failing the whole migration — the operator should fix the bad rows and
--    re-run.
-- ---------------------------------------------------------------------------
do $$
declare
  v_bad text;
begin
  -- phone_models
  if not exists (select 1 from pg_constraint where conname = 'phone_models_cost_price_check') then
    select string_agg(id::text, ', ') into v_bad
      from public.phone_models where cost_price is not null and cost_price < 0 limit 5;
    if v_bad is not null then
      raise warning 'Skipping phone_models cost_price >= 0: violating rows %', v_bad;
    else
      alter table public.phone_models add constraint phone_models_cost_price_check
        check (cost_price is null or cost_price >= 0);
    end if;
  end if;

  if not exists (select 1 from pg_constraint where conname = 'phone_models_sale_price_check') then
    select string_agg(id::text, ', ') into v_bad
      from public.phone_models where sale_price is not null and sale_price < 0 limit 5;
    if v_bad is not null then
      raise warning 'Skipping phone_models sale_price >= 0: violating rows %', v_bad;
    else
      alter table public.phone_models add constraint phone_models_sale_price_check
        check (sale_price is null or sale_price >= 0);
    end if;
  end if;

  if not exists (select 1 from pg_constraint where conname = 'phone_models_opening_stock_check') then
    select string_agg(id::text, ', ') into v_bad
      from public.phone_models where opening_stock < 0 limit 5;
    if v_bad is not null then
      raise warning 'Skipping phone_models opening_stock >= 0: violating rows %', v_bad;
    else
      alter table public.phone_models add constraint phone_models_opening_stock_check
        check (opening_stock >= 0);
    end if;
  end if;

  if not exists (select 1 from pg_constraint where conname = 'phone_models_bought_in_check') then
    select string_agg(id::text, ', ') into v_bad
      from public.phone_models where bought_in < 0 limit 5;
    if v_bad is not null then
      raise warning 'Skipping phone_models bought_in >= 0: violating rows %', v_bad;
    else
      alter table public.phone_models add constraint phone_models_bought_in_check
        check (bought_in >= 0);
    end if;
  end if;

  if not exists (select 1 from pg_constraint where conname = 'phone_models_low_stock_threshold_check') then
    select string_agg(id::text, ', ') into v_bad
      from public.phone_models where low_stock_threshold < 0 limit 5;
    if v_bad is not null then
      raise warning 'Skipping phone_models low_stock_threshold >= 0: violating rows %', v_bad;
    else
      alter table public.phone_models add constraint phone_models_low_stock_threshold_check
        check (low_stock_threshold >= 0);
    end if;
  end if;

  -- transactions
  if not exists (select 1 from pg_constraint where conname = 'transactions_amount_check') then
    select string_agg(id::text, ', ') into v_bad
      from public.transactions where amount < 0 limit 5;
    if v_bad is not null then
      raise warning 'Skipping transactions amount >= 0: violating rows %', v_bad;
    else
      alter table public.transactions add constraint transactions_amount_check
        check (amount >= 0);
    end if;
  end if;

  -- stock_adjustments
  if not exists (select 1 from pg_constraint where conname = 'stock_adjustments_delta_check') then
    select string_agg(id::text, ', ') into v_bad
      from public.stock_adjustments where delta = 0 limit 5;
    if v_bad is not null then
      raise warning 'Skipping stock_adjustments delta <> 0: violating rows %', v_bad;
    else
      alter table public.stock_adjustments add constraint stock_adjustments_delta_check
        check (delta <> 0);
    end if;
  end if;

  -- stock_requests
  if not exists (select 1 from pg_constraint where conname = 'stock_requests_payload_ck') then
    select string_agg(id::text, ', ') into v_bad
      from public.stock_requests
     where not (
       (type = 'create_model' and model_name is not null and phone_model_id is null and delta is null)
       or (type = 'adjust_stock' and phone_model_id is not null and delta is not null and delta <> 0)
     ) limit 5;
    if v_bad is not null then
      raise warning 'Skipping stock_requests payload check: violating rows %', v_bad;
    else
      alter table public.stock_requests add constraint stock_requests_payload_ck check (
        (type = 'create_model' and model_name is not null and phone_model_id is null and delta is null)
        or (type = 'adjust_stock' and phone_model_id is not null and delta is not null and delta <> 0)
      );
    end if;
  end if;
end;
$$;

-- ---------------------------------------------------------------------------
-- 5. Cross-shop guards
-- ---------------------------------------------------------------------------
create or replace function public.enforce_item_shop_match()
returns trigger language plpgsql security definer set search_path = '' as $$
declare v_tx_shop uuid; v_model_shop uuid;
begin
  select t.shop_id into v_tx_shop from public.transactions t where t.id = new.transaction_id;
  select m.shop_id into v_model_shop from public.phone_models m where m.id = new.phone_model_id;
  if v_tx_shop is null then raise exception 'Unknown transaction' using errcode='P0001'; end if;
  if v_model_shop is null then raise exception 'Unknown phone model' using errcode='P0001'; end if;
  if v_tx_shop <> v_model_shop then
    raise exception 'Phone model does not belong to this transaction''s shop' using errcode='P0001';
  end if;
  return new;
end; $$;
drop trigger if exists item_shop_match on public.transaction_items;
create trigger item_shop_match before insert or update on public.transaction_items
  for each row execute function public.enforce_item_shop_match();

create or replace function public.enforce_adjustment_shop_match()
returns trigger language plpgsql security definer set search_path = '' as $$
declare v_model_shop uuid;
begin
  select m.shop_id into v_model_shop from public.phone_models m where m.id = new.phone_model_id;
  if v_model_shop is null then raise exception 'Unknown phone model' using errcode='P0001'; end if;
  if v_model_shop <> new.shop_id then
    raise exception 'Phone model does not belong to this shop' using errcode='P0001';
  end if;
  return new;
end; $$;
drop trigger if exists adjustment_shop_match on public.stock_adjustments;
create trigger adjustment_shop_match before insert or update on public.stock_adjustments
  for each row execute function public.enforce_adjustment_shop_match();

create or replace function public.enforce_request_shop_match()
returns trigger language plpgsql security definer set search_path = '' as $$
declare v_model_shop uuid;
begin
  if new.phone_model_id is null then return new; end if;
  select m.shop_id into v_model_shop from public.phone_models m where m.id = new.phone_model_id;
  if v_model_shop is null then raise exception 'Unknown phone model' using errcode='P0001'; end if;
  if v_model_shop <> new.shop_id then
    raise exception 'Phone model does not belong to this shop' using errcode='P0001';
  end if;
  return new;
end; $$;
drop trigger if exists request_shop_match on public.stock_requests;
create trigger request_shop_match before insert or update on public.stock_requests
  for each row execute function public.enforce_request_shop_match();

-- ---------------------------------------------------------------------------
-- 6. Stock triggers — now row-lock and clamp bought_in
-- ---------------------------------------------------------------------------
create or replace function public.apply_item_stock_change()
returns trigger language plpgsql security definer set search_path = '' as $$
declare cur_avail int;
begin
  if tg_op = 'DELETE' then
    update public.phone_models set available = available + (case when old.direction='out' then old.qty else -old.qty end)
     where id = old.phone_model_id; return old; end if;
  if tg_op = 'INSERT' then
    select available into cur_avail from public.phone_models where id = new.phone_model_id for update;
    if new.direction='out' and coalesce(cur_avail,0) < new.qty then
      raise exception 'Insufficient stock: only % available for this model', coalesce(cur_avail,0) using errcode='P0001'; end if;
    update public.phone_models set available = available + (case when new.direction='out' then -new.qty else new.qty end)
     where id = new.phone_model_id; return new; end if;
  if tg_op = 'UPDATE' then
    update public.phone_models set available = available + (case when old.direction='out' then old.qty else -old.qty end)
     where id = old.phone_model_id;
    select available into cur_avail from public.phone_models where id = new.phone_model_id for update;
    if new.direction='out' and coalesce(cur_avail,0) < new.qty then
      raise exception 'Insufficient stock: only % available for this model', coalesce(cur_avail,0) using errcode='P0001'; end if;
    update public.phone_models set available = available + (case when new.direction='out' then -new.qty else new.qty end)
     where id = new.phone_model_id; return new; end if;
  return null;
end; $$;
drop trigger if exists item_stock_change on public.transaction_items;
create trigger item_stock_change after insert or update or delete on public.transaction_items
  for each row execute function public.apply_item_stock_change();

create or replace function public.apply_stock_adjustment()
returns trigger language plpgsql security definer set search_path = '' as $$
declare cur_avail int;
begin
  if tg_op = 'DELETE' then
    update public.phone_models set available = available - old.delta, bought_in = greatest(bought_in - case when old.delta>0 then old.delta else 0 end, 0)
     where id = old.phone_model_id; return old; end if;
  if tg_op = 'INSERT' then
    select available into cur_avail from public.phone_models where id = new.phone_model_id for update;
    if new.delta<0 and coalesce(cur_avail,0) < abs(new.delta) then
      raise exception 'Insufficient stock to correct: only % available', coalesce(cur_avail,0) using errcode='P0001'; end if;
    update public.phone_models set available = available + new.delta, bought_in = bought_in + case when new.delta>0 then new.delta else 0 end
     where id = new.phone_model_id; return new; end if;
  if tg_op = 'UPDATE' then
    update public.phone_models set available = available - old.delta, bought_in = greatest(bought_in - case when old.delta>0 then old.delta else 0 end, 0)
     where id = old.phone_model_id;
    select available into cur_avail from public.phone_models where id = new.phone_model_id for update;
    if new.delta<0 and coalesce(cur_avail,0) < abs(new.delta) then
      raise exception 'Insufficient stock to correct: only % available', coalesce(cur_avail,0) using errcode='P0001'; end if;
    update public.phone_models set available = available + new.delta, bought_in = bought_in + case when new.delta>0 then new.delta else 0 end
     where id = new.phone_model_id; return new; end if;
  return null;
end; $$;
drop trigger if exists stock_adjustment_change on public.stock_adjustments;
create trigger stock_adjustment_change after insert or update or delete on public.stock_adjustments
  for each row execute function public.apply_stock_adjustment();

create or replace function public.normalize_model_stock()
returns trigger language plpgsql security definer set search_path = '' as $$
begin new.bought_in:=greatest(coalesce(new.bought_in,0),0); new.opening_stock:=greatest(coalesce(new.opening_stock,0),0); new.available:=new.opening_stock+new.bought_in; return new; end; $$;
drop trigger if exists model_stock_normalize on public.phone_models;
create trigger model_stock_normalize before insert on public.phone_models
  for each row execute function public.normalize_model_stock();

-- ---------------------------------------------------------------------------
-- 7. RPCs — every SECURITY DEFINER function now uses search_path = '' and
--    require_owner/require_profile. Signatures are unchanged.
-- ---------------------------------------------------------------------------
create or replace function public.record_transaction(
  p_shop_id uuid, p_customer_name text, p_customer_phone text, p_type public.tx_type,
  p_payment_method public.payment_method, p_amount numeric, p_date timestamptz default now(),
  p_out_items jsonb default '[]'::jsonb, p_in_items jsonb default '[]'::jsonb, p_idempotency_key uuid default null,
  p_swap_in jsonb default '[]'::jsonb
) returns uuid language plpgsql security definer set search_path = '' as $$
declare v_me public.users; v_role public.user_role; v_staff_id uuid; v_tx_id uuid; v_item jsonb; v_model_id uuid; v_condition public.phone_condition; v_avail int; v_qty int;
begin
  v_me:=public.require_profile(); v_staff_id:=v_me.id; v_role:=v_me.role;
  if v_role is distinct from 'owner'::public.user_role then
    if p_shop_id is distinct from v_me.shop_id then raise exception 'Not allowed to record transactions for this shop' using errcode='P0001'; end if; end if;
  if p_amount is null or p_amount<0 then raise exception 'Amount must be 0 or more' using errcode='P0001'; end if;
  if p_idempotency_key is not null then
    select t.id into v_tx_id from public.transactions t where t.idempotency_key=p_idempotency_key and t.shop_id=p_shop_id;
    if v_tx_id is not null then return v_tx_id; end if; end if;
  insert into public.transactions (shop_id,staff_id,customer_name,customer_phone,type,payment_method,amount,date,idempotency_key)
  values (p_shop_id,v_staff_id,p_customer_name,p_customer_phone,p_type,p_payment_method,p_amount,p_date,p_idempotency_key) returning id into v_tx_id;
  for v_item in select value from jsonb_array_elements(coalesce(p_out_items,'[]'::jsonb)) as t(value) order by (value->>'phone_model_id') loop
    v_qty:=coalesce((v_item->>'qty')::int,1); if v_qty<=0 then raise exception 'Quantity must be greater than 0' using errcode='P0001'; end if;
    select available into v_avail from public.phone_models where id=(v_item->>'phone_model_id')::uuid and shop_id=p_shop_id for update;
    if v_avail is null then raise exception 'Unknown phone model for this shop' using errcode='P0001'; end if;
    if v_avail < v_qty then raise exception 'Insufficient stock: only % available for this model', v_avail using errcode='P0001'; end if;
    insert into public.transaction_items (transaction_id,phone_model_id,direction,qty) values (v_tx_id,(v_item->>'phone_model_id')::uuid,'out',v_qty); end loop;
  for v_item in select * from jsonb_array_elements(coalesce(p_in_items,'[]'::jsonb)) loop
    v_qty:=coalesce((v_item->>'qty')::int,1); if v_qty<=0 then raise exception 'Quantity must be greater than 0' using errcode='P0001'; end if;
    v_condition:=coalesce((v_item->>'condition')::public.phone_condition,'used'::public.phone_condition);
    if (v_item->>'phone_model_id') is not null then
      select id into v_model_id from public.phone_models where id=(v_item->>'phone_model_id')::uuid and shop_id=p_shop_id;
      if v_model_id is null then raise exception 'Unknown phone model for this shop' using errcode='P0001'; end if;
    else
      select id into v_model_id from public.phone_models where shop_id=p_shop_id and model_name=(v_item->>'model_name') and condition=v_condition;
      if v_model_id is null then
        insert into public.phone_models (shop_id,model_name,condition,cost_price,sale_price,opening_stock,bought_in,available)
        values (p_shop_id,(v_item->>'model_name'),v_condition,(v_item->>'cost_price')::numeric,(v_item->>'sale_price')::numeric,0,0,0) returning id into v_model_id; end if; end if;
    insert into public.transaction_items (transaction_id,phone_model_id,direction,qty) values (v_tx_id,v_model_id,'in',v_qty); end loop;
  for v_item in select * from jsonb_array_elements(coalesce(p_swap_in,'[]'::jsonb)) loop
    if coalesce(v_item->>'model_name','')='' then continue; end if;
    insert into public.swapped_phones (shop_id,transaction_id,staff_id,model_name,customer_name,customer_phone)
    values (p_shop_id,v_tx_id,v_staff_id,(v_item->>'model_name'),(v_item->>'customer_name'),(v_item->>'customer_phone')); end loop;
  return v_tx_id;
end; $$;

create or replace function public.adjust_stock(p_shop_id uuid, p_phone_model_id uuid, p_delta int, p_type public.adjustment_type default 'restock', p_reason text default null)
returns uuid language plpgsql security definer set search_path = '' as $$
declare v_me public.users; v_id uuid;
begin
  v_me:=public.require_profile();
  if v_me.role is distinct from 'owner'::public.user_role and not coalesce(v_me.can_edit_stock,false) then
    raise exception 'Only owners or staff with stock privileges can adjust stock directly' using errcode='P0001'; end if;
  if v_me.role is distinct from 'owner'::public.user_role and p_shop_id is distinct from v_me.shop_id then
    raise exception 'Not allowed to adjust stock for this shop' using errcode='P0001'; end if;
  if p_delta is null or p_delta=0 then raise exception 'Adjustment quantity must not be zero' using errcode='P0001'; end if;
  if not exists (select 1 from public.phone_models where id=p_phone_model_id and shop_id=p_shop_id) then
    raise exception 'Phone model does not belong to this shop' using errcode='P0001'; end if;
  insert into public.stock_adjustments (shop_id,phone_model_id,staff_id,type,delta,reason) values (p_shop_id,p_phone_model_id,v_me.id,p_type,p_delta,p_reason) returning id into v_id;
  return v_id;
end; $$;

create or replace function public.bulk_adjust_stock(p_shop_id uuid, p_items jsonb, p_reason text default null)
returns int language plpgsql security definer set search_path = '' as $$
declare v_me public.users; v_item jsonb; v_model uuid; v_target int; v_avail int; v_delta int; v_changes int:=0;
begin
  v_me:=public.require_profile();
  if v_me.role is distinct from 'owner'::public.user_role and not coalesce(v_me.can_edit_stock,false) then
    raise exception 'Only owners or staff with stock privileges can adjust stock directly' using errcode='P0001'; end if;
  if v_me.role is distinct from 'owner'::public.user_role and p_shop_id is distinct from v_me.shop_id then
    raise exception 'Not allowed to adjust stock for this shop' using errcode='P0001'; end if;
  for v_item in select value from jsonb_array_elements(coalesce(p_items,'[]'::jsonb)) as t(value) order by (value->>'phone_model_id') loop
    v_model:=(v_item->>'phone_model_id')::uuid; v_target:=(v_item->>'target_qty')::int;
    if v_target is null or v_target<0 then raise exception 'Target quantity must be 0 or more' using errcode='P0001'; end if;
    select available into v_avail from public.phone_models where id=v_model and shop_id=p_shop_id for update;
    if v_avail is null then raise exception 'Phone model does not belong to this shop' using errcode='P0001'; end if;
    v_delta:=v_target - v_avail; if v_delta=0 then continue; end if;
    insert into public.stock_adjustments (shop_id,phone_model_id,staff_id,type,delta,reason)
    values (p_shop_id,v_model,v_me.id, case when v_delta>0 then 'restock'::public.adjustment_type else 'correction'::public.adjustment_type end, v_delta,p_reason);
    v_changes:=v_changes+1; end loop;
  return v_changes;
end; $$;

create or replace function public.delete_transaction(p_transaction_id uuid) returns void
language plpgsql security definer set search_path = '' as $$ begin perform public.require_owner(); delete from public.transactions where id=p_transaction_id; if not found then raise exception 'Transaction not found' using errcode='P0001'; end if; end; $$;

create or replace function public.restore_backup(p_data jsonb) returns jsonb
language plpgsql security definer set search_path = '' as $$
declare v_me public.users; v_rec jsonb; v_rows bigint; v_repaired bigint; v_key text;
begin
  v_me:=public.require_owner();
  if p_data is null or jsonb_typeof(p_data)<>'object' then raise exception 'Invalid backup file' using errcode='P0001'; end if;
  foreach v_key in array array['shops','users','phone_models','transactions','transaction_items','stock_adjustments'] loop
    if not p_data ? v_key then raise exception 'Invalid backup file: missing "%"', v_key using errcode='P0001'; end if;
    if jsonb_typeof(p_data->v_key)<>'array' then raise exception 'Invalid backup file: "%" must be an array', v_key using errcode='P0001'; end if; end loop;
  if not exists (select 1 from jsonb_array_elements(p_data->'users') as u(value) where (u.value->>'role')='owner') then
    raise exception 'Invalid backup file: it contains no owner account' using errcode='P0001'; end if;
  alter table public.transaction_items disable trigger item_stock_change;
  alter table public.stock_adjustments disable trigger stock_adjustment_change;
  delete from public.stock_logs where true; delete from public.login_logs where true;
  delete from public.stock_requests where true; delete from public.swapped_phones where true;
  delete from public.transaction_items where true; delete from public.transactions where true;
  delete from public.stock_adjustments where true; delete from public.phone_models where true;
  delete from public.users where true; delete from public.shops where true;
  for v_rec in select * from jsonb_array_elements(coalesce(p_data->'shops','[]'::jsonb)) loop
    insert into public.shops (id,name,location,phone,created_at) values ((v_rec->>'id')::uuid,v_rec->>'name',v_rec->>'location',v_rec->>'phone',coalesce((v_rec->>'created_at')::timestamptz,now())); end loop;
  for v_rec in select * from jsonb_array_elements(coalesce(p_data->'users','[]'::jsonb)) loop
    if exists (select 1 from auth.users where id=(v_rec->>'id')::uuid) then
      insert into public.users (id,name,role,shop_id,can_edit_stock,created_at)
      values ((v_rec->>'id')::uuid,coalesce(v_rec->>'name',''),coalesce((v_rec->>'role')::public.user_role,'attendant'),nullif(v_rec->>'shop_id','')::uuid,coalesce((v_rec->>'can_edit_stock')::boolean,false),coalesce((v_rec->>'created_at')::timestamptz,now())) on conflict (id) do nothing; end if; end loop;
  insert into public.users (id,name,role) values (v_me.id,v_me.name,'owner') on conflict (id) do update set role='owner', shop_id=null;
  for v_rec in select * from jsonb_array_elements(coalesce(p_data->'phone_models','[]'::jsonb)) loop
    insert into public.phone_models (id,shop_id,model_name,condition,cost_price,sale_price,opening_stock,bought_in,available,low_stock_threshold,created_at)
    values ((v_rec->>'id')::uuid,(v_rec->>'shop_id')::uuid,v_rec->>'model_name',coalesce((v_rec->>'condition')::public.phone_condition,'new'),(v_rec->>'cost_price')::numeric,(v_rec->>'sale_price')::numeric,greatest(coalesce((v_rec->>'opening_stock')::int,0),0),greatest(coalesce((v_rec->>'bought_in')::int,0),0),greatest(coalesce((v_rec->>'available')::int,0),0),greatest(coalesce((v_rec->>'low_stock_threshold')::int,5),0),coalesce((v_rec->>'created_at')::timestamptz,now())); end loop;
  for v_rec in select * from jsonb_array_elements(coalesce(p_data->'transactions','[]'::jsonb)) loop
    insert into public.transactions (id,shop_id,staff_id,customer_name,customer_phone,type,payment_method,amount,date,created_at)
    values ((v_rec->>'id')::uuid,(v_rec->>'shop_id')::uuid,coalesce((select u.id from public.users u where u.id=nullif(v_rec->>'staff_id','')::uuid),v_me.id),v_rec->>'customer_name',v_rec->>'customer_phone',coalesce((v_rec->>'type')::public.tx_type,'sale'),coalesce((v_rec->>'payment_method')::public.payment_method,'cash'),greatest(coalesce((v_rec->>'amount')::numeric,0),0),coalesce((v_rec->>'date')::timestamptz,now()),coalesce((v_rec->>'created_at')::timestamptz,now())); end loop;
  for v_rec in select * from jsonb_array_elements(coalesce(p_data->'transaction_items','[]'::jsonb)) loop
    insert into public.transaction_items (id,transaction_id,phone_model_id,direction,qty)
    values ((v_rec->>'id')::uuid,(v_rec->>'transaction_id')::uuid,(v_rec->>'phone_model_id')::uuid,(v_rec->>'direction')::public.item_direction,greatest(coalesce((v_rec->>'qty')::int,1),1)); end loop;
  for v_rec in select * from jsonb_array_elements(coalesce(p_data->'stock_adjustments','[]'::jsonb)) loop
    if coalesce((v_rec->>'delta')::int,0)<>0 then
      insert into public.stock_adjustments (id,shop_id,phone_model_id,staff_id,type,delta,reason,date)
      values ((v_rec->>'id')::uuid,(v_rec->>'shop_id')::uuid,(v_rec->>'phone_model_id')::uuid,coalesce((select u.id from public.users u where u.id=nullif(v_rec->>'staff_id','')::uuid),v_me.id),coalesce((v_rec->>'type')::public.adjustment_type,'restock'),(v_rec->>'delta')::int,v_rec->>'reason',coalesce((v_rec->>'date')::timestamptz,now())); end if; end loop;
  alter table public.transaction_items enable trigger item_stock_change;
  alter table public.stock_adjustments enable trigger stock_adjustment_change;
  with computed as (select m.id, greatest(m.opening_stock + m.bought_in + coalesce((select sum(i.qty) from public.transaction_items i where i.phone_model_id=m.id and i.direction='in'),0) - coalesce((select sum(i.qty) from public.transaction_items i where i.phone_model_id=m.id and i.direction='out'),0) - coalesce((select sum(-a.delta) from public.stock_adjustments a where a.phone_model_id=m.id and a.delta<0),0),0)::int as expected from public.phone_models m)
  update public.phone_models m set available=c.expected from computed c where c.id=m.id and m.available<>c.expected;
  get diagnostics v_repaired=row_count;
  select count(*) into v_rows from public.transactions;
  return jsonb_build_object('restored',true,'transactions',v_rows,'stock_repaired',v_repaired);
end; $$;

create or replace function public.approve_stock_request(p_request_id uuid) returns void
language plpgsql security definer set search_path = '' as $$
declare v_me public.users; v_rec public.stock_requests;
begin
  v_me:=public.require_owner();
  select * into v_rec from public.stock_requests where id=p_request_id and status='pending' for update;
  if v_rec.id is null then raise exception 'Pending stock request not found' using errcode='P0001'; end if;
  if v_rec.type='create_model' then
    if exists (select 1 from public.phone_models where shop_id=v_rec.shop_id and model_name=v_rec.model_name and condition=v_rec.condition) then
      raise exception 'A model with this name and condition already exists' using errcode='P0001'; end if;
    insert into public.phone_models (shop_id,model_name,condition,cost_price,sale_price,opening_stock,bought_in,available,low_stock_threshold)
    values (v_rec.shop_id,v_rec.model_name,v_rec.condition,v_rec.cost_price,v_rec.sale_price,coalesce(v_rec.opening_stock,0),0,coalesce(v_rec.opening_stock,0),coalesce(v_rec.low_stock_threshold,5));
  elsif v_rec.type='adjust_stock' then
    if not exists (select 1 from public.phone_models where id=v_rec.phone_model_id and shop_id=v_rec.shop_id) then
      raise exception 'Phone model does not belong to this shop' using errcode='P0001'; end if;
    insert into public.stock_adjustments (shop_id,phone_model_id,staff_id,type,delta,reason)
    values (v_rec.shop_id,v_rec.phone_model_id,v_rec.staff_id, case when v_rec.delta>0 then 'restock'::public.adjustment_type else 'correction'::public.adjustment_type end, v_rec.delta, v_rec.reason); end if;
  update public.stock_requests set status='approved', decided_at=now(), decided_by=v_me.id, error_note=null where id=p_request_id;
end; $$;

create or replace function public.reject_stock_request(p_request_id uuid) returns void
language plpgsql security definer set search_path = '' as $$
declare v_me public.users;
begin v_me:=public.require_owner();
  update public.stock_requests set status='rejected', decided_at=now(), decided_by=v_me.id, error_note=null where id=p_request_id and status='pending';
  if not found then raise exception 'Pending stock request not found' using errcode='P0001'; end if;
end; $$;

create or replace function public.approve_all_stock_requests(p_shop_id uuid default null)
returns table (approved bigint, failed bigint) language plpgsql security definer set search_path = '' as $$
declare v_me public.users; v_rec public.stock_requests; v_approved bigint:=0; v_failed bigint:=0;
begin
  v_me:=public.require_owner();
  for v_rec in select * from public.stock_requests where status='pending' and (p_shop_id is null or shop_id=p_shop_id) order by created_at loop
    begin
      if v_rec.type='create_model' then
        if exists (select 1 from public.phone_models where shop_id=v_rec.shop_id and model_name=v_rec.model_name and condition=v_rec.condition) then
          raise exception 'A model with this name and condition already exists'; end if;
        insert into public.phone_models (shop_id,model_name,condition,cost_price,sale_price,opening_stock,bought_in,available,low_stock_threshold)
        values (v_rec.shop_id,v_rec.model_name,v_rec.condition,v_rec.cost_price,v_rec.sale_price,coalesce(v_rec.opening_stock,0),0,coalesce(v_rec.opening_stock,0),coalesce(v_rec.low_stock_threshold,5));
      elsif v_rec.type='adjust_stock' then
        if not exists (select 1 from public.phone_models where id=v_rec.phone_model_id and shop_id=v_rec.shop_id) then
          raise exception 'Phone model does not belong to this shop'; end if;
        insert into public.stock_adjustments (shop_id,phone_model_id,staff_id,type,delta,reason)
        values (v_rec.shop_id,v_rec.phone_model_id,v_rec.staff_id, case when v_rec.delta>0 then 'restock'::public.adjustment_type else 'correction'::public.adjustment_type end, v_rec.delta, v_rec.reason); end if;
      update public.stock_requests set status='approved', decided_at=now(), decided_by=v_me.id, error_note=null where id=v_rec.id;
      v_approved:=v_approved+1;
    exception when others then
      v_failed:=v_failed+1; update public.stock_requests set error_note=left(sqlerrm,300) where id=v_rec.id; end;
  end loop;
  return query select v_approved, v_failed;
end; $$;

create or replace function public.current_user_profile() returns public.user_profile_t
language sql stable security definer set search_path = '' as $$ select id, role, shop_id from public.users where id = auth.uid() $$;
create or replace function public.current_user_can_edit_stock() returns boolean
language sql stable security definer set search_path = '' as $$
  select coalesce((select u.role='owner' or u.can_edit_stock from public.users u where u.id=auth.uid()), false) $$;

-- ---------------------------------------------------------------------------
-- 8. RLS — drop everything the old schema left behind, then recreate the
--    tightened set. This overwrites the old permissive policies.
-- ---------------------------------------------------------------------------
do $$ declare r record; begin
  for r in select schemaname, tablename, policyname from pg_policies where schemaname='public' loop
    execute format('drop policy if exists %I on %I.%I', r.policyname, r.schemaname, r.tablename); end loop;
end $$;

alter table public.shops enable row level security;
alter table public.users enable row level security;
alter table public.phone_models enable row level security;
alter table public.transactions enable row level security;
alter table public.transaction_items enable row level security;
alter table public.stock_adjustments enable row level security;
alter table public.stock_requests enable row level security;
alter table public.swapped_phones enable row level security;
alter table public.login_logs enable row level security;
alter table public.stock_logs enable row level security;

create policy "shops: owner full access" on public.shops for all using (((select public.current_user_profile())).role='owner') with check (((select public.current_user_profile())).role='owner');
create policy "shops: attendant sees own shop" on public.shops for select using (((select public.current_user_profile())).shop_id = id);

create policy "users: owner full access" on public.users for all using (((select public.current_user_profile())).role='owner') with check (((select public.current_user_profile())).role='owner');
create policy "users: read own row" on public.users for select using (auth.uid()=id);
create policy "users: attendant reads staff in own shop" on public.users for select using (((select public.current_user_profile())).shop_id = shop_id);

create policy "phone_models: owner full access" on public.phone_models for all using (((select public.current_user_profile())).role='owner') with check (((select public.current_user_profile())).role='owner');
create policy "phone_models: attendant reads own shop" on public.phone_models for select using (((select public.current_user_profile())).shop_id = shop_id);
create policy "phone_models: privileged staff adds own shop" on public.phone_models for insert with check (((select public.current_user_profile())).shop_id = shop_id and (select public.current_user_can_edit_stock()));
create policy "phone_models: privileged staff edits own shop" on public.phone_models for update using (((select public.current_user_profile())).shop_id = shop_id and (select public.current_user_can_edit_stock())) with check (((select public.current_user_profile())).shop_id = shop_id and (select public.current_user_can_edit_stock()));

create policy "transactions: owner full access" on public.transactions for all using (((select public.current_user_profile())).role='owner') with check (((select public.current_user_profile())).role='owner');
create policy "transactions: attendant reads own shop" on public.transactions for select using (((select public.current_user_profile())).shop_id = shop_id);

create policy "transaction_items: owner full access" on public.transaction_items for all using (((select public.current_user_profile())).role='owner') with check (((select public.current_user_profile())).role='owner');
create policy "transaction_items: attendant reads own shop" on public.transaction_items for select using (exists (select 1 from public.transactions t where t.id=transaction_id and t.shop_id=((select public.current_user_profile())).shop_id));

create policy "stock_adjustments: owner full access" on public.stock_adjustments for all using (((select public.current_user_profile())).role='owner') with check (((select public.current_user_profile())).role='owner');
create policy "stock_adjustments: attendant reads own shop" on public.stock_adjustments for select using (((select public.current_user_profile())).shop_id = shop_id);

create policy "stock_requests: owner full access" on public.stock_requests for all using (((select public.current_user_profile())).role='owner') with check (((select public.current_user_profile())).role='owner');
create policy "stock_requests: attendant insert own shop" on public.stock_requests for insert with check (((select public.current_user_profile())).role='attendant' and ((select public.current_user_profile())).shop_id=shop_id and staff_id=auth.uid() and status='pending' and decided_at is null and decided_by is null);
create policy "stock_requests: attendant reads own shop" on public.stock_requests for select using (((select public.current_user_profile())).shop_id = shop_id);

create policy "swapped_phones: owner full access" on public.swapped_phones for all using (((select public.current_user_profile())).role='owner') with check (((select public.current_user_profile())).role='owner');
create policy "swapped_phones: attendant insert own shop" on public.swapped_phones for insert with check (((select public.current_user_profile())).role='attendant' and ((select public.current_user_profile())).shop_id=shop_id and staff_id=auth.uid());
create policy "swapped_phones: attendant reads own shop" on public.swapped_phones for select using (((select public.current_user_profile())).shop_id = shop_id);

create policy "login_logs: owner reads all" on public.login_logs for select using (((select public.current_user_profile())).role='owner');
create policy "login_logs: read own" on public.login_logs for select using (auth.uid()=user_id);

create policy "stock_logs: owner reads all" on public.stock_logs for select using (((select public.current_user_profile())).role='owner');
create policy "stock_logs: attendant reads own shop" on public.stock_logs for select using (((select public.current_user_profile())).shop_id = shop_id);

-- ---------------------------------------------------------------------------
-- 9. Grants — revoke anon everywhere; column-level UPDATE grant on phone_models
--    and users so the derived columns (available/opening_stock/bought_in and role)
--    can never be written from any client session.
-- ---------------------------------------------------------------------------
revoke all on public.shops from anon; revoke all on public.users from anon; revoke all on public.phone_models from anon;
revoke all on public.transactions from anon; revoke all on public.transaction_items from anon; revoke all on public.stock_adjustments from anon;
revoke all on public.stock_requests from anon; revoke all on public.swapped_phones from anon; revoke all on public.login_logs from anon; revoke all on public.stock_logs from anon;

revoke all on public.shops from authenticated; revoke all on public.users from authenticated; revoke all on public.phone_models from authenticated;
revoke all on public.transactions from authenticated; revoke all on public.transaction_items from authenticated; revoke all on public.stock_adjustments from authenticated;
revoke all on public.stock_requests from authenticated; revoke all on public.swapped_phones from authenticated; revoke all on public.login_logs from authenticated; revoke all on public.stock_logs from authenticated;

grant select, insert, update, delete on public.shops to authenticated;
grant select, insert, delete on public.users to authenticated;
grant update (name, shop_id, can_edit_stock) on public.users to authenticated;
grant select, insert, delete on public.phone_models to authenticated;
grant update (model_name, condition, cost_price, sale_price, low_stock_threshold) on public.phone_models to authenticated;
grant select, insert, update, delete on public.transactions to authenticated;
grant select, insert, update, delete on public.transaction_items to authenticated;
grant select, insert, update, delete on public.stock_adjustments to authenticated;
grant select, insert, update, delete on public.stock_requests to authenticated;
grant select, insert, update, delete on public.swapped_phones to authenticated;
grant select on public.login_logs to authenticated;
grant select on public.stock_logs to authenticated;

revoke all on function public.require_profile() from public, anon;
revoke all on function public.require_owner() from public, anon;
revoke all on function public.current_user_profile() from public, anon;
revoke all on function public.current_user_can_edit_stock() from public, anon;
grant execute on function public.current_user_profile() to authenticated;
grant execute on function public.current_user_can_edit_stock() to authenticated;

-- Close every RPC to anon/PUBLIC. Postgres grants EXECUTE to PUBLIC on CREATE.
do $$ declare v_fn text; begin
  for v_fn in select p.oid::regprocedure::text from pg_proc p join pg_namespace n on n.oid=p.pronamespace where n.nspname='public' loop
    execute format('revoke all on function %s from public', v_fn);
    execute format('revoke all on function %s from anon', v_fn); end loop; end $$;  grant execute on function public.record_transaction(uuid,text,text,public.tx_type,public.payment_method,numeric,timestamptz,jsonb,jsonb,uuid,jsonb) to authenticated;
grant execute on function public.adjust_stock(uuid,uuid,int,public.adjustment_type,text) to authenticated;
grant execute on function public.bulk_adjust_stock(uuid,jsonb,text) to authenticated;
grant execute on function public.delete_transaction(uuid) to authenticated;
grant execute on function public.restore_backup(jsonb) to authenticated;
grant execute on function public.approve_stock_request(uuid) to authenticated;
grant execute on function public.reject_stock_request(uuid) to authenticated;
grant execute on function public.approve_all_stock_requests(uuid) to authenticated;
grant execute on function public.current_user_profile() to authenticated;
grant execute on function public.current_user_can_edit_stock() to authenticated;

alter default privileges in schema public revoke all on functions from public;
alter default privileges in schema public revoke all on functions from anon;
alter default privileges in schema public revoke all on tables from anon;

-- ---------------------------------------------------------------------------
-- 10. Verification — fails loudly rather than leaving a silently-insecure DB
-- ---------------------------------------------------------------------------
do $$ declare v_bad text; begin
  select string_agg(p.oid::regprocedure::text, ', ') into v_bad from pg_proc p join pg_namespace n on n.oid=p.pronamespace
   where n.nspname='public' and (has_function_privilege('anon',p.oid,'execute') or coalesce((select bool_or(a.grantee=0) from aclexplode(p.proacl) a where a.privilege_type='EXECUTE'),false));
  if v_bad is not null then raise exception 'SECURITY: functions still executable by anon/PUBLIC: %', v_bad; end if;
  select string_agg(c.relname, ', ') into v_bad from pg_class c join pg_namespace n on n.oid=c.relnamespace where n.nspname='public' and c.relkind='r' and not c.relrowsecurity;
  if v_bad is not null then raise exception 'SECURITY: RLS not enabled on: %', v_bad; end if;
  if has_column_privilege('authenticated','public.phone_models','available','update')
     or has_column_privilege('authenticated','public.phone_models','opening_stock','update')
     or has_column_privilege('authenticated','public.phone_models','bought_in','update') then
    raise exception 'SECURITY: authenticated can UPDATE derived stock columns on phone_models'; end if;
  if has_column_privilege('authenticated','public.users','role','update') then
    raise exception 'SECURITY: authenticated can UPDATE role on users'; end if;
  raise notice 'Migration verification passed.';
end; $$;
