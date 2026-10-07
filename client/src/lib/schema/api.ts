import { z } from "zod";
import { LayoutSchema } from "./layout";
import { BLOCK_TYPES } from "./primitives";
import { ThemeSchema } from "./theme";

/** Error codes the plugin returns; the client maps them to UI states. */
export const ERROR_CODES = [
  "rk_unauthorized",
  "rk_forbidden",
  "rk_not_found",
  "rk_invalid_layout",
  "rk_invalid_theme",
  "rk_revision_conflict",
  "rk_preview_invalid",
  "rk_payload_too_large",
  "rk_server_error",
  "rk_invalid_media",
  "rk_invalid_bundle",
  "rk_invalid_reusable",
  "rk_reusable_in_use",
] as const;
export type RkErrorCode = (typeof ERROR_CODES)[number];

export const ApiErrorBody = z.object({
  code: z.string(),
  message: z.string(),
  data: z
    .object({
      status: z.number().optional(),
      currentRevision: z.number().optional(),
      issues: z
        .array(z.object({ path: z.string(), message: z.string() }))
        .optional(),
    })
    .passthrough()
    .optional(),
});

export const PageStatus = z.enum([
  "draft",
  "publish",
  "private",
  "pending",
  "future",
]);

export const PageSummary = z.object({
  id: z.number().int(),
  title: z.string(),
  slug: z.string(),
  status: PageStatus,
  modified: z.string(),
  revision: z.number().int(),
  publishedRevision: z.number().int().nullable().optional(),
});
export type PageSummary = z.infer<typeof PageSummary>;
/** A page as the dashboard lists it (the extra fields come from the WordPress-hosted editor). */
export const PageRow = PageSummary.extend({
  link: z.string().optional(),
  isFront: z.boolean().optional(),
  noindex: z.boolean().optional(),
  hasDescription: z.boolean().optional(),
});
export type PageRow = z.infer<typeof PageRow>;
export const PageRowResponse = z.object({ page: PageRow });
export const PageListResponse = z.object({
  pages: z.array(PageRow),
  total: z.number().int(),
});

export const LoadResponse = z.object({
  page: z.object({
    id: z.number().int(),
    title: z.string(),
    slug: z.string(),
    status: PageStatus,
    link: z.string().optional(),
  }),
  layout: z.unknown(), // validated + migrated by parseLayout, never trusted raw
  theme: z.unknown(),
  revision: z.number().int(),
  publishedRevision: z.number().int().nullable().optional(),
  updatedAt: z.string(),
  capabilities: z
    .object({ manageTheme: z.boolean(), publish: z.boolean() })
    .optional(),
  /** Present when the document is a theme-builder template rather than a page. */
  template: z
    .object({
      kind: z.enum([
        "single",
        "archive",
        "loop",
        "notfound",
        "header",
        "footer",
      ]),
      postType: z.string(),
      taxonomy: z.string(),
      active: z.boolean(),
    })
    .optional(),
});

export const SaveRequest = z.strictObject({
  layout: LayoutSchema,
  theme: ThemeSchema.optional(),
  expectedRevision: z.number().int().min(0),
  status: z.literal("draft"),
});
export type SaveRequest = z.infer<typeof SaveRequest>;

export const SaveResponse = z.object({
  ok: z.literal(true),
  pageId: z.number().int(),
  revision: z.number().int(),
  status: PageStatus,
  updatedAt: z.string(),
});
export type SaveResponse = z.infer<typeof SaveResponse>;

export const PublishResponse = SaveResponse.extend({
  publishedRevision: z.number().int(),
  publishedAt: z.string(),
  link: z.string().optional(),
});
export type PublishResponse = z.infer<typeof PublishResponse>;

export const RevisionSummary = z.object({
  id: z.number().int(),
  kind: z.enum(["draft", "publish", "restore", "unpublish"]),
  savedAt: z.string(),
  author: z.string(),
  blocks: z.number().int(),
});
export type RevisionSummary = z.infer<typeof RevisionSummary>;
export const RevisionListResponse = z.object({
  revisions: z.array(RevisionSummary),
  retained: z.number().int(),
});
export const RevisionDetailResponse = z.object({
  revision: RevisionSummary,
  layout: z.unknown(),
});

export const PreviewTokenResponse = z.object({
  token: z.string(),
  expiresAt: z.string(),
  url: z.string().optional(),
});

