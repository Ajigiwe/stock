-- ============================================================================
-- Migration 0004: fraud controls and reconciliation
--
-- Non-destructive. Existing transactions remain visible and are treated as
-- completed unless an owner changes their status. Run after 0003.
-- ============================================================================

-- 1. Preserve staff history while allowing access to be revoked.
alter table public.users add column if not exists active boolean not null default true;
alter table public.users add column if not exists deactivated_at timestamptz;
alter table public.users add column if not exists deactivated_by uuid references public.users(id) on delete set null;

-- 2. Transaction lifecycle and payment evidence.
alter table public.transactions add column if not exists status text not null default 'completed';
alter table public.transactions add column if not exists review_reason text;
alter table public.transactions add column if not exists listed_amount numeric(12,2);
alter table public.transactions add column if not exists discount_reason text;
alter table public.transactions add column if not exists payment_reference text;
alter table public.transactions add column if not exists reviewed_by uuid references public.users(id) on delete set null;
alter table public.transactions add column if not exists reviewed_at timestamptz;
alter table public.transactions add column if not exists voided_by uuid references public.users(id) on delete set null;
alter table public.transactions add column if not exists voided_at timestamptz;
alter table public.transactions add column if not exists void_reason text;

update public.transactions
   set status = 'completed'
 where status is null or status not in ('completed', 'pending_review', 'voided', 'rejected');

alter table public.transactions drop constraint if exists transactions_status_check;
alter table public.transactions add constraint transactions_status_check
  check (status in ('completed', 'pending_review', 'voided', 'rejected'));
create index if not exists transactions_status_date_idx
  on public.transactions (status, date desc);

-- 3. Append-only transaction event history.
create table if not exists public.transaction_events (
  id             uuid primary key default gen_random_uuid(),
  -- Restore replaces transaction data and legacy owner-only deletes are
  -- revoked below; cascading here keeps restore compatible without exposing a
  -- direct client delete path.
  transaction_id uuid not null references public.transactions(id) on delete cascade,
  actor_id       uuid not null references public.users(id) on delete restrict,
  action         text not null check (action in ('created', 'approved', 'rejected', 'voided')),
  details        jsonb,
  created_at     timestamptz not null default now()
);
create index if not exists transaction_events_tx_idx
  on public.transaction_events (transaction_id, created_at);
create index if not exists transaction_events_actor_idx
  on public.transaction_events (actor_id, created_at desc);
alter table public.transaction_events enable row level security;
revoke all on public.transaction_events from anon, authenticated;
grant select on public.transaction_events to authenticated;
drop policy if exists "transaction_events: owner reads all" on public.transaction_events;
create policy "transaction_events: owner reads all" on public.transaction_events
  for select using (((select public.current_user_profile())).role = 'owner');

-- 4. Replace record_transaction at the database boundary.
-- Remove legacy overloads first: otherwise an old 10/11-argument RPC remains
-- callable and bypasses the new lifecycle and discount controls.
drop function if exists public.record_transaction(uuid, text, text, public.tx_type, public.payment_method, numeric, timestamptz, jsonb, jsonb, uuid);
drop function if exists public.record_transaction(uuid, text, text, public.tx_type, public.payment_method, numeric, timestamptz, jsonb, jsonb, uuid, jsonb);

-- Under-list-price sales are recorded with stock movement but remain
-- pending_review until the owner approves them. A reason is mandatory.
create or replace function public.record_transaction(
  p_shop_id uuid,
  p_customer_name text,
  p_customer_phone text,
  p_type public.tx_type,
  p_payment_method public.payment_method,
  p_amount numeric,
  p_date timestamptz default now(),
  p_out_items jsonb default '[]'::jsonb,
  p_in_items jsonb default '[]'::jsonb,
  p_idempotency_key uuid default null,
  p_swap_in jsonb default '[]'::jsonb,
  p_discount_reason text default null,
  p_payment_reference text default null
) returns uuid
language plpgsql security definer set search_path = ''
as $$
declare
  v_me             public.users;
  v_tx_id          uuid;
  v_item           jsonb;
  v_model_id       uuid;
  v_condition      public.phone_condition;
  v_avail          int;
  v_qty            int;
  v_sale_price     numeric;
  v_required_price numeric := 0;
  v_status         text := 'completed';
  v_discount       text := nullif(trim(coalesce(p_discount_reason, '')), '');
