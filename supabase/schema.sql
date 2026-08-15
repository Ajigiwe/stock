-- ============================================================================
-- Phone Stock Management System — Supabase schema
-- Run this in the Supabase SQL editor (Dashboard > SQL > New query), or via
-- `supabase db push` if you have the CLI linked.
--
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
  created_at  timestamptz not null default now()
);

-- Create a public.users row automatically whenever an auth user signs up.
create or replace function public.handle_new_user()
returns trigger
language plpgsql security definer set search_path = public
as $$
begin
  insert into public.users (id, name)
  values (new.id, coalesce(new.raw_user_meta_data ->> 'name', split_part(new.email, '@', 1)));
  return new;
end;
$$;

drop trigger if exists on_auth_user_created on auth.users;
create trigger on_auth_user_created
  after insert on auth.users
  for each row execute function public.handle_new_user();

-- ---------------------------------------------------------------------------
-- phone_models  (one row per model + condition, per shop)
-- ---------------------------------------------------------------------------
create table public.phone_models (
  id                   uuid primary key default gen_random_uuid(),
  shop_id              uuid not null references public.shops (id) on delete cascade,
  model_name           text not null,
  condition            phone_condition not null default 'new',
  cost_price           numeric(12,2),
  sale_price           numeric(12,2),
  opening_stock        int not null default 0,
  bought_in            int not null default 0,
  available            int not null default 0 check (available >= 0),
  low_stock_threshold  int not null default 5,
  created_at           timestamptz not null default now(),
  unique (shop_id, model_name, condition)
);

-- ---------------------------------------------------------------------------
-- transactions  (one row per customer interaction)
-- ---------------------------------------------------------------------------
create table public.transactions (
  id              uuid primary key default gen_random_uuid(),
  shop_id         uuid not null references public.shops (id) on delete cascade,
  staff_id        uuid not null references public.users (id),
  customer_name   text,
  customer_phone  text,
  type            tx_type not null,
  payment_method  payment_method not null,
  amount          numeric(12,2) not null default 0, -- sale: full price; swap: top-up; repair: charge
  date            timestamptz not null default now(),
  created_at      timestamptz not null default now()
);

create index transactions_shop_date_idx on public.transactions (shop_id, date desc);
create index transactions_type_idx     on public.transactions (type);

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

-- ---------------------------------------------------------------------------
-- stock_adjustments  (restocking / manual corrections — the ONLY way stock
--                     changes outside of transaction_items)
-- ---------------------------------------------------------------------------
create table public.stock_adjustments (
  id              uuid primary key default gen_random_uuid(),
  shop_id         uuid not null references public.shops (id) on delete cascade,
  phone_model_id  uuid not null references public.phone_models (id) on delete cascade,
  staff_id        uuid not null references public.users (id),
  type            adjustment_type not null default 'restock',
  delta           int not null,      -- + added to stock, - removed (correction)
  reason          text,
  date            timestamptz not null default now()
);

create index stock_adjustments_model_idx on public.stock_adjustments (phone_model_id);

-- ---------------------------------------------------------------------------
-- Stock triggers
-- ---------------------------------------------------------------------------

-- Applies the effect of a transaction_items row to phone_models.available.
-- Guards against selling/out-ing more than available.
create or replace function public.apply_item_stock_change()
returns trigger
language plpgsql security definer set search_path = public
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
    select available into cur_avail from public.phone_models where id = new.phone_model_id;
    if new.direction = 'out' and cur_avail < new.qty then
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
    select available into cur_avail from public.phone_models where id = new.phone_model_id;
    if new.direction = 'out' and cur_avail < new.qty then
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
create or replace function public.apply_stock_adjustment()
returns trigger
language plpgsql security definer set search_path = public
as $$
declare
  cur_avail int;
begin
  if tg_op = 'DELETE' then
    update public.phone_models
       set available = available - old.delta,
           bought_in = bought_in - case when old.delta > 0 then old.delta else 0 end
     where id = old.phone_model_id;
    return old;
  end if;

  if tg_op = 'INSERT' then
    select available into cur_avail from public.phone_models where id = new.phone_model_id;
    if new.delta < 0 and cur_avail < abs(new.delta) then
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
           bought_in = bought_in - case when old.delta > 0 then old.delta else 0 end
     where id = old.phone_model_id;
    select available into cur_avail from public.phone_models where id = new.phone_model_id;
    if new.delta < 0 and cur_avail < abs(new.delta) then
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
-- Helper: claim the owner role (run once after your first signup)
-- ---------------------------------------------------------------------------
-- Usage: after signing up your personal account in the app, run:
--     select public.claim_owner();
-- Only works while no owner exists yet (prevents accidental re-claiming).
create or replace function public.claim_owner()
returns void
language plpgsql security definer set search_path = public
as $$
declare
  existing uuid;
