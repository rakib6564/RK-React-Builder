import { useEffect, useRef, useState } from "react";
import {
  ArrowDown,
  ArrowUp,
  ChevronDown,
  ChevronRight,
  Copy,
  ImagePlus,
  Palette,
  Plus,
  Trash2,
  X,
} from "lucide-react";
import { HEX, blurbTag, parseRows, semi } from "@/blocks/links";
import { MediaPicker } from "./MediaPicker";

const MAX_CARDS = 150;

type Card = {
  image: string;
  eyebrow: string;
  title: string;
  blurb: string;
  tag: string;
  specs: [string, string][];
  bullets: string[];
  link: string;
  popup: boolean;
  pImage: string;
  pTitle: string;
  pIntro: string;
  pItems: string[];
};

/** The separators the text format uses cannot appear inside a value. */
const clean = (s: string) =>
  s.replace(/\r?\n/g, " ").replace(/\|/g, "/").replace(/;/g, ",");

const blankCard = (): Card => ({
  image: "",
  eyebrow: "",
  title: "New card",
  blurb: "",
  tag: "",
  specs: [],
  bullets: [],
  link: "",
  popup: false,
  pImage: "",
  pTitle: "",
  pIntro: "",
  pItems: [],
});

export function parseCards(items: string, modals: string): Card[] {
  const pops = parseRows(modals, 4, MAX_CARDS);
  return parseRows(items, 7, MAX_CARDS).map(
    ([image, eyebrow, title, blurbFull, specs, bullets, link], i) => {
      const tag = blurbTag(blurbFull);
      const blurb = tag
        ? blurbFull.slice(0, blurbFull.lastIndexOf(" · ")).trim()
        : blurbFull;
      const p = pops[i] ?? ["", "", "", ""];
      return {
        image: image ?? "",
        eyebrow: eyebrow ?? "",
        title: title ?? "",
        blurb,
        tag,
        specs: semi(specs ?? "", 24).flatMap(s => {
          const cut = s.indexOf(":");
          return cut < 1
            ? []
            : [
                [s.slice(0, cut).trim(), s.slice(cut + 1).trim()] as [
                  string,
                  string,
                ],
              ];
        }),
        bullets: semi(bullets ?? "", 24),
        link: link ?? "",
        popup: (p[1] ?? "") !== "",
        pImage: p[0] ?? "",
        pTitle: p[1] ?? "",
        pIntro: p[2] ?? "",
        pItems: semi(p[3] ?? "", 24),
      };
    }
  );
}

export function serializeCards(cards: Card[]): {
  items: string;
  modals: string;
} {
  const items = cards.map(c =>
    [
      clean(c.image),
      clean(c.eyebrow),
      clean(c.title),
      clean(c.blurb) + (c.tag.trim() ? ` · ${clean(c.tag).trim()}` : ""),
      c.specs
        .filter(([k, v]) => k.trim() && v.trim())
        .map(([k, v]) => `${clean(k)}: ${clean(v)}`)
        .join("; "),
      c.bullets
        .map(clean)
        .filter(b => b.trim())
        .join("; "),
      clean(c.link),
    ].join("|")
  );
  // One row per card keeps the pop-ups lined up with their cards; "|||" means "no pop-up".
  const modals = cards.map(c =>
    c.popup && c.pTitle.trim()
      ? [
          clean(c.pImage),
          clean(c.pTitle),
          clean(c.pIntro),
          c.pItems
            .map(clean)
            .filter(x => x.trim())
            .join("; "),
        ].join("|")
      : "|||"
  );
  const anyPopup = cards.some(c => c.popup && c.pTitle.trim());
  return {
    items: items.join("\n"),
    modals: anyPopup ? modals.join("\n") : "",
  };
}

