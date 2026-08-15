"use client";

import { useMemo, useState } from "react";
import type { PhoneModel } from "@/lib/data";
import { Badge, Input } from "@/components/ui";

export function ModelPicker({
  models,
  value,
  onChange,
  placeholder = "Type to search model…",
  showStock = true,
}: {
  models: PhoneModel[];
  value: string;
  onChange: (id: string) => void;
  placeholder?: string;
  showStock?: boolean;
}) {
  const [text, setText] = useState(
    () => models.find((m) => m.id === value)?.model_name ?? "",
  );
  const [prevValue, setPrevValue] = useState(value);
  const [editing, setEditing] = useState(false);
  const [open, setOpen] = useState(false);
  const [highlight, setHighlight] = useState(0);

  const selected = models.find((m) => m.id === value);

  // Sync the input text when the chosen model changes externally (e.g. the
  // parent resets a line after switching shops). Skipped while the user is
  // typing so editing a selected model isn't clobbered.
  if (value !== prevValue) {
    setPrevValue(value);
    if (!editing) {
      setText(selected ? selected.model_name : "");
    }
  }

  const matches = useMemo(() => {
    const q = text.trim().toLowerCase();
    if (!q) return models;
    return models.filter((m) => m.model_name.toLowerCase().includes(q));
  }, [models, text]);

  const pick = (id: string) => {
    const m = models.find((x) => x.id === id);
    setText(m ? m.model_name : "");
    setEditing(false);
    onChange(id);
    setOpen(false);
  };

  const type = (input: string) => {
    setText(input);
    setEditing(true);
    setOpen(true);
    setHighlight(0);
    if (value) onChange(""); // editing a chosen model clears the selection
  };

  const onKeyDown = (e: React.KeyboardEvent<HTMLInputElement>) => {
    if (e.key === "ArrowDown") {
      e.preventDefault();
      setOpen(true);
      setHighlight((h) => (h + 1) % Math.max(matches.length, 1));
    } else if (e.key === "ArrowUp") {
      e.preventDefault();
      setOpen(true);
      setHighlight((h) => (h - 1 + matches.length) % Math.max(matches.length, 1));
    } else if (e.key === "Enter") {
      e.preventDefault();
      if (open && matches[highlight]) pick(matches[highlight].id);
      else if (matches.length === 1 && text && !open) pick(matches[0].id);
    } else if (e.key === "Escape") {
      setOpen(false);
    }
  };

  return (
    <div className="relative">
      <Input
        value={text}
        placeholder={placeholder}
        onChange={(e) => type(e.target.value)}
        onFocus={() => setOpen(true)}
        onBlur={() => setTimeout(() => setOpen(false), 120)}
        onKeyDown={onKeyDown}
        autoComplete="off"
        aria-expanded={open}
        aria-haspopup="listbox"
      />

      {open && (
        <div className="absolute z-20 mt-1 max-h-64 w-full overflow-y-auto rounded-lg border border-zinc-200 bg-white shadow-lg">
          {matches.length === 0 ? (
            <div className="px-3 py-2 text-sm text-zinc-500">
              No matching models.
            </div>
          ) : (
            <ul role="listbox">
              {matches.slice(0, 30).map((m, i) => {
                const low = m.available <= m.low_stock_threshold;
                return (
                  <li
                    key={m.id}
                    role="option"
                    aria-selected={i === highlight}
                    onMouseDown={(e) => {
                      e.preventDefault();
                      pick(m.id);
                    }}
                    onMouseEnter={() => setHighlight(i)}
                    className={`flex cursor-pointer items-center justify-between gap-2 px-3 py-2 text-sm ${
                      i === highlight ? "bg-zinc-100" : ""
                    }`}
                  >
                    <span className="flex min-w-0 items-center gap-2">
                      <span className="truncate font-medium text-zinc-900">
                        {m.model_name}
                      </span>
                      <Badge tone={m.condition === "new" ? "blue" : "gray"}>
                        {m.condition}
                      </Badge>
                    </span>
                    {showStock && (
                      <span
                        className={`shrink-0 text-xs ${
                          low ? "text-red-600" : "text-zinc-500"
                        }`}
                      >
                        {m.available} in stock
                      </span>
                    )}
                  </li>
                );
              })}
            </ul>
          )}
        </div>
      )}
    </div>
  );
}