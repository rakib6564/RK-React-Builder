import { useEffect, useState } from "react";
import { z } from "zod";
import { request } from "@/lib/api/http";

export const Review = z.object({
  id: z.string(),
  author: z.string(),
  rating: z.number(),
  text: z.string(),
  date: z.string(),
  source: z.string(),
  url: z.string(),
  photo: z.string().optional(),
  hidden: z.boolean().optional(),
});
export type Review = z.infer<typeof Review>;
export const ReviewsPublic = z.object({
  summary: z.object({ rating: z.number(), count: z.number() }),
  items: z.array(Review),
  links: z.object({ profile: z.string(), write: z.string() }),
});
export type ReviewsPublic = z.infer<typeof ReviewsPublic>;

let cache: Promise<ReviewsPublic | null> | null = null;

/** The site's reviews (null until loaded, or when the endpoint is unavailable). Shared by every Reviews block. */
export function useReviews(): ReviewsPublic | null {
  const [data, setData] = useState<ReviewsPublic | null>(null);
  useEffect(() => {
    let live = true;
    cache ??= request("reviews", ReviewsPublic).catch(() => null);
    void cache.then(d => live && setData(d));
    return () => {
      live = false;
    };
  }, []);
  return data;
}

/** Forget the cached reviews (after the dashboard changes them). */
export const resetReviewsCache = () => {
  cache = null;
};
