-- ============================================================================
-- Phone Stock Management System — Supabase schema
-- Run this in the Supabase SQL editor (Dashboard > SQL > New query), or via
-- `supabase db push` if you have the CLI linked.
--
-- SAFE TO RE-RUN: the header below drops any previous version first.
-- ============================================================================
drop trigger if exists on_auth_user_created on auth.users;

drop table if exists public.login_logs cascade;
drop table if exists public.stock_logs cascade;
drop table if exists public.swapped_phones cascade;
drop table if exists public.stock_requests cascade;
drop table if exists public.stock_adjustments cascade;
drop table if exists public.transaction_items cascade;
drop table if exists public.transactions cascade;
drop table if exists public.phone_models cascade;
drop table if exists public.users cascade;
drop table if exists public.shops cascade;
drop type if exists public.user_profile_t cascade;
drop type if exists public.adjustment_type cascade;
drop type if exists public.item_direction cascade;
drop type if exists public.payment_method cascade;
drop type if exists public.tx_type cascade;
drop type if exists public.phone_condition cascade;
drop type if exists public.user_role cascade;

-- DESIGN DECISIONS (resolves open items in design.md):
--   * Repairs are SERVICE-ONLY: the customer's phone comes in and goes back
--     out with the customer; no stock movement. Only the transaction record
--     and amount charged are stored.
--   * Stock invariant enforced at DB level:
--         available = opening_stock + bought_in + (sum of "in" items)
--                    - (sum of "out" items)
--     `available` can never be hand-edited and can never go below 0
--     (CHECK constraint + explicit guard in triggers).
--   * Swap top-up amount is stored on the transaction (amount = cash top-up).
--   * A low-stock threshold is stored per model (defaults to 5).
--
-- SECURITY MODEL:
--   * Every SECURITY DEFINER function uses `set search_path = ''` and fully
--     schema-qualified names, so a temp-schema object can never shadow a table.
--   * Role checks go through require_owner() / require_profile(), which raise on
--     a MISSING profile row. Never compare a possibly-NULL role with `<>`:
--     `NULL <> 'owner'` is NULL, `IF NULL` is not true, and the guard silently
--     falls through — that turns an owner-only RPC into an anon-callable one.
--   * EXECUTE is revoked from public/anon on every RPC. Postgres grants EXECUTE
--     to PUBLIC by default on CREATE FUNCTION, so `grant ... to authenticated`
--     alone does NOT keep anon out.
--   * Attendants never hold direct DML on stock: `available`, `opening_stock`
--     and `bought_in` are excluded from their column-level UPDATE grant, and
--     stock only moves via record_transaction / adjust_stock.
-- ============================================================================

-- ---------------------------------------------------------------------------
-- Enums
-- ---------------------------------------------------------------------------
create type user_role          as enum ('owner', 'attendant');
create type phone_condition    as enum ('new', 'used');
create type tx_type            as enum ('sale', 'swap', 'repair');
create type payment_method     as enum ('cash', 'mobile_money', 'card', 'bank_transfer', 'other');
create type item_direction     as enum ('out', 'in');
create type adjustment_type    as enum ('restock', 'correction');

-- composite returned by current_user_profile() (scalar — usable in RLS policies)
create type user_profile_t as (id uuid, role user_role, shop_id uuid);

-- ---------------------------------------------------------------------------
-- shops
-- ---------------------------------------------------------------------------
create table public.shops (
  id          uuid primary key default gen_random_uuid(),
  name        text not null,
  location    text,
  phone       text,
  created_at  timestamptz not null default now()
);

-- ---------------------------------------------------------------------------
-- users  (public profile rows keyed to auth.users)
-- ---------------------------------------------------------------------------
create table public.users (
  id          uuid primary key references auth.users (id) on delete cascade,
  name        text not null default '',
  role        user_role not null default 'attendant',
  shop_id     uuid references public.shops (id) on delete set null, -- null for owner
  can_edit_stock boolean not null default false, -- staff granted direct stock-editing by the owner
  created_at  timestamptz not null default now()
);

-- Hit by current_user_profile(), which every RLS policy calls.
create index users_shop_idx on public.users (shop_id);

-- Create a public.users row automatically whenever an auth user signs up.
-- Tolerates accounts with no email and no name metadata (phone / OAuth), and
-- is idempotent so a restored profile row does not break re-signup.
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

drop trigger if exists on_auth_user_created on auth.users;
create trigger on_auth_user_created
  after insert on auth.users
  for each row execute function public.handle_new_user();

-- ---------------------------------------------------------------------------
-- Role guards used by every SECURITY DEFINER RPC below.
--
-- These RAISE when the caller has no public.users row. That is the whole point:
-- the previous form, `if (select role from public.users where id = auth.uid())
-- <> 'owner' then raise`, evaluates to NULL for a caller with no profile row —
-- including an unauthenticated anon caller, for whom auth.uid() is NULL — and
-- `IF NULL` is not true, so execution fell straight through the guard.
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
-- phone_models  (one row per model + condition, per shop)
-- ---------------------------------------------------------------------------
create table public.phone_models (
  id                   uuid primary key default gen_random_uuid(),
  shop_id              uuid not null references public.shops (id) on delete cascade,
  model_name           text not null,
  condition            phone_condition not null default 'new',
  cost_price           numeric(12,2) check (cost_price is null or cost_price >= 0),
  sale_price           numeric(12,2) check (sale_price is null or sale_price >= 0),
  opening_stock        int not null default 0 check (opening_stock >= 0),
  bought_in            int not null default 0 check (bought_in >= 0),
  available            int not null default 0 check (available >= 0),
  low_stock_threshold  int not null default 5 check (low_stock_threshold >= 0),
  created_at           timestamptz not null default now(),
  unique (shop_id, model_name, condition)
);

create index phone_models_shop_idx on public.phone_models (shop_id);

-- ---------------------------------------------------------------------------
-- transactions  (one row per customer interaction)
-- ---------------------------------------------------------------------------
create table public.transactions (
  id                uuid primary key default gen_random_uuid(),
  shop_id           uuid not null references public.shops (id) on delete cascade,
  staff_id          uuid not null references public.users (id) on delete restrict,
  customer_name     text,
  customer_phone    text,
  type              tx_type not null,
  payment_method    payment_method not null,
  amount            numeric(12,2) not null default 0 check (amount >= 0), -- sale: full price; swap: top-up; repair: charge
  date              timestamptz not null default now(),
  created_at        timestamptz not null default now(),
  idempotency_key   uuid unique  -- client-generated; prevents double-submit duplicates
);

create index transactions_shop_date_idx on public.transactions (shop_id, date desc);
create index transactions_type_idx     on public.transactions (type);
create index transactions_staff_idx    on public.transactions (staff_id);

-- ---------------------------------------------------------------------------
-- transaction_items  (line items; lets a swap move stock both ways)
-- ---------------------------------------------------------------------------
create table public.transaction_items (
  id                uuid primary key default gen_random_uuid(),
  transaction_id    uuid not null references public.transactions (id) on delete cascade,
  phone_model_id    uuid not null references public.phone_models (id) on delete restrict,
  direction         item_direction not null, -- 'out' leaves shop stock, 'in' enters it
  qty               int not null default 1 check (qty > 0)
);

create index transaction_items_tx_idx   on public.transaction_items (transaction_id);
create index transaction_items_model_idx on public.transaction_items (phone_model_id);

-- Every line item must reference a model in the SAME shop as its transaction.
-- Without this, an attendant could post a transaction in their own shop that
-- references another shop's phone_model_id and drain that shop's stock — RLS
-- only ever checks transactions.shop_id, never the model's shop.
create or replace function public.enforce_item_shop_match()
returns trigger
language plpgsql security definer set search_path = ''
as $$
declare
  v_tx_shop    uuid;
  v_model_shop uuid;
