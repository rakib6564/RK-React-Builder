import type { ViewProps } from "@/render/ViewProps";
import { useContent } from "@/render/content";
import { parseRows, safeImg, uniqueTags } from "../links";
import { galleryClasses, slugLabel, type GalleryProps } from "./schema";

type Row = [string, string, string];

export function GalleryView({ props }: ViewProps<GalleryProps>) {
  if (props.source && props.source !== "manual")
    return <AutoGallery props={props} source={props.source} />;
  return (
    <GalleryGrid props={props} rows={parseRows(props.items, 3, 300) as Row[]} />
  );
}

/** Photos taken from the media library or from projects / services (the server does the same in PHP). */
function AutoGallery({
  props,
  source,
}: {
  props: GalleryProps;
  source: "media" | "portfolio" | "service";
}) {
  const limit = props.limit ?? 12;
  const result = useContent({
    source,
    limit: Math.min(limit, source === "media" ? 40 : 24),
    category: props.filter ?? "",
    orderBy: "date",
    order: "desc",
  });
  const rows: Row[] = result.items
    .filter(i => i.image)
    .map(i => [
      i.image!.url,
      source === "media" ? "" : slugLabel(i.categories[0] ?? ""),
      i.title,
    ]);
  return <GalleryGrid props={props} rows={rows} />;
}

function GalleryGrid({
  props,
  rows: all,
}: {
  props: GalleryProps;
  rows: Row[];
}) {
  const rows = all.filter(([src]) => safeImg(src) !== "");
  const tags = props.filters ? uniqueTags(rows.map(r => r[1])) : [];
  const featured = props.featured !== false;
  const pageSize = props.pageSize ?? 0;
  const paged = pageSize > 0;
  const more = paged && rows.length > pageSize && (
    <div className="pf-more-wrap">
      <p className="pf-count" data-count="" aria-live="polite">
        {`Showing ${pageSize} of ${rows.length}`}
      </p>
      <button type="button" className="pf-btn outline" data-more="">
        Load more
      </button>
    </div>
  );
  const grid = rows.map(([src, cat, alt], i) => (
    <figure
      key={i}
      className={i === 0 && featured ? "big" : undefined}
      {...(props.filters && cat !== "" ? { "data-tag": cat } : {})}
      {...(props.lightbox ? { tabIndex: 0 } : {})}
      {...(paged && i >= pageSize ? { hidden: true } : {})}
    >
      <img src={src} alt={alt} decoding="async" loading="lazy" />
      {(cat || alt) && (
        <figcaption>{cat && alt ? `${cat} · ${alt}` : cat || alt}</figcaption>
      )}
    </figure>
  ));
  return (
    <section
      className={galleryClasses(props)}
      {...(paged ? { "data-page": pageSize } : {})}
    >
      {tags.length > 0 ? (
        <div className="pf-wrap">
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
          <div className="pf-gallery-grid">{grid}</div>
          {more}
        </div>
      ) : more ? (
        <div className="pf-wrap">
          <div className="pf-gallery-grid">{grid}</div>
          {more}
        </div>
      ) : (
        <div className="pf-wrap pf-gallery-grid">{grid}</div>
      )}
    </section>
  );
}