begin
  v_me := public.require_profile();
  if not v_me.active then
    raise exception 'This staff account is deactivated' using errcode = 'P0001';
  end if;

  if v_me.role is distinct from 'owner'::public.user_role
     and p_shop_id is distinct from v_me.shop_id then
    raise exception 'Not allowed to record transactions for this shop' using errcode = 'P0001';
  end if;
  if p_amount is null or p_amount < 0 then
    raise exception 'Amount must be 0 or more' using errcode = 'P0001';
  end if;
  if p_payment_method in ('mobile_money'::public.payment_method, 'card'::public.payment_method, 'bank_transfer'::public.payment_method)
     and nullif(trim(coalesce(p_payment_reference, '')), '') is null then
    raise exception 'A payment reference is required for this payment method' using errcode = 'P0001';
  end if;
  if p_customer_name is null or length(trim(p_customer_name)) = 0
     or p_customer_phone is null or length(trim(p_customer_phone)) = 0 then
    raise exception 'Customer name and phone are required' using errcode = 'P0001';
  end if;
  if p_date < now() - interval '10 years' or p_date > now() + interval '1 day' then
    raise exception 'Transaction date is outside the allowed window' using errcode = 'P0001';
  end if;
  if p_type in ('sale'::public.tx_type, 'swap'::public.tx_type)
     and jsonb_array_length(coalesce(p_out_items, '[]'::jsonb)) = 0 then
    raise exception 'A sale or swap must include a phone going out' using errcode = 'P0001';
  end if;
  if p_type = 'swap'::public.tx_type
     and jsonb_array_length(coalesce(p_swap_in, '[]'::jsonb)) = 0 then
    raise exception 'A swap must include a trade-in phone' using errcode = 'P0001';
  end if;
  if jsonb_array_length(coalesce(p_in_items, '[]'::jsonb)) > 0 then
    raise exception 'Sellable incoming stock must be recorded through stock controls' using errcode = 'P0001';
  end if;
  if p_type <> 'swap'::public.tx_type
     and jsonb_array_length(coalesce(p_swap_in, '[]'::jsonb)) > 0 then
    raise exception 'Trade-ins are only valid for swaps' using errcode = 'P0001';
  end if;

  if p_type = 'repair'::public.tx_type
     and jsonb_array_length(coalesce(p_out_items, '[]'::jsonb)) > 0 then
    raise exception 'Repairs cannot move stock' using errcode = 'P0001';
  end if;
  if p_idempotency_key is not null then
    select t.id into v_tx_id
      from public.transactions t
     where t.idempotency_key = p_idempotency_key
       and t.shop_id = p_shop_id;
    if v_tx_id is not null then return v_tx_id; end if;
  end if;

  -- Validate all outgoing models and calculate the current list-price floor
  -- before creating the transaction. Duplicate lines are intentionally summed.
  for v_item in
    select value from jsonb_array_elements(coalesce(p_out_items, '[]'::jsonb)) as x(value)
  loop
    v_qty := coalesce((v_item ->> 'qty')::int, 1);
    if v_qty <= 0 then
      raise exception 'Quantity must be greater than 0' using errcode = 'P0001';
    end if;
    select m.available, m.sale_price into v_avail, v_sale_price
      from public.phone_models m
     where m.id = (v_item ->> 'phone_model_id')::uuid
       and m.shop_id = p_shop_id
       for update;
    if v_avail is null then
      raise exception 'Unknown phone model for this shop' using errcode = 'P0001';
    end if;
    if p_type = 'sale'::public.tx_type and v_sale_price is null then
      raise exception 'Every phone in a sale must have a listed price' using errcode = 'P0001';
    end if;
    if v_avail < v_qty then
      raise exception 'Insufficient stock: only % available for this model', v_avail using errcode = 'P0001';
    end if;
    v_required_price := v_required_price + coalesce(v_sale_price, 0) * v_qty;
  end loop;

  if p_type = 'sale'::public.tx_type and p_amount < v_required_price then
    if v_discount is null then
      raise exception 'A discount reason is required below the listed price' using errcode = 'P0001';
    end if;
    v_status := 'pending_review';
  end if;

  if p_type = 'sale'::public.tx_type and v_required_price = 0 and jsonb_array_length(coalesce(p_out_items, '[]'::jsonb)) > 0 then
    raise exception 'Listed prices must be set before recording a sale' using errcode = 'P0001';
  end if;

  insert into public.transactions
    (shop_id, staff_id, customer_name, customer_phone, type, payment_method,
     amount, date, idempotency_key, status, review_reason, discount_reason,
     payment_reference, listed_amount)
  values
    (p_shop_id, v_me.id, p_customer_name, p_customer_phone, p_type,
     p_payment_method, p_amount, p_date, p_idempotency_key, v_status,
     case when v_status = 'pending_review' then 'Below listed price' else null end,
     v_discount, nullif(trim(coalesce(p_payment_reference, '')), ''),
     v_required_price)
  returning id into v_tx_id;

  -- Re-read and apply outgoing stock in deterministic order.
  for v_item in
    select value from jsonb_array_elements(coalesce(p_out_items, '[]'::jsonb)) as x(value)
     order by (value ->> 'phone_model_id')
  loop
    v_qty := coalesce((v_item ->> 'qty')::int, 1);
    select available into v_avail from public.phone_models
     where id = (v_item ->> 'phone_model_id')::uuid and shop_id = p_shop_id for update;
    if v_avail is null or v_avail < v_qty then
      raise exception 'Insufficient stock for this transaction' using errcode = 'P0001';
    end if;
    insert into public.transaction_items (transaction_id, phone_model_id, direction, qty)
    values (v_tx_id, (v_item ->> 'phone_model_id')::uuid, 'out', v_qty);
  end loop;

  -- Incoming sellable stock (legacy swap flow support).
  for v_item in select * from jsonb_array_elements(coalesce(p_in_items, '[]'::jsonb)) loop
    v_qty := coalesce((v_item ->> 'qty')::int, 1);
    if v_qty <= 0 then raise exception 'Quantity must be greater than 0' using errcode = 'P0001'; end if;
    v_condition := coalesce((v_item ->> 'condition')::public.phone_condition, 'used'::public.phone_condition);
    if (v_item ->> 'phone_model_id') is not null then
      select id into v_model_id from public.phone_models
       where id = (v_item ->> 'phone_model_id')::uuid and shop_id = p_shop_id;
      if v_model_id is null then raise exception 'Unknown phone model for this shop' using errcode = 'P0001'; end if;
    else
      select id into v_model_id from public.phone_models
       where shop_id = p_shop_id and model_name = (v_item ->> 'model_name') and condition = v_condition;
      if v_model_id is null then
        insert into public.phone_models
          (shop_id, model_name, condition, cost_price, sale_price, opening_stock, bought_in, available)
        values (p_shop_id, v_item ->> 'model_name', v_condition,
                (v_item ->> 'cost_price')::numeric, (v_item ->> 'sale_price')::numeric, 0, 0, 0)
        returning id into v_model_id;
      end if;
    end if;
    insert into public.transaction_items (transaction_id, phone_model_id, direction, qty)
    values (v_tx_id, v_model_id, 'in', v_qty);
  end loop;

  for v_item in select * from jsonb_array_elements(coalesce(p_swap_in, '[]'::jsonb)) loop
    if coalesce(v_item ->> 'model_name', '') = '' then continue; end if;
    insert into public.swapped_phones
      (shop_id, transaction_id, staff_id, model_name, customer_name, customer_phone)
    values (p_shop_id, v_tx_id, v_me.id, v_item ->> 'model_name',
            v_item ->> 'customer_name', v_item ->> 'customer_phone');
  end loop;

  insert into public.transaction_events (transaction_id, actor_id, action, details)
  values (v_tx_id, v_me.id, 'created', jsonb_build_object(     'status', v_status, 'listed_price', v_required_price,
    'amount', p_amount, 'discount_reason', v_discount,
    'payment_reference', nullif(trim(coalesce(p_payment_reference, '')), '')
  ));
  return v_tx_id;