begin
  select t.shop_id into v_tx_shop
    from public.transactions t where t.id = new.transaction_id;
  select m.shop_id into v_model_shop
    from public.phone_models m where m.id = new.phone_model_id;

  if v_tx_shop is null then
    raise exception 'Unknown transaction' using errcode = 'P0001';
  end if;
  if v_model_shop is null then
    raise exception 'Unknown phone model' using errcode = 'P0001';
  end if;
  if v_tx_shop <> v_model_shop then
    raise exception 'Phone model does not belong to this transaction''s shop'
      using errcode = 'P0001';
  end if;

  return new;
end;
$$;

drop trigger if exists item_shop_match on public.transaction_items;
create trigger item_shop_match
  before insert or update on public.transaction_items
  for each row execute function public.enforce_item_shop_match();

-- ---------------------------------------------------------------------------
-- stock_adjustments  (restocking / manual corrections — the ONLY way stock
--                     changes outside of transaction_items)
-- ---------------------------------------------------------------------------
create table public.stock_adjustments (
  id              uuid primary key default gen_random_uuid(),
  shop_id         uuid not null references public.shops (id) on delete cascade,
  phone_model_id  uuid not null references public.phone_models (id) on delete cascade,
  staff_id        uuid not null references public.users (id) on delete restrict,
  type            adjustment_type not null default 'restock',
  delta           int not null check (delta <> 0),
  reason          text,
  date            timestamptz not null default now()
);

create index stock_adjustments_model_idx on public.stock_adjustments (phone_model_id);
create index stock_adjustments_shop_idx  on public.stock_adjustments (shop_id, date desc);
create index stock_adjustments_staff_idx on public.stock_adjustments (staff_id);

-- Same cross-shop guard as transaction_items: the adjusted model must live in
-- the shop the adjustment is booked against.
create or replace function public.enforce_adjustment_shop_match()
returns trigger
language plpgsql security definer set search_path = ''
as $$
declare
  v_model_shop uuid;
begin
  select m.shop_id into v_model_shop
    from public.phone_models m where m.id = new.phone_model_id;
  if v_model_shop is null then
    raise exception 'Unknown phone model' using errcode = 'P0001';
  end if;
  if v_model_shop <> new.shop_id then
    raise exception 'Phone model does not belong to this shop' using errcode = 'P0001';
  end if;
  return new;
end;
$$;

drop trigger if exists adjustment_shop_match on public.stock_adjustments;
create trigger adjustment_shop_match
  before insert or update on public.stock_adjustments
  for each row execute function public.enforce_adjustment_shop_match();

-- ---------------------------------------------------------------------------
-- Stock triggers
-- ---------------------------------------------------------------------------

-- Applies the effect of a transaction_items row to phone_models.available.
-- Guards against selling/out-ing more than available.
--
-- Every read of `available` takes a row lock first. Without `for update`, two
-- concurrent inserts against available = 1 both read 1, both pass the guard,
-- and the CHECK constraint aborts the loser with a raw 23514 instead of the
-- intended 'Insufficient stock' message.
create or replace function public.apply_item_stock_change()
returns trigger
language plpgsql security definer set search_path = ''
as $$
declare
  cur_avail int;
begin
  -- DELETE: reverse the old effect
  if tg_op = 'DELETE' then
    update public.phone_models
       set available = available + (case when old.direction = 'out' then old.qty else -old.qty end)
     where id = old.phone_model_id;
    return old;
  end if;

  -- INSERT: apply the new effect
  if tg_op = 'INSERT' then
    select available into cur_avail
      from public.phone_models where id = new.phone_model_id for update;
    if new.direction = 'out' and coalesce(cur_avail, 0) < new.qty then
      raise exception 'Insufficient stock: only % available for this model', coalesce(cur_avail, 0)
        using errcode = 'P0001';
    end if;
    update public.phone_models
       set available = available + (case when new.direction = 'out' then -new.qty else new.qty end)
     where id = new.phone_model_id;
    return new;
  end if;

  -- UPDATE: reverse old, then apply new
  if tg_op = 'UPDATE' then
    update public.phone_models
       set available = available + (case when old.direction = 'out' then old.qty else -old.qty end)
     where id = old.phone_model_id;
    select available into cur_avail
      from public.phone_models where id = new.phone_model_id for update;
    if new.direction = 'out' and coalesce(cur_avail, 0) < new.qty then
      raise exception 'Insufficient stock: only % available for this model', coalesce(cur_avail, 0)
        using errcode = 'P0001';
    end if;
    update public.phone_models
       set available = available + (case when new.direction = 'out' then -new.qty else new.qty end)
     where id = new.phone_model_id;
    return new;
  end if;

  return null;
end;
$$;

drop trigger if exists item_stock_change on public.transaction_items;
create trigger item_stock_change
  after insert or update or delete on public.transaction_items
  for each row execute function public.apply_item_stock_change();

-- Applies stock_adjustments: available always moves by delta;
-- bought_in only tracks positive intakes (restocks).
--
-- bought_in is clamped at 0 on reversal: it is a cumulative intake counter, and
-- `bought_in >= 0` is now a CHECK, so an unclamped subtraction would abort the
-- reversal outright.
create or replace function public.apply_stock_adjustment()
returns trigger
language plpgsql security definer set search_path = ''
as $$
declare
  cur_avail int;
begin
  if tg_op = 'DELETE' then
    update public.phone_models
       set available = available - old.delta,
           bought_in = greatest(bought_in - case when old.delta > 0 then old.delta else 0 end, 0)
     where id = old.phone_model_id;
    return old;
  end if;

  if tg_op = 'INSERT' then
    select available into cur_avail
      from public.phone_models where id = new.phone_model_id for update;
    if new.delta < 0 and coalesce(cur_avail, 0) < abs(new.delta) then
      raise exception 'Insufficient stock to correct: only % available', coalesce(cur_avail, 0)
        using errcode = 'P0001';
    end if;
    update public.phone_models
       set available = available + new.delta,
           bought_in = bought_in + case when new.delta > 0 then new.delta else 0 end
     where id = new.phone_model_id;
    return new;
  end if;

  if tg_op = 'UPDATE' then
    update public.phone_models
       set available = available - old.delta,
           bought_in = greatest(bought_in - case when old.delta > 0 then old.delta else 0 end, 0)
     where id = old.phone_model_id;
    select available into cur_avail
      from public.phone_models where id = new.phone_model_id for update;
    if new.delta < 0 and coalesce(cur_avail, 0) < abs(new.delta) then
      raise exception 'Insufficient stock to correct: only % available', coalesce(cur_avail, 0)
        using errcode = 'P0001';
    end if;
    update public.phone_models
       set available = available + new.delta,
           bought_in = bought_in + case when new.delta > 0 then new.delta else 0 end
     where id = new.phone_model_id;
    return new;
  end if;

  return null;
end;
$$;

drop trigger if exists stock_adjustment_change on public.stock_adjustments;
create trigger stock_adjustment_change
  after insert or update or delete on public.stock_adjustments
  for each row execute function public.apply_stock_adjustment();

