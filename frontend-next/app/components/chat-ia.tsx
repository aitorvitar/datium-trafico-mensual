"use client";

import { FormEvent, useState } from "react";

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
  const [messages, setMessages] = useState<ChatMessage[]>(INITIAL_MESSAGES);
  const [input, setInput] = useState("");
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const question = input.trim();
    if (!question || loading) {
      return;
    }

    setError("");
    setLoading(true);
    setMessages((prev) => [...prev, { role: "user", content: question }]);
    setInput("");

    try {
      const response = await fetch("/api/chat", {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
        },
        body: JSON.stringify({
          message: question,
          history: messages.slice(-8),
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
    <section className="panel">
      <h2 className="title" style={{ fontSize: "1.3rem", marginBottom: 8 }}>
        Chat IA
      </h2>
      <p className="subtitle">Consultas en lenguaje natural sobre facturacion de clientes (castiphone).</p>

      <div className="chatBox" aria-live="polite">
        {messages.map((message, idx) => (
          <article key={`msg-${idx}`} className={`chatMsg chatMsg-${message.role}`}>
            <p className="chatRole">{message.role === "assistant" ? "IA" : "Tu"}</p>
            <p className="chatText">{message.content}</p>
          </article>
        ))}
      </div>

      <form className="chatForm" onSubmit={handleSubmit}>
        <input
          type="text"
          value={input}
          onChange={(event) => setInput(event.target.value)}
          placeholder='Escribe tu consulta. Ejemplo: "que ha facturado ConnetSur en 2026?"'
          disabled={loading}
        />
        <button className="btn" type="submit" disabled={loading}>
          {loading ? "Consultando..." : "Enviar"}
        </button>
      </form>

      {error && <p className="errorBox">{error}</p>}
    </section>
  );
}