end;
$$;

-- The legacy overloads were dropped above; only the hardened signature is granted below.
revoke all on function public.record_transaction(uuid, text, text, public.tx_type, public.payment_method, numeric, timestamptz, jsonb, jsonb, uuid, jsonb, text, text) from public, anon, authenticated;
grant execute on function public.record_transaction(uuid, text, text, public.tx_type, public.payment_method, numeric, timestamptz, jsonb, jsonb, uuid, jsonb, text, text) to authenticated;

-- 5. Owner review and void operations. Stock changes are reversed by deleting
-- the child lines, while the original transaction and event history remain.
create or replace function public.review_transaction(
  p_transaction_id uuid,
  p_decision text,
  p_reason text default null
) returns void
language plpgsql security definer set search_path = ''
as $$
declare
  v_me public.users;
  v_tx public.transactions;
  v_reason text := nullif(trim(coalesce(p_reason, '')), '');
begin
  v_me := public.require_owner();
  if p_decision not in ('approve', 'reject') then
    raise exception 'Invalid review decision' using errcode = 'P0001';
  end if;
  select * into v_tx from public.transactions where id = p_transaction_id for update;
  if v_tx.id is null or v_tx.status is distinct from 'pending_review' then
    raise exception 'Transaction is not awaiting review' using errcode = 'P0001';
  end if;
  if p_decision = 'approve' then
    set local app.allow_transaction_mutation = 'on';
    update public.transactions set status = 'completed', reviewed_by = v_me.id,
      reviewed_at = now(), review_reason = coalesce(v_reason, review_reason)
     where id = p_transaction_id;
    insert into public.transaction_events (transaction_id, actor_id, action, details)
    values (p_transaction_id, v_me.id, 'approved', jsonb_build_object('reason', v_reason));
  else
    set local app.allow_transaction_mutation = 'on';
    delete from public.transaction_items where transaction_id = p_transaction_id;
    update public.swapped_phones set status = 'returned' where transaction_id = p_transaction_id;
    update public.transactions set status = 'rejected', reviewed_by = v_me.id,
      reviewed_at = now(), review_reason = coalesce(v_reason, review_reason)
     where id = p_transaction_id;
    insert into public.transaction_events (transaction_id, actor_id, action, details)
    values (p_transaction_id, v_me.id, 'rejected', jsonb_build_object('reason', v_reason));
  end if;
