import { z } from "zod";
import { text } from "@/lib/schema/primitives";

export const GALLERY_SHAPES = [
  "rows",
  "square",
  "landscape",
  "portrait",
  "wide",
] as const;
export const GALLERY_GAPS = ["sm", "md", "lg"] as const;
export const GALLERY_CAPTIONS = ["overlay", "below", "hover", "none"] as const;

export const GALLERY_SOURCES = [
  "manual",
  "media",
  "portfolio",
  "service",
] as const;

export const galleryProps = z.strictObject({
  /** One photo per line: "image|Category|Description" (up to 300). The first is shown large unless `featured` is false. */
  items: text(0, 45000),
  /** A row of category buttons above the grid. */
  filters: z.boolean().optional(),
  /** Columns on a computer (2 to 4); 3 when absent. */
  columns: z.number().int().min(2).max(4).optional(),
  /** Photo shape: equal-height rows (the original look, also when absent) or a fixed shape. */
  shape: z.enum(GALLERY_SHAPES).optional(),
  /** Space between photos; medium when absent. */
  gap: z.enum(GALLERY_GAPS).optional(),
  /** Show the first photo large; on when absent. */
  featured: z.boolean().optional(),
  /** Click a photo to see it large, with previous / next. */
  lightbox: z.boolean().optional(),
  /** Where the caption goes; over the photo when absent. */
  captions: z.enum(GALLERY_CAPTIONS).optional(),
  /** Where the photos come from: added by hand (the original), the newest images in the media library, or the pictures of projects / services. */
  source: z.enum(GALLERY_SOURCES).optional(),
  /** How many photos an automatic gallery shows (12 when absent). */
  limit: z.number().int().min(1).max(40).optional(),
  /** Automatic galleries: a category slug for projects / services, or words to find in the media library. */
  filter: text(0, 80).optional(),
  /** Show this many photos first and a "Load more" button for the rest; 0 or absent shows them all. */
  pageSize: z.number().int().min(0).max(100).optional(),
});
export type GalleryProps = z.infer<typeof galleryProps>;
export const galleryDefaults: GalleryProps = {
  items: "",
  filters: false,
  columns: 3,
  shape: "rows",
  gap: "md",
  featured: true,
  lightbox: false,
  captions: "overlay",
  source: "manual",
  limit: 12,
  filter: "",
  pageSize: 0,
};

/** "hardwood-floors" -> "Hardwood Floors": the filter button text for a category slug. */
export function slugLabel(slug: string): string {
  return slug
    .split(/[-_\s]+/)
    .filter(Boolean)
    .map(w => w.charAt(0).toUpperCase() + w.slice(1))
    .join(" ");
}

/** The classes the section carries for its options (the PHP renderer builds the same string). */
export function galleryClasses(p: GalleryProps): string {
  const c = ["pf-section", "pf-gallery"];
  if (p.columns && p.columns !== 3) c.push(`cols-${p.columns}`);
  if (p.shape && p.shape !== "rows") c.push(`shape-${p.shape}`);
  if (p.gap && p.gap !== "md") c.push(`gap-${p.gap}`);
  if (p.featured === false) c.push("no-feature");
  if (p.captions && p.captions !== "overlay") c.push(`cap-${p.captions}`);
  if (p.lightbox) c.push("has-lightbox");
  return c.join(" ");
}