function ImageControl({
  id,
  label,
  value,
  onChange,
  allowColor,
}: {
  id: string;
  label: string;
  value: string;
  onChange: (v: string) => void;
  allowColor?: boolean;
}) {
  const [picking, setPicking] = useState(false);
  const isColor = HEX.test(value);
  return (
    <div className="field">
      <span className="field-label" id={`${id}-l`}>
        {label}
      </span>
      <div className="cc-image">
        <div
          className="cc-thumb"
          style={
            isColor
              ? { backgroundColor: value }
              : value
                ? { backgroundImage: `url("${value}")` }
                : undefined
          }
          aria-hidden="true"
        >
          {!value && <ImagePlus size={20} />}
        </div>
        <div className="cc-image-actions">
          <button
            type="button"
            className="top-btn"
            aria-describedby={`${id}-l`}
            onClick={() => setPicking(true)}
          >
            <ImagePlus size={14} aria-hidden="true" />{" "}
            {value && !isColor ? "Change image" : "Choose image"}
          </button>
          {allowColor && (
            <label className="top-btn cc-color">
              <Palette size={14} aria-hidden="true" /> Color
              <input
                type="color"
                value={isColor ? value : "#c8b79a"}
                onChange={e => onChange(e.target.value)}
                aria-label={`${label} color`}
              />
            </label>
          )}
          {value && (
            <button
              type="button"
              className="top-btn"
              onClick={() => onChange("")}
            >
              <X size={14} aria-hidden="true" /> Remove
            </button>
          )}
        </div>
      </div>
      <input
        id={id}
        type="text"
        inputMode="url"
        placeholder="or paste an image address"
        value={isColor ? "" : value}
        onChange={e => onChange(e.target.value)}
        aria-label={`${label} address`}
      />
      {picking && (
        <MediaPicker
          onClose={() => setPicking(false)}
          onSelect={m => {
            onChange(m.url);
            setPicking(false);
          }}
        />
      )}
    </div>
  );
}

function ListEditor({
  id,
  label,
  items,
  onChange,
  placeholder,
  addLabel,
}: {
  id: string;
  label: string;
  items: string[];
  onChange: (v: string[]) => void;
  placeholder: string;
  addLabel: string;
}) {
  return (
    <div className="field">
      <span className="field-label">{label}</span>
      {items.map((b, j) => (
        <div className="cc-row" key={j}>
          <input
            id={`${id}-${j}`}
            type="text"
            value={b}
            placeholder={placeholder}
            aria-label={`${label} ${j + 1}`}
            onChange={e =>
              onChange(items.map((x, k) => (k === j ? e.target.value : x)))
            }
          />
          <button
            type="button"
            className="icon-btn"
            aria-label={`Remove ${label.toLowerCase()} ${j + 1}`}
            onClick={() => onChange(items.filter((_, k) => k !== j))}
          >
            <X size={13} aria-hidden="true" />
          </button>
        </div>
      ))}
      <button
        type="button"
        className="top-btn"
        onClick={() => onChange([...items, ""])}
      >
        <Plus size={13} aria-hidden="true" /> {addLabel}
      </button>
    </div>
  );
}

function SwitchRow({
  label,
  checked,
  onChange,
}: {
  label: string;
  checked: boolean;
  onChange: (v: boolean) => void;
}) {
  return (
    <div className="field check">
      <label>
        <input
          type="checkbox"
          checked={checked}
          onChange={e => onChange(e.target.checked)}
        />{" "}
        <span>{label}</span>
      </label>
    </div>
  );
}

