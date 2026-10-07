import { Star } from "lucide-react";
import type { Review } from "@/lib/reviews";
import type { ViewProps } from "@/render/ViewProps";
import { useReviews } from "@/lib/reviews";
import { safeHref, safeImg } from "../links";
import type { ReviewsProps } from "./schema";

export function Stars({ rating }: { rating: number }) {
  const on = Math.max(0, Math.min(5, Math.round(rating)));
  return (
    <span className="pf-stars" role="img" aria-label={`${on} out of 5`}>
      {[0, 1, 2, 3, 4].map(i => (
        <Star
          key={i}
          size={16}
          aria-hidden="true"
          fill={i < on ? "currentColor" : "none"}
          className={i < on ? "on" : ""}
        />
      ))}
    </span>
  );
}

const clip = (s: string, n: number) =>
  s.length > n ? `${s.slice(0, n - 1).trimEnd()}…` : s;

/** The Google "G" mark (it says where a review comes from). */
function GoogleG({ size }: { size: number }) {
  return (
    <svg
      xmlns="http://www.w3.org/2000/svg"
      width={size}
      height={size}
      viewBox="0 0 48 48"
      aria-hidden="true"
    >
      <path
        fill="#EA4335"
        d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"
      />
      <path
        fill="#4285F4"
        d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"
      />
      <path
        fill="#FBBC05"
        d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"
      />
      <path
        fill="#34A853"
        d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"
      />
    </svg>
  );
}

const WIDGETS = ["carousel", "cards", "masonry", "list", "slider"];

/** Label above the score on the badge layout. Mirrored by rk_builder_review_label(). */
export const ratingLabel = (rating: number): string =>
  rating >= 4.5
    ? "Excellent"
    : rating >= 4
      ? "Great"
      : rating >= 3
        ? "Good"
        : "Rated";

/** "Google" in the brand colours, then " Reviews". */
function Wordmark() {
  return (
    <b className="rv-wm">
      {"Google".split("").map((c, i) => (
        <span key={i}>{c}</span>
      ))}
      {" Reviews"}
    </b>
  );
}

/** One card of the Google-style widget. Mirrored by rk_builder_review_card() in render/pf2.php. */
function WidgetCard({ r, i }: { r: Review; i: number }) {
  const photo = safeImg(r.photo ?? "");
  const initial = ([...r.author][0] ?? "").toUpperCase();
  return (
    <article className="rv-card">
      <div className="rv-top">
        <span className="rv-ava">
          <span className={`rv-avatar c${i % 6}`}>
            {photo ? (
              <img
                src={photo}
                alt=""
                loading="lazy"
                decoding="async"
                referrerPolicy="no-referrer"
              />
            ) : (
              initial
            )}
          </span>
          {r.source === "google" && (
            <span className="rv-gb">
              <GoogleG size={14} />
            </span>
          )}
        </span>
        <span className="rv-who">
          <strong>{r.author}</strong>
          {r.date && <small>{r.date}</small>}
        </span>
      </div>
      <Stars rating={r.rating} />
      {r.text && <p className="rv-text">{r.text}</p>}
      {r.text && (
        <button type="button" className="rv-more" hidden>
          Read more
        </button>
      )}
    </article>
  );
}

