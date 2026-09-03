-- 0002: Atomic swap trade-ins
--
-- Adds an optional p_swap_in parameter to record_transaction so swap trade-in
-- phones are inserted into swapped_phones inside the same transaction as the
-- swap itself. Previously the app inserted these rows in a second, separate
-- call — a failure there left the swap recorded but the trade-in missing.
--
-- The function body is replaced wholesale; the parameter has a default, so
-- existing callers (old app builds, restore_backup, tests) keep working.

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
language plpgsql
security definer
set search_path = ''
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

  -- incoming stock (swap-ins that enter sellable stock)
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

  -- Swap trade-ins: logged atomically with the transaction. These are the
  -- customer's old phones — recorded for tracking, they do NOT enter
  -- sellable stock.
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

-- Re-grant for the new 11-argument signature (Postgres overloads are per-
-- signature; the old revoke/grant pair covered only the 10-arg version).
revoke all on function public.record_transaction(uuid, text, text, public.tx_type, public.payment_method, numeric, timestamptz, jsonb, jsonb, uuid, jsonb) from public;
revoke all on function public.record_transaction(uuid, text, text, public.tx_type, public.payment_method, numeric, timestamptz, jsonb, jsonb, uuid, jsonb) from anon;
grant execute on function public.record_transaction(uuid, text, text, public.tx_type, public.payment_method, numeric, timestamptz, jsonb, jsonb, uuid, jsonb) to authenticated;