-- ---------------------------------------------------------------------------
-- RPC: record_transaction
-- Atomic recording of a sale/swap/repair, including all stock side-effects.
-- p_out_items: jsonb array of {"phone_model_id", "qty"}
-- p_in_items : jsonb array of {"model_name","condition","cost_price","sale_price","qty"}
--              or {"phone_model_id","qty"} if the swap-in model already exists.
-- Attendants may only post to their own shop; the owner may post to any shop.
-- ---------------------------------------------------------------------------
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
  p_swap_in jsonb default '[]'::jsonb
) returns uuid
language plpgsql security definer set search_path = ''
as $$
declare
  v_me         public.users;
  v_role       public.user_role;
  v_staff_id   uuid;
  v_tx_id      uuid;
  v_item       jsonb;
  v_model_id   uuid;
  v_condition  public.phone_condition;
  v_avail      int;
  v_qty        int;
begin
  v_me := public.require_profile();
  v_staff_id := v_me.id;
  v_role := v_me.role;

  if v_role is distinct from 'owner'::public.user_role then
    if p_shop_id is distinct from v_me.shop_id then
      raise exception 'Not allowed to record transactions for this shop' using errcode = 'P0001';
    end if;
  end if;

  if p_amount is null or p_amount < 0 then
    raise exception 'Amount must be 0 or more' using errcode = 'P0001';
  end if;

  -- Idempotency: if this key was already used, return the existing transaction
  -- (no double stock deduction). Scoped to the shop so a key collision can
  -- never hand back another shop's transaction id — this runs as SECURITY
  -- DEFINER, so RLS would not catch that.
  if p_idempotency_key is not null then
    select t.id into v_tx_id
      from public.transactions t
     where t.idempotency_key = p_idempotency_key
       and t.shop_id = p_shop_id;
    if v_tx_id is not null then
      return v_tx_id;
    end if;
  end if;

  insert into public.transactions (shop_id, staff_id, customer_name, customer_phone, type, payment_method, amount, date, idempotency_key)
  values (p_shop_id, v_staff_id, p_customer_name, p_customer_phone, p_type, p_payment_method, p_amount, p_date, p_idempotency_key)
  returning id into v_tx_id;

  -- outgoing stock
  -- Locked in phone_model_id order: two concurrent multi-model transactions
  -- touching the same models in opposite payload order would otherwise deadlock.
  for v_item in
    select value
      from jsonb_array_elements(coalesce(p_out_items, '[]'::jsonb)) as t(value)
     order by (value ->> 'phone_model_id')
  loop
    v_qty := coalesce((v_item ->> 'qty')::int, 1);
    if v_qty <= 0 then
      raise exception 'Quantity must be greater than 0' using errcode = 'P0001';
    end if;

    select available into v_avail
      from public.phone_models
     where id = (v_item ->> 'phone_model_id')::uuid
       and shop_id = p_shop_id
       for update;
    if v_avail is null then
      raise exception 'Unknown phone model for this shop' using errcode = 'P0001';
    end if;
    if v_avail < v_qty then
      raise exception 'Insufficient stock: only % available for this model', v_avail using errcode = 'P0001';
    end if;
    insert into public.transaction_items (transaction_id, phone_model_id, direction, qty)
    values (v_tx_id, (v_item ->> 'phone_model_id')::uuid, 'out', v_qty);
  end loop;

  -- incoming stock (swap-ins)
  for v_item in select * from jsonb_array_elements(coalesce(p_in_items, '[]'::jsonb)) loop
    v_qty := coalesce((v_item ->> 'qty')::int, 1);
    if v_qty <= 0 then
      raise exception 'Quantity must be greater than 0' using errcode = 'P0001';
    end if;
    v_condition := coalesce((v_item ->> 'condition')::public.phone_condition, 'used'::public.phone_condition);

    if (v_item ->> 'phone_model_id') is not null then
      -- Must be a model in THIS shop, else a swap-in would inflate another
      -- shop's stock.
      select id into v_model_id
        from public.phone_models
       where id = (v_item ->> 'phone_model_id')::uuid
         and shop_id = p_shop_id;
      if v_model_id is null then
        raise exception 'Unknown phone model for this shop' using errcode = 'P0001';
      end if;
    else
      select id into v_model_id
        from public.phone_models
       where shop_id = p_shop_id
         and model_name = (v_item ->> 'model_name')
         and condition = v_condition;
      if v_model_id is null then
        insert into public.phone_models (shop_id, model_name, condition, cost_price, sale_price, opening_stock, bought_in, available)
        values (p_shop_id, (v_item ->> 'model_name'), v_condition,
                (v_item ->> 'cost_price')::numeric,
                (v_item ->> 'sale_price')::numeric,
                0, 0, 0)
        returning id into v_model_id;
      end if;
    end if;

    insert into public.transaction_items (transaction_id, phone_model_id, direction, qty)
    values (v_tx_id, v_model_id, 'in', v_qty);
  end loop;

  -- Swap trade-ins: logged atomically with the transaction. Previously these
  -- were a separate client-side insert, so a failure there left the swap
  -- recorded but the trade-in phone missing from the swapped-phones list.
  for v_item in select * from jsonb_array_elements(coalesce(p_swap_in, '[]'::jsonb)) loop
    if coalesce(v_item ->> 'model_name', '') = '' then
      continue;
    end if;
    insert into public.swapped_phones (shop_id, transaction_id, staff_id, model_name, customer_name, customer_phone)
    values (
      p_shop_id,
      v_tx_id,
      v_staff_id,
      (v_item ->> 'model_name'),
      (v_item ->> 'customer_name'),
      (v_item ->> 'customer_phone')
    );
  end loop;

  return v_tx_id;
end;
$$;

revoke all on function public.record_transaction(uuid, text, text, public.tx_type, public.payment_method, numeric, timestamptz, jsonb, jsonb, uuid, jsonb) from public;
revoke all on function public.record_transaction(uuid, text, text, public.tx_type, public.payment_method, numeric, timestamptz, jsonb, jsonb, uuid, jsonb) from anon;
grant execute on function public.record_transaction(uuid, text, text, public.tx_type, public.payment_method, numeric, timestamptz, jsonb, jsonb, uuid, jsonb) to authenticated;

-- ---------------------------------------------------------------------------
-- RPC: adjust_stock  (restock / manual correction)
-- Allowed for the owner and for staff the owner granted stock-editing
-- privileges to (users.can_edit_stock). Everyone else must request stock
-- changes and the owner approves them via approve_stock_request.
-- ---------------------------------------------------------------------------
create or replace function public.adjust_stock(
  p_shop_id uuid,
  p_phone_model_id uuid,
  p_delta int,
  p_type public.adjustment_type default 'restock',
  p_reason text default null
) returns uuid
language plpgsql security definer set search_path = ''
as $$
declare
  v_me        public.users;
  v_id        uuid;
begin
  v_me := public.require_profile();

  if v_me.role is distinct from 'owner'::public.user_role
     and not coalesce(v_me.can_edit_stock, false) then
    raise exception 'Only owners or staff with stock privileges can adjust stock directly' using errcode = 'P0001';
  end if;

  -- Privileged attendants are still confined to their own shop.
  if v_me.role is distinct from 'owner'::public.user_role
     and p_shop_id is distinct from v_me.shop_id then
    raise exception 'Not allowed to adjust stock for this shop' using errcode = 'P0001';
  end if;

  if p_delta is null or p_delta = 0 then
    raise exception 'Adjustment quantity must not be zero' using errcode = 'P0001';
  end if;

  if not exists (select 1 from public.phone_models where id = p_phone_model_id and shop_id = p_shop_id) then
    raise exception 'Phone model does not belong to this shop' using errcode = 'P0001';
  end if;

  insert into public.stock_adjustments (shop_id, phone_model_id, staff_id, type, delta, reason)
  values (p_shop_id, p_phone_model_id, v_me.id, p_type, p_delta, p_reason)
  returning id into v_id;

  return v_id;
