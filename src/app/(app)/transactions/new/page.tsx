import { redirect } from "next/navigation";
import { getSession, getShops, getStock } from "@/lib/data";
import { TransactionForm } from "@/components/transaction-form";

export default async function NewTransactionPage({
  searchParams,
}: {
  searchParams: Promise<{ shop?: string }>;
}) {
  const session = await getSession();
  if (!session) redirect("/login");

  const isOwner = session.profile?.role === "owner";
  const { shop } = await searchParams;

  const shops = await getShops();
  const stock = await getStock();

  let defaultShopId: string | undefined;
  if (isOwner) {
    defaultShopId = shop && shops.some((s) => s.id === shop) ? shop : shops[0]?.id;
  } else {
    defaultShopId = session.profile?.shop_id ?? undefined;
  }

  if (!defaultShopId) {
    redirect("/");
  }

  return (
    <TransactionForm
      shops={shops}
      stock={stock}
      defaultShopId={defaultShopId}
      isOwner={isOwner}
    />
  );
}