end;
$$;

create or replace function public.void_transaction(
  p_transaction_id uuid,
  p_reason text
) returns void
language plpgsql security definer set search_path = ''
as $$
declare
  v_me public.users;
  v_tx public.transactions;
  v_reason text := nullif(trim(coalesce(p_reason, '')), '');
begin
  v_me := public.require_owner();
  if v_reason is null then raise exception 'A void reason is required' using errcode = 'P0001'; end if;
  select * into v_tx from public.transactions where id = p_transaction_id for update;
  if v_tx.id is null or v_tx.status in ('voided', 'rejected') then
    raise exception 'Transaction is already closed' using errcode = 'P0001';
  end if;
  set local app.allow_transaction_mutation = 'on';
  delete from public.transaction_items where transaction_id = p_transaction_id;
  update public.swapped_phones set status = 'returned' where transaction_id = p_transaction_id;
  update public.transactions set status = 'voided', voided_by = v_me.id,
    voided_at = now(), void_reason = v_reason, reviewed_by = v_me.id, reviewed_at = now()
   where id = p_transaction_id;
  insert into public.transaction_events (transaction_id, actor_id, action, details)
  values (p_transaction_id, v_me.id, 'voided', jsonb_build_object('reason', v_reason));
end;
$$;

create or replace function public.update_swapped_phone_status(
  p_id uuid,
  p_status text
) returns void
language plpgsql security definer set search_path = ''
as $$
begin
  perform public.require_owner();
  if p_status not in ('in_stock', 'sold', 'returned') then
    raise exception 'Invalid trade-in status' using errcode = 'P0001';
  end if;
  update public.swapped_phones set status = p_status
   where id = p_id;
  if not found then raise exception 'Trade-in not found' using errcode = 'P0001'; end if;
end;
$$;

revoke all on function public.review_transaction(uuid, text, text) from public, anon;
revoke all on function public.void_transaction(uuid, text) from public, anon;
revoke all on function public.update_swapped_phone_status(uuid, text) from public, anon;
grant execute on function public.review_transaction(uuid, text, text) to authenticated;
grant execute on function public.void_transaction(uuid, text) to authenticated;
grant execute on function public.update_swapped_phone_status(uuid, text) to authenticated;
drop function if exists public.delete_transaction(uuid);

-- The old destructive RPC is no longer part of the client API. New reversals
-- go through void_transaction/review_transaction, which preserve history.

-- 6. Daily close: counted money is submitted, then owner-locked.
create table if not exists public.daily_closes (
  id                    uuid primary key default gen_random_uuid(),
  shop_id               uuid not null references public.shops(id) on delete cascade,
  close_date             date not null,
  status                 text not null default 'open' check (status in ('open', 'locked')),
  expected_cash          numeric(12,2) not null default 0,
  expected_mobile_money  numeric(12,2) not null default 0,
  expected_other         numeric(12,2) not null default 0,
  counted_cash            numeric(12,2),
  counted_mobile_money   numeric(12,2),
  counted_other           numeric(12,2),
  notes                   text,
  submitted_by            uuid not null references public.users(id) on delete restrict,
  submitted_at            timestamptz not null default now(),
  locked_by               uuid references public.users(id) on delete set null,
  locked_at               timestamptz,
  created_at              timestamptz not null default now(),
  unique (shop_id, close_date)
);
create index if not exists daily_closes_shop_date_idx on public.daily_closes(shop_id, close_date desc);
alter table public.daily_closes enable row level security;
revoke all on public.daily_closes from anon, authenticated;
grant select on public.daily_closes to authenticated;
drop policy if exists "daily_closes: owner reads all" on public.daily_closes;
drop policy if exists "daily_closes: attendant reads own shop" on public.daily_closes;
create policy "daily_closes: owner reads all" on public.daily_closes for select
  using (((select public.current_user_profile())).role = 'owner');
create policy "daily_closes: attendant reads own shop" on public.daily_closes for select
  using (((select public.current_user_profile())).shop_id = shop_id);

create or replace function public.submit_daily_close(
  p_shop_id uuid,
  p_close_date date,
  p_counted_cash numeric,
  p_counted_mobile_money numeric,
  p_counted_other numeric default 0,
  p_notes text default null
) returns uuid
language plpgsql security definer set search_path = ''
as $$
declare
  v_me public.users;
  v_id uuid;
  v_cash numeric := 0;
  v_mobile numeric := 0;
  v_other numeric := 0;
