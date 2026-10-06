import { ArrowRight, ArrowUpRight, CheckCircle2 } from "lucide-react";
import type { ViewProps } from "@/render/ViewProps";
import {
  HEX,
  blurbTag,
  paragraphs,
  parseRows,
  safeHref,
  safeImg,
  semi,
  uniqueTags,
} from "../links";
import type { CatalogProps } from "./schema";

export function CatalogView({ props }: ViewProps<CatalogProps>) {
  const rows = parseRows(props.items, 7, 150);
  const modals = parseRows(props.modals ?? "", 4, 150);
  const modalLabel = props.modalLabel || "View all products";
  const ctas = semi(props.modalCta ?? "", 2)
    .map(c => parseRows(c, 2, 1)[0])
    .filter(c => c && c[0] !== "" && safeHref(c[1]) !== "");
  const byEyebrow = props.tagField === "eyebrow";
  const tagOf = (r: string[]) => (byEyebrow ? r[1] : blurbTag(r[3]));
  const tags = props.filters ? uniqueTags(rows.map(tagOf)) : [];
  const pageSize = props.pageSize ?? 0;
  const paged = pageSize > 0;
  const searchLabel = props.searchLabel || "Search";
  const pagerOn = paged || props.search === true;
  return (
    <section
      className={`pf-section pf-catalog ${props.tone}`}
      {...(pagerOn ? { "data-page": pageSize } : {})}
    >
      <div className="pf-wrap">
        {(props.eyebrow || props.heading || props.intro) && (
          <div className="pf-catalog-head">
            {props.eyebrow && <p className="pf-kicker">{props.eyebrow}</p>}
            {props.heading && <h2>{props.heading}</h2>}
            {paragraphs(props.intro).map((p, i) => (
              <p className="pf-body" key={i}>
                {p}
              </p>
            ))}
          </div>
        )}
        {props.search && (
          <div className="pf-search">
            <input
              type="search"
              placeholder={searchLabel}
              aria-label={searchLabel}
              data-search=""
            />
          </div>
        )}
        {tags.length > 0 && (
          <div className="pf-filters" role="group" aria-label="Filter">
            {["All", ...tags].map((t, k) => (
              <button
                type="button"
                className={k === 0 ? "on" : undefined}
                data-filter={t}
                key={t}
              >
                {t}
              </button>
            ))}
          </div>
        )}
        <div
          className={props.joined ? "pf-cards joined" : "pf-cards"}
          style={{
            gridTemplateColumns: `repeat(${props.cols}, minmax(0, 1fr))`,
          }}
        >
          {rows.map(
            ([image, eyebrow, title, blurb, specs, bullets, link], i) => {
              const swatch = HEX.test(image);
              const src = swatch ? "" : safeImg(image);
              const href = safeHref(link);
              const specRows = semi(specs).flatMap(s => {
                const cut = s.indexOf(":");
                if (cut < 1) return [];
                const k = s.slice(0, cut).trim();
                const v = s.slice(cut + 1).trim();
                return k !== "" && v !== "" ? [[k, v] as const] : [];
              });
              const bulletList = semi(bullets);
              const body = (
                <>
                  {swatch && (
                    <div
                      className="pf-card-media swatch"
                      style={{ backgroundColor: image }}
                    />
                  )}
                  {src && (
                    <div className="pf-card-media">
                      <img src={src} alt="" decoding="async" loading="lazy" />
                      {props.numbered && (
                        <span className="pf-badge">
                          {String(i + 1).padStart(2, "0")}
                        </span>
                      )}
                    </div>
                  )}
                  <div className="pf-card-body">
                    {eyebrow && <p className="pf-card-eyebrow">{eyebrow}</p>}
                    {href ? (
                      <div className="pf-card-title">
                        <h3>{title}</h3>
                        <ArrowUpRight size={20} aria-hidden="true" />
                      </div>
                    ) : (
                      <h3>{title}</h3>
                    )}
                    {blurb && <p>{blurb}</p>}
                    {specRows.length > 0 && (
                      <dl className="pf-specs">
                        {specRows.map(([k, v]) => (
                          <div key={k}>
                            <dt>{k}</dt>
                            <dd>{v}</dd>
                          </div>
                        ))}
                      </dl>
                    )}
                    {bulletList.length > 0 && (
                      <ul className="pf-bullets">
                        {bulletList.map((b, j) => (
                          <li key={j}>
                            <CheckCircle2 size={16} aria-hidden="true" />
                            {b}
                          </li>
                        ))}
                      </ul>
                    )}
                    {!href && modals[i] && modals[i][1] !== "" && (
                      <button
                        type="button"
                        className="pf-open"
                        data-modal-open={i}
                      >
                        {modalLabel}
                        <ArrowRight size={16} aria-hidden="true" />
                      </button>
                    )}
                  </div>
                </>
              );
              const tag = props.filters ? tagOf(rows[i]) : "";
              const tagAttr = tag !== "" ? { "data-tag": tag } : {};
              const hid = paged && i >= pageSize ? { hidden: true } : {};
              return href ? (
                <a
                  className="pf-card link"
                  href={href}
                  key={i}
                  {...tagAttr}
                  {...hid}
                >
                  {body}
                </a>
              ) : (
                <article className="pf-card" key={i} {...tagAttr} {...hid}>
                  {body}
                </article>
              );
            }
          )}
        </div>
        {pagerOn && (
          <p className="pf-empty" hidden>
            Nothing matches. Try a different word or category.
          </p>
        )}
        {paged && rows.length > pageSize && (
          <div className="pf-more-wrap">
            <p className="pf-count" data-count="" aria-live="polite">
              {`Showing ${pageSize} of ${rows.length}`}
            </p>
            <button type="button" className="pf-btn outline" data-more="">
              Load more
            </button>
          </div>
        )}
        {modals.map(([image, title, intro, items], i) => {
          if (title === "") return null;
          const src = safeImg(image);
          return (
            <dialog
              className="pf-modal"
              data-modal={i}
              aria-label={title}
              key={i}
            >
              <button
                type="button"
                className="pf-modal-x"
                aria-label="Close"
                data-modal-close=""
              >
                ×
              </button>
              <div className="pf-modal-grid">
                {src && (
                  <div className="pf-modal-img">
                    <img src={src} alt="" decoding="async" loading="lazy" />
                  </div>
                )}
                <div className="pf-modal-body">
                  <p className="pf-kicker">Product catalog</p>
                  <h2>{title}</h2>
                  {intro && <p>{intro}</p>}
                  <ul className="pf-modal-items">
                    {semi(items, 40).map((it, j) => (
                      <li key={j}>{it}</li>
                    ))}
                  </ul>
                  {ctas.length > 0 && (
                    <div className="pf-modal-actions">
                      {ctas.map(([label, link], k) => (
                        <a
                          className={k === 0 ? "pf-btn dark" : "pf-btn outline"}
                          href={safeHref(link)}
                          key={k}
                        >
                          {label}
                          {k === 0 && (
                            <ArrowRight size={16} aria-hidden="true" />
                          )}
                        </a>
                      ))}
                    </div>
                  )}
                </div>
              </div>
            </dialog>
          );
        })}
      </div>
    </section>
  );
}
