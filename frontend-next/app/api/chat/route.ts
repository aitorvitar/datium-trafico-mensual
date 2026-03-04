import { NextRequest, NextResponse } from "next/server";

type ChatHistoryItem = {
  role: "assistant" | "user";
  content: string;
};

type OpenAIToolCall = {
  id: string;
  type: "function";
  function: {
    name: string;
    arguments: string;
  };
};

type OpenAIMessage = {
  role: "system" | "user" | "assistant" | "tool";
  content?: string | null;
  tool_calls?: OpenAIToolCall[];
  tool_call_id?: string;
  name?: string;
};

type OpenAIResponse = {
  choices?: Array<{
    message?: OpenAIMessage;
  }>;
  error?: {
    message?: string;
  };
};

type BillingIntent = {
  year: number;
  client: string;
  resellerId: number;
  byMonth: boolean;
  monthFilter: number | null;
};

type BillingRow = {
  year?: number | null;
  month?: number | null;
  venta_total?: number;
  coste_total?: number;
  beneficio_total?: number;
  margen_pct?: number;
};

type BillingPayload = {
  ok?: boolean;
  data?: {
    query?: {
      mode?: string;
      client?: string;
      year?: number;
      by_month?: boolean;
      reseller_id?: number | null;
      reseller_name?: string;
    };
    rows?: BillingRow[];
    totals?: {
      venta_total?: number;
      coste_total?: number;
      beneficio_total?: number;
      margen_pct?: number;
    };
    notes?: string[];
  };
  error?: string;
};

const TOOL_DEFINITIONS = [
  {
    type: "function",
    function: {
      name: "list_sources",
      description: "List available database sources and what each source is for.",
      parameters: {
        type: "object",
        properties: {},
        additionalProperties: false,
      },
    },
  },
  {
    type: "function",
    function: {
      name: "get_schema",
      description: "Get table and column metadata for one source. Use before writing SQL if unsure.",
      parameters: {
        type: "object",
        properties: {
          source: {
            type: "string",
            description: "One source from list_sources.",
          },
          table_pattern: {
            type: "string",
            description: "Optional LIKE-style fragment to narrow tables.",
          },
        },
        required: ["source"],
        additionalProperties: false,
      },
    },
  },
  {
    type: "function",
    function: {
      name: "run_query",
      description: "Run one read-only SQL query on one source.",
      parameters: {
        type: "object",
        properties: {
          source: {
            type: "string",
            description: "One source from list_sources.",
          },
          sql: {
            type: "string",
            description: "A SELECT/CTE query only.",
          },
        },
        required: ["source", "sql"],
        additionalProperties: false,
      },
    },
  },
];

const SYSTEM_PROMPT = [
  "You are an internal telecom data analyst.",
  "You must use tools for factual database answers.",
  "Never invent table names, columns, or values.",
  "Available sources are from tools and can include:",
  "- db78 (MySQL voipswitch incoming CDR)",
  "- workflow (MySQL workflowtest mapping resellers/DID)",
  "- wholesale (MySQL voipswitch wholesale CDR)",
  "- castiphone (SQL Server billing)",
  "Workflow:",
  "1) Use list_sources if needed.",
  "2) Use get_schema when schema is uncertain.",
  "3) Use run_query to get real data.",
  "4) Summarize clearly in Spanish with concrete numbers and dates.",
  "If user asks for monthly breakdown, group by month.",
  "If user gives reseller ID, prioritize mapping/filtering by reseller ID.",
  "When possible, include which source was used.",
].join(" ");

function clampText(value: string, maxLen = 12000): string {
  if (value.length <= maxLen) {
    return value;
  }
  return `${value.slice(0, maxLen)}\n...[truncated]`;
}

function formatEuros(value: number): string {
  return new Intl.NumberFormat("es-ES", {
    style: "currency",
    currency: "EUR",
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  }).format(value);
}

function formatPercent(value: number): string {
  return `${new Intl.NumberFormat("es-ES", {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  }).format(value)}%`;
}