end;
$$;

revoke all on function public.adjust_stock(uuid, uuid, int, public.adjustment_type, text) from public;
revoke all on function public.adjust_stock(uuid, uuid, int, public.adjustment_type, text) from anon;
grant execute on function public.adjust_stock(uuid, uuid, int, public.adjustment_type, text) to authenticated;

-- ---------------------------------------------------------------------------
-- RPC: bulk_adjust_stock  (set target quantities for many models atomically)
-- p_items: jsonb array of {"phone_model_id", "target_qty"}
--
-- Replaces the previous app-side loop, which read `available`, computed deltas,
-- then fired one adjust_stock per model: a sale landing mid-loop made the
-- result wrong, and a mid-loop failure left earlier deltas committed with no
-- rollback. Here every model is locked before any delta is written, so the whole
-- batch commits or none of it does.
-- ---------------------------------------------------------------------------
create or replace function public.bulk_adjust_stock(
  p_shop_id uuid,
  p_items jsonb,
  p_reason text default null
) returns int
language plpgsql security definer set search_path = ''
as $$
declare
  v_me      public.users;
  v_item    jsonb;
  v_model   uuid;
  v_target  int;
  v_avail   int;
  v_delta   int;
  v_changes int := 0;
begin
  v_me := public.require_profile();

  if v_me.role is distinct from 'owner'::public.user_role
     and not coalesce(v_me.can_edit_stock, false) then
    raise exception 'Only owners or staff with stock privileges can adjust stock directly' using errcode = 'P0001';
  end if;

  if v_me.role is distinct from 'owner'::public.user_role
     and p_shop_id is distinct from v_me.shop_id then
    raise exception 'Not allowed to adjust stock for this shop' using errcode = 'P0001';
  end if;

  -- Ordered lock acquisition keeps concurrent bulk edits from deadlocking.
  for v_item in
    select value
      from jsonb_array_elements(coalesce(p_items, '[]'::jsonb)) as t(value)
     order by (value ->> 'phone_model_id')
  loop
    v_model  := (v_item ->> 'phone_model_id')::uuid;
    v_target := (v_item ->> 'target_qty')::int;

    if v_target is null or v_target < 0 then
      raise exception 'Target quantity must be 0 or more' using errcode = 'P0001';
    end if;

    select available into v_avail
      from public.phone_models
     where id = v_model and shop_id = p_shop_id
       for update;
    if v_avail is null then
      raise exception 'Phone model does not belong to this shop' using errcode = 'P0001';
    end if;

    v_delta := v_target - v_avail;
    if v_delta = 0 then
      continue;
    end if;

    insert into public.stock_adjustments (shop_id, phone_model_id, staff_id, type, delta, reason)
    values (p_shop_id, v_model, v_me.id,
            case when v_delta > 0 then 'restock'::public.adjustment_type
                 else 'correction'::public.adjustment_type end,
            v_delta, p_reason);
    v_changes := v_changes + 1;
  end loop;

  return v_changes;
end;
$$;

revoke all on function public.bulk_adjust_stock(uuid, jsonb, text) from public;
revoke all on function public.bulk_adjust_stock(uuid, jsonb, text) from anon;
grant execute on function public.bulk_adjust_stock(uuid, jsonb, text) to authenticated;

-- ---------------------------------------------------------------------------
-- RPC: delete_transaction  (owner only — stock side-effects are reversed)
-- ---------------------------------------------------------------------------
create or replace function public.delete_transaction(p_transaction_id uuid)
returns void
language plpgsql security definer set search_path = ''
as $$
begin
  perform public.require_owner();

  delete from public.transactions where id = p_transaction_id;
  if not found then
    raise exception 'Transaction not found' using errcode = 'P0001';
  end if;
end;
$$;

revoke all on function public.delete_transaction(uuid) from public;
revoke all on function public.delete_transaction(uuid) from anon;
grant execute on function public.delete_transaction(uuid) to authenticated;

