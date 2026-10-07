import type { FieldDef } from "../fields";

export const reviewsFields: FieldDef[] = [
  { kind: "text", key: "eyebrow", label: "Eyebrow label", maxLength: 80 },
  { kind: "text", key: "heading", label: "Heading", maxLength: 200 },
  { kind: "text", key: "intro", label: "Intro", maxLength: 300 },
  {
    kind: "number",
    key: "limit",
    label: "Reviews to show",
    min: 1,
    max: 50,
    help: "Add or sync reviews in Dashboard > Reviews",
  },
  {
    kind: "select",
    key: "layout",
    label: "Layout",
    options: [
      { value: "grid", label: "Plain cards" },
      { value: "carousel", label: "Carousel" },
      { value: "slider", label: "Slider (one at a time)" },
      { value: "cards", label: "Grid" },
      { value: "masonry", label: "Masonry" },
      { value: "list", label: "List" },
      { value: "badge", label: "Card badge (rating only)" },
    ],
  },
  {
    kind: "number",
    key: "minRating",
    label: "Lowest rating to show",
    min: 1,
    max: 5,
  },
  { kind: "number", key: "cols", label: "Cards per row", min: 2, max: 4 },
  {
    kind: "checkbox",
    key: "showSummary",
    label: "Show stars and review count",
  },
  { kind: "checkbox", key: "showLinks", label: "Show Google review links" },
  {
    kind: "select",
    key: "tone",
    label: "Background",
    options: [
      { value: "light", label: "Light" },
      { value: "muted", label: "Muted gray" },
    ],
  },
];
