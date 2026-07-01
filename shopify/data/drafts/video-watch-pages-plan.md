# Implementation Plan: Indexable Video "Watch Pages"

**Goal:** Resolve the Google Search Console error *"Video isn't on a watch page"* so ASR Outdoor's
videos get indexed and can appear in Google Video search under **asroutdoor.com**.

**Approach (approved direction):** Mirror the Journal/blog structure — a central **Videos** hub
that links out to **one dedicated page per video**, where that single video is the main content.
A page with one prominent, playable video + correct structured data = a "watch page" Google will
index.

---

## Why this fixes the error
Google indexes a video only when the page is a *watch page*, which requires all three:
1. **The video is the main content** (not one in a long list).
2. **A real, playable player is in the HTML on load** (today Dawn hides the YouTube player inside a
   deferred `<template>`, so Googlebot sees no player — a primary cause of the current failure).
3. **Valid `VideoObject` structured data** (name, description, thumbnail, uploadDate, embedUrl,
   duration).

The current `/pages/gold-panning-videos` fails #1 and #2 (it's a list, with deferred players).
One-video-per-page fixes both.

---

## Honest expectations (for the boss)
- The videos live on **ASR Outdoor's own YouTube channel**, so they are *already* indexable on
  YouTube. Watch pages add **our domain** to Google Video results — a real, separate win.
- Google may still treat the **YouTube page as canonical** for some videos, and indexing can take
  **weeks** even when everything is correct. This maximizes our chances and is the
  Google-recommended structure; it is not a 100% guarantee for every single video.

---

## Inventory: 20 unique videos
- **17** are embedded on product pages (as product video media).
- **3** more appear only on the current Videos page (`5ZUFmtFPgdE`, `pXoZAJsz4qo`, `UxDOZeiHAes`).
- Full ID list captured; each becomes one watch page. (We can curate down to just the how-to /
  tutorial videos if preferred — sales/teaser clips add little SEO value.)

---

## Architecture
**Hub:** a new Shopify blog titled **"Videos"** → its listing page `/blogs/videos` is the hub
(like `/blogs/journal`). Nav "How-To Videos" / "Videos" points here.

**Watch pages:** one **article per video** in the Videos blog → each gets its own URL
(`/blogs/videos/<handle>`) = a watch page.

**On each watch page:**
- A **live, prominently embedded YouTube iframe** (real `<iframe>` in the HTML, NOT Dawn's deferred
  player) as the main content.
- A short written **description / context paragraph** (also good for users + SEO).
- **`VideoObject` JSON-LD** emitted by the theme (Shopify strips `<script>` from article bodies,
  so schema must come from the theme template, fed by article fields + a metafield).

---

## Build steps

### Part A — Theme (Skyler pastes once; Claude writes the Liquid)
1. A **video-article template** (`article.video.liquid`) or a conditional block in the existing
   article template that, when an article has a `custom.youtube_id` metafield:
   - Renders the YouTube iframe at the top as main content (responsive 16:9).
   - Outputs `VideoObject` JSON-LD using: article title (name), article excerpt/body (description),
     `https://i.ytimg.com/vi/<id>/hqdefault.jpg` (thumbnailUrl),
     `https://www.youtube.com/embed/<id>` (embedUrl), plus uploadDate + duration metafields.
2. A simple **hub layout** for `/blogs/videos` (Dawn's blog template already lists articles with
   thumbnails — minimal/no change needed).

### Part B — Video metadata (one prerequisite)
`VideoObject` **requires uploadDate** and strongly benefits from **duration** — neither is available
from a plain embed. Two options:
- **(Recommended)** Create a free **YouTube Data API key** (Google Cloud Console, 5 min). Claude's
  script then auto-pulls each video's real title, description, publish date, and duration.
- **(Manual)** Skyler provides a quick list: per video, the upload date + length. More tedious.

### Part C — Content (Claude does via API; needs write_content, already have it)
1. **Create the "Videos" blog** (`blogCreate`).
2. For each of the ~20 videos, **create an article** (`articleCreate`) with:
   - Title (the video's title), a clean handle, a written description, the embedded iframe in body.
   - Metafields: `custom.youtube_id`, `custom.video_upload_date`, `custom.video_duration`.
   - The video article template assigned.
3. **Dry-run → CSV preview** of all article titles/handles/descriptions for review before publish.
4. Publish; keep articles in "Videos" blog.

### Part D — Hub + nav + cleanup
1. Point the nav "Videos / How-To Videos" link at `/blogs/videos`.
2. Decide the fate of the old `/pages/gold-panning-videos`: keep as an extra landing page, or
   301-redirect it to the new hub (recommended to avoid two competing video pages).
3. (Optional) On product pages, link each product's video to its new watch page for internal links.

### Part E — Verify
1. Test one watch page in Google's **Rich Results Test** → confirm a valid `VideoObject`.
2. Submit the `/blogs/videos` hub + a few watch pages for indexing in GSC.
3. Watch the GSC **Videos** report over the following weeks for "indexed" status.

---

## Sequencing
1. Decide: all 20 videos or a curated how-to subset.
2. Get the YouTube Data API key (or supply metadata manually).
3. Claude builds the content script + the theme Liquid in parallel.
4. Skyler pastes the theme code; Claude creates the blog + articles (dry-run first).
5. Verify with Rich Results Test, then request indexing.

## Decisions (LOCKED 2026-07-01)
- **All 20 videos** get watch pages.
- **Metadata:** Skyler hands Claude a per-video list (title, description, upload date, duration);
  Claude may offer to auto-pull upload date + duration from YouTube to save effort.
- **Old `/pages/gold-panning-videos`:** DELETE + 301-redirect to the new `/blogs/videos` hub.
- **Hub name:** "Videos".

## IMPORTANT nuance (set boss expectations)
The GSC "not on a watch page" list is mostly PRODUCT pages (+2 blog articles + the videos page).
Product pages can't be watch pages (the product is the main content). So:
- Watch pages get each of the 20 unique videos INDEXED via a new /blogs/videos URL (the real goal).
- The video note on PRODUCT pages may persist (harmless once the video is indexed via its watch
  page) unless we also strip the video/schema from product pages — NOT recommended (product videos
  aid conversion). Verify during build whether product pages even emit VideoObject; if they don't,
  those notes clear on their own.

## Theme file delivered
`shopify/theme/video-jsonld.liquid` — paste into `sections/main-article.liquid` (top). Emits the
VideoObject from `custom.youtube_id / video_description / video_upload_date / video_duration`
metafields. Live iframe player goes in the article body (Claude sets via API).