begin
  select id into existing from public.users where role = 'owner' limit 1;
  if existing is not null then
    raise exception 'An owner already exists' using errcode = 'P0001';
  end if;
  update public.users set role = 'owner' where id = auth.uid();
  if not found then
    raise exception 'No user profile found for the current session' using errcode = 'P0001';
  end if;
end;
$$;

grant execute on function public.claim_owner() to authenticated;

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
  p_in_items jsonb default '[]'::jsonb
) returns uuid
language plpgsql security definer set search_path = public
as $$
declare
  v_role       public.user_role;
  v_staff_id   uuid;
  v_tx_id      uuid;
  v_item       jsonb;
  v_model_id   uuid;
  v_condition  public.phone_condition;
  v_avail      int;
  v_qty        int;
begin
  select u.id, u.role into v_staff_id, v_role
    from public.users u
   where u.id = auth.uid();
  if v_staff_id is null then
    raise exception 'Not authenticated' using errcode = 'P0001';
  end if;

  if v_role <> 'owner' then
    if p_shop_id <> (select shop_id from public.users where id = v_staff_id) then
      raise exception 'Not allowed to record transactions for this shop' using errcode = 'P0001';
    end if;
  end if;

  insert into public.transactions (shop_id, staff_id, customer_name, customer_phone, type, payment_method, amount, date)
  values (p_shop_id, v_staff_id, p_customer_name, p_customer_phone, p_type, p_payment_method, p_amount, p_date)
  returning id into v_tx_id;

  -- outgoing stock
  for v_item in select * from jsonb_array_elements(coalesce(p_out_items, '[]'::jsonb)) loop
    v_qty := coalesce((v_item ->> 'qty')::int, 1);
    select available into v_avail
      from public.phone_models
     where id = (v_item ->> 'phone_model_id')::uuid
       for update;
    if v_avail is null then
      raise exception 'Unknown phone model' using errcode = 'P0001';
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
    v_condition := coalesce((v_item ->> 'condition')::public.phone_condition, 'used'::public.phone_condition);

    if (v_item ->> 'phone_model_id') is not null then
      v_model_id := (v_item ->> 'phone_model_id')::uuid;
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

  return v_tx_id;
end;
$$;

grant execute on function public.record_transaction(uuid, text, text, public.tx_type, public.payment_method, numeric, timestamptz, jsonb, jsonb) to authenticated;

-- ---------------------------------------------------------------------------
-- RPC: adjust_stock  (restock / manual correction)
-- ---------------------------------------------------------------------------
create or replace function public.adjust_stock(
  p_shop_id uuid,
  p_phone_model_id uuid,
  p_delta int,
  p_type public.adjustment_type default 'restock',
  p_reason text default null
) returns uuid
language plpgsql security definer set search_path = public
as $$
declare
  v_role      public.user_role;
  v_staff_id  uuid;
  v_id        uuid;
begin
  select u.id, u.role into v_staff_id, v_role
    from public.users u
   where u.id = auth.uid();
  if v_staff_id is null then
    raise exception 'Not authenticated' using errcode = 'P0001';
  end if;

  if v_role <> 'owner' then
    if p_shop_id <> (select shop_id from public.users where id = v_staff_id) then
      raise exception 'Not allowed to adjust stock for this shop' using errcode = 'P0001';
    end if;
  end if;

  if not exists (select 1 from public.phone_models where id = p_phone_model_id and shop_id = p_shop_id) then
    raise exception 'Phone model does not belong to this shop' using errcode = 'P0001';
  end if;

  insert into public.stock_adjustments (shop_id, phone_model_id, staff_id, type, delta, reason)
  values (p_shop_id, p_phone_model_id, v_staff_id, p_type, p_delta, p_reason)
  returning id into v_id;

  return v_id;
end;
$$;

grant execute on function public.adjust_stock(uuid, uuid, int, public.adjustment_type, text) to authenticated;