-- ---------------------------------------------------------------------------
-- RPC: restore_backup  (owner only — full data restore from a backup file)
-- Replaces ALL data with the contents of the backup. Stock triggers are
-- disabled while loading so the saved `available` values are preserved (they
-- are authoritative). Runs as a single transaction — a bad file rolls back
-- completely.
-- p_data: jsonb shaped like the backup file produced by the /settings/backup
-- route: { shops[], users[], phone_models[], transactions[],
--         transaction_items[], stock_adjustments[] }
--
-- Hardening notes:
--   * The payload is fully shape-checked BEFORE the first DELETE. Previously
--     only `p_data ? 'shops'` was checked, so a file missing `users` wiped every
--     profile — including the caller's — and locked everyone out permanently.
--   * Deletes run in FK order and include stock_requests / swapped_phones /
--     stock_logs / login_logs, whose staff_id FKs are not cascading. Without
--     that the DELETE on users raised a FK violation whenever any of those rows
--     existed, so restore simply never worked on a live database.
--   * The caller's own profile is always re-asserted as owner afterwards, so a
--     backup from another project can't lock them out of their own database.
--   * `available` is reconciled against the invariant after load, so a stale or
--     hand-edited backup cannot leave stock permanently wrong.
--
-- ALTER TABLE ... DISABLE TRIGGER is cluster-wide rather than session-local, but
-- it takes an ACCESS EXCLUSIVE lock, so concurrent writers block until this
-- transaction ends rather than slipping through with triggers off. DDL is also
-- transactional in Postgres: on any failure the disable is rolled back with the
-- data, so the re-enable below is for the success path only.
-- ---------------------------------------------------------------------------
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

  -- Validate the whole payload before destroying anything.
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

  -- WHERE true keeps Supabase's "safe delete" guard happy (DELETE without a
  -- WHERE clause is rejected by the platform).
  -- Order matters: everything referencing users/shops must go first.
  delete from public.stock_logs where true;
  delete from public.login_logs where true;
  delete from public.stock_requests where true;
  delete from public.swapped_phones where true;
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

  -- Only restore profiles whose auth account still exists (same project).
  for v_rec in select * from jsonb_array_elements(coalesce(p_data -> 'users', '[]'::jsonb)) loop
    if exists (select 1 from auth.users where id = (v_rec ->> 'id')::uuid) then
      insert into public.users (id, name, role, shop_id, can_edit_stock, created_at)
      values ((v_rec ->> 'id')::uuid,
              coalesce(v_rec ->> 'name', ''),
              coalesce((v_rec ->> 'role')::public.user_role, 'attendant'),
              nullif(v_rec ->> 'shop_id', '')::uuid,
              coalesce((v_rec ->> 'can_edit_stock')::boolean, false),
              coalesce((v_rec ->> 'created_at')::timestamptz, now()))
      on conflict (id) do nothing;
    end if;
  end loop;

  -- Never let a foreign backup lock the caller out of their own database.
  insert into public.users (id, name, role)
  values (v_me.id, v_me.name, 'owner')
  on conflict (id) do update
     set role = 'owner', shop_id = null;

  for v_rec in select * from jsonb_array_elements(coalesce(p_data -> 'phone_models', '[]'::jsonb)) loop
    insert into public.phone_models
      (id, shop_id, model_name, condition, cost_price, sale_price,
       opening_stock, bought_in, available, low_stock_threshold, created_at)
    values ((v_rec ->> 'id')::uuid,
            (v_rec ->> 'shop_id')::uuid,
            v_rec ->> 'model_name',
            coalesce((v_rec ->> 'condition')::public.phone_condition, 'new'),
            (v_rec ->> 'cost_price')::numeric,
            (v_rec ->> 'sale_price')::numeric,
            greatest(coalesce((v_rec ->> 'opening_stock')::int, 0), 0),
            greatest(coalesce((v_rec ->> 'bought_in')::int, 0), 0),
            greatest(coalesce((v_rec ->> 'available')::int, 0), 0),
            greatest(coalesce((v_rec ->> 'low_stock_threshold')::int, 5), 0),
            coalesce((v_rec ->> 'created_at')::timestamptz, now()));
  end loop;

  for v_rec in select * from jsonb_array_elements(coalesce(p_data -> 'transactions', '[]'::jsonb)) loop
    insert into public.transactions
      (id, shop_id, staff_id, customer_name, customer_phone, type,
       payment_method, amount, date, created_at)
    values ((v_rec ->> 'id')::uuid,
            (v_rec ->> 'shop_id')::uuid,
            coalesce((select u.id from public.users u
                       where u.id = nullif(v_rec ->> 'staff_id', '')::uuid),
                     v_me.id),
            v_rec ->> 'customer_name',
            v_rec ->> 'customer_phone',
            coalesce((v_rec ->> 'type')::public.tx_type, 'sale'),
            coalesce((v_rec ->> 'payment_method')::public.payment_method, 'cash'),
            greatest(coalesce((v_rec ->> 'amount')::numeric, 0), 0),
            coalesce((v_rec ->> 'date')::timestamptz, now()),
            coalesce((v_rec ->> 'created_at')::timestamptz, now()));
  end loop;

  for v_rec in select * from jsonb_array_elements(coalesce(p_data -> 'transaction_items', '[]'::jsonb)) loop
    insert into public.transaction_items (id, transaction_id, phone_model_id, direction, qty)
    values ((v_rec ->> 'id')::uuid,
            (v_rec ->> 'transaction_id')::uuid,
            (v_rec ->> 'phone_model_id')::uuid,
            (v_rec ->> 'direction')::public.item_direction,
            greatest(coalesce((v_rec ->> 'qty')::int, 1), 1));
  end loop;

  for v_rec in select * from jsonb_array_elements(coalesce(p_data -> 'stock_adjustments', '[]'::jsonb)) loop
    -- delta = 0 rows are skipped: they are no-ops and would trip the
    -- `delta <> 0` CHECK, failing the whole restore.
    if coalesce((v_rec ->> 'delta')::int, 0) <> 0 then
      insert into public.stock_adjustments
        (id, shop_id, phone_model_id, staff_id, type, delta, reason, date)
      values ((v_rec ->> 'id')::uuid,
              (v_rec ->> 'shop_id')::uuid,
              (v_rec ->> 'phone_model_id')::uuid,
              coalesce((select u.id from public.users u
                         where u.id = nullif(v_rec ->> 'staff_id', '')::uuid),
                       v_me.id),
              coalesce((v_rec ->> 'type')::public.adjustment_type, 'restock'),
              (v_rec ->> 'delta')::int,
              v_rec ->> 'reason',
              coalesce((v_rec ->> 'date')::timestamptz, now()));
    end if;
  end loop;

  alter table public.transaction_items enable trigger item_stock_change;
  alter table public.stock_adjustments enable trigger stock_adjustment_change;

  -- Reconcile `available` with the invariant. The backup's own value is trusted
  -- first, but a stale or hand-edited file must not leave stock permanently
  -- wrong, so any row that disagrees with its own line items is repaired.
  with computed as (
    select m.id,
           greatest(
             m.opening_stock + m.bought_in
             + coalesce((select sum(i.qty) from public.transaction_items i
                          where i.phone_model_id = m.id and i.direction = 'in'), 0)
             - coalesce((select sum(i.qty) from public.transaction_items i
                          where i.phone_model_id = m.id and i.direction = 'out'), 0)
             - coalesce((select sum(-a.delta) from public.stock_adjustments a
                          where a.phone_model_id = m.id and a.delta < 0), 0),
             0)::int as expected
      from public.phone_models m
  )
  update public.phone_models m
     set available = c.expected
    from computed c
   where c.id = m.id and m.available <> c.expected;
  get diagnostics v_repaired = row_count;

  select count(*) into v_rows from public.transactions;
  return jsonb_build_object(
    'restored', true,
    'transactions', v_rows,
    'stock_repaired', v_repaired
  );
end;
$$;

revoke all on function public.restore_backup(jsonb) from public;
revoke all on function public.restore_backup(jsonb) from anon;
grant execute on function public.restore_backup(jsonb) to authenticated;

-- ---------------------------------------------------------------------------
-- stock_requests  (attendant stock-change approvals)
-- Attendant stock changes (new models + restocks/corrections) are recorded
-- here as PENDING; only the owner can approve/reject them. Approved changes
-- are applied by approve_stock_request.
-- ---------------------------------------------------------------------------
create table public.stock_requests (
  id                  uuid primary key default gen_random_uuid(),
  shop_id             uuid not null references public.shops (id) on delete cascade,
  staff_id            uuid not null references public.users (id) on delete restrict,
  type                text not null check (type in ('create_model', 'adjust_stock')),
  status              text not null default 'pending' check (status in ('pending', 'approved', 'rejected')),
  -- create_model payload
  model_name          text,
  condition           public.phone_condition,
  cost_price          numeric(12,2) check (cost_price is null or cost_price >= 0),
  sale_price          numeric(12,2) check (sale_price is null or sale_price >= 0),
  low_stock_threshold int check (low_stock_threshold is null or low_stock_threshold >= 0),
  opening_stock       int check (opening_stock is null or opening_stock >= 0),
  -- adjust_stock payload
  phone_model_id      uuid references public.phone_models (id) on delete cascade,
  delta               int,
  reason              text,
  created_at          timestamptz not null default now(),
  decided_at          timestamptz,
  decided_by          uuid references public.users (id) on delete set null,
  error_note          text,
  -- Each request type must carry its own payload and nothing else's.
  constraint stock_requests_payload_ck check (
    (type = 'create_model' and model_name is not null and phone_model_id is null and delta is null)
    or
    (type = 'adjust_stock' and phone_model_id is not null and delta is not null and delta <> 0)
  )
);

create index stock_requests_shop_status_idx on public.stock_requests (shop_id, status);
create index stock_requests_status_idx on public.stock_requests (status);
create index stock_requests_staff_idx on public.stock_requests (staff_id);
create index stock_requests_model_idx on public.stock_requests (phone_model_id);

-- A request must not name a model from another shop. RLS on stock_requests only
-- validates shop_id, and approve_stock_request applies the row verbatim, so
-- without this an attendant could file a request against another shop's model
-- and have the owner unknowingly approve a cross-shop stock movement.
create or replace function public.enforce_request_shop_match()
returns trigger
language plpgsql security definer set search_path = ''
as $$
declare
  v_model_shop uuid;
begin
  if new.phone_model_id is null then
    return new;
  end if;

  select m.shop_id into v_model_shop
    from public.phone_models m where m.id = new.phone_model_id;
  if v_model_shop is null then
    raise exception 'Unknown phone model' using errcode = 'P0001';
  end if;
  if v_model_shop <> new.shop_id then
    raise exception 'Phone model does not belong to this shop' using errcode = 'P0001';
  end if;

  return new;
end;
$$;

drop trigger if exists request_shop_match on public.stock_requests;
create trigger request_shop_match
  before insert or update on public.stock_requests
  for each row execute function public.enforce_request_shop_match();

-- Owner-only: apply a pending request (creates the model or moves stock).
create or replace function public.approve_stock_request(p_request_id uuid)
returns void
language plpgsql security definer set search_path = ''
as $$
declare
  v_me  public.users;
  v_rec public.stock_requests;
