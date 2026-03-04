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
    candidates?: Array<{
      id: number;
      name: string;
      score?: number;
    }>;
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
  "Use context from the last 10 messages.",
  "If date is missing, infer from recent context; if still ambiguous, ask a short clarification.",
  "If reseller is not found, suggest closest matches and ask confirmation.",
  "Available sources are from tools and can include:",
  "- db78 (MySQL voipswitch incoming CDR)",
  "- workflow (MySQL workflowtest mapping resellers/DID)",
  "- wholesale (MySQL voipswitch wholesale CDR)",
  "- castiphone/eclipSe (SQL Server billing with strict read-only policy)",
  "Workflow:",
  "1) Use list_sources if needed.",
  "2) Use get_schema when schema is uncertain.",
  "3) Use run_query to get real data.",
  "4) Summarize clearly in Spanish with concrete numbers and dates.",
  "If user asks for monthly breakdown, group by month.",
  "If user gives reseller ID, prioritize mapping/filtering by reseller ID.",
  "When possible, include which source was used.",
].join(" ");

const MAX_TOOL_STEPS = (() => {
  const raw = Number(process.env.OPENAI_TOOL_MAX_STEPS ?? "20");
  if (!Number.isFinite(raw)) {
    return 20;
  }
  return Math.min(40, Math.max(6, Math.floor(raw)));
})();
const DETERMINISTIC_BILLING_ENABLED = String(process.env.CHAT_BILLING_DETERMINISTIC ?? "1") === "1";

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

function stripAccents(value: string): string {
  return value.normalize("NFD").replace(/[\u0300-\u036f]/g, "");
}

function normalizeIntentText(value: string): string {
  let text = stripAccents(value.toLowerCase());
  const replacements: Array<[RegExp, string]> = [
    [/\bfcaturacion\b|\bfactruacion\b|\bfaturacion\b|\bfacturacion\b|\bfacturaccion\b/g, "facturacion"],
    [/\bdesglozado\b|\bdesglosao\b|\bdesglosada\b/g, "desglosado"],
    [/\bscaame\b|\bscame\b|\bsacame\b/g, "pasame"],
    [/\bid[_\s-]*reseler\b/g, "id_reseller"],
  ];
  for (const [pattern, replacement] of replacements) {
    text = text.replace(pattern, replacement);
  }

  return text.replace(/[^a-z0-9_]+/g, " ").replace(/\s+/g, " ").trim();
}

function tokenize(value: string): string[] {
  return value
    .split(" ")
    .map((item) => item.trim())
    .filter((item) => item !== "");
}

function levenshteinDistance(a: string, b: string): number {
  if (a === b) {
    return 0;
  }
  if (a.length === 0) {
    return b.length;
  }
  if (b.length === 0) {
    return a.length;
  }

  const matrix: number[][] = Array.from({ length: a.length + 1 }, () => new Array<number>(b.length + 1).fill(0));
  for (let i = 0; i <= a.length; i += 1) {
    matrix[i][0] = i;
  }
  for (let j = 0; j <= b.length; j += 1) {
    matrix[0][j] = j;
  }

  for (let i = 1; i <= a.length; i += 1) {
    for (let j = 1; j <= b.length; j += 1) {
      const cost = a[i - 1] === b[j - 1] ? 0 : 1;
      matrix[i][j] = Math.min(matrix[i - 1][j] + 1, matrix[i][j - 1] + 1, matrix[i - 1][j - 1] + cost);
    }
  }

  return matrix[a.length][b.length];
}

function fuzzyTokenMatch(token: string, target: string, maxDistance = 2): boolean {
  if (token === target) {
    return true;
  }
  if (Math.abs(token.length - target.length) > maxDistance) {
    return false;
  }
  return levenshteinDistance(token, target) <= maxDistance;
}

function extractYear(normalizedText: string): number {
  const match = normalizedText.match(/\b(20\d{2})\b/);
  if (!match) {
    return 0;
  }
  const year = Number(match[1]);
  if (!Number.isFinite(year) || year < 2000 || year > 2100) {
    return 0;
  }
  return year;
}

function extractResellerId(normalizedText: string): number {
  const match =
    normalizedText.match(/\bid_reseller\s*[:=]?\s*(\d+)\b/) ?? normalizedText.match(/\breseller\s*[:=]?\s*(\d+)\b/);
  if (!match) {
    return 0;
  }
  return Number(match[1]);
}