export const MediaItem = z.object({
  id: z.number().int(),
  url: z.string(),
  alt: z.string(),
  title: z.string(),
  width: z.number().int().optional(),
  height: z.number().int().optional(),
  srcset: z.string().optional(),
  // only on the dashboard's list (?detail=1)
  caption: z.string().optional(),
  description: z.string().optional(),
  filename: z.string().optional(),
  mime: z.string().optional(),
  bytes: z.number().int().optional(),
  date: z.string().optional(),
});
export type MediaItem = z.infer<typeof MediaItem>;
export const MediaListResponse = z.object({ items: z.array(MediaItem) });
export const ReusableItem = z.object({
  id: z.number().int(),
  name: z.string(),
  block: z.object({
    type: z.enum(BLOCK_TYPES).exclude(["reusable"]),
    props: z.unknown(),
  }),
  /** Pages that use it; only on the dashboard's library list. */
  uses: z.number().int().optional(),
});
export type ReusableItem = z.infer<typeof ReusableItem>;
export const ReusableListResponse = z.object({ items: z.array(ReusableItem) });
export const ReusableResponse = z.object({ item: ReusableItem });
export const MediaUploadResponse = z.object({ item: MediaItem });
export const MediaDeleteResponse = z.object({ deleted: z.number().int() });

/** Whole-site export file. Contents are validated by the server on import; the client only checks the envelope. */
export const SiteBundle = z
  .object({ format: z.literal("rk-builder-site"), version: z.literal(1) })
  .passthrough();
export type SiteBundle = z.infer<typeof SiteBundle>;

export type SiteImportOptions = {
  dryRun: boolean;
  theme: boolean;
  content: boolean;
  contentStatus: "draft" | "publish";
  settings?: boolean;
  redirects?: boolean;
  siteInfo?: boolean;
};
const Skipped = z.object({ slug: z.string(), issues: z.array(z.string()) });
export const SiteImportReport = z.object({
  dryRun: z.boolean(),
  pages: z.object({
    create: z.number(),
    update: z.number(),
    skipped: z.array(Skipped),
    done: z.array(
      z.object({
        slug: z.string(),
        id: z.number(),
        action: z.enum(["created", "updated"]),
        revision: z.number(),
        link: z.string(),
      })
    ),
  }),
  media: z.object({
    total: z.number(),
    imported: z.number(),
    reused: z.number(),
    failed: z.array(z.object({ url: z.string(), reason: z.string() })),
  }),
  reusables: z
    .object({
      create: z.number(),
      update: z.number(),
      skipped: z.array(Skipped),
    })
    .optional(),
  theme: z.object({ included: z.boolean(), applied: z.boolean() }),
  content: z.object({
    included: z.number(),
    created: z.number(),
    updated: z.number(),
  }),
  types: z.object({ included: z.number(), applied: z.boolean() }).optional(),
  templates: z
    .object({
      create: z.number(),
      update: z.number(),
      skipped: z.array(Skipped),
    })
    .optional(),
  entries: z
    .object({ included: z.number(), created: z.number(), updated: z.number() })
    .optional(),
  hidden: z
    .object({
      from: z.string(),
      pages: z.number(),
      templates: z.number(),
      posts: z.number(),
    })
    .optional(),
  site: z
    .object({
      settings: z.boolean(),
      redirects: z.number(),
      siteInfo: z.boolean(),
    })
    .optional(),
  warnings: z.array(z.string()),
});
export type SiteImportReport = z.infer<typeof SiteImportReport>;

