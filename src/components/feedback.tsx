"use client";

import {
  createContext,
  useCallback,
  useContext,
  useRef,
  useState,
  type ReactNode,
} from "react";

type ToastTone = "success" | "error" | "info";
type Toast = { id: number; message: string; tone: ToastTone };

type ConfirmOptions = {
  title: string;
  message?: string;
  confirmLabel?: string;
  cancelLabel?: string;
  danger?: boolean;
};

type ConfirmState = ConfirmOptions & { resolve: (v: boolean) => void };

const ToastCtx = createContext<(message: string, tone: ToastTone) => void>(
  () => {},
);
const ConfirmCtx = createContext<(o: ConfirmOptions) => Promise<boolean>>(
  async () => false,
);

export function useToast() {
  const push = useContext(ToastCtx);
  return {
    success: (m: string) => push(m, "success"),
    error: (m: string) => push(m, "error"),
    info: (m: string) => push(m, "info"),
  };
}

export function useConfirm() {
  return useContext(ConfirmCtx);
}

const toneStyles: Record<ToastTone, string> = {
  success: "border-emerald-200 bg-emerald-50 text-emerald-800",
  error: "border-red-200 bg-red-50 text-red-700",
  info: "border-zinc-200 bg-white text-zinc-800",
};

const toneIcon: Record<ToastTone, string> = {
  success: "✓",
  error: "!",
  info: "i",
};

export function FeedbackProvider({ children }: { children: ReactNode }) {
  const [toasts, setToasts] = useState<Toast[]>([]);
  const idRef = useRef(0);

  const push = useCallback((message: string, tone: ToastTone) => {
    const id = ++idRef.current;
    setToasts((cur) => [...cur, { id, message, tone }]);
    setTimeout(() => {
      setToasts((cur) => cur.filter((t) => t.id !== id));
    }, 4000);
  }, []);

  const [confirmState, setConfirmState] = useState<ConfirmState | null>(null);

  const confirm = useCallback(
    (o: ConfirmOptions) =>
      new Promise<boolean>((resolve) => setConfirmState({ ...o, resolve })),
    [],
  );

  const closeConfirm = (v: boolean) => {
    setConfirmState((cur) => {
      cur?.resolve(v);
      return null;
    });
  };

  return (
    <ToastCtx.Provider value={push}>
      <ConfirmCtx.Provider value={confirm}>
        {children}

        {/* Toasts */}
        <div className="pointer-events-none fixed inset-x-0 top-3 z-[60] flex flex-col items-center gap-2 px-3 sm:top-4">
          {toasts.map((t) => (
            <div
              key={t.id}
              role="status"
              className={`pointer-events-auto flex w-full max-w-sm items-start gap-2 rounded-lg border px-4 py-3 text-sm font-medium shadow-lg ${toneStyles[t.tone]}`}
            >
              <span
                aria-hidden="true"
                className={`mt-0.5 inline-flex h-4 w-4 shrink-0 items-center justify-center rounded-full text-[10px] font-bold ${
                  t.tone === "success"
                    ? "bg-emerald-600 text-white"
                    : t.tone === "error"
                      ? "bg-red-600 text-white"
                      : "bg-zinc-400 text-white"
                }`}
              >
                {toneIcon[t.tone]}
              </span>
              <span className="min-w-0 flex-1">{t.message}</span>
            </div>
          ))}
        </div>

        {/* Confirm dialog */}
        {confirmState && (
          <div className="fixed inset-0 z-[70] flex items-end justify-center sm:items-center sm:p-4">
            <div
              className="absolute inset-0 bg-zinc-900/50"
              onClick={() => closeConfirm(false)}
              aria-hidden="true"
            />
            <div className="relative z-10 w-full max-w-sm rounded-t-2xl bg-white p-5 shadow-xl sm:rounded-2xl">
              <h2 className="text-base font-semibold text-zinc-900">
                {confirmState.title}
              </h2>
              {confirmState.message && (
                <p className="mt-1 text-sm text-zinc-500">{confirmState.message}</p>
              )}
              <div className="mt-5 flex justify-end gap-2">
                <button
                  type="button"
                  onClick={() => closeConfirm(false)}
                  className="inline-flex h-10 items-center justify-center rounded-lg border border-zinc-300 bg-white px-4 text-sm font-medium text-zinc-700 transition-colors hover:bg-zinc-50"
                >
                  {confirmState.cancelLabel ?? "Cancel"}
                </button>
                <button
                  type="button"
                  onClick={() => closeConfirm(true)}
                  className={`inline-flex h-10 items-center justify-center rounded-lg px-4 text-sm font-medium text-white transition-colors ${
                    confirmState.danger
                      ? "bg-red-600 hover:bg-red-500"
                      : "bg-indigo-600 hover:bg-indigo-500"
                  }`}
                >
                  {confirmState.confirmLabel ?? "Confirm"}
                </button>
              </div>
            </div>
          </div>
        )}
      </ConfirmCtx.Provider>
    </ToastCtx.Provider>
  );
}