function extractMonth(normalizedText: string): number | null {
  const tokens = tokenize(normalizedText);
  const monthByName: Record<string, number> = {
    enero: 1,
    febrero: 2,
    marzo: 3,
    abril: 4,
    mayo: 5,
    junio: 6,
    julio: 7,
    agosto: 8,
    septiembre: 9,
    octubre: 10,
    noviembre: 11,
    diciembre: 12,
    setiembre: 9,
  };

  for (const [monthName, monthNumber] of Object.entries(monthByName)) {
    if (tokens.some((token) => fuzzyTokenMatch(token, monthName, 2))) {
      return monthNumber;
    }
  }

  return null;
}

function isLikelyBillingRequest(normalizedText: string): boolean {
  const tokens = tokenize(normalizedText);
  const billingKeywords = ["facturacion", "factura", "facturar", "venta", "coste", "beneficio", "margen"];
  for (const keyword of billingKeywords) {
    if (tokens.some((token) => fuzzyTokenMatch(token, keyword, 2))) {
      return true;
    }
  }
  return false;
}

function sanitizeClientCandidate(candidate: string): string {
  const text = normalizeIntentText(candidate);
  const stopwords = new Set([
    "de",
    "del",
    "en",
    "el",
    "la",
    "los",
    "las",
    "por",
    "para",
    "cada",
    "dato",
    "datos",
    "mes",
    "meses",
    "mensual",
    "desglosado",
    "y",
    "total",
    "facturacion",
    "factura",
    "facturar",
    "venta",
    "coste",
    "beneficio",
    "margen",
    "reseller",
    "id_reseller",
    "pasame",
    "sacame",
    "dame",
    "los",
    "las",
  ]);
  const months = new Set([
    "enero",
    "febrero",
    "marzo",
    "abril",
    "mayo",
    "junio",
    "julio",
    "agosto",
    "septiembre",
    "octubre",
    "noviembre",
    "diciembre",
    "setiembre",
  ]);

  return tokenize(text)
    .filter((token) => !stopwords.has(token))
    .filter((token) => !months.has(token))
    .filter((token) => !/^\d+$/.test(token))
    .join(" ")
    .trim();
}

function extractClient(normalizedText: string, year: number): string {
  if (year <= 0) {
    return "";
  }

  const regexes = [
    new RegExp(`(?:facturacion|factura|facturar|venta|coste|beneficio|margen)\\s+(.+?)\\s+(?:de|del|en\\s+)?${year}\\b`, "i"),
    new RegExp(`(?:de|del)\\s+(.+?)\\s+(?:de|del|en\\s+)?${year}\\b`, "i"),
  ];

  for (const regex of regexes) {
    const match = normalizedText.match(regex);
    if (match && match[1]) {
      const parsed = sanitizeClientCandidate(match[1]);
      if (parsed !== "") {
        return parsed;
      }
    }
  }

  return sanitizeClientCandidate(normalizedText);
}

function extractClientLoose(normalizedText: string): string {
  const patterns = [
    /(?:^|\s)y\s+para\s+(.+)$/i,
    /(?:^|\s)para\s+(.+)$/i,
    /(?:^|\s)de\s+(.+)$/i,
  ];
  for (const pattern of patterns) {
    const match = normalizedText.match(pattern);
    if (match && match[1]) {
      const value = sanitizeClientCandidate(match[1]);
      if (value !== "") {
        return value;
      }
    }
  }

  return sanitizeClientCandidate(normalizedText);
}