function extractBillingIntent(message: string): BillingIntent | null {
  const text = message.trim();
  if (!/factur|venta|coste|beneficio|margen/i.test(text)) {
    return null;
  }

  const yearMatch = text.match(/\b(20\d{2})\b/);
  if (!yearMatch) {
    return null;
  }
  const year = Number(yearMatch[1]);
  if (!Number.isFinite(year) || year < 2000 || year > 2100) {
    return null;
  }

  const monthMap: Array<[RegExp, number]> = [
    [/\benero\b/i, 1],
    [/\bfebrero\b/i, 2],
    [/\bmarzo\b/i, 3],
    [/\babril\b/i, 4],
    [/\bmayo\b/i, 5],
    [/\bjunio\b/i, 6],
    [/\bjulio\b/i, 7],
    [/\bagosto\b/i, 8],
    [/\bseptiembre\b/i, 9],
    [/\boctubre\b/i, 10],
    [/\bnoviembre\b/i, 11],
    [/\bdiciembre\b/i, 12],
  ];
  let monthFilter: number | null = null;
  for (const [regex, monthNumber] of monthMap) {
    if (regex.test(text)) {
      monthFilter = monthNumber;
      break;
    }
  }

  const byMonth =
    monthFilter !== null || /\bmes(es)?\b/i.test(text) || /desglosad[oa]/i.test(text) || /mensual/i.test(text);

  let resellerId = 0;
  const resellerIdMatch =
    text.match(/\bid[_\s-]*reseller\s*[:=]?\s*(\d+)\b/i) ?? text.match(/\breseller\s*[:=]?\s*(\d+)\b/i);
  if (resellerIdMatch) {
    resellerId = Number(resellerIdMatch[1]);
  }

  let client = "";
  const namedPatterns = [
    /factur(?:aci[oó]n)?\s+de\s+(.+?)\s+(?:del|de)\s+20\d{2}/i,
    /ha\s+facturado\s+(.+?)\s+(?:en|del|de)\s+20\d{2}/i,
    /pasame\s+factur(?:aci[oó]n)?\s+de\s+(.+?)\s+(?:en|del|de)\s+20\d{2}/i,
  ];
  for (const pattern of namedPatterns) {
    const match = text.match(pattern);
    if (match && match[1]) {
      client = match[1].trim();
      break;
    }
  }

  if (client === "") {
    const generic = text.match(/\bde\s+(.+?)\s+(?:en|del|de)\s+20\d{2}/i);
    if (generic && generic[1]) {
      client = generic[1].trim();
    }
  }

  client = client
    .replace(/\b(febrero|enero|marzo|abril|mayo|junio|julio|agosto|septiembre|octubre|noviembre|diciembre)\b/gi, "")
    .replace(/[.,;:!?]+$/g, "")
    .trim();

  if (resellerId <= 0 && client === "") {
    return null;
  }

  return {
    year,
    client,
    resellerId,
    byMonth,
    monthFilter,
  };
}

async function callBillingQuery(intent: BillingIntent): Promise<BillingPayload> {
  const backendBase = process.env.PHP_BACKEND_BASE_URL ?? "http://backend/";
  const endpoint = new URL("api/chat_query.php", backendBase);
  const form = new URLSearchParams();
  form.set("action", "billing_query");
  form.set("year", String(intent.year));
  form.set("by_month", intent.byMonth ? "1" : "0");
  if (intent.resellerId > 0) {
    form.set("reseller_id", String(intent.resellerId));
  }
  if (intent.client !== "") {
    form.set("client", intent.client);
  }

  const response = await fetch(endpoint.toString(), {
    method: "POST",
    headers: {
      "Content-Type": "application/x-www-form-urlencoded",
    },
    body: form.toString(),
    cache: "no-store",
  });

  const payload = (await response.json()) as BillingPayload;
  if (!response.ok) {
    throw new Error(payload.error ?? "Error consultando facturacion.");
  }

  return payload;
}