/** Theme engine: packages kept in this site's library. */
export const ThemeSummary = z.object({
  slug: z.string(),
  name: z.string(),
  description: z.string(),
  version: z.string(),
  author: z.string(),
  pages: z.number(),
  reusables: z.number(),
  media: z.number(),
  content: z.number(),
  templates: z.number().optional(),
  types: z.number().optional(),
  preview: z.string(),
  createdAt: z.string(),
  bytes: z.number(),
  /** A kit zip (carries its own images) rather than a plain JSON package. */
  kit: z.boolean().optional(),
  /** The theme most recently installed on this site. */
  active: z.boolean().optional(),
  images: z.number().optional(),
  industry: z.string().optional(),
  license: z.string().optional(),
  demo: z.string().optional(),
});
export type ThemeSummary = z.infer<typeof ThemeSummary>;
/** Kit Library: a catalogue of kits hosted anywhere. */
export const LibraryKit = z.object({
  id: z.string(),
  name: z.string(),
  description: z.string(),
  version: z.string(),
  author: z.string(),
  industry: z.string(),
  license: z.string(),
  price: z.string(),
  preview: z.string(),
  demo: z.string(),
  bytes: z.number(),
  requires: z.string(),
  tags: z.array(z.string()),
  requiresKey: z.boolean(),
  state: z.enum(["new", "added", "update", "needs-plugin"]),
});
export type LibraryKit = z.infer<typeof LibraryKit>;
export const LibraryView = z.object({
  configured: z.boolean(),
  url: z.string(),
  hasKey: z.boolean(),
  name: z.string(),
  items: z.array(LibraryKit),
  fetchedAt: z.string(),
  error: z.string().optional(),
});
export type LibraryView = z.infer<typeof LibraryView>;

export const UndoSummary = z.object({
  name: z.string(),
  at: z.string(),
  counts: z.object({
    created: z.number(),
    changed: z.number(),
    hidden: z.number(),
  }),
});
export type UndoSummary = z.infer<typeof UndoSummary>;
export const ThemeList = z.object({
  items: z.array(ThemeSummary),
  undo: UndoSummary.nullable().optional(),
});
export const UndoResult = z.object({
  undone: z.object({
    trashed: z.number(),
    restored: z.number(),
    shown: z.number(),
  }),
});
export const ThemeSaved = z.object({ theme: ThemeSummary });
export const ThemeAdded = z.object({
  theme: ThemeSummary,
  check: SiteImportReport,
});
export const ThemeInstallReport = SiteImportReport.extend({
  published: z.number(),
  suggest: z
    .array(z.object({ label: z.string(), find: z.string() }))
    .optional(),
  undo: UndoSummary.nullable().optional(),
  publishedTemplates: z.number().optional(),
  frontPage: z.boolean(),
});
export type ThemeInstallReport = z.infer<typeof ThemeInstallReport>;
export type ThemeInstallOptions = {
  dryRun: boolean;
  theme: boolean;
  content: boolean;
  publish: boolean;
  frontPage: boolean;
  settings?: boolean;
  redirects?: boolean;
  siteInfo?: boolean;
  /** Hide the active theme's pages, templates and content (default on). */
  switch?: boolean;
  /** false = design only: no pages or demo content. */
  pages?: boolean;
  /** Your own business details instead of the demo's. */
  replace?: { find: string; with: string }[];
};
export type ThemeMetaInput = {
  name: string;
  description: string;
  version: string;
  author: string;
};
export type KitMetaInput = ThemeMetaInput & {
  industry: string;
  license: string;
  demo: string;
};

export const ContentItem = z.object({
  id: z.number().int(),
  title: z.string(),
  excerpt: z.string(),
  link: z.string(),
  categories: z.array(z.string()),
  image: z
    .object({
      url: z.string(),
      width: z.number().int(),
      height: z.number().int(),
      alt: z.string(),
      srcset: z.string().optional(),
    })
    .nullable(),
});
export type ContentItem = z.infer<typeof ContentItem>;
export const ContentListResponse = z.object({
  items: z.array(ContentItem),
  total: z.number().int(),
});

export const PublicPageResponse = z.object({
  page: z.object({
    id: z.number().int(),
    title: z.string(),
    slug: z.string(),
    description: z.string(),
    modified: z.string(),
    image: z.string().nullable().optional(),
  }),
  layout: z.unknown(),
  theme: z.unknown(),
  revision: z.number().int(),
  preview: z.boolean().optional(),
  /** Library entries referenced by the layout, keyed by id (stringified). */
  reusables: z.record(z.string(), ReusableItem).optional(),
});

