import { NextRequest, NextResponse } from "next/server";

type Extraction = {
  intent: "client_billing_year" | "unknown";
  client: string;
  year: number | null;
};

type BillingRow = {
  cliente_codigo: number;
  cliente: string;
  id_reseller: number | null;
  venta_total: number;
  coste_total: number;
  beneficio_total: number;
  margen_pct: number;
};

function toNumber(value: unknown): number {
  const parsed = Number(value);
  return Number.isFinite(parsed) ? parsed : 0;
}

function euro(value: number): string {
  return `${new Intl.NumberFormat("es-ES", { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(value)} €`;
}

function normalizeExtraction(raw: unknown): Extraction {
  if (!raw || typeof raw !== "object") {
    return { intent: "unknown", client: "", year: null };
  }

  const candidate = raw as Record<string, unknown>;
  const intent = candidate.intent === "client_billing_year" ? "client_billing_year" : "unknown";
  const client = typeof candidate.client === "string" ? candidate.client.trim() : "";
  const year = Number.parseInt(String(candidate.year ?? ""), 10);
  const normalizedYear = Number.isFinite(year) && year >= 2000 && year <= 2100 ? year : null;

  return { intent, client, year: normalizedYear };
}

async function extractIntentWithOpenAI(message: string, apiKey: string, model: string): Promise<Extraction> {
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
            'Extrae solo JSON con claves: intent, client, year. intent permitido: "client_billing_year" o "unknown". Si falta nombre de cliente o año, usa intent "unknown". No inventes datos.',
        },
        { role: "user", content: message },
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

async function fetchBillingByClientYear(client: string, year: number): Promise<BillingRow[]> {
  const backendBase = process.env.PHP_BACKEND_BASE_URL ?? "http://backend/";
  const endpoint = new URL("api/chat_query.php", backendBase);
  endpoint.searchParams.set("action", "client_billing_year");
  endpoint.searchParams.set("client", client);
  endpoint.searchParams.set("year", String(year));

  const response = await fetch(endpoint.toString(), { cache: "no-store" });
  const payload = (await response.json()) as {
    ok?: boolean;
    data?: { rows?: BillingRow[] };
    error?: string;
  };

  if (!response.ok || !payload.ok) {
    throw new Error(payload.error ?? "No se pudo consultar facturacion.");
  }

  return payload.data?.rows ?? [];
}

function buildBillingAnswer(rows: BillingRow[], year: number): string {
  if (rows.length === 0) {
    return `No encuentro facturacion para ese cliente en ${year}. Revisa el nombre exacto y vuelve a probar.`;
  }

  const totalVenta = rows.reduce((acc, row) => acc + toNumber(row.venta_total), 0);
  const totalCoste = rows.reduce((acc, row) => acc + toNumber(row.coste_total), 0);
  const totalBeneficio = rows.reduce((acc, row) => acc + toNumber(row.beneficio_total), 0);
  const margen = totalVenta > 0 ? (totalBeneficio / totalVenta) * 100 : 0;
  const summary = `Facturacion ${year}: venta ${euro(totalVenta)}, coste ${euro(totalCoste)}, beneficio ${euro(
    totalBeneficio,
  )}, margen ${margen.toFixed(2)}%.`;

  if (rows.length === 1) {
    const row = rows[0];
    return `${row.cliente} (${row.cliente_codigo}). ${summary}`;
  }

  const detail = rows
    .slice(0, 5)
    .map((row) => `${row.cliente} (${row.cliente_codigo}): ${euro(toNumber(row.venta_total))}`)
    .join(" | ");

  return `He encontrado ${rows.length} coincidencias. ${summary} Top coincidencias: ${detail}`;
}

export async function POST(request: NextRequest) {
  try {
    const body = (await request.json()) as { message?: string };
    const message = String(body.message ?? "").trim();
    if (!message) {
      return NextResponse.json({ ok: false, error: "La consulta esta vacia." }, { status: 400 });
    }

    const apiKey = String(process.env.OPENAI_API_KEY ?? "").trim();
    if (!apiKey) {
      return NextResponse.json(
        { ok: false, error: "Falta OPENAI_API_KEY. Configura la variable en el entorno del frontend." },
        { status: 500 },
      );
    }

    const model = String(process.env.OPENAI_MODEL ?? "gpt-4.1-mini").trim();
    const extraction = await extractIntentWithOpenAI(message, apiKey, model);

    if (extraction.intent !== "client_billing_year" || !extraction.client || !extraction.year) {
      return NextResponse.json({
        ok: true,
        answer: 'Ahora mismo puedo responder: "que ha facturado <cliente> en <año>". Ejemplo: "que ha facturado ConnetSur en 2026?"',
      });
    }

    const rows = await fetchBillingByClientYear(extraction.client, extraction.year);
    const answer = buildBillingAnswer(rows, extraction.year);

    return NextResponse.json({ ok: true, answer });
  } catch (error) {
    const message = error instanceof Error ? error.message : "Error inesperado en Chat IA.";
    return NextResponse.json({ ok: false, error: message }, { status: 500 });
  }
}
