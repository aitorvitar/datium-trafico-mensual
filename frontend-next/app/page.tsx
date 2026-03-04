import ChatIASection from "./components/chat-ia";

type SearchValue = string | string[] | undefined;
type SearchParams = Record<string, SearchValue>;

type ReportData = {
  title: string;
  columns: string[];
  rows: Array<Record<string, string | number>>;
  filters: Record<string, string>;
  totalRows: number;
  filteredCount: number;
  page: number;
  maxPage: number;
  perPage: number;
  sortColumn: string;
  sortDir: "asc" | "desc";
};

type ReportOption = {
  id: string;
  title: string;
};

const REPORT_OPTIONS: ReportOption[] = [
  { id: "global_reseller_voz", title: "Global - Reseller | Entrantes | Salientes | Facturacion" },
  { id: "entrantes_did_mensual", title: "Entrantes - Minutos entrantes por DID" },
  { id: "entrantes_por_reseller", title: "Entrantes - Minutos y llamadas por reseller" },
  { id: "salientes_por_retail", title: "Salientes - Minutos, llamadas y coste por retail client" },
  { id: "salientes_por_reseller", title: "Salientes - Minutos, llamadas y coste por reseller" },
];

function firstValue(value: SearchValue): string {
  if (Array.isArray(value)) {
    return value[0] ?? "";
  }
  return value ?? "";
}

function parseFilters(searchParams: SearchParams): Record<string, string> {
  const parsed: Record<string, string> = {};
  for (const [key, value] of Object.entries(searchParams)) {
    if (!key.startsWith("filters[") || !key.endsWith("]")) {
      continue;
    }
    const rawColumn = key.slice(8, -1);
    parsed[rawColumn] = firstValue(value);
  }
  return parsed;
}

function buildHref(input: {
  report: string;
  from: string;
  to: string;
  run?: boolean;
  page?: number;
  sort?: string;
  dir?: string;
  filters?: Record<string, string>;
}): string {
  const qs = new URLSearchParams();
  qs.set("report", input.report);
  qs.set("from", input.from);
  qs.set("to", input.to);
  if (input.run) {
    qs.set("run", "1");
  }

  if (input.page && input.page > 1) {
    qs.set("page", String(input.page));
  }
  if (input.sort) {
    qs.set("sort", input.sort);
  }
  if (input.dir) {
    qs.set("dir", input.dir);
  }
  if (input.filters) {
    for (const [column, value] of Object.entries(input.filters)) {
      if (value.trim() === "") {
        continue;
      }
      qs.set(`filters[${column}]`, value);
    }
  }

  return `/?${qs.toString()}`;
}

function ensureTrailingSlash(value: string): string {
  if (value.trim() === "") {
    return "/";
  }
  return value.endsWith("/") ? value : `${value}/`;
}

function toNumber(value: unknown): number {
  if (typeof value === "number") {
    return value;
  }
  const normalized = String(value ?? "").replace(",", ".");
  const parsed = Number(normalized);
  return Number.isFinite(parsed) ? parsed : 0;
}

function fmtNumber(value: unknown): string {
  return new Intl.NumberFormat("es-ES", { maximumFractionDigits: 2 }).format(toNumber(value));
}

function fmtEuro(value: unknown): string {
  return `${new Intl.NumberFormat("es-ES", { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(
    toNumber(value),
  )} €`;
}

function fmtPercent(value: unknown): string {
  return `${new Intl.NumberFormat("es-ES", { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(
    toNumber(value),
  )} %`;
}

function formatDisplayValue(column: string, value: unknown): string {
  if (
    column.startsWith("Facturacion") ||
    column === "Venta servicios" ||
    column === "Compra servicios" ||
    column === "Beneficio servicios"
  ) {
    return fmtEuro(value);
  }

  if (column.startsWith("Margen")) {
    return fmtPercent(value);
  }

  if (
    column.startsWith("Minutos") ||
    column.includes("llamadas") ||
    column.includes("coste") ||
    column.includes("suma") ||
    column.includes("total")
  ) {
    return fmtNumber(value);
  }

  return String(value ?? "");
}

function sumColumn(rows: Array<Record<string, string | number>>, column: string): number {
  return rows.reduce((acc, row) => acc + toNumber(row[column]), 0);
}