/* ---- Dashboard ---- */
export const Overview = z.object({
  pages: z.object({
    total: z.number(),
    publish: z.number(),
    draft: z.number(),
    other: z.number(),
  }),
  recent: z.array(PageRow),
  attention: z.object({
    unpublishedChanges: z.array(PageRow),
    missingDescription: z.array(PageRow),
  }),
  media: z.number(),
  setup: z.object({
    searchConsole: z.boolean(),
    analytics: z.boolean(),
    businessProfile: z.boolean(),
    localBusiness: z.boolean(),
    socialImage: z.boolean(),
    reviews: z.number(),
    redirects: z.number(),
  }),
  content: z.object({ services: z.number(), projects: z.number() }),
  themes: z.number(),
  visualizer: z.object({
    enabled: z.boolean(),
    ready: z.boolean(),
    provider: z.string(),
    label: z.string(),
    leads: z.number(),
    lastLead: z.string(),
  }),
  site: z.object({
    name: z.string(),
    tagline: z.string(),
    url: z.string(),
    adminUrl: z.string(),
    frontPageId: z.number(),
    searchVisible: z.boolean(),
    plugin: z.string(),
  }),
});
export type Overview = z.infer<typeof Overview>;

/** What a page asks for in its JSON-LD (the server fills every field, so none is optional here). */
export const PageSchema = z.object({
  pageType: z.string(),
  breadcrumb: z.boolean(),
  business: z.boolean(),
  article: z.object({ on: z.boolean(), type: z.string() }),
  service: z.object({
    on: z.boolean(),
    name: z.string(),
    description: z.string(),
  }),
  product: z.object({
    on: z.boolean(),
    name: z.string(),
    price: z.string(),
    currency: z.string(),
    availability: z.string(),
    brand: z.string(),
  }),
  faq: z.object({ on: z.boolean(), items: z.string() }),
  review: z.object({ on: z.boolean(), rating: z.string(), count: z.string() }),
});
export type PageSchemaSettings = z.infer<typeof PageSchema>;

export const PageSeo = z.object({
  seo: z.object({
    title: z.string(),
    description: z.string(),
    image: z.string(),
    noindex: z.boolean(),
    schema: PageSchema,
    pageTitle: z.string(),
  }),
});
export type PageSeoFields = z.infer<typeof PageSeo>["seo"];

export const SiteSettings = z.object({
  name: z.string(),
  tagline: z.string(),
  searchVisible: z.boolean(),
  frontPageId: z.number(),
  loginPageId: z.number(),
  loginEnabled: z.boolean(),
  loginImage: z.string(),
  loginUrl: z.string(),
  loginLink: z.string(),
  organization: z.object({
    name: z.string(),
    telephone: z.string(),
    email: z.string(),
    description: z.string(),
    logo: z.string(),
    defaultImage: z.string(),
    favicon: z.string(),
    businessType: z.string(),
    street: z.string(),
    city: z.string(),
    region: z.string(),
    postal: z.string(),
    country: z.string(),
    hours: z.string(),
    areaServed: z.string(),
    priceRange: z.string(),
    profiles: z.record(z.string(), z.string()),
  }),
});
export type SiteSettings = z.infer<typeof SiteSettings>;
export const SiteSettingsResponse = z.object({
  site: SiteSettings,
  pages: z.array(z.object({ id: z.number(), title: z.string() })),
  loginPages: z.array(z.object({ id: z.number(), title: z.string() })),
});

export const VizLead = z.object({
  name: z.string(),
  email: z.string(),
  phone: z.string(),
  at: z.string(),
});
export const VizSettings = z.object({
  enabled: z.boolean(),
  provider: z.string(),
  gemini_model: z.string(),
  custom_url: z.string(),
  custom_header: z.string(),
  free_count: z.number(),
  bonus_count: z.number(),
  cooldown_hours: z.number(),
  ip_per_hour: z.number(),
  timeout: z.number(),
  notify_email: z.string(),
  hf_token_set: z.boolean(),
  gemini_key_set: z.boolean(),
  custom_key_set: z.boolean(),
  hf_token_env: z.boolean(),
  gemini_key_env: z.boolean(),
});
export type VizSettings = z.infer<typeof VizSettings>;
export const VizAdmin = z.object({
  settings: VizSettings,
  providers: z.record(z.string(), z.string()),
  ready: z.boolean(),
  leads: z.array(VizLead),
});
export type VizAdmin = z.infer<typeof VizAdmin>;

/* ---- Code, reviews, redirects ---- */
export const CodeSettings = z.object({
  gsc: z.string(),
  bing: z.string(),
  ga4: z.string(),
  gtm: z.string(),
  skipLoggedIn: z.boolean(),
  head: z.string(),
  bodyStart: z.string(),
  footer: z.string(),
});
export type CodeSettings = z.infer<typeof CodeSettings>;
export const CodeResponse = z.object({
  code: CodeSettings,
  canEditCode: z.boolean(),
});