function buildBillingAnswer(intent: BillingIntent, payload: BillingPayload): string {
  const data = payload.data;
  if (!payload.ok || !data) {
    return "No he podido obtener la facturacion en este momento.";
  }

  const resellerLabel = data.query?.reseller_id
    ? `Reseller ${data.query.reseller_id}${data.query?.reseller_name ? ` (${data.query.reseller_name})` : ""}`
    : (data.query?.client ?? intent.client);

  const rows = Array.isArray(data.rows) ? data.rows : [];
  const totals = data.totals;

  if (intent.monthFilter !== null) {
    const monthRow = rows.find((row) => Number(row.month ?? 0) === intent.monthFilter);
    if (!monthRow) {
      return `No encuentro facturacion para ${resellerLabel} en ${intent.year} mes ${intent.monthFilter}.`;
    }
    const venta = Number(monthRow.venta_total ?? 0);
    const coste = Number(monthRow.coste_total ?? 0);
    const beneficio = Number(monthRow.beneficio_total ?? 0);
    const margen = Number(monthRow.margen_pct ?? 0);

    return [
      `Facturacion de ${resellerLabel} en ${intent.monthFilter}/${intent.year}:`,
      `- Venta: ${formatEuros(venta)}`,
      `- Coste: ${formatEuros(coste)}`,
      `- Beneficio: ${formatEuros(beneficio)}`,
      `- Margen: ${formatPercent(margen)}`,
    ].join("\n");
  }

  if (intent.byMonth) {
    if (rows.length === 0) {
      return `No encuentro facturacion para ${resellerLabel} en ${intent.year}.`;
    }

    const lines = rows.map((row) => {
      const month = Number(row.month ?? 0);
      const venta = Number(row.venta_total ?? 0);
      const coste = Number(row.coste_total ?? 0);
      const beneficio = Number(row.beneficio_total ?? 0);
      const margen = Number(row.margen_pct ?? 0);
      return `- ${month}/${intent.year}: venta ${formatEuros(venta)}, coste ${formatEuros(coste)}, beneficio ${formatEuros(beneficio)}, margen ${formatPercent(margen)}`;
    });

    const totalVenta = Number(totals?.venta_total ?? 0);
    const totalCoste = Number(totals?.coste_total ?? 0);
    const totalBeneficio = Number(totals?.beneficio_total ?? 0);
    const totalMargen = Number(totals?.margen_pct ?? 0);
    lines.push(
      `Total ${intent.year}: venta ${formatEuros(totalVenta)}, coste ${formatEuros(totalCoste)}, beneficio ${formatEuros(totalBeneficio)}, margen ${formatPercent(totalMargen)}`,
    );

    return `Facturacion mensual de ${resellerLabel} en ${intent.year}:\n${lines.join("\n")}`;
  }

  if (!totals) {
    return `No encuentro facturacion para ${resellerLabel} en ${intent.year}.`;
  }

  return [
    `Facturacion de ${resellerLabel} en ${intent.year}:`,
    `- Venta: ${formatEuros(Number(totals.venta_total ?? 0))}`,
    `- Coste: ${formatEuros(Number(totals.coste_total ?? 0))}`,
    `- Beneficio: ${formatEuros(Number(totals.beneficio_total ?? 0))}`,
    `- Margen: ${formatPercent(Number(totals.margen_pct ?? 0))}`,
  ].join("\n");
}

function safeJsonParse(value: string): Record<string, unknown> {
  try {
    const parsed = JSON.parse(value);
    if (parsed && typeof parsed === "object") {
      return parsed as Record<string, unknown>;
    }
    return {};
  } catch {
    return {};
  }
}

function toOpenAIConversation(history: ChatHistoryItem[], userMessage: string): OpenAIMessage[] {
  const conversation: OpenAIMessage[] = [{ role: "system", content: SYSTEM_PROMPT }];

  for (const item of history.slice(-8)) {
    if (!item || (item.role !== "assistant" && item.role !== "user")) {
      continue;
    }
    if (typeof item.content !== "string" || item.content.trim() === "") {
      continue;
    }
    conversation.push({
      role: item.role,
      content: item.content.trim(),
    });
  }

  conversation.push({ role: "user", content: userMessage });
  return conversation;
}