begin
  v_me := public.require_profile();
  if not v_me.active then raise exception 'This staff account is deactivated' using errcode='P0001'; end if;
  if v_me.role is distinct from 'owner' and p_shop_id is distinct from v_me.shop_id then
    raise exception 'Not allowed for this shop' using errcode='P0001';
  end if;
  if coalesce(p_counted_cash, -1) < 0 or coalesce(p_counted_mobile_money, -1) < 0 or coalesce(p_counted_other, -1) < 0 then
    raise exception 'Counted amounts cannot be negative' using errcode='P0001';
  end if;
  select coalesce(sum(case when payment_method = 'cash' then amount else 0 end),0),
         coalesce(sum(case when payment_method = 'mobile_money' then amount else 0 end),0),
         coalesce(sum(case when payment_method not in ('cash','mobile_money') then amount else 0 end),0)
    into v_cash, v_mobile, v_other
    from public.transactions
   where shop_id = p_shop_id and date >= p_close_date::timestamptz
     and date < (p_close_date + 1)::timestamptz and status = 'completed';
  select id into v_id from public.daily_closes where shop_id = p_shop_id and close_date = p_close_date for update;
  if v_id is not null and exists (select 1 from public.daily_closes where id=v_id and status='locked') then
    raise exception 'This daily close is already locked' using errcode='P0001';
  end if;
  insert into public.daily_closes
    (id, shop_id, close_date, expected_cash, expected_mobile_money, expected_other,
     counted_cash, counted_mobile_money, counted_other, notes, submitted_by, submitted_at, status)
  values (coalesce(v_id, gen_random_uuid()), p_shop_id, p_close_date, v_cash, v_mobile, v_other,
          p_counted_cash, p_counted_mobile_money, p_counted_other, p_notes, v_me.id, now(), 'open')
  on conflict (shop_id, close_date) do update set
    expected_cash = excluded.expected_cash, expected_mobile_money = excluded.expected_mobile_money,
    expected_other = excluded.expected_other, counted_cash = excluded.counted_cash,
    counted_mobile_money = excluded.counted_mobile_money, counted_other = excluded.counted_other,
    notes = excluded.notes, submitted_by = excluded.submitted_by, submitted_at = excluded.submitted_at;
  select id into v_id from public.daily_closes where shop_id=p_shop_id and close_date=p_close_date;
  return v_id;
end;
$$;

create or replace function public.lock_daily_close(p_close_id uuid)
returns void
language plpgsql security definer set search_path = ''
as $$
declare v_me public.users;
begin
  v_me := public.require_owner();
  update public.daily_closes set status='locked', locked_by=v_me.id, locked_at=now()
   where id=p_close_id and status='open';
  if not found then raise exception 'Open daily close not found' using errcode='P0001'; end if;
end;
$$;
revoke all on function public.submit_daily_close(uuid,date,numeric,numeric,numeric,text) from public, anon;
revoke all on function public.lock_daily_close(uuid) from public, anon;
grant execute on function public.submit_daily_close(uuid,date,numeric,numeric,numeric,text) to authenticated;
grant execute on function public.lock_daily_close(uuid) to authenticated;

-- 7. Physical stock counts are evidence only; they never mutate stock.
create table if not exists public.stock_counts (
  id           uuid primary key default gen_random_uuid(),
  shop_id      uuid not null references public.shops(id) on delete cascade,
  count_date   date not null,
  status       text not null default 'submitted' check (status in ('submitted','approved','applied')),
  submitted_by uuid not null references public.users(id) on delete restrict,
  approved_by  uuid references public.users(id) on delete set null,
  notes        text,
  created_at   timestamptz not null default now()
);
alter table public.stock_counts drop constraint if exists stock_counts_status_check;
alter table public.stock_counts add constraint stock_counts_status_check check (status in ('submitted','approved','applied'));
alter table public.daily_closes drop constraint if exists daily_closes_status_check;
alter table public.daily_closes add constraint daily_closes_status_check check (status in ('open','locked'));

create table if not exists public.stock_count_items (
  id              uuid primary key default gen_random_uuid(),
  count_id        uuid not null references public.stock_counts(id) on delete cascade,
  phone_model_id  uuid not null references public.phone_models(id) on delete restrict,
  expected_qty    int not null check (expected_qty >= 0),
  counted_qty     int not null check (counted_qty >= 0),
  unique(count_id, phone_model_id)
);
create index if not exists stock_counts_shop_date_idx on public.stock_counts(shop_id, count_date desc);
alter table public.stock_counts enable row level security;
alter table public.stock_count_items enable row level security;
revoke all on public.stock_counts, public.stock_count_items from anon, authenticated;
grant select on public.stock_counts, public.stock_count_items to authenticated;
drop policy if exists "stock_counts: owner reads all" on public.stock_counts;
drop policy if exists "stock_counts: attendant reads own shop" on public.stock_counts;
drop policy if exists "stock_count_items: owner reads all" on public.stock_count_items;
drop policy if exists "stock_count_items: attendant reads own shop" on public.stock_count_items;
create policy "stock_counts: owner reads all" on public.stock_counts for select
  using (((select public.current_user_profile())).role='owner');
create policy "stock_counts: attendant reads own shop" on public.stock_counts for select
  using (((select public.current_user_profile())).shop_id=shop_id);