begin
  v_me := public.require_owner();

  -- Locked so two concurrent approvals cannot both apply the same request.
  select * into v_rec from public.stock_requests
   where id = p_request_id and status = 'pending'
     for update;
  if v_rec.id is null then
    raise exception 'Pending stock request not found' using errcode = 'P0001';
  end if;

  if v_rec.type = 'create_model' then
    if exists (
      select 1 from public.phone_models
      where shop_id = v_rec.shop_id
        and model_name = v_rec.model_name
        and condition = v_rec.condition
    ) then
      raise exception 'A model with this name and condition already exists' using errcode = 'P0001';
    end if;
    insert into public.phone_models
      (shop_id, model_name, condition, cost_price, sale_price, opening_stock, bought_in, available, low_stock_threshold)
    values (v_rec.shop_id, v_rec.model_name, v_rec.condition, v_rec.cost_price, v_rec.sale_price,
            coalesce(v_rec.opening_stock, 0), 0, coalesce(v_rec.opening_stock, 0),
            coalesce(v_rec.low_stock_threshold, 5));
  elsif v_rec.type = 'adjust_stock' then
    -- Re-check shop ownership at approval time: the model could have been
    -- reassigned between request and approval.
    if not exists (
      select 1 from public.phone_models
       where id = v_rec.phone_model_id and shop_id = v_rec.shop_id
    ) then
      raise exception 'Phone model does not belong to this shop' using errcode = 'P0001';
    end if;
    insert into public.stock_adjustments (shop_id, phone_model_id, staff_id, type, delta, reason)
    values (v_rec.shop_id, v_rec.phone_model_id, v_rec.staff_id,
            case when v_rec.delta > 0 then 'restock'::public.adjustment_type else 'correction'::public.adjustment_type end,
            v_rec.delta, v_rec.reason);
  end if;

  update public.stock_requests
     set status = 'approved', decided_at = now(), decided_by = v_me.id, error_note = null
   where id = p_request_id;
end;
$$;

revoke all on function public.approve_stock_request(uuid) from public;
revoke all on function public.approve_stock_request(uuid) from anon;
grant execute on function public.approve_stock_request(uuid) to authenticated;

-- Owner-only: reject a pending request (no stock change).
create or replace function public.reject_stock_request(p_request_id uuid)
returns void
language plpgsql security definer set search_path = ''
as $$
declare
  v_me public.users;
begin
  v_me := public.require_owner();

  update public.stock_requests
     set status = 'rejected', decided_at = now(), decided_by = v_me.id, error_note = null
   where id = p_request_id and status = 'pending';
  if not found then
    raise exception 'Pending stock request not found' using errcode = 'P0001';
  end if;
end;
$$;

revoke all on function public.reject_stock_request(uuid) from public;
revoke all on function public.reject_stock_request(uuid) from anon;
grant execute on function public.reject_stock_request(uuid) to authenticated;

-- Owner-only: approve every pending request at once (optionally for one shop).
-- Requests that fail to apply (e.g. duplicate model, insufficient stock) stay
-- pending and record why in error_note.
create or replace function public.approve_all_stock_requests(p_shop_id uuid default null)
returns table (approved bigint, failed bigint)
language plpgsql security definer set search_path = ''
as $$
declare
  v_me       public.users;
  v_rec      public.stock_requests;
  v_approved bigint := 0;
  v_failed   bigint := 0;
begin
  v_me := public.require_owner();

  for v_rec in
    select * from public.stock_requests
     where status = 'pending'
       and (p_shop_id is null or shop_id = p_shop_id)
     order by created_at
  loop
    begin
      if v_rec.type = 'create_model' then
        if exists (
          select 1 from public.phone_models
          where shop_id = v_rec.shop_id
            and model_name = v_rec.model_name
            and condition = v_rec.condition
        ) then
          raise exception 'A model with this name and condition already exists';
        end if;
        insert into public.phone_models
          (shop_id, model_name, condition, cost_price, sale_price, opening_stock, bought_in, available, low_stock_threshold)
        values (v_rec.shop_id, v_rec.model_name, v_rec.condition, v_rec.cost_price, v_rec.sale_price,
                coalesce(v_rec.opening_stock, 0), 0, coalesce(v_rec.opening_stock, 0),
                coalesce(v_rec.low_stock_threshold, 5));
      elsif v_rec.type = 'adjust_stock' then
        if not exists (
          select 1 from public.phone_models
           where id = v_rec.phone_model_id and shop_id = v_rec.shop_id
        ) then
          raise exception 'Phone model does not belong to this shop';
        end if;
        insert into public.stock_adjustments (shop_id, phone_model_id, staff_id, type, delta, reason)
        values (v_rec.shop_id, v_rec.phone_model_id, v_rec.staff_id,
                case when v_rec.delta > 0 then 'restock'::public.adjustment_type else 'correction'::public.adjustment_type end,
                v_rec.delta, v_rec.reason);
      end if;

      update public.stock_requests
         set status = 'approved', decided_at = now(), decided_by = v_me.id, error_note = null
       where id = v_rec.id;
      v_approved := v_approved + 1;
    exception when others then
      v_failed := v_failed + 1;
      update public.stock_requests
         set error_note = left(sqlerrm, 300)
       where id = v_rec.id;
    end;
  end loop;

  return query select v_approved, v_failed;
end;
$$;

revoke all on function public.approve_all_stock_requests(uuid) from public;
revoke all on function public.approve_all_stock_requests(uuid) from anon;
grant execute on function public.approve_all_stock_requests(uuid) to authenticated;

-- ============================================================================
-- Row Level Security
-- ============================================================================
alter table public.shops            enable row level security;
alter table public.users            enable row level security;
alter table public.phone_models     enable row level security;
alter table public.transactions     enable row level security;
alter table public.transaction_items enable row level security;
alter table public.stock_adjustments enable row level security;
alter table public.stock_requests   enable row level security;

-- helper used by policies below: current user's profile.
-- Policies call this as `(select public.current_user_profile())` so Postgres
-- caches it as an InitPlan once per statement instead of re-evaluating per row.
create or replace function public.current_user_profile()
returns public.user_profile_t
language sql stable security definer set search_path = ''
as $$
  select id, role, shop_id from public.users where id = auth.uid()
$$;

-- Whether the current user may edit stock directly (owner, or staff the owner
-- granted the privilege to).
create or replace function public.current_user_can_edit_stock()
returns boolean
language sql stable security definer set search_path = ''
as $$
  select coalesce(
    (select u.role = 'owner' or u.can_edit_stock
       from public.users u where u.id = auth.uid()),
    false)
$$;

revoke all on function public.require_profile() from public, anon;
revoke all on function public.require_owner() from public, anon;
revoke all on function public.current_user_profile() from public, anon;
revoke all on function public.current_user_can_edit_stock() from public, anon;
grant execute on function public.current_user_profile() to authenticated;
grant execute on function public.current_user_can_edit_stock() to authenticated;

-- ---------------------------------------------------------------------------
-- Table privileges
--
-- Supabase's default privileges grant anon and authenticated full DML on new
-- public tables, so these REVOKEs are what actually keeps anon out — RLS alone
-- would also do it here, but a single missing policy should not become a breach.
--
-- The important line is the column list on phone_models: `available`,
-- `opening_stock` and `bought_in` are deliberately absent, so NO client role can
-- ever UPDATE them. Stock moves only through the trigger functions, which are
-- SECURITY DEFINER and run as the table owner, bypassing these grants.
-- ---------------------------------------------------------------------------
revoke all on public.shops             from anon;
revoke all on public.users             from anon;
revoke all on public.phone_models      from anon;
revoke all on public.transactions      from anon;
revoke all on public.transaction_items from anon;
revoke all on public.stock_adjustments from anon;
revoke all on public.stock_requests    from anon;