/** Card-by-card editing for the catalog block: image picker, text, specs, bullets, link and an optional pop-up. */
export function CatalogCardsEditor({
  uid,
  items,
  modals,
  modalLabel,
  modalCta,
  errors,
  onChange,
}: {
  uid: string;
  items: string;
  modals: string;
  modalLabel: string;
  modalCta: string;
  errors: Record<string, string>;
  onChange: (patch: Record<string, unknown>) => void;
}) {
  // The cards live in local state while editing: half-typed rows (an empty list item, a pop-up with no title yet)
  // have no place in the saved text, so they would vanish if we re-read them from it on every keystroke.
  const [cards, setCards] = useState<Card[]>(() => parseCards(items, modals));
  const cardsRef = useRef(cards);
  cardsRef.current = cards;
  useEffect(() => {
    const ser = serializeCards(cardsRef.current);
    if (ser.items !== items || ser.modals !== modals)
      setCards(parseCards(items, modals)); // changed elsewhere (undo, redo, another block)
  }, [items, modals]);
  const [open, setOpen] = useState<number | null>(cards.length > 0 ? 0 : null);
  const ctas = semi(modalCta, 2).map(c => {
    const cut = c.indexOf("|");
    return cut < 0
      ? ([c.trim(), ""] as [string, string])
      : ([c.slice(0, cut).trim(), c.slice(cut + 1).trim()] as [string, string]);
  });
  const anyPopup = cards.some(c => c.popup);

  const commit = (next: Card[]) => {
    setCards(next);
    onChange({ ...serializeCards(next) });
  };
  const patchCard = (i: number, p: Partial<Card>) =>
    commit(cards.map((c, k) => (k === i ? { ...c, ...p } : c)));
  const move = (i: number, d: number) => {
    const j = i + d;
    if (j < 0 || j >= cards.length) return;
    const next = [...cards];
    [next[i], next[j]] = [next[j]!, next[i]!];
    commit(next);
    setOpen(j);
  };
  const ctaAt = (k: number): [string, string] => ctas[k] ?? ["", ""];
  const setCta = (k: number, v: [string, string]) => {
    const next = [ctaAt(0), ctaAt(1)];
    next[k] = v;
    const last = next.reduce((m, [l, h], q) => (l || h ? q : m), -1);
    onChange({
      modalCta: next
        .slice(0, last + 1)
        .map(([l, h]) => `${clean(l)}|${clean(h)}`)
        .join("; "),
    });
  };
  const err = errors.items || errors.modals;

  return (
    <div className="cc">
      <div className="cc-head">
        <span className="field-label">
          Cards ({cards.length}/{MAX_CARDS})
        </span>
        <button
          type="button"
          className="top-btn"
          disabled={cards.length >= MAX_CARDS}
          onClick={() => {
            commit([...cards, blankCard()]);
            setOpen(cards.length);
          }}
        >
          <Plus size={13} aria-hidden="true" /> Add card
        </button>
      </div>
      {cards.length === 0 && (
        <p className="help">No cards yet. Add the first one.</p>
      )}
      {cards.map((c, i) => {
        const id = `${uid}-card${i}`;
        const isOpen = open === i;
        return (
          <section
            className={`cc-card${isOpen ? " open" : ""}`}
            key={i}
            aria-label={`Card ${i + 1}`}
          >
            <header>
              <button
                type="button"
                className="cc-toggle"
                aria-expanded={isOpen}
                onClick={() => setOpen(isOpen ? null : i)}
              >
                {isOpen ? (
                  <ChevronDown size={15} aria-hidden="true" />
                ) : (
                  <ChevronRight size={15} aria-hidden="true" />
                )}
                <span
                  className="cc-mini"
                  style={
                    HEX.test(c.image)
                      ? { backgroundColor: c.image }
                      : c.image
                        ? { backgroundImage: `url("${c.image}")` }
                        : undefined
                  }
                  aria-hidden="true"
                />
                <span className="cc-title">
                  <b>{String(i + 1).padStart(2, "0")}</b>{" "}
                  {c.title || "Untitled card"}
                </span>
              </button>
              <span className="cc-tools">
                <button
                  type="button"
                  className="icon-btn"
                  aria-label={`Move card ${i + 1} up`}
                  disabled={i === 0}
                  onClick={() => move(i, -1)}
                >
                  <ArrowUp size={13} aria-hidden="true" />
                </button>
                <button
                  type="button"
                  className="icon-btn"
                  aria-label={`Move card ${i + 1} down`}
                  disabled={i === cards.length - 1}
                  onClick={() => move(i, 1)}
                >
                  <ArrowDown size={13} aria-hidden="true" />
                </button>
                <button
                  type="button"
                  className="icon-btn"
                  aria-label={`Duplicate card ${i + 1}`}
                  disabled={cards.length >= MAX_CARDS}
                  onClick={() => {
                    const next = [...cards];
                    next.splice(i + 1, 0, { ...c });
                    commit(next);
                    setOpen(i + 1);
                  }}
                >
                  <Copy size={13} aria-hidden="true" />
                </button>
                <button
                  type="button"
                  className="icon-btn danger"
                  aria-label={`Delete card ${i + 1}`}
                  onClick={() => {
                    commit(cards.filter((_, k) => k !== i));
                    setOpen(null);
                  }}
                >
                  <Trash2 size={13} aria-hidden="true" />
                </button>
              </span>
            </header>
            {isOpen && (
              <div className="cc-body">
                <ImageControl
                  id={`${id}-img`}
                  label="Photo or color"
                  value={c.image}
                  allowColor
                  onChange={v => patchCard(i, { image: v })}
                />
                <div className="field">
                  <label htmlFor={`${id}-title`}>
                    <span>Title</span>
                  </label>
                  <input
                    id={`${id}-title`}
                    type="text"
                    value={c.title}
                    onChange={e => patchCard(i, { title: e.target.value })}
                  />
                </div>
                <div className="field">
                  <label htmlFor={`${id}-eyebrow`}>
                    <span>Small label above the title</span>
                  </label>
                  <input
                    id={`${id}-eyebrow`}
                    type="text"
                    value={c.eyebrow}
                    onChange={e => patchCard(i, { eyebrow: e.target.value })}
                  />
                </div>
                <div className="field">
                  <label htmlFor={`${id}-blurb`}>
                    <span>Description</span>
                  </label>
                  <textarea
                    id={`${id}-blurb`}
                    rows={4}
                    value={c.blurb}
                    onChange={e => patchCard(i, { blurb: e.target.value })}
                  />
                </div>
                <div className="field">
                  <label htmlFor={`${id}-tag`}>
                    <span>Filter tag</span>
                  </label>
                  <small className="help">
                    Shown after the description; the filter buttons use it.
                  </small>
                  <input
                    id={`${id}-tag`}
                    type="text"
                    value={c.tag}
                    placeholder={
                      c.blurb.trim()
                        ? "used by the filter buttons"
                        : "add a description first"
                    }
                    disabled={!c.blurb.trim()}
                    onChange={e => patchCard(i, { tag: e.target.value })}
                  />
                </div>
                <ListEditor
                  id={`${id}-bul`}
                  label="Checklist"
                  items={c.bullets}
                  placeholder="e.g. Custom sizing"
                  addLabel="Add item"
                  onChange={v => patchCard(i, { bullets: v })}
                />
                <div className="field">
                  <span className="field-label">Details (name and value)</span>
                  {c.specs.map(([k, v], j) => (
                    <div className="cc-row two" key={j}>
                      <input
                        type="text"
                        value={k}
                        placeholder="Name"
                        aria-label={`Detail ${j + 1} name`}
                        onChange={e =>
                          patchCard(i, {
                            specs: c.specs.map((s, q) =>
                              q === j ? [e.target.value, s[1]] : s
                            ) as [string, string][],
                          })
                        }
                      />
                      <input
                        type="text"
                        value={v}
                        placeholder="Value"
                        aria-label={`Detail ${j + 1} value`}
                        onChange={e =>
                          patchCard(i, {
                            specs: c.specs.map((s, q) =>
                              q === j ? [s[0], e.target.value] : s
                            ) as [string, string][],
                          })
                        }
                      />
                      <button
                        type="button"
                        className="icon-btn"
                        aria-label={`Remove detail ${j + 1}`}
                        onClick={() =>
                          patchCard(i, {
                            specs: c.specs.filter((_, q) => q !== j),
                          })
                        }
                      >
                        <X size={13} aria-hidden="true" />
                      </button>
                    </div>
                  ))}
                  <button
                    type="button"
                    className="top-btn"
                    onClick={() =>
                      patchCard(i, { specs: [...c.specs, ["", ""]] })
                    }
                  >
                    <Plus size={13} aria-hidden="true" /> Add detail
                  </button>
                </div>
                <div className="field">
                  <label htmlFor={`${id}-link`}>
                    <span>Card link</span>
                  </label>
                  <input
                    id={`${id}-link`}
                    type="text"
                    inputMode="url"
                    value={c.link}
                    placeholder="/page, https://… or tel:…"
                    onChange={e => patchCard(i, { link: e.target.value })}
                  />
                  <small className="help">
                    A linked card shows an arrow and has no pop-up button.
                  </small>
                </div>

                <SwitchRow
                  label="Open a pop-up from this card"
                  checked={c.popup}
                  onChange={v =>
                    patchCard(
                      i,
                      v
                        ? {
                            popup: true,
                            pTitle: c.pTitle || c.title || "Details",
                          }
                        : { popup: false }
                    )
                  }
                />
                {c.popup && (
                  <div className="cc-popup">
                    <ImageControl
                      id={`${id}-pimg`}
                      label="Pop-up image"
                      value={c.pImage}
                      onChange={v => patchCard(i, { pImage: v })}
                    />
                    <div className="field">
                      <label htmlFor={`${id}-ptitle`}>
                        <span>Pop-up title</span>
                      </label>
                      <input
                        id={`${id}-ptitle`}
                        type="text"
                        value={c.pTitle}
                        onChange={e => patchCard(i, { pTitle: e.target.value })}
                      />
                    </div>
                    <div className="field">
                      <label htmlFor={`${id}-pintro`}>
                        <span>Pop-up text</span>
                      </label>
                      <textarea
                        id={`${id}-pintro`}
                        rows={3}
                        value={c.pIntro}
                        onChange={e => patchCard(i, { pIntro: e.target.value })}
                      />
                    </div>
                    <ListEditor
                      id={`${id}-pit`}
                      label="Pop-up list"
                      items={c.pItems}
                      placeholder="e.g. Yellow pine grade 1"
                      addLabel="Add item"
                      onChange={v => patchCard(i, { pItems: v })}
                    />
                  </div>
                )}
              </div>
            )}
          </section>
        );
      })}

      {anyPopup && (
        <div className="cc-popup-settings">
          <span className="field-label">Pop-up buttons</span>
          <div className="field">
            <label htmlFor={`${uid}-mlabel`}>
              <span>Card button label</span>
            </label>
            <input
              id={`${uid}-mlabel`}
              type="text"
              maxLength={40}
              value={modalLabel}
              onChange={e => onChange({ modalLabel: e.target.value })}
            />
          </div>
          {[0, 1].map(k => (
            <div className="cc-row two" key={k}>
              <input
                type="text"
                value={ctaAt(k)[0]}
                placeholder={
                  k === 0 ? "Main button label" : "Second button label"
                }
                aria-label={`Pop-up button ${k + 1} label`}
                onChange={e => setCta(k, [e.target.value, ctaAt(k)[1]])}
              />
              <input
                type="text"
                value={ctaAt(k)[1]}
                placeholder="/contact or tel:+1…"
                aria-label={`Pop-up button ${k + 1} link`}
                onChange={e => setCta(k, [ctaAt(k)[0], e.target.value])}
              />
            </div>
          ))}
        </div>
      )}
      {err && (
        <small className="form-error" role="alert">
          {err}
        </small>
      )}
    </div>
  );
}