async function fetchReport(params: {
  report: string;
  from: string;
  to: string;
  page: number;
  sort: string;
  dir: "asc" | "desc";
  filters: Record<string, string>;
}): Promise<ReportData> {
  const backendBase = process.env.PHP_BACKEND_BASE_URL ?? "http://127.0.0.1/Datium%20-%20trafico%20mensual/";
  const endpoint = new URL("api/report.php", backendBase);
  endpoint.searchParams.set("report", params.report);
  endpoint.searchParams.set("from", params.from);
  endpoint.searchParams.set("to", params.to);
  endpoint.searchParams.set("page", String(params.page));
  endpoint.searchParams.set("per_page", "20");
  if (params.sort) {
    endpoint.searchParams.set("sort", params.sort);
    endpoint.searchParams.set("dir", params.dir);
  }
  for (const [column, value] of Object.entries(params.filters)) {
    if (value.trim() === "") {
      continue;
    }
    endpoint.searchParams.set(`filters[${column}]`, value);
  }

  const response = await fetch(endpoint.toString(), { cache: "no-store" });
  const payload = (await response.json()) as { ok: boolean; data?: ReportData; error?: string };

  if (!response.ok || !payload.ok || !payload.data) {
    throw new Error(payload.error ?? "No se pudo cargar el reporte.");
  }

  return payload.data;
}