revoke all on public.shops             from authenticated;
revoke all on public.users             from authenticated;
revoke all on public.phone_models      from authenticated;
revoke all on public.transactions      from authenticated;
revoke all on public.transaction_items from authenticated;
revoke all on public.stock_adjustments from authenticated;
revoke all on public.stock_requests    from authenticated;

grant select, insert, update, delete on public.shops to authenticated;
grant select, insert, delete         on public.users to authenticated;
-- `role` is deliberately excluded: no client role may ever change a user's role,
-- not even the owner's session. Role assignment happens only through the
-- service-role admin client (setupOwner / createStaff), so a stolen owner
-- session cannot mint another owner, and RLS is not the only thing standing
-- between an attendant and `role = 'owner'`.
grant update (name, shop_id, can_edit_stock) on public.users to authenticated;
grant select, insert, delete         on public.phone_models to authenticated;
grant update (model_name, condition, cost_price, sale_price, low_stock_threshold)
  on public.phone_models to authenticated;
grant select, insert, update, delete on public.transactions to authenticated;
grant select, insert, update, delete on public.transaction_items to authenticated;
grant select, insert, update, delete on public.stock_adjustments to authenticated;
grant select, insert, update, delete on public.stock_requests to authenticated;

-- New models always start consistent: available is derived, never client-supplied.
-- Without this, a privileged attendant could insert a model with opening_stock 0
-- and available 9999 and fabricate inventory in a single INSERT.
create or replace function public.normalize_model_stock()
returns trigger
language plpgsql security definer set search_path = ''
as $$
begin
  new.bought_in := greatest(coalesce(new.bought_in, 0), 0);
  new.opening_stock := greatest(coalesce(new.opening_stock, 0), 0);
  new.available := new.opening_stock + new.bought_in;
  return new;
end;
$$;

drop trigger if exists model_stock_normalize on public.phone_models;
create trigger model_stock_normalize
  before insert on public.phone_models
  for each row execute function public.normalize_model_stock();

-- ---------- shops ----------
create policy "shops: owner full access" on public.shops
  for all using (((select public.current_user_profile())).role = 'owner')
  with check (((select public.current_user_profile())).role = 'owner');

create policy "shops: attendant sees own shop" on public.shops
  for select using (((select public.current_user_profile())).shop_id = id);

-- ---------- users ----------
create policy "users: owner full access" on public.users
  for all using (((select public.current_user_profile())).role = 'owner')
  with check (((select public.current_user_profile())).role = 'owner');

create policy "users: read own row" on public.users
  for select using (auth.uid() = id);

create policy "users: attendant reads staff in own shop" on public.users
  for select using (((select public.current_user_profile())).shop_id = shop_id);

-- No self-UPDATE policy: a user must never be able to edit their own row, or
-- they could set role = 'owner' or move themselves to another shop. Profile
-- changes go through the owner (service-role admin client).

-- ---------- phone_models ----------
create policy "phone_models: owner full access" on public.phone_models
  for all using (((select public.current_user_profile())).role = 'owner')
  with check (((select public.current_user_profile())).role = 'owner');

create policy "phone_models: attendant reads own shop" on public.phone_models
  for select using (((select public.current_user_profile())).shop_id = shop_id);

-- Only staff the owner granted stock privileges to may add or edit models, and
-- only in their own shop. The column grant above still prevents them from
-- touching the stock counters.
create policy "phone_models: privileged staff adds own shop" on public.phone_models
  for insert with check (
    ((select public.current_user_profile())).shop_id = shop_id
    and (select public.current_user_can_edit_stock())
  );

create policy "phone_models: privileged staff edits own shop" on public.phone_models
  for update using (
    ((select public.current_user_profile())).shop_id = shop_id
    and (select public.current_user_can_edit_stock())
  )
  with check (
    ((select public.current_user_profile())).shop_id = shop_id
    and (select public.current_user_can_edit_stock())
  );

-- ---------- transactions ----------
create policy "transactions: owner full access" on public.transactions
  for all using (((select public.current_user_profile())).role = 'owner')
  with check (((select public.current_user_profile())).role = 'owner');

-- Attendants read only. There is deliberately no attendant INSERT policy:
-- writes go exclusively through record_transaction(), which pins staff_id to
-- auth.uid() and validates stock. A direct INSERT policy would let an attendant
-- attribute a transaction to a colleague with an arbitrary amount and date.
create policy "transactions: attendant reads own shop" on public.transactions
  for select using (((select public.current_user_profile())).shop_id = shop_id);

-- ---------- transaction_items ----------
-- Read-only for attendants. The old policy was FOR ALL, which let an attendant
-- DELETE an 'out' item to credit stock back, or INSERT an 'in' item to fabricate
-- it. Items are written only by record_transaction().
create policy "transaction_items: owner full access" on public.transaction_items
  for all using (((select public.current_user_profile())).role = 'owner')
  with check (((select public.current_user_profile())).role = 'owner');

create policy "transaction_items: attendant reads own shop" on public.transaction_items
  for select using (
    exists (
      select 1 from public.transactions t
      where t.id = transaction_id
        and t.shop_id = ((select public.current_user_profile())).shop_id
    )
  );

-- ---------- stock_adjustments ----------
-- Read-only for attendants; rows are written by adjust_stock() /
-- bulk_adjust_stock() / approve_stock_request(), which set staff_id themselves.
create policy "stock_adjustments: owner full access" on public.stock_adjustments
  for all using (((select public.current_user_profile())).role = 'owner')
  with check (((select public.current_user_profile())).role = 'owner');

create policy "stock_adjustments: attendant reads own shop" on public.stock_adjustments
  for select using (((select public.current_user_profile())).shop_id = shop_id);

-- ---------- stock_requests ----------
create policy "stock_requests: owner full access" on public.stock_requests
  for all using (((select public.current_user_profile())).role = 'owner')
  with check (((select public.current_user_profile())).role = 'owner');

-- Attendants file requests for their own shop, as themselves, always pending.
-- staff_id and status are pinned so a request cannot be pre-approved or filed
-- in a colleague's name.
create policy "stock_requests: attendant insert own shop" on public.stock_requests
  for insert with check (
    ((select public.current_user_profile())).role = 'attendant'
    and ((select public.current_user_profile())).shop_id = shop_id
    and staff_id = auth.uid()
    and status = 'pending'
    and decided_at is null
    and decided_by is null
  );

create policy "stock_requests: attendant reads own shop" on public.stock_requests
  for select using (((select public.current_user_profile())).shop_id = shop_id);

-- ---------------------------------------------------------------------------
-- swapped_phones  (old phones taken in during a swap; a separate list, NOT
-- merged into sellable stock). Populated by the swap flow in the app.
-- ---------------------------------------------------------------------------
create table public.swapped_phones (
  id              uuid primary key default gen_random_uuid(),
  shop_id         uuid not null references public.shops (id) on delete cascade,
  transaction_id  uuid references public.transactions (id) on delete set null,
  staff_id        uuid references public.users (id),
  model_name      text not null,
  condition       phone_condition not null default 'used',
  customer_name   text,
  customer_phone  text,
  status          text not null default 'in_stock' check (status in ('in_stock', 'sold', 'returned')),
  notes           text,
  created_at      timestamptz not null default now()
);

create index swapped_phones_shop_idx on public.swapped_phones (shop_id, created_at desc);
create index swapped_phones_tx_idx    on public.swapped_phones (transaction_id);
create index swapped_phones_staff_idx on public.swapped_phones (staff_id);

