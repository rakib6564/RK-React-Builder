import type { FieldDef } from "../fields";

export const catalogFields: FieldDef[] = [
  { kind: "text", key: "eyebrow", label: "Eyebrow label", maxLength: 80 },
  { kind: "text", key: "heading", label: "Heading", maxLength: 200 },
  { kind: "textarea", key: "intro", label: "Intro copy", maxLength: 1000 },
  { kind: "catalogCards", key: "items", label: "Cards" },
  {
    kind: "checkbox",
    key: "filters",
    label: "Show filter buttons above the cards",
  },
  {
    kind: "checkbox",
    key: "joined",
    label: "Join the cards into one bordered grid",
  },
  {
    kind: "number",
    key: "pageSize",
    label: "Cards shown first (0 shows all, the rest load with a button)",
    min: 0,
    max: 100,
  },
  { kind: "checkbox", key: "search", label: "Show a search box" },
  {
    kind: "select",
    key: "tagField",
    label: "Filter buttons come from",
    options: [
      { value: "blurb", label: "The end of the card text (after ' · ')" },
      { value: "eyebrow", label: "The small label above the title" },
    ],
  },
  { kind: "number", key: "cols", label: "Columns", min: 2, max: 4 },
  { kind: "checkbox", key: "numbered", label: "Number the photos" },
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
