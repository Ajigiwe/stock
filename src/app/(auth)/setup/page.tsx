import { redirect } from "next/navigation";
import { getAdminClient } from "@/lib/admin";
import { SetupOwnerForm } from "@/components/setup-owner-form";

export const dynamic = "force-dynamic";

export default async function SetupPage() {
  const admin = getAdminClient();
  const { data, error } = await admin
    .from("users")
    .select("id")
    .eq("role", "owner")
    .limit(1);

  if (error) {
    return (
      <div className="flex flex-1 items-center justify-center px-4 py-12">
        <div className="w-full max-w-sm text-sm text-zinc-600">
          Could not check for an existing owner: {error.message}
        </div>
      </div>
    );
  }

  if (data && data.length > 0) {
    redirect("/login");
  }

  return (
    <div className="flex flex-1 items-center justify-center px-4 py-12">
      <div className="w-full max-w-sm">
        <div className="mb-6 text-center">
          <h1 className="text-2xl font-bold text-zinc-900">Owner setup</h1>
          <p className="mt-1 text-sm text-zinc-500">
            Create the single owner account. Staff accounts are added later from
            Settings and can&apos;t sign up on their own.
          </p>
        </div>
        <div className="rounded-xl border border-zinc-200 bg-white p-6 shadow-sm">
          <SetupOwnerForm />
        </div>
      </div>
    </div>
  );
}
