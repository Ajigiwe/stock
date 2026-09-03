-- ---------------------------------------------------------------------------
-- Migration 0003: stock editing is owner-only
--
-- Removes the per-staff `can_edit_stock` privilege path. Previously the owner
-- could toggle stock-editing on individual staff; those staff could adjust
-- stock directly and add/edit phone models in their own shop. Now only the
-- owner may edit stock — everyone else goes through the stock_requests
-- approval flow, which already existed.
--
-- Notes:
--   * `users.can_edit_stock` column is KEPT (not dropped) so existing backups
--     and restores keep working; it simply no longer grants anything.
--   * `current_user_can_edit_stock()` is kept because RLS policies and any
--     external callers reference it — it is redefined to mean "is owner".
--   * Safe to run while the app is live: the attendant flow (stock_requests)
--     is unchanged, and staff who had the privilege lose direct edit access
--     immediately.
-- ---------------------------------------------------------------------------

-- 1. adjust_stock: owner only -------------------------------------------------
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

  if v_me.role is distinct from 'owner'::public.user_role then
    raise exception 'Only the owner can adjust stock directly' using errcode = 'P0001';
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

-- 2. bulk_adjust_stock: owner only -------------------------------------------
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

  if v_me.role is distinct from 'owner'::public.user_role then
    raise exception 'Only the owner can adjust stock directly' using errcode = 'P0001';
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

-- 3. phone_models RLS: owner-only insert/update -------------------------------
drop policy if exists "phone_models: privileged staff adds own shop" on public.phone_models;
drop policy if exists "phone_models: privileged staff edits own shop" on public.phone_models;

create policy "phone_models: owner-only insert" on public.phone_models
  for insert with check (((select public.current_user_profile())).role = 'owner');

create policy "phone_models: owner-only update" on public.phone_models
  for update using (((select public.current_user_profile())).role = 'owner')
  with check (((select public.current_user_profile())).role = 'owner');

-- 4. The helper now means "is owner" ------------------------------------------
create or replace function public.current_user_can_edit_stock()
returns boolean
language sql stable security definer set search_path = ''
as $$
  select coalesce(
    (select u.role = 'owner'
       from public.users u where u.id = auth.uid()),
    false)
$$;

-- 5. No client may flip the privilege flag anymore ----------------------------
revoke update (can_edit_stock) on public.users from authenticated;

-- Keep the owner's remaining legitimate grants intact (they were part of a
-- wider grant; revoking one column does not affect the others).
grant update (name, shop_id) on public.users to authenticated;

-- Grants on the replaced functions are re-established defensively (REPLACE
-- does not drop them, but this keeps the file self-contained when run on a
-- database where they were revoked).
revoke all on function public.adjust_stock(uuid, uuid, int, public.adjustment_type, text) from public;
revoke all on function public.adjust_stock(uuid, uuid, int, public.adjustment_type, text) from anon;
grant execute on function public.adjust_stock(uuid, uuid, int, public.adjustment_type, text) to authenticated;

revoke all on function public.bulk_adjust_stock(uuid, jsonb, text) from public;
revoke all on function public.bulk_adjust_stock(uuid, jsonb, text) from anon;
grant execute on function public.bulk_adjust_stock(uuid, jsonb, text) to authenticated;

revoke all on function public.current_user_can_edit_stock() from public, anon;
grant execute on function public.current_user_can_edit_stock() to authenticated;