create policy "stock_count_items: owner reads all" on public.stock_count_items for select
  using (exists(select 1 from public.stock_counts c where c.id=count_id and ((select public.current_user_profile())).role='owner'));
create policy "stock_count_items: attendant reads own shop" on public.stock_count_items for select
  using (exists(select 1 from public.stock_counts c where c.id=count_id and c.shop_id=((select public.current_user_profile())).shop_id));

create or replace function public.approve_stock_count(
  p_count_id uuid
) returns void
language plpgsql security definer set search_path = ''
as $$
declare
  v_me public.users;
  v_count public.stock_counts;
begin
  v_me := public.require_owner();
  select * into v_count from public.stock_counts where id = p_count_id for update;
  if v_count.id is null or v_count.status is distinct from 'submitted' then
    raise exception 'Submitted stock count not found' using errcode = 'P0001';
  end if;
  update public.stock_counts
     set status = 'approved', approved_by = v_me.id
   where id = p_count_id;
end;
$$;
revoke all on function public.approve_stock_count(uuid) from public, anon;
grant execute on function public.approve_stock_count(uuid) to authenticated;

create or replace function public.apply_stock_count_correction(
  p_count_id uuid,
  p_reason text
) returns int
language plpgsql security definer set search_path = ''
as $$
declare
  v_me public.users;
  v_count public.stock_counts;
  v_item public.stock_count_items;
  v_delta int;
  v_changes int := 0;
  v_reason text := nullif(trim(coalesce(p_reason, '')), '');
begin
  v_me := public.require_owner();
  if v_reason is null then raise exception 'A correction reason is required' using errcode = 'P0001'; end if;
  select * into v_count from public.stock_counts where id = p_count_id for update;
  if v_count.id is null or v_count.status is distinct from 'approved' then
    raise exception 'Approve the stock count before applying a correction' using errcode = 'P0001';
  end if;
  for v_item in select * from public.stock_count_items where count_id = p_count_id loop
    v_delta := v_item.counted_qty - v_item.expected_qty;
    if v_delta <> 0 then
      insert into public.stock_adjustments (shop_id, phone_model_id, staff_id, type, delta, reason)
      values (v_count.shop_id, v_item.phone_model_id, v_me.id,
              case when v_delta > 0 then 'restock'::public.adjustment_type else 'correction'::public.adjustment_type end,
              v_delta, v_reason);
      v_changes := v_changes + 1;
    end if;
  end loop;
  update public.stock_counts set status = 'applied' where id = p_count_id;
  return v_changes;
end;
$$;
revoke all on function public.apply_stock_count_correction(uuid,text) from public, anon;
grant execute on function public.apply_stock_count_correction(uuid,text) to authenticated;

create or replace function public.submit_stock_count(
  p_shop_id uuid,
  p_count_date date,
  p_items jsonb,
  p_notes text default null
) returns uuid
language plpgsql security definer set search_path = ''
as $$
declare
  v_me public.users;
  v_count_id uuid := gen_random_uuid();
  v_item jsonb;
  v_model public.phone_models;
  v_counted int;
begin
  v_me := public.require_profile();
  if not v_me.active then raise exception 'This staff account is deactivated' using errcode='P0001'; end if;
  if v_me.role is distinct from 'owner' and p_shop_id is distinct from v_me.shop_id then
    raise exception 'Not allowed for this shop' using errcode='P0001';
  end if;
  if jsonb_array_length(coalesce(p_items, '[]'::jsonb)) = 0 then
    raise exception 'A stock count must include at least one model' using errcode = 'P0001';
  end if;
  insert into public.stock_counts(id, shop_id, count_date, submitted_by, notes)
  values(v_count_id,p_shop_id,p_count_date,v_me.id,p_notes);
  for v_item in select * from jsonb_array_elements(coalesce(p_items, '[]'::jsonb)) loop
    select * into v_model from public.phone_models where id=(v_item->>'phone_model_id')::uuid and shop_id=p_shop_id;

    if v_model.id is null then raise exception 'Unknown phone model for this shop' using errcode='P0001'; end if;
    v_counted := (v_item->>'counted_qty')::int;
    if v_counted is null or v_counted < 0 then raise exception 'Counted quantity must be 0 or more' using errcode='P0001'; end if;
    insert into public.stock_count_items(count_id,phone_model_id,expected_qty,counted_qty)
    values(v_count_id,v_model.id,v_model.available,v_counted);
  end loop;
  return v_count_id;
end;
$$;
revoke all on function public.submit_stock_count(uuid,date,jsonb,text) from public, anon;
grant execute on function public.submit_stock_count(uuid,date,jsonb,text) to authenticated;

-- Hide deactivated profiles from all RLS policies as well as from the app
-- session loader. This matters if a previously issued JWT is still valid.
create or replace function public.current_user_profile()
returns public.user_profile_t
language sql stable security definer set search_path = ''
as $$
  select u.id, u.role, u.shop_id
    from public.users u
   where u.id = auth.uid() and u.active
