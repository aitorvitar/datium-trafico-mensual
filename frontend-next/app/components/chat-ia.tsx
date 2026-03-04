"use client";

import { FormEvent, useEffect, useRef, useState } from "react";

type ChatMessage = {
  role: "assistant" | "user";
  content: string;
};

type ChatApiResponse = {
  ok: boolean;
  answer?: string;
  error?: string;
};

const INITIAL_MESSAGES: ChatMessage[] = [
  {
    role: "assistant",
    content:
      'Chat IA listo. Ahora consulta fuentes reales (db78, workflow, wholesale y castiphone). Ejemplo: "que ha facturado Telcat en 2026, desglosado en meses".',
  },
];

export default function ChatIASection() {
  const [open, setOpen] = useState(false);
  const [messages, setMessages] = useState<ChatMessage[]>(INITIAL_MESSAGES);
  const [input, setInput] = useState("");
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");
  const chatScrollRef = useRef<HTMLDivElement | null>(null);

  useEffect(() => {
    if (!open) {
      return;
    }
    const target = chatScrollRef.current;
    if (target) {
      target.scrollTop = target.scrollHeight;
    }
  }, [messages, open]);

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const question = input.trim();
    if (!question || loading) {
      return;
    }

    const nextUserMessage: ChatMessage = { role: "user", content: question };
    const historyForApi = [...messages, nextUserMessage].slice(-10);

    setError("");
    setLoading(true);
    setMessages((prev) => [...prev, nextUserMessage]);
    setInput("");

    try {
      const response = await fetch("/api/chat", {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
        },
        body: JSON.stringify({
          message: question,
          history: historyForApi,
        }),
      });

      const payload = (await response.json()) as ChatApiResponse;
      if (!response.ok || !payload.ok) {
        throw new Error(payload.error ?? "No se pudo responder la consulta.");
      }

      setMessages((prev) => [...prev, { role: "assistant", content: payload.answer ?? "" }]);
    } catch (err) {
      const detail = err instanceof Error ? err.message : "Error inesperado.";
      setError(detail);
      setMessages((prev) => [
        ...prev,
        {
          role: "assistant",
          content: "No he podido responder ahora mismo. Revisa la configuracion de OpenAI y vuelve a intentarlo.",
        },
      ]);
    } finally {
      setLoading(false);
    }
  }

  return (
    <div className="chatFloating">
      {!open && (
        <button type="button" className="chatFab" onClick={() => setOpen(true)} aria-label="Abrir Chat IA">
          Chat IA
        </button>
      )}

      {open && (
        <section className="chatWindow" aria-live="polite">
          <header className="chatWindowHeader">
            <div>
              <h2 className="chatWindowTitle">Chat IA</h2>
              <p className="chatWindowSubtitle">Consultas de facturacion en lenguaje natural.</p>
            </div>
            <button type="button" className="chatWindowClose" onClick={() => setOpen(false)} aria-label="Cerrar chat">
              ×
            </button>
          </header>

          <div className="chatWindowBody">
            <div className="chatBox" ref={chatScrollRef}>
              {messages.map((message, idx) => (
                <article key={`msg-${idx}`} className={`chatMsg chatMsg-${message.role}`}>
                  <p className="chatRole">{message.role === "assistant" ? "IA" : "Tu"}</p>
                  <p className="chatText">{message.content}</p>
                </article>
              ))}
            </div>

            {error && <p className="errorBox">{error}</p>}

            <form className="chatForm" onSubmit={handleSubmit}>
              <input
                type="text"
                value={input}
                onChange={(event) => setInput(event.target.value)}
                placeholder='Ej: "facturacion Telcat 2026 por meses"'
                disabled={loading}
              />
              <button className="btn" type="submit" disabled={loading}>
                {loading ? "Consultando..." : "Enviar"}
              </button>
            </form>
          </div>
        </section>
      )}
    </div>
  );
}