export const ReviewItem = z.object({
  id: z.string(),
  author: z.string(),
  rating: z.number(),
  text: z.string(),
  date: z.string(),
  source: z.string(),
  url: z.string(),
  photo: z.string().optional(),
  hidden: z.boolean(),
});
export type ReviewItem = z.infer<typeof ReviewItem>;
export const ReviewsAdmin = z.object({
  config: z.object({
    profileUrl: z.string(),
    placeId: z.string(),
    keySet: z.boolean(),
    keyFromServer: z.boolean(),
    auto: z.boolean(),
    syncedAt: z.string(),
    lastError: z.string(),
    writeUrl: z.string(),
  }),
  summary: z.object({ rating: z.number(), count: z.number() }),
  items: z.array(ReviewItem),
});
export type ReviewsAdmin = z.infer<typeof ReviewsAdmin>;

export const PlacesSearch = z.object({
  results: z.array(
    z.object({
      placeId: z.string(),
      name: z.string(),
      address: z.string(),
      rating: z.number(),
      count: z.number(),
    })
  ),
});
export type PlacesSearch = z.infer<typeof PlacesSearch>;
export const PlacesLink = z.object({
  profileUrl: z.string(),
  name: z.string(),
  placeId: z.string(),
  writeUrl: z.string(),
});
export type PlacesLink = z.infer<typeof PlacesLink>;
export const PlacesImport = z.object({
  placeId: z.string(),
  fields: z.object({
    name: z.string(),
    telephone: z.string(),
    street: z.string(),
    city: z.string(),
    region: z.string(),
    postal: z.string(),
    country: z.string(),
    hours: z.string(),
    googleBusiness: z.string(),
  }),
  rating: z.number(),
  count: z.number(),
});
export type PlacesImport = z.infer<typeof PlacesImport>;

export const RedirectRule = z.object({
  from: z.string(),
  to: z.string(),
  code: z.number(),
});
export type RedirectRule = z.infer<typeof RedirectRule>;
export const RedirectList = z.object({ items: z.array(RedirectRule) });

/* ---- content types, entries, templates (theme builder) ---- */

export const FIELD_TYPES = [
  "text",
  "textarea",
  "number",
  "email",
  "url",
  "date",
  "color",
  "select",
  "toggle",
  "image",
  "gallery",
  "repeater",
] as const;
export type FieldType = (typeof FIELD_TYPES)[number];

const FieldBase = z.object({
  key: z.string(),
  label: z.string(),
  type: z.enum(FIELD_TYPES),
  help: z.string(),
  required: z.boolean(),
  default: z.string(),
  options: z.array(z.object({ value: z.string(), label: z.string() })),
  min: z.number().nullable(),
  max: z.number().nullable(),
});
export const EntryField = FieldBase.extend({ subfields: z.array(FieldBase) });
export type EntryField = z.infer<typeof EntryField>;

export const ContentType = z.object({
  slug: z.string(),
  singular: z.string(),
  plural: z.string(),
  icon: z.string(),
  supports: z.array(z.string()),
  public: z.boolean(),
  hasArchive: z.boolean(),
  rewrite: z.string(),
  builtin: z.boolean(),
  schema: z.enum(["WebPage", "Article", "Service"]),
  archiveTitle: z.string(),
  archiveDescription: z.string(),
  taxonomies: z.array(
    z.object({
      slug: z.string(),
      singular: z.string(),
      plural: z.string(),
      hierarchical: z.boolean(),
    })
  ),
  fields: z.array(EntryField),
  count: z.number().int().optional(),
  taxonomyTerms: z
    .array(
      z.object({
        slug: z.string(),
        name: z.string(),
        hierarchical: z.boolean(),
        terms: z.array(z.object({ slug: z.string(), name: z.string() })),
      })
    )
    .optional(),
});
export type ContentType = z.infer<typeof ContentType>;
export const TypesResponse = z.object({
  types: z.array(ContentType),
  fieldTypes: z.array(z.string()),
});

/** A media item or file attached to an entry field. */
export const EntryMedia = z
  .object({ id: z.number().int(), url: z.string() })
  .passthrough();
export type EntryMedia = z.infer<typeof EntryMedia>;

