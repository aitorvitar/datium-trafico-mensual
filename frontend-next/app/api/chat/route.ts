import { NextRequest, NextResponse } from "next/server";

type ChatHistoryItem = {
  role: "assistant" | "user";
  content: string;
};

type Extraction = {
  intent: "billing_query" | "unknown";
  client: string;
  year: number | null;
  reseller_id: number | null;
  by_month: boolean;
};

type BillingRow = {
  year: number | null;
  month: number | null;
  venta_total: number;
  coste_total: number;
  beneficio_total: number;
  margen_pct: number;
};

type BillingPayload = {
  query: {
    mode: "reseller" | "client_name";
    client: string;
    year: number;
    by_month: boolean;
    reseller_id: number | null;
    reseller_name: string;
  };
  rows: BillingRow[];
  totals: {
    venta_total: number;
    coste_total: number;
    beneficio_total: number;
    margen_pct: number;
  };
  notes: string[];
};

function toNumber(value: unknown): number {
  const parsed = Number(value);
  return Number.isFinite(parsed) ? parsed : 0;
}

function toBool(value: unknown): boolean {
  if (typeof value === "boolean") {
    return value;
  }
  const normalized = String(value ?? "")
    .trim()
    .toLowerCase();
  return normalized === "1" || normalized === "true" || normalized === "yes";
}

function euro(value: number): string {
  return `${new Intl.NumberFormat("es-ES", { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(value)} EUR`;
}

function normalizeExtraction(raw: unknown): Extraction {
  if (!raw || typeof raw !== "object") {
    return { intent: "unknown", client: "", year: null, reseller_id: null, by_month: false };
  }

  const candidate = raw as Record<string, unknown>;
  const intent = candidate.intent === "billing_query" ? "billing_query" : "unknown";
  const client = typeof candidate.client === "string" ? candidate.client.trim() : "";
  const yearValue = Number.parseInt(String(candidate.year ?? ""), 10);
  const year = Number.isFinite(yearValue) && yearValue >= 2000 && yearValue <= 2100 ? yearValue : null;
  const resellerRaw = Number.parseInt(String(candidate.reseller_id ?? ""), 10);
  const reseller_id = Number.isFinite(resellerRaw) && resellerRaw > 0 ? resellerRaw : null;
  const by_month = toBool(candidate.by_month);

  return { intent, client, year, reseller_id, by_month };
}

function mergeExtraction(primary: Extraction, fallback: Partial<Extraction>): Extraction {
  const merged: Extraction = {
    intent:
      primary.intent !== "unknown"
        ? primary.intent
        : fallback.intent === "billing_query"
          ? "billing_query"
          : "unknown",
    client: primary.client || (fallback.client ?? ""),
    year: primary.year ?? fallback.year ?? null,
    reseller_id: primary.reseller_id ?? fallback.reseller_id ?? null,
    by_month: primary.by_month || Boolean(fallback.by_month),
  };

  if (merged.intent === "unknown" && merged.year && (merged.client || merged.reseller_id)) {
    merged.intent = "billing_query";
  }

  return merged;
}

function extractFallback(message: string): Partial<Extraction> {
  const text = message.trim();
  const lowered = text.toLowerCase();
  const yearMatch = lowered.match(/\b(20\d{2})\b/);
  const resellerMatch = lowered.match(/\bid[_\s-]*reseller\s*=?\s*(\d{1,6})\b/) ?? lowered.match(/\breseller\s*=?\s*(\d{1,6})\b/);
  const leadingIdMatch = lowered.match(/^\s*(\d{1,6})[\s,\t]+/);
  const byMonth = /(mes|meses|mensual|desglos)/i.test(text);

  let client = "";
  const nameMatch =
    text.match(/(?:facturad\w*|fcaturad\w*)\s+(.+?)\s+en\s+20\d{2}/i) ??
    text.match(/(?:factura\w*)\s+de\s+(.+?)\s+en\s+20\d{2}/i);
  if (nameMatch?.[1]) {
    client = nameMatch[1].replace(/[,:]/g, " ").trim();
  }

  if (!client && leadingIdMatch && text.length > leadingIdMatch[0].length) {
    const maybeName = text.slice(leadingIdMatch[0].length).replace(/id[_\s-]*reseller.*/i, "").trim();
    client = maybeName.replace(/[,:]/g, " ").trim();
  }

  const resellerIdParsed = Number.parseInt(
    resellerMatch?.[1] ?? leadingIdMatch?.[1] ?? "",
    10,
  );

  return {
    intent: yearMatch && (client || resellerMatch || leadingIdMatch) ? "billing_query" : "unknown",
    client,
    year: yearMatch ? Number.parseInt(yearMatch[1], 10) : null,
    reseller_id: Number.isFinite(resellerIdParsed) && resellerIdParsed > 0 ? resellerIdParsed : null,
    by_month: byMonth,
  };
}