function extractBillingIntent(message: string): BillingIntent | null {
  const normalized = normalizeIntentText(message);
  if (!isLikelyBillingRequest(normalized)) {
    return null;
  }

  const year = extractYear(normalized);
  if (year <= 0) {
    return null;
  }

  const monthFilter = extractMonth(normalized);
  const byMonth =
    monthFilter !== null ||
    /\bmes(es)?\b/.test(normalized) ||
    /\bdesglosado\b/.test(normalized) ||
    /\bmensual\b/.test(normalized) ||
    /\btotal\b/.test(normalized);

  const resellerId = extractResellerId(normalized);
  const client = extractClient(normalized, year);

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

function extractBillingIntentFromHistory(history: ChatHistoryItem[]): BillingIntent | null {
  const recentUsers = history
    .slice(-10)
    .filter((item) => item.role === "user" && typeof item.content === "string")
    .map((item) => item.content.trim())
    .filter((value) => value !== "");

  for (let i = recentUsers.length - 1; i >= 0; i -= 1) {
    const intent = extractBillingIntent(recentUsers[i]);
    if (intent !== null) {
      return intent;
    }
  }

  return null;
}

function extractFollowUpBillingIntent(history: ChatHistoryItem[], message: string): BillingIntent | null {
  const baseIntent = extractBillingIntentFromHistory(history);
  if (baseIntent === null) {
    return null;
  }

  const normalized = normalizeIntentText(message);
  const directResellerId = extractResellerId(normalized);
  const directYear = extractYear(normalized);
  const directMonth = extractMonth(normalized);
  const wantsMonth = /\bmes(es)?\b|\bmensual\b|\bdesglosado\b|\btotal\b/.test(normalized);
  const directClient = extractClientLoose(normalized);

  const intent: BillingIntent = {
    year: directYear > 0 ? directYear : baseIntent.year,
    client: directClient !== "" ? directClient : baseIntent.client,
    resellerId: directResellerId > 0 ? directResellerId : 0,
    byMonth: baseIntent.byMonth || wantsMonth || directMonth !== null,
    monthFilter: directMonth ?? null,
  };

  if (intent.resellerId <= 0 && intent.client === "") {
    return null;
  }

  return intent;
}

function isAffirmativeReply(normalizedText: string): boolean {
  return /^(si|sii|ok|vale|perfecto|correcto|eso|ese|ese mismo|claro|adelante)\b/.test(normalizedText);
}

function extractSuggestionMeta(history: ChatHistoryItem[]): BillingIntent | null {
  const recentAssistant = history
    .slice(-10)
    .filter((item) => item.role === "assistant" && typeof item.content === "string")
    .map((item) => item.content.trim())
    .filter((text) => text !== "")
    .reverse();

  for (const assistantText of recentAssistant) {
    const refMatch = assistantText.match(/Referencia:\s*id_reseller=(\d+)\s+year=(\d{4})\s+by_month=(0|1)(?:\s+month=(\d+))?/i);
    if (!refMatch) {
      continue;
    }

    const resellerId = Number(refMatch[1]);
    const year = Number(refMatch[2]);
    const byMonth = refMatch[3] === "1";
    const monthFilter = refMatch[4] ? Number(refMatch[4]) : null;
    if (!Number.isFinite(resellerId) || resellerId <= 0 || !Number.isFinite(year) || year < 2000 || year > 2100) {
      continue;
    }

    return {
      year,
      client: "",
      resellerId,
      byMonth,
      monthFilter,
    };
  }

  return null;
}

function buildBillingContext(history: ChatHistoryItem[], currentMessage: string): string {
  const recent = history
    .slice(-6)
    .filter((item) => item.role === "user" && typeof item.content === "string")
    .map((item) => item.content.trim())
    .filter((line) => line !== "");

  return [...recent, currentMessage].join(" ");
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
      const candidates = Array.isArray(data.candidates) ? data.candidates : [];
      if (intent.client !== "" && candidates.length > 0) {
        const top = candidates.slice(0, 3);
        const lines = top.map((candidate, index) => {
          const scoreLabel = typeof candidate.score === "number" ? ` (similitud ${candidate.score.toFixed(1)}%)` : "";
          return `${index + 1}. [${candidate.id}] ${candidate.name}${scoreLabel}`;
        });
        const first = top[0];
        const ref = first
          ? `Referencia: id_reseller=${first.id} year=${intent.year} by_month=${intent.byMonth ? 1 : 0}${intent.monthFilter !== null ? ` month=${intent.monthFilter}` : ""}`
          : "";

        return [
          `No encuentro facturacion para "${intent.client}" en ${intent.year}.`,
          "He encontrado resellers con nombre parecido:",
          ...lines,
          `Si quieres, responde por ejemplo: "si, usa ${first?.id ?? "ID"}".`,
          ref,
        ]
          .filter((line) => line.trim() !== "")
          .join("\n");
      }
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
    const candidates = Array.isArray(data.candidates) ? data.candidates : [];
    if (intent.client !== "" && candidates.length > 0) {
      const top = candidates.slice(0, 3);
      const lines = top.map((candidate, index) => {
        const scoreLabel = typeof candidate.score === "number" ? ` (similitud ${candidate.score.toFixed(1)}%)` : "";
        return `${index + 1}. [${candidate.id}] ${candidate.name}${scoreLabel}`;
      });
      const first = top[0];
      const ref = first
        ? `Referencia: id_reseller=${first.id} year=${intent.year} by_month=${intent.byMonth ? 1 : 0}${intent.monthFilter !== null ? ` month=${intent.monthFilter}` : ""}`
        : "";

      return [
        `No encuentro facturacion para "${intent.client}" en ${intent.year}.`,
        "He encontrado resellers con nombre parecido:",
        ...lines,
        `Si quieres, responde por ejemplo: "si, usa ${first?.id ?? "ID"}".`,
        ref,
      ]
        .filter((line) => line.trim() !== "")
        .join("\n");
    }
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

  for (const item of history.slice(-10)) {
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

    const normalizedMessage = normalizeIntentText(message);
    const directResellerId = extractResellerId(normalizedMessage);
    const baseIntent = extractBillingIntentFromHistory(history);
    const suggestionIntent = extractSuggestionMeta(history);
    const shouldUseSuggestion = isAffirmativeReply(normalizedMessage) && suggestionIntent !== null;
    const likelyBilling =
      isLikelyBillingRequest(normalizedMessage) ||
      directResellerId > 0 ||
      /\b(factur|venta|coste|beneficio|margen)\b/i.test(normalizedMessage) ||
      (baseIntent !== null && /\\b(para|ese|mismo|igual|tambien)\\b/i.test(normalizedMessage)) ||
      shouldUseSuggestion;
    if (DETERMINISTIC_BILLING_ENABLED) {
      let billingIntent = extractBillingIntent(message);
    if (billingIntent === null && likelyBilling) {
      billingIntent = extractBillingIntent(buildBillingContext(history, message));
    }
    if (billingIntent === null) {
      billingIntent = extractFollowUpBillingIntent(history, message);
    }
    if (billingIntent === null && shouldUseSuggestion && suggestionIntent !== null) {
      billingIntent = suggestionIntent;
    }
    if (billingIntent !== null) {
      if (directResellerId > 0) {
        billingIntent.resellerId = directResellerId;
      }

      const directYear = extractYear(normalizedMessage);
      if (directYear > 0) {
        billingIntent.year = directYear;
      }

      const directMonth = extractMonth(normalizedMessage);
      if (directMonth !== null) {
        billingIntent.monthFilter = directMonth;
        billingIntent.byMonth = true;
      }
      if (/\bmes(es)?\b|\bmensual\b|\bdesglosado\b|\btotal\b/.test(normalizedMessage)) {
        billingIntent.byMonth = true;
      }

      const directClient =
        extractYear(normalizedMessage) > 0 || isLikelyBillingRequest(normalizedMessage)
          ? extractClient(normalizedMessage, billingIntent.year)
          : extractClientLoose(normalizedMessage);
      if (directClient !== "") {
        billingIntent.client = directClient;
      }
    }
    if (billingIntent === null && likelyBilling) {
      return NextResponse.json({
        ok: true,
        answer:
          "Para consultar facturacion necesito al menos anio y cliente o id_reseller. Ejemplo: facturacion Telcat 2026 por meses.",
      });
    }
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
    }

    const messages = toOpenAIConversation(history, message);

    for (let step = 0; step < MAX_TOOL_STEPS; step += 1) {
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

    if (DETERMINISTIC_BILLING_ENABLED && likelyBilling) {
      const fallbackIntent = extractBillingIntent(buildBillingContext(history, message));
      if (fallbackIntent !== null) {
        try {
          const billingPayload = await callBillingQuery(fallbackIntent);
          return NextResponse.json({
            ok: true,
            answer: buildBillingAnswer(fallbackIntent, billingPayload),
          });
        } catch {
          // Keep generic fallback below if deterministic query also fails.
        }
      }
    }

    return NextResponse.json({
      ok: true,
      answer: `Se alcanzo el limite de iteraciones (${MAX_TOOL_STEPS}). Intenta una consulta mas concreta.`,
    });
  } catch (error) {
    const message = error instanceof Error ? error.message : "Error inesperado en Chat IA.";
    return NextResponse.json({ ok: false, error: message }, { status: 500 });
  }
}