export const EntryRow = z.object({
  id: z.number().int(),
  title: z.string(),
  slug: z.string(),
  status: z.string(),
  modified: z.string(),
  link: z.string(),
  image: z.string().nullable(),
});
export type EntryRow = z.infer<typeof EntryRow>;
export const EntryList = z.object({
  items: z.array(EntryRow),
  total: z.number().int(),
  pages: z.number().int(),
});
const emptyMap = (v: unknown) => (Array.isArray(v) && v.length === 0 ? {} : v);
export const Entry = z.object({
  id: z.number().int(),
  type: z.string(),
  title: z.string(),
  slug: z.string(),
  status: z.string(),
  excerpt: z.string(),
  content: z.string(),
  image: EntryMedia.nullable(),
  menuOrder: z.number().int(),
  // PHP sends an empty map as []
  terms: z.preprocess(emptyMap, z.record(z.string(), z.array(z.string()))),
  fields: z.preprocess(emptyMap, z.record(z.string(), z.unknown())),
  link: z.string(),
  modified: z.string(),
  seo: z.object({
    title: z.string(),
    description: z.string(),
    image: z.string(),
    noindex: z.boolean(),
  }),
});
export type Entry = z.infer<typeof Entry>;
export const EntryResponse = z.object({ entry: Entry });

export const TemplateItem = z.object({
  id: z.number().int(),
  title: z.string(),
  kind: z.enum(["single", "archive", "loop", "notfound", "header", "footer"]),
  postType: z.string(),
  taxonomy: z.string(),
  active: z.boolean(),
  status: z.string(),
  modified: z.string(),
  live: z.boolean(),
});
export type TemplateItem = z.infer<typeof TemplateItem>;
export const TemplateList = z.object({ items: z.array(TemplateItem) });
export const TemplateResponse = z.object({ item: TemplateItem });

export const DynRender = z.object({
  html: z.string(),
  sample: z.boolean(),
  valid: z.boolean(),
});
export type DynRender = z.infer<typeof DynRender>;

export const GlobalSettings = z.object({
  layout_width: z.number().int(),
  gutter: z.number().int(),
  gutter_mobile: z.number().int(),
  admin_bar: z.enum(["default", "builder", "hidden"]),
  theme_styles: z.boolean(),
  no_emojis: z.boolean(),
  no_embeds: z.boolean(),
  no_block_css: z.boolean(),
  no_head_clutter: z.boolean(),
});
export type GlobalSettings = z.infer<typeof GlobalSettings>;
export const GlobalResponse = z.object({ global: GlobalSettings });

export const McpLevel = z.enum(["read", "write", "full"]);
export type McpLevel = z.infer<typeof McpLevel>;
export const McpAdmin = z.object({
  settings: z.object({ enabled: z.boolean(), level: McpLevel }),
  endpoint: z.string(),
  tools: z.array(
    z.object({
      name: z.string(),
      title: z.string(),
      group: z.string(),
      risk: McpLevel,
      description: z.string(),
      enabled: z.boolean(),
    })
  ),
  log: z.array(
    z.object({
      t: z.string(),
      user: z.string(),
      tool: z.string(),
      ok: z.boolean(),
      via: z.string().optional(),
    })
  ),
  keys: z.array(
    z.object({
      id: z.string(),
      name: z.string(),
      prefix: z.string(),
      level: McpLevel,
      created: z.string(),
      used: z.string(),
      user: z.string(),
    })
  ),
  /** Only in the answer to creating a key: the key itself, shown once. */
  created: z.object({ token: z.string(), id: z.string() }).optional(),
  passwordsOk: z.boolean(),
  profileUrl: z.string(),
  username: z.string(),
  /** What the owner tells the assistant about the business (used when it rewrites the site's wording). */
  brief: z
    .object({
      about: z.string(),
      audience: z.string(),
      tone: z.string(),
      offers: z.string(),
      notes: z.string(),
    })
    .optional(),
  prompt: z.string().optional(),
});
export type McpAdmin = z.infer<typeof McpAdmin>;
export type AiBrief = NonNullable<McpAdmin["brief"]>;
export const AiBriefSaved = z.object({
  brief: z.object({
    about: z.string(),
    audience: z.string(),
    tone: z.string(),
    offers: z.string(),
    notes: z.string(),
  }),
  prompt: z.string(),
});