$$;
revoke all on function public.current_user_profile() from public, anon;
grant execute on function public.current_user_profile() to authenticated;

-- Existing helper must reject deactivated users for future RPCs.
create or replace function public.require_profile()
returns public.users
language plpgsql stable security definer set search_path = ''
as $$
declare v_user public.users;
begin
  if auth.uid() is null then raise exception 'Not authenticated' using errcode='P0001'; end if;
  select * into v_user from public.users where id=auth.uid();
  if v_user.id is null then raise exception 'No profile for the current user' using errcode='P0001'; end if;
  if not v_user.active then raise exception 'This account is deactivated' using errcode='P0001'; end if;
  return v_user;
end;
$$;
revoke all on function public.require_profile() from public, anon;
grant execute on function public.require_profile() to authenticated;

-- Close direct client mutation paths. Controlled RPCs above are the only writes.
revoke insert, update, delete on public.transactions from public, anon, authenticated;
revoke insert, update, delete on public.transaction_items from public, anon, authenticated;
revoke insert, update, delete on public.stock_adjustments from public, anon, authenticated;
revoke insert, update, delete on public.swapped_phones from public, anon, authenticated;
revoke insert, update, delete on public.daily_closes from public, anon, authenticated;
revoke insert, update, delete on public.stock_counts, public.stock_count_items from public, anon, authenticated;

-- The legacy destructive RPC was dropped above; it no longer exists to grant.

-- 8. Backup restore must now also clear the fraud-control tables (their FKs
-- reference users/shops/phone_models with RESTRICT, so an untouched row would
-- abort the restore). History tables are not part of the backup file, so they
-- are cleared rather than restored — consistent with login_logs/stock_logs.
create or replace function public.restore_backup(p_data jsonb)
returns jsonb
language plpgsql security definer set search_path = ''
as $$
declare
  v_me       public.users;
  v_rec      jsonb;
  v_rows     bigint;
  v_repaired bigint;
  v_key      text;