export function ReviewsView({ props }: ViewProps<ReviewsProps>) {
  const data = useReviews();
  const items = (data?.items ?? [])
    .filter(r => !r.hidden && r.rating >= props.minRating)
    .slice(0, props.limit);
  const profile = safeHref(data?.links.profile ?? "");
  const write = safeHref(data?.links.write ?? "");
  const summary = data?.summary;
  const layout = props.layout ?? "grid";
  const heading = (props.eyebrow || props.heading || props.intro) && (
    <div className="pf-center">
      {props.eyebrow && <p className="pf-kicker">{props.eyebrow}</p>}
      {props.heading && <h2>{props.heading}</h2>}
      {props.intro && <p className="pf-intro">{props.intro}</p>}
    </div>
  );
  if (layout === "badge") {
    const href = profile || write;
    const inner = summary && summary.count > 0 && (
      <>
        <GoogleG size={34} />
        <strong className="rv-label">{`${ratingLabel(summary.rating)} on Google`}</strong>
        <span className="rv-score">
          <b>{summary.rating.toFixed(1)}</b>
          <Stars rating={summary.rating} />
        </span>
        <span className="rv-count">{`${summary.count} review${summary.count === 1 ? "" : "s"}`}</span>
      </>
    );
    return (
      <section
        className={`pf-section pf-reviews ${props.tone} rv-widget rv-badge-layout`}
      >
        <div className="pf-wrap">
          {heading}
          {inner && (
            <div className="rv-badge-wrap">
              {href ? (
                <a
                  className="rv-badge"
                  href={href}
                  target="_blank"
                  rel="noopener noreferrer"
                >
                  {inner}
                </a>
              ) : (
                <div className="rv-badge">{inner}</div>
              )}
            </div>
          )}
        </div>
      </section>
    );
  }
  if (WIDGETS.includes(layout)) {
    const slide = layout === "carousel" || layout === "slider";
    const has = items.length > 0 || (summary && summary.count > 0);
    return (
      <section
        className={`pf-section pf-reviews ${props.tone} rv-widget rv-${layout} rv-c${props.cols}`}
      >
        <div className="pf-wrap">
          {heading}
          {has && (
            <div className="rv-shell">
              <div className="rv-head">
                <div className="rv-meta">
                  <Wordmark />
                  {props.showSummary && summary && summary.count > 0 && (
                    <span className="rv-rate">
                      <strong>{summary.rating.toFixed(1)}</strong>
                      <Stars rating={summary.rating} />
                      <span>{`(${summary.count})`}</span>
                    </span>
                  )}
                </div>
                {props.showLinks && write && (
                  <a
                    className="rv-write"
                    href={write}
                    target="_blank"
                    rel="noopener noreferrer"
                  >
                    Review us on Google
                  </a>
                )}
              </div>
              <div className="rv-carousel">
                {slide && (
                  <button
                    type="button"
                    className="rv-nav prev"
                    aria-label="Previous reviews"
                  >
                    ‹
                  </button>
                )}
                <div className="rv-track">
                  {items.map((r, i) => (
                    <WidgetCard key={r.id} r={r} i={i} />
                  ))}
                </div>
                {slide && (
                  <button
                    type="button"
                    className="rv-nav next"
                    aria-label="Next reviews"
                  >
                    ›
                  </button>
                )}
              </div>
              {props.showLinks && profile && (
                <p className="rv-all">
                  <a href={profile} target="_blank" rel="noopener noreferrer">
                    See all reviews on Google
                  </a>
                </p>
              )}
            </div>
          )}
        </div>
      </section>
    );
  }
  return (
    <section className={`pf-section pf-reviews ${props.tone}`}>
      <div className="pf-wrap">
        {(props.eyebrow || props.heading || props.intro) && (
          <div className="pf-center">
            {props.eyebrow && <p className="pf-kicker">{props.eyebrow}</p>}
            {props.heading && <h2>{props.heading}</h2>}
            {props.intro && <p className="pf-intro">{props.intro}</p>}
          </div>
        )}
        {props.showSummary && summary && summary.count > 0 && (
          <div className="pf-rv-summary">
            <Stars rating={summary.rating} />
            <strong>{summary.rating.toFixed(1)}</strong>
            <span>
              {summary.count} Google review{summary.count === 1 ? "" : "s"}
            </span>
          </div>
        )}
        {props.showLinks && (profile || write) && (
          <div className="pf-rv-links">
            {profile && (
              <a
                className="pf-btn outline"
                href={profile}
                target="_blank"
                rel="noopener noreferrer"
              >
                See all reviews on Google
              </a>
            )}
            {write && (
              <a
                className="pf-btn dark"
                href={write}
                target="_blank"
                rel="noopener noreferrer"
              >
                Leave a review
              </a>
            )}
          </div>
        )}
        <div
          className="pf-cardgrid pf-rv-grid"
          style={{
            gridTemplateColumns: `repeat(${props.cols}, minmax(0, 1fr))`,
          }}
        >
          {items.map(r => (
            <article key={r.id}>
              <Stars rating={r.rating} />
              {r.text && <p className="pf-rv-text">{clip(r.text, 280)}</p>}
              <footer>
                <strong>{r.author}</strong>
                {r.date && <small>{r.date}</small>}
                {r.source === "google" && (
                  <span className="pf-rv-src">Google</span>
                )}
              </footer>
            </article>
          ))}
        </div>
      </div>
    </section>
  );
}