export default async function Page({ searchParams }: { searchParams: SearchParams }) {
  const shouldRun = firstValue(searchParams.run) === "1";
  const report = firstValue(searchParams.report) || "global_reseller_voz";
  const from = firstValue(searchParams.from);
  const to = firstValue(searchParams.to);
  const page = Math.max(1, Number.parseInt(firstValue(searchParams.page) || "1", 10) || 1);
  const sort = firstValue(searchParams.sort);
  const dir = firstValue(searchParams.dir).toLowerCase() === "asc" ? "asc" : "desc";
  const filters = parseFilters(searchParams);

  let data: ReportData | null = null;
  let error = "";

  if (shouldRun) {
    if (!from || !to) {
      error = "Debes indicar un rango de fechas y pulsar Hacer consulta.";
    } else {
      try {
        data = await fetchReport({
          report,
          from,
          to,
          page,
          sort,
          dir,
          filters,
        });
      } catch (err) {
        error = err instanceof Error ? err.message : "Error cargando datos.";
      }
    }
  }

  const incomingPage = data ? sumColumn(data.rows, "Minutos entrantes") : 0;
  const outgoingWPage = data ? sumColumn(data.rows, "Minutos salientes (W)") : 0;
  const outgoingRPage = data ? sumColumn(data.rows, "Minutos salientes (R)") : 0;

  const backendPublicBase = ensureTrailingSlash(
    process.env.BACKEND_PUBLIC_BASE_URL ?? process.env.PHP_BACKEND_BASE_URL ?? "/",
  );

  return (
    <main className="shell">
      <section className="hero">
        <h1 className="title">Datium Reporting VoIP</h1>
        <p className="subtitle">
          Interfaz renovada con filtros server-side, ordenacion por columnas y exportacion para informes.
        </p>
      </section>

      <section className="panel">
        <form method="get">
          <div className="controlGrid">
            <label className="field">
              Reporte
              <select name="report" defaultValue={report}>
                {REPORT_OPTIONS.map((option) => (
                  <option key={option.id} value={option.id}>
                    {option.title}
                  </option>
                ))}
              </select>
            </label>
            <label className="field">
              Desde
              <input type="date" name="from" defaultValue={from} />
            </label>
            <label className="field">
              Hasta (exclusivo)
              <input type="date" name="to" defaultValue={to} />
            </label>
          </div>
          <input type="hidden" name="run" value="1" />
          <div className="actionRow">
            <button className="btn" type="submit">
              Hacer consulta
            </button>
            {from && to && shouldRun && (
              <a
                className="btn btnGhost"
                href={`${backendPublicBase}export.php?report=${encodeURIComponent(report)}&from=${encodeURIComponent(
                  from,
                )}&to=${encodeURIComponent(to)}`}
              >
                Exportar CSV
              </a>
            )}
            <a className="btn btnGhost" href="/">
              Limpiar
            </a>
          </div>
        </form>
      </section>

      {!shouldRun && <p className="infoBox">Selecciona un rango y pulsa Hacer consulta para cargar datos.</p>}

      {error && <p className="errorBox">{error}</p>}

      {data && (
        <section className="panel">
          <h2 className="title" style={{ fontSize: "1.3rem", marginBottom: 8 }}>
            {data.title}
          </h2>
          <p className="subtitle mono">
            Total filtradas: {data.filteredCount} de {data.totalRows} | Pagina {data.page} de {data.maxPage}
          </p>

          <div className="metrics">
            <article className="metric">
              <p className="metricLabel">Minutos entrantes (pagina)</p>
              <p className="metricValue">{fmtNumber(incomingPage)}</p>
            </article>
            <article className="metric">
              <p className="metricLabel">Minutos salientes W (pagina)</p>
              <p className="metricValue">{fmtNumber(outgoingWPage)}</p>
            </article>
            <article className="metric">
              <p className="metricLabel">Minutos salientes R (pagina)</p>
              <p className="metricValue">{fmtNumber(outgoingRPage)}</p>
            </article>
            <article className="metric">
              <p className="metricLabel">Columnas activas</p>
              <p className="metricValue">{data.columns.length}</p>
            </article>
          </div>

          <form method="get" className="tableFilters">
            <input type="hidden" name="report" value={report} />
            <input type="hidden" name="from" value={from} />
            <input type="hidden" name="to" value={to} />
            <input type="hidden" name="run" value="1" />
            {data.sortColumn ? <input type="hidden" name="sort" value={data.sortColumn} /> : null}
            {data.sortColumn ? <input type="hidden" name="dir" value={data.sortDir} /> : null}

            <div className="filterGrid">
              {data.columns.map((column) => (
                <label className="field" key={column}>
                  {column}
                  <input name={`filters[${column}]`} defaultValue={data.filters[column] ?? ""} placeholder="Filtrar..." />
                </label>
              ))}
            </div>

            <div className="actionRow">
              <button className="btn" type="submit">
                Aplicar filtros
              </button>
              <a className="btn btnGhost" href={buildHref({ report, from, to, run: true })}>
                Limpiar
              </a>
            </div>
          </form>

          <div className="tableWrap">
            <table className="dataTable">
              <thead>
                <tr>
                  {data.columns.map((column) => {
                    const nextDir = data.sortColumn === column && data.sortDir === "asc" ? "desc" : "asc";
                    const suffix =
                      data.sortColumn === column ? (data.sortDir === "asc" ? " ▲" : " ▼") : "";

                    return (
                      <th key={column}>
                        <a
                          className="sortLink"
                          href={buildHref({
                            report,
                            from,
                            to,
                            run: true,
                            page: 1,
                            sort: column,
                            dir: nextDir,
                            filters,
                          })}
                        >
                          {column}
                          {suffix}
                        </a>
                      </th>
                    );
                  })}
                </tr>
              </thead>
              <tbody>
                {data.rows.length === 0 ? (
                  <tr>
                    <td className="empty" colSpan={Math.max(1, data.columns.length)}>
                      Sin resultados para ese rango.
                    </td>
                  </tr>
                ) : (
                  data.rows.map((row, idx) => (
                    <tr key={`row-${idx}`}>
                      {data.columns.map((column) => {
                        if (report === "global_reseller_voz" && column === "Reseller" && Number(row.id_reseller || 0) > 0) {
                          const detailHref =
                            `${backendPublicBase}reseller_detail.php?id_reseller=${encodeURIComponent(
                              String(row.id_reseller),
                            )}&from=${encodeURIComponent(from)}&to=${encodeURIComponent(to)}`;
                          return (
                            <td key={`${idx}-${column}`}>
                              <a className="resellerLink" href={detailHref} target="_blank" rel="noopener noreferrer">
                                {String(row[column] ?? "")}
                              </a>
                            </td>
                          );
                        }

                        return <td key={`${idx}-${column}`}>{formatDisplayValue(column, row[column])}</td>;
                      })}
                    </tr>
                  ))
                )}
              </tbody>
            </table>
          </div>

          {data.maxPage > 1 && (
            <nav className="pagination">
              {data.page > 1 && (
                <a
                  className="btn btnGhost"
                  href={buildHref({ report, from, to, run: true, page: data.page - 1, sort, dir, filters })}
                >
                  Anterior
                </a>
              )}

              <span className="pageTag">
                Pagina {data.page} / {data.maxPage}
              </span>

              {data.page < data.maxPage && (
                <a
                  className="btn btnGhost"
                  href={buildHref({ report, from, to, run: true, page: data.page + 1, sort, dir, filters })}
                >
                  Siguiente
                </a>
              )}
            </nav>
          )}
        </section>
      )}

      <ChatIASection />
    </main>
  );
}