begin
  v_me := public.require_owner();

  if p_data is null or jsonb_typeof(p_data) <> 'object' then
    raise exception 'Invalid backup file' using errcode = 'P0001';
  end if;
  foreach v_key in array array[
    'shops', 'users', 'phone_models', 'transactions',
    'transaction_items', 'stock_adjustments'
  ] loop
    if not p_data ? v_key then
      raise exception 'Invalid backup file: missing "%"', v_key using errcode = 'P0001';
    end if;
    if jsonb_typeof(p_data -> v_key) <> 'array' then
      raise exception 'Invalid backup file: "%" must be an array', v_key using errcode = 'P0001';
    end if;
  end loop;

  if not exists (
    select 1 from jsonb_array_elements(p_data -> 'users') as u(value)
     where (u.value ->> 'role') = 'owner'
  ) then
    raise exception 'Invalid backup file: it contains no owner account' using errcode = 'P0001';
  end if;

  alter table public.transaction_items disable trigger item_stock_change;
  alter table public.stock_adjustments disable trigger stock_adjustment_change;

  delete from public.stock_logs where true;
  delete from public.login_logs where true;
  delete from public.stock_requests where true;
  delete from public.swapped_phones where true;
  delete from public.transaction_events where true;
  delete from public.daily_closes where true;
  delete from public.stock_counts where true; -- stock_count_items cascade
  delete from public.transaction_items where true;
  delete from public.transactions where true;
  delete from public.stock_adjustments where true;
  delete from public.phone_models where true;
  delete from public.users where true;
  delete from public.shops where true;

  for v_rec in select * from jsonb_array_elements(coalesce(p_data -> 'shops', '[]'::jsonb)) loop
    insert into public.shops (id, name, location, phone, created_at)
    values ((v_rec ->> 'id')::uuid, v_rec ->> 'name', v_rec ->> 'location', v_rec ->> 'phone',
            coalesce((v_rec ->> 'created_at')::timestamptz, now()));
  end loop;

  for v_rec in select * from jsonb_array_elements(coalesce(p_data -> 'users', '[]'::jsonb)) loop
    if exists (select 1 from auth.users where id = (v_rec ->> 'id')::uuid) then
      insert into public.users (id, name, role, shop_id, can_edit_stock, active, created_at)
      values ((v_rec ->> 'id')::uuid,
              coalesce(v_rec ->> 'name', ''),
              coalesce((v_rec ->> 'role')::public.user_role, 'attendant'),
              nullif(v_rec ->> 'shop_id', '')::uuid,
              coalesce((v_rec ->> 'can_edit_stock')::boolean, false),
              coalesce((v_rec ->> 'active')::boolean, true),
              coalesce((v_rec ->> 'created_at')::timestamptz, now()))
      on conflict (id) do nothing;
    end if;
  end loop;

  insert into public.users (id, name, role, active)
  values (v_me.id, v_me.name, 'owner', true)
  on conflict (id) do update
     set role = 'owner', shop_id = null, active = true;

  for v_rec in select * from jsonb_array_elements(coalesce(p_data -> 'phone_models', '[]'::jsonb)) loop
    insert into public.phone_models
      (id, shop_id, model_name, condition, cost_price, sale_price,
       opening_stock, bought_in, available, low_stock_threshold, created_at)
    values ((v_rec ->> 'id')::uuid, (v_rec ->> 'shop_id')::uuid, v_rec ->> 'model_name',
            coalesce((v_rec ->> 'condition')::public.phone_condition, 'new'),
            (v_rec ->> 'cost_price')::numeric, (v_rec ->> 'sale_price')::numeric,
            greatest(coalesce((v_rec ->> 'opening_stock')::int, 0), 0),
            greatest(coalesce((v_rec ->> 'bought_in')::int, 0), 0),
            greatest(coalesce((v_rec ->> 'available')::int, 0), 0),
            greatest(coalesce((v_rec ->> 'low_stock_threshold')::int, 5), 0),
            coalesce((v_rec ->> 'created_at')::timestamptz, now()));
  end loop;

  for v_rec in select * from jsonb_array_elements(coalesce(p_data -> 'transactions', '[]'::jsonb)) loop
    insert into public.transactions
      (id, shop_id, staff_id, customer_name, customer_phone, type,
       payment_method, amount, date, created_at, status)
    values ((v_rec ->> 'id')::uuid, (v_rec ->> 'shop_id')::uuid,
            coalesce((select u.id from public.users u where u.id = nullif(v_rec ->> 'staff_id', '')::uuid), v_me.id),
            v_rec ->> 'customer_name', v_rec ->> 'customer_phone',
            coalesce((v_rec ->> 'type')::public.tx_type, 'sale'),
            coalesce((v_rec ->> 'payment_method')::public.payment_method, 'cash'),
            greatest(coalesce((v_rec ->> 'amount')::numeric, 0), 0),
            coalesce((v_rec ->> 'date')::timestamptz, now()),
            coalesce((v_rec ->> 'created_at')::timestamptz, now()),
            'completed');
  end loop;

  for v_rec in select * from jsonb_array_elements(coalesce(p_data -> 'transaction_items', '[]'::jsonb)) loop
    insert into public.transaction_items (id, transaction_id, phone_model_id, direction, qty)
    values ((v_rec ->> 'id')::uuid, (v_rec ->> 'transaction_id')::uuid,
            (v_rec ->> 'phone_model_id')::uuid,
            (v_rec ->> 'direction')::public.item_direction,
            greatest(coalesce((v_rec ->> 'qty')::int, 1), 1));
  end loop;

  for v_rec in select * from jsonb_array_elements(coalesce(p_data -> 'stock_adjustments', '[]'::jsonb)) loop
    if coalesce((v_rec ->> 'delta')::int, 0) <> 0 then
      insert into public.stock_adjustments
        (id, shop_id, phone_model_id, staff_id, type, delta, reason, date)
      values ((v_rec ->> 'id')::uuid, (v_rec ->> 'shop_id')::uuid,
              (v_rec ->> 'phone_model_id')::uuid,
              coalesce((select u.id from public.users u where u.id = nullif(v_rec ->> 'staff_id', '')::uuid), v_me.id),
              coalesce((v_rec ->> 'type')::public.adjustment_type, 'restock'),
              (v_rec ->> 'delta')::int, v_rec ->> 'reason',
              coalesce((v_rec ->> 'date')::timestamptz, now()));
    end if;
  end loop;

  alter table public.transaction_items enable trigger item_stock_change;
  alter table public.stock_adjustments enable trigger stock_adjustment_change;

  with computed as (
    select m.id,
           greatest(m.opening_stock + m.bought_in
             + coalesce((select sum(i.qty) from public.transaction_items i where i.phone_model_id = m.id and i.direction = 'in'), 0)
             - coalesce((select sum(i.qty) from public.transaction_items i where i.phone_model_id = m.id and i.direction = 'out'), 0)
             - coalesce((select sum(-a.delta) from public.stock_adjustments a where a.phone_model_id = m.id and a.delta < 0), 0),
             0)::int as expected
      from public.phone_models m
  )
  update public.phone_models m
     set available = c.expected
    from computed c
   where c.id = m.id and m.available <> c.expected;
  get diagnostics v_repaired = row_count;

  select count(*) into v_rows from public.transactions;
  return jsonb_build_object('restored', true, 'transactions', v_rows, 'stock_repaired', v_repaired);
end;
$$;
revoke all on function public.restore_backup(jsonb) from public, anon;
grant execute on function public.restore_backup(jsonb) to authenticated;

-- Ensure the database API exposes the new fields to PostgREST after migration.
notify pgrst, 'reload schema';
