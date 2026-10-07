import { z } from "zod";
import { text } from "@/lib/schema/primitives";

export const reviewsProps = z.strictObject({
  eyebrow: text(0, 80),
  heading: text(0, 200),
  intro: text(0, 300),
  /** How many reviews to show (the best rated first). */
  limit: z.number().int().min(1).max(50),
  /** Hide reviews below this rating. */
  minRating: z.number().int().min(1).max(5),
  cols: z.number().int().min(2).max(4),
  /** Stars and the review count above the cards. */
  showSummary: z.boolean(),
  /** "See all reviews" and "Leave a review" buttons (they need a Google Business Profile in the dashboard). */
  showLinks: z.boolean(),
  tone: z.enum(["light", "muted"]),
  /**
   * grid = the original plain cards (also when absent); carousel = a Google-style reviews widget you slide through;
   * cards = the same widget with the cards laid out in a grid.
   */
  layout: z
    .enum(["grid", "carousel", "cards", "masonry", "list", "slider", "badge"])
    .optional(),
});
export type ReviewsProps = z.infer<typeof reviewsProps>;
export const reviewsDefaults: ReviewsProps = {
  eyebrow: "Reviews",
  heading: "What our customers say",
  intro: "",
  limit: 6,
  minRating: 4,
  cols: 3,
  showSummary: true,
  showLinks: true,
  tone: "muted",
  layout: "grid",
};