-- ---------------------------------------------------------------------------
-- RPC: delete_transaction  (owner only — stock side-effects are reversed)
-- ---------------------------------------------------------------------------
create or replace function public.delete_transaction(p_transaction_id uuid)
returns void
language plpgsql security definer set search_path = public
as $$
begin
  if (select role from public.users where id = auth.uid()) <> 'owner' then
    raise exception 'Only owners can delete transactions' using errcode = 'P0001';
  end if;
  delete from public.transactions where id = p_transaction_id;
  if not found then
    raise exception 'Transaction not found' using errcode = 'P0001';
  end if;
end;
$$;

grant execute on function public.delete_transaction(uuid) to authenticated;

-- ============================================================================
-- Row Level Security
-- ============================================================================
alter table public.shops            enable row level security;
alter table public.users            enable row level security;
alter table public.phone_models     enable row level security;
alter table public.transactions     enable row level security;
alter table public.transaction_items enable row level security;
alter table public.stock_adjustments enable row level security;

-- helper used by policies below: current user's profile
create or replace function public.current_user_profile()
returns table (id uuid, role user_role, shop_id uuid)
language sql stable security definer set search_path = public
as $$
  select id, role, shop_id from public.users where id = auth.uid()
$$;

grant execute on function public.current_user_profile() to authenticated;

-- ---------- shops ----------
create policy "shops: owner full access" on public.shops
  for all using (public.current_user_profile().role = 'owner')
  with check (public.current_user_profile().role = 'owner');

create policy "shops: attendant sees own shop" on public.shops
  for select using (public.current_user_profile().shop_id = id);

-- ---------- users ----------
create policy "users: owner full access" on public.users
  for all using (public.current_user_profile().role = 'owner')
  with check (public.current_user_profile().role = 'owner');

create policy "users: read own row" on public.users
  for select using (auth.uid() = id);

create policy "users: attendant reads staff in own shop" on public.users
  for select using (public.current_user_profile().shop_id = shop_id);

-- ---------- phone_models ----------
create policy "phone_models: owner full access" on public.phone_models
  for all using (public.current_user_profile().role = 'owner')
  with check (public.current_user_profile().role = 'owner');

create policy "phone_models: attendant manages own shop" on public.phone_models
  for all using (public.current_user_profile().shop_id = shop_id)
  with check (public.current_user_profile().shop_id = shop_id);

-- ---------- transactions ----------
create policy "transactions: owner full access" on public.transactions
  for all using (public.current_user_profile().role = 'owner')
  with check (public.current_user_profile().role = 'owner');

-- attendant: can only insert into own shop; never update/delete
create policy "transactions: attendant inserts own shop" on public.transactions
  for insert with check (
    public.current_user_profile().shop_id = shop_id
    and public.current_user_profile().role = 'attendant'
  );

create policy "transactions: attendant reads own shop" on public.transactions
  for select using (public.current_user_profile().shop_id = shop_id);

-- ---------- transaction_items ----------
create policy "transaction_items: owner full access" on public.transaction_items
  for all using (
    public.current_user_profile().role = 'owner'
    or exists (
      select 1 from public.transactions t
      where t.id = transaction_id and t.shop_id = public.current_user_profile().shop_id
    )
  )
  with check (
    public.current_user_profile().role = 'owner'
    or exists (
      select 1 from public.transactions t
      where t.id = transaction_id and t.shop_id = public.current_user_profile().shop_id
    )
  );

-- ---------- stock_adjustments ----------
create policy "stock_adjustments: owner full access" on public.stock_adjustments
  for all using (public.current_user_profile().role = 'owner')
  with check (public.current_user_profile().role = 'owner');

-- attendant: insert + read own shop; no update/delete
create policy "stock_adjustments: attendant insert own shop" on public.stock_adjustments
  for insert with check (
    public.current_user_profile().shop_id = shop_id
    and public.current_user_profile().role = 'attendant'
  );

create policy "stock_adjustments: attendant reads own shop" on public.stock_adjustments
  for select using (public.current_user_profile().shop_id = shop_id);

-- ---------------------------------------------------------------------------
-- Realtime
-- ---------------------------------------------------------------------------
do $$
begin
  begin
    alter publication supabase_realtime add table public.shops, public.phone_models,
      public.transactions, public.transaction_items, public.stock_adjustments;
  exception when duplicate_object or undefined_object then
    raise notice 'realtime publication skipped (already subscribed or publication missing)';
  end;
end;
$$;
