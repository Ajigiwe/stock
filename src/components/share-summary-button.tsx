"use client";

import { ButtonSecondary } from "@/components/ui";

export function ShareSummaryButton({
  text,
  label = "Share summary",
}: {
  text: string;
  label?: string;
}) {
  const onShare = async () => {
    if (typeof navigator !== "undefined" && navigator.share) {
      try {
        await navigator.share({ text });
        return;
      } catch {
        // fall through to WhatsApp
      }
    }
    window.open(
      `https://wa.me/?text=${encodeURIComponent(text)}`,
      "_blank",
      "noopener",
    );
  };

  return (
    <ButtonSecondary onClick={onShare} className="h-8 px-3 text-xs">
      {label}
    </ButtonSecondary>
  );
}
