"use client";

import { Button, ButtonSecondary } from "@/components/ui";

function waLink(text: string, phone?: string | null): string {
  let digits = (phone ?? "").replace(/[^0-9]/g, "");
  if (digits.startsWith("00")) digits = digits.slice(2);
  else if (digits.startsWith("0")) digits = "233" + digits.slice(1); // Ghana default
  const base = digits ? `https://wa.me/${digits}` : "https://wa.me/";
  return `${base}?text=${encodeURIComponent(text)}`;
}

export function ReceiptActions({
  shareText,
  customerPhone,
}: {
  shareText: string;
  customerPhone?: string | null;
}) {
  const onWhatsApp = () => {
    window.open(waLink(shareText, customerPhone), "_blank", "noopener");
  };
  const onPrint = () => window.print();

  return (
    <div className="no-print flex flex-wrap gap-2">
      <Button onClick={onWhatsApp} className="flex-1">
        Send on WhatsApp
      </Button>
      <ButtonSecondary onClick={onPrint} className="flex-1">
        Print / Save PDF
      </ButtonSecondary>
    </div>
  );
}