alter table public.swapped_phones enable row level security;
revoke all on public.swapped_phones from anon;
revoke all on public.swapped_phones from authenticated;
grant select, insert, update, delete on public.swapped_phones to authenticated;

create policy "swapped_phones: owner full access" on public.swapped_phones
  for all using (((select public.current_user_profile())).role = 'owner')
  with check (((select public.current_user_profile())).role = 'owner');

create policy "swapped_phones: attendant insert own shop" on public.swapped_phones
  for insert with check (
    ((select public.current_user_profile())).role = 'attendant'
    and ((select public.current_user_profile())).shop_id = shop_id
    and staff_id = auth.uid()
  );

create policy "swapped_phones: attendant reads own shop" on public.swapped_phones
  for select using (((select public.current_user_profile())).shop_id = shop_id);

-- ---------------------------------------------------------------------------
-- login_logs  (who signed in, from where, when — seen by the owner)
-- ---------------------------------------------------------------------------
create table public.login_logs (
  id          uuid primary key default gen_random_uuid(),
  user_id     uuid not null references public.users (id) on delete cascade,
  email       text,
  name        text,
  ip          text,
  user_agent  text,
  device      text,
  created_at  timestamptz not null default now()
);

create index login_logs_user_idx on public.login_logs (user_id, created_at desc);
create index login_logs_time_idx on public.login_logs (created_at desc);

-- Audit trail: append-only, and only the server may append.
-- Both log tables are written exclusively by the service-role admin client
-- (logStockEdit / the login action), which bypasses RLS, so no client role needs
-- INSERT. Removing it also removes the ability to spoof ip / user_agent / device,
-- and removing DELETE stops an owner from quietly erasing their own audit trail.
alter table public.login_logs enable row level security;
revoke all on public.login_logs from anon;
revoke all on public.login_logs from authenticated;
grant select on public.login_logs to authenticated;

create policy "login_logs: owner reads all" on public.login_logs
  for select using (((select public.current_user_profile())).role = 'owner');

create policy "login_logs: read own" on public.login_logs
  for select using (auth.uid() = user_id);

-- ---------------------------------------------------------------------------
-- stock_logs  (every stock edit: who, what, when — seen by the owner)
-- ---------------------------------------------------------------------------
create table public.stock_logs (
  id              uuid primary key default gen_random_uuid(),
  shop_id         uuid not null references public.shops (id) on delete cascade,
  phone_model_id  uuid references public.phone_models (id) on delete set null,
  staff_id        uuid not null references public.users (id) on delete cascade,
  action          text not null, -- create_model | update_model | adjust_stock | bulk_create
  model_name      text,
  condition       text,
  details         jsonb,         -- before/after, delta, reason
  created_at      timestamptz not null default now()
);

create index stock_logs_shop_idx on public.stock_logs (shop_id, created_at desc);
create index stock_logs_time_idx on public.stock_logs (created_at desc);
create index stock_logs_model_idx on public.stock_logs (phone_model_id);
create index stock_logs_staff_idx on public.stock_logs (staff_id);

alter table public.stock_logs enable row level security;
revoke all on public.stock_logs from anon;
revoke all on public.stock_logs from authenticated;
grant select on public.stock_logs to authenticated;

create policy "stock_logs: owner reads all" on public.stock_logs
  for select using (((select public.current_user_profile())).role = 'owner');

create policy "stock_logs: attendant reads own shop" on public.stock_logs
  for select using (((select public.current_user_profile())).shop_id = shop_id);

-- ---------------------------------------------------------------------------
-- Catch-all privilege lockdown
--
-- Postgres grants EXECUTE to PUBLIC on every CREATE FUNCTION, and Supabase's
-- default privileges grant anon/authenticated broadly. The per-function REVOKEs
-- above are explicit for documentation; this sweep is the backstop that catches
-- anything added later and forgotten. Run it LAST — it must come after every
-- create function / create table in this file.
-- ---------------------------------------------------------------------------
do $$
declare
  v_fn text;
begin
  for v_fn in
    select p.oid::regprocedure::text
      from pg_proc p
      join pg_namespace n on n.oid = p.pronamespace
     where n.nspname = 'public'
  loop
    execute format('revoke all on function %s from public', v_fn);
    execute format('revoke all on function %s from anon', v_fn);
  end loop;
end;
$$;

-- Re-grant only the RPCs the app actually calls.
grant execute on function public.record_transaction(uuid, text, text, public.tx_type, public.payment_method, numeric, timestamptz, jsonb, jsonb, uuid) to authenticated;
grant execute on function public.adjust_stock(uuid, uuid, int, public.adjustment_type, text) to authenticated;
grant execute on function public.bulk_adjust_stock(uuid, jsonb, text) to authenticated;
grant execute on function public.delete_transaction(uuid) to authenticated;
grant execute on function public.restore_backup(jsonb) to authenticated;
grant execute on function public.approve_stock_request(uuid) to authenticated;
grant execute on function public.reject_stock_request(uuid) to authenticated;
grant execute on function public.approve_all_stock_requests(uuid) to authenticated;
grant execute on function public.current_user_profile() to authenticated;
grant execute on function public.current_user_can_edit_stock() to authenticated;

-- Anything created in this schema from now on stays closed to anon by default.
alter default privileges in schema public revoke all on functions from public;
alter default privileges in schema public revoke all on functions from anon;
alter default privileges in schema public revoke all on tables from anon;

-- ---------------------------------------------------------------------------
-- Post-install verification
--
-- Fails loudly rather than leaving a silently-insecure database. Run the whole
-- file again after any schema edit to re-check.
-- ---------------------------------------------------------------------------
do $$
declare
  v_bad text;
begin
  -- 1. No public-schema function may be callable by anon or PUBLIC.
  select string_agg(p.oid::regprocedure::text, ', ')
    into v_bad
    from pg_proc p
    join pg_namespace n on n.oid = p.pronamespace
   where n.nspname = 'public'
     and (has_function_privilege('anon', p.oid, 'execute')
          or coalesce(
               (select bool_or(a.grantee = 0)
                  from aclexplode(p.proacl) a
                 where a.privilege_type = 'EXECUTE'),
               false));
  if v_bad is not null then
    raise exception 'SECURITY: functions still executable by anon/PUBLIC: %', v_bad;
  end if;

  -- 2. Every table holding business data must have RLS on.
  select string_agg(c.relname, ', ')
    into v_bad
    from pg_class c
    join pg_namespace n on n.oid = c.relnamespace
   where n.nspname = 'public' and c.relkind = 'r' and not c.relrowsecurity;
  if v_bad is not null then
    raise exception 'SECURITY: RLS not enabled on: %', v_bad;
  end if;

  -- 3. No client role may write the derived stock columns.
  if has_column_privilege('authenticated', 'public.phone_models', 'available', 'update')
     or has_column_privilege('authenticated', 'public.phone_models', 'opening_stock', 'update')
     or has_column_privilege('authenticated', 'public.phone_models', 'bought_in', 'update') then
    raise exception 'SECURITY: authenticated can UPDATE derived stock columns on phone_models';
  end if;

  raise notice 'Schema verification passed.';
end;
$$;

-- ---------------------------------------------------------------------------
-- Realtime
-- ---------------------------------------------------------------------------
do $$
begin
  begin
    alter publication supabase_realtime add table public.shops, public.phone_models,
      public.transactions, public.transaction_items, public.stock_adjustments,
      public.swapped_phones;
  exception when duplicate_object or undefined_object then
    raise notice 'realtime publication skipped (already subscribed or publication missing)';
  end;
end;
$$;
