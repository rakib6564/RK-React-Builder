import type { FieldDef } from "../fields";

export const galleryFields: FieldDef[] = [
  {
    kind: "select",
    key: "source",
    label: "Photos from",
    options: [
      { value: "manual", label: "Added by hand" },
      { value: "media", label: "Newest in the media library" },
      { value: "portfolio", label: "Project pictures" },
      { value: "service", label: "Service pictures" },
    ],
    help: "Automatic galleries fill themselves and stay up to date. Project and service pictures use each entry's featured image, its title as caption and its first category as the filter button.",
  },
  {
    kind: "number",
    key: "limit",
    label: "How many photos",
    min: 1,
    max: 40,
    showIf: v => !!v.source && v.source !== "manual",
    help: "Up to 24 for projects and services, 40 for the media library. The editor preview shows at most 24.",
  },
  {
    kind: "text",
    key: "filter",
    label: "Only show",
    maxLength: 80,
    showIf: v => !!v.source && v.source !== "manual",
    help: "Projects and services: a category slug such as hardwood-floors. Media library: words that appear in the picture's title or text. Leave empty for all.",
  },
  {
    kind: "galleryItems",
    key: "items",
    label: "Photos",
    showIf: v => !v.source || v.source === "manual",
  },
  { kind: "group", label: "Layout" },
  {
    kind: "select",
    key: "columns",
    label: "Columns",
    numeric: true,
    options: [
      { value: 2, label: "2" },
      { value: 3, label: "3" },
      { value: 4, label: "4" },
    ],
  },
  {
    kind: "select",
    key: "shape",
    label: "Photo shape",
    options: [
      { value: "rows", label: "Equal-height rows" },
      { value: "square", label: "Square" },
      { value: "landscape", label: "Landscape (4:3)" },
      { value: "portrait", label: "Portrait (3:4)" },
      { value: "wide", label: "Wide (16:9)" },
    ],
  },
  {
    kind: "select",
    key: "gap",
    label: "Space between photos",
    options: [
      { value: "sm", label: "Small" },
      { value: "md", label: "Medium" },
      { value: "lg", label: "Large" },
    ],
  },
  {
    kind: "checkbox",
    key: "featured",
    label: "Show the first photo large",
    defaultOn: true,
  },
  { kind: "group", label: "Behaviour" },
  {
    kind: "select",
    key: "captions",
    label: "Captions",
    options: [
      { value: "overlay", label: "Over the photo" },
      { value: "below", label: "Below the photo" },
      { value: "hover", label: "On hover" },
      { value: "none", label: "Hidden" },
    ],
  },
  {
    kind: "checkbox",
    key: "lightbox",
    label: "Open a photo large when clicked",
  },
  { kind: "checkbox", key: "filters", label: "Show category buttons" },
  {
    kind: "number",
    key: "pageSize",
    label: "Photos shown first (0 shows all, the rest load with a button)",
    min: 0,
    max: 100,
  },
];