async function extractIntentWithOpenAI(text: string, apiKey: string, model: string): Promise<Extraction> {
  const response = await fetch("https://api.openai.com/v1/chat/completions", {
    method: "POST",
    headers: {
      Authorization: `Bearer ${apiKey}`,
      "Content-Type": "application/json",
    },
    body: JSON.stringify({
      model,
      temperature: 0,
      response_format: { type: "json_object" },
      messages: [
        {
          role: "system",
          content:
            'Devuelve solo JSON con: intent, client, year, reseller_id, by_month. intent permitido: "billing_query" o "unknown". Si piden desglose mensual, by_month=true. Si aparece id_reseller, extraelo como numero. No inventes.',
        },
        { role: "user", content: text },
      ],
    }),
  });

  const payload = (await response.json()) as {
    choices?: Array<{ message?: { content?: string } }>;
    error?: { message?: string };
  };

  if (!response.ok) {
    throw new Error(payload.error?.message ?? "Error llamando a OpenAI.");
  }

  const content = payload.choices?.[0]?.message?.content ?? "{}";
  let parsed: unknown = {};
  try {
    parsed = JSON.parse(content);
  } catch {
    parsed = {};
  }

  return normalizeExtraction(parsed);
}

async function fetchBilling(params: {
  client: string;
  year: number;
  resellerId: number | null;
  byMonth: boolean;
}): Promise<BillingPayload> {
  const backendBase = process.env.PHP_BACKEND_BASE_URL ?? "http://backend/";
  const endpoint = new URL("api/chat_query.php", backendBase);
  endpoint.searchParams.set("action", "billing_query");
  endpoint.searchParams.set("year", String(params.year));
  endpoint.searchParams.set("by_month", params.byMonth ? "1" : "0");
  if (params.client) {
    endpoint.searchParams.set("client", params.client);
  }
  if (params.resellerId) {
    endpoint.searchParams.set("reseller_id", String(params.resellerId));
  }

  const response = await fetch(endpoint.toString(), { cache: "no-store" });
  const payload = (await response.json()) as {
    ok?: boolean;
    data?: BillingPayload;
    error?: string;
  };

  if (!response.ok || !payload.ok || !payload.data) {
    throw new Error(payload.error ?? "No se pudo consultar facturacion.");
  }

  return payload.data;
}

function buildAnswer(payload: BillingPayload): string {
  if (!payload.rows.length) {
    return `No encuentro facturacion para ese criterio en ${payload.query.year}.`;
  }

  const head =
    payload.query.mode === "reseller"
      ? `Reseller ${payload.query.reseller_id}${payload.query.reseller_name ? ` (${payload.query.reseller_name})` : ""}`
      : `Cliente "${payload.query.client}"`;

  const summary = `Facturacion ${payload.query.year}: venta ${euro(payload.totals.venta_total)}, coste ${euro(
    payload.totals.coste_total,
  )}, beneficio ${euro(payload.totals.beneficio_total)}, margen ${payload.totals.margen_pct.toFixed(2)}%.`;

  if (!payload.query.by_month) {
    return `${head}. ${summary} Fuente: castiphone (Facturas + FacturasProductos + Clientes).`;
  }

  const monthLines = payload.rows
    .map((row) => {
      const month = Number(row.month ?? 0);
      const monthLabel = `${payload.query.year}-${String(month).padStart(2, "0")}`;
      return `${monthLabel}: ${euro(toNumber(row.venta_total))}`;
    })
    .join(" | ");

  return `${head}. ${summary} Desglose mensual: ${monthLines}. Fuente: castiphone (con mapeo workflow si aplica).`;
}

export async function POST(request: NextRequest) {
  try {
    const body = (await request.json()) as { message?: string; history?: ChatHistoryItem[] };
    const message = String(body.message ?? "").trim();
    if (!message) {
      return NextResponse.json({ ok: false, error: "La consulta esta vacia." }, { status: 400 });
    }

    const history = Array.isArray(body.history) ? body.history : [];
    const recentUserContext = history
      .filter((item) => item && item.role === "user" && typeof item.content === "string")
      .slice(-4)
      .map((item) => item.content.trim())
      .filter(Boolean)
      .join("\n");
    const contextText = recentUserContext ? `${recentUserContext}\n${message}` : message;

    const apiKey = String(process.env.OPENAI_API_KEY ?? "").trim();
    if (!apiKey) {
      return NextResponse.json(
        { ok: false, error: "Falta OPENAI_API_KEY. Configura la variable en el entorno del frontend." },
        { status: 500 },
      );
    }

    const model = String(process.env.OPENAI_MODEL ?? "gpt-4.1-mini").trim();
    const modelExtraction = await extractIntentWithOpenAI(contextText, apiKey, model);
    const fallbackExtraction = extractFallback(contextText);
    const extraction = mergeExtraction(modelExtraction, fallbackExtraction);

    if (extraction.intent !== "billing_query" || !extraction.year || (!extraction.client && !extraction.reseller_id)) {
      return NextResponse.json({
        ok: true,
        answer:
          'Puedo responder consultas de facturacion por anio. Ejemplos: "que ha facturado Telcat en 2026, desglosado en meses" o "id_reseller=26 en 2026".',
      });
    }

    const payload = await fetchBilling({
      client: extraction.client,
      year: extraction.year,
      resellerId: extraction.reseller_id,
      byMonth: extraction.by_month,
    });

    return NextResponse.json({
      ok: true,
      answer: buildAnswer(payload),
    });
  } catch (error) {
    const message = error instanceof Error ? error.message : "Error inesperado en Chat IA.";
    return NextResponse.json({ ok: false, error: message }, { status: 500 });
  }
}