async function callOpenAI(args: {
  apiKey: string;
  model: string;
  messages: OpenAIMessage[];
}): Promise<OpenAIMessage> {
  const response = await fetch("https://api.openai.com/v1/chat/completions", {
    method: "POST",
    headers: {
      Authorization: `Bearer ${args.apiKey}`,
      "Content-Type": "application/json",
    },
    body: JSON.stringify({
      model: args.model,
      temperature: 0,
      messages: args.messages,
      tools: TOOL_DEFINITIONS,
      tool_choice: "auto",
    }),
  });

  const payload = (await response.json()) as OpenAIResponse;
  if (!response.ok) {
    throw new Error(payload.error?.message ?? "Error calling OpenAI.");
  }

  const message = payload.choices?.[0]?.message;
  if (!message) {
    throw new Error("OpenAI returned no message.");
  }

  return message;
}

async function callBackendTool(
  action: "list_sources" | "get_schema" | "run_query",
  params: Record<string, unknown>,
): Promise<Record<string, unknown>> {
  const backendBase = process.env.PHP_BACKEND_BASE_URL ?? "http://backend/";
  const endpoint = new URL("api/ai_tools.php", backendBase);
  const form = new URLSearchParams();
  form.set("action", action);

  for (const [key, value] of Object.entries(params)) {
    if (value === undefined || value === null) {
      continue;
    }
    form.set(key, String(value));
  }

  const response = await fetch(endpoint.toString(), {
    method: "POST",
    headers: {
      "Content-Type": "application/x-www-form-urlencoded",
    },
    body: form.toString(),
    cache: "no-store",
  });

  const payload = (await response.json()) as Record<string, unknown>;
  if (!response.ok) {
    const message = typeof payload.error === "string" ? payload.error : `Tool ${action} failed.`;
    throw new Error(message);
  }

  return payload;
}

async function executeToolCall(toolCall: OpenAIToolCall): Promise<string> {
  const name = toolCall.function.name;
  const args = safeJsonParse(toolCall.function.arguments ?? "{}");

  if (name !== "list_sources" && name !== "get_schema" && name !== "run_query") {
    return JSON.stringify({
      ok: false,
      error: `Unknown tool: ${name}`,
    });
  }

  try {
    const result = await callBackendTool(name, args);
    return clampText(JSON.stringify(result));
  } catch (error) {
    return JSON.stringify({
      ok: false,
      error: error instanceof Error ? error.message : "Tool execution error.",
    });
  }
}

export async function POST(request: NextRequest) {
  try {
    const body = (await request.json()) as { message?: string; history?: ChatHistoryItem[] };
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
    const history = Array.isArray(body.history) ? body.history : [];

    const billingIntent = extractBillingIntent(message);
    if (billingIntent !== null) {
      try {
        const billingPayload = await callBillingQuery(billingIntent);
        return NextResponse.json({
          ok: true,
          answer: buildBillingAnswer(billingIntent, billingPayload),
        });
      } catch (error) {
        const fallbackMessage = error instanceof Error ? error.message : "Error consultando facturacion.";
        return NextResponse.json({
          ok: true,
          answer: `No he podido consultar facturacion ahora mismo: ${fallbackMessage}`,
        });
      }
    }

    const messages = toOpenAIConversation(history, message);

    for (let step = 0; step < 10; step += 1) {
      const assistantMessage = await callOpenAI({
        apiKey,
        model,
        messages,
      });

      messages.push({
        role: "assistant",
        content: assistantMessage.content ?? null,
        tool_calls: assistantMessage.tool_calls,
      });

      const toolCalls = assistantMessage.tool_calls ?? [];
      if (toolCalls.length === 0) {
        const answer = String(assistantMessage.content ?? "").trim();
        if (!answer) {
          return NextResponse.json({
            ok: true,
            answer: "No he podido generar respuesta. Reformula la consulta indicando fuente o periodo.",
          });
        }
        return NextResponse.json({ ok: true, answer });
      }

      for (const call of toolCalls) {
        const toolContent = await executeToolCall(call);
        messages.push({
          role: "tool",
          tool_call_id: call.id,
          name: call.function.name,
          content: toolContent,
        });
      }
    }

    return NextResponse.json({
      ok: true,
      answer: "Se alcanzo el limite de iteraciones. Intenta una consulta mas concreta.",
    });
  } catch (error) {
    const message = error instanceof Error ? error.message : "Error inesperado en Chat IA.";
    return NextResponse.json({ ok: false, error: message }, { status: 500 });
  }
}
