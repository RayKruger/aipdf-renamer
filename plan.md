# AiPDF Renamer — Vercel / JavaScript Rebuild Plan

> [!NOTE]
> **MCP servers already configured:** `supabase-mcp-server`, `vercel` (Vercel MCP), `resend`.  
> **GitHub CLI:** authenticated as `RayKruger` with `repo` + `workflow` scopes — ready for `gh` commands.

Migrate the existing PHP website to a pure HTML + JavaScript stack deployable on **Vercel**.  
Preserve the visual look-and-feel (dark gradient background, Tailwind CSS, lime-400/cyan accent colours).  
All PHP, MySQL, and server-side rendering is removed.

---

## What Changes

| Old (PHP / TinkerHost) | New (JS / Vercel) |
|---|---|
| `index.php` — renamer page | `app/renamer.html` — pure HTML/JS |
| `AiPDFsearchPage.php` — search page | **Removed entirely** |
| `login.php` — custom MySQL auth | Supabase Auth (email + password) |
| `db.php`, `delete_pdf.php` etc. | Removed |
| `about.html` | Replaced by new `app/landing.html` |
| API keys in PHP `$_SESSION` | Browser `localStorage` (persists across sessions) |

---

## Visual Identity & Aesthetics (Tailwind)

We maintain the **exact look-and-feel** of the legacy site while refining it to feel more "premium":
- **Styling Engine**: Tailwind CSS 3.x (loaded via CDN for the static site).
- **Core Palette**: 
  - `slate-900` (cards), `slate-800` (headers/buttons), `slate-700` (borders)
  - `lime-400` (branding/accent names), `cyan-400` (CTAs/links)
  - `blue-600` / `green-600` / `red-600` (action buttons)
- **Background**: `linear-gradient(to bottom, #0f172a, #1e293b, #000)` on the body.
- **Micro-interactions**: Subtle hover states (`transition-all`, `hover:brightness-125`, `scale-105` on buttons).
- **Typography**: Import Google Fonts — **'Inter'** or **'Outfit'** to replace default system fonts.

---

## New Folder: `app/`

All new files go into a new **`app/`** folder at the repo root. The `legacy/` folder is left untouched.

```
app/
├── index.html          ← Landing page (replaces about.html; is now the root)
├── renamer.html        ← The actual renamer tool (login required)
├── js/
│   ├── auth.js         ← Supabase login/logout helpers
│   ├── renamer.js      ← All renamer logic (PDF.js, API call, download)
│   └── supabase-client.js  ← Initialises Supabase with env keys
├── css/
│   └── style.css       ← Any overrides on top of Tailwind CDN
└── vercel.json         ← Rewrites: / → index.html, /renamer → renamer.html
```

---

## Page Details

### `index.html` — Landing Page
- **Header**: `AiPDF Renamer` logo (left) + **Login** button (top-right, links to auth modal).
- **Hero section**: title, 1-line description, CTA button `→ Try the Renamer`.
- **"How it works" section**: 
  - 3-card layout (modern glassmorphism style using `backdrop-blur-md`).
  - Visual static mock examples of extraction (before/after).
- **Login/Sign-up modal**: dark, sleek, integrated with Supabase.  
- **Layout**: Same dark gradient background + Tailwind `slate` core palette as legacy.

### `renamer.html` — Renamer Tool (auth-gated)
- On load: check Supabase session. If not logged in, redirect to `index.html`.
- **Header**: Same as landing but adds user email + **Logout**.
- **API Configuration card**: auto-loads from `localStorage` using Tailwind `bg-slate-900/60`.
- **Drop zone**: matching the legacy `#58b9d9` dashed border look.
- **Workflow**: 
  1. PDF selection trigger first-page render (PDF.js).
  2. Canvas preview displayed alongside metadata form.
  3. Extract button → LLM call → fields auto-populate.
- **Footer**: pixel-perfect copy of the legacy signature.

---

## Supabase Auth Setup

Project already exists — **no new project needed**.

| Setting | Value |
|---|---|
| Project | **RayKruger's Project** (`jgwzhmbqpyozejfoqhmg`) |
| Region | `us-west-2` · Status: `ACTIVE_HEALTHY` |
| Project URL | `https://jgwzhmbqpyozejfoqhmg.supabase.co` |
| Anon (publishable) key | `sb_publishable_z6HhLEDqqxsUoCa-QZOUDg_ujSZWTBf` |

**Steps (done via Supabase MCP, no dashboard login needed):**
1. Use **Supabase MCP** to enable Email/Password auth (`mcp_supabase-mcp-server_*` tools).
2. Paste URL + anon key directly into `js/supabase-client.js` — both are safe to be public.
3. Supabase JS SDK loaded from CDN; no npm build required.

### Key Storage Strategy (no `.env` needed for a static site)

| Key | Where it lives |
|---|---|
| `SUPABASE_URL` | Hard-coded in `js/supabase-client.js` (public, safe) |
| `SUPABASE_ANON_KEY` | Hard-coded in `js/supabase-client.js` (public anon key, safe) |

> [!NOTE]
> The Supabase **anon key** is designed to be public. It only allows what Row-Level Security (RLS) policies permit. No private keys ever enter the browser.

The Supabase `@supabase/supabase-js` SDK is loaded from the CDN in each HTML file.

---

## API Key Persistence (User's LLM Keys)

- User enters `API Base URL`, `API Key`, and `Model` in the renamer's config card.
- Clicking **Save to Browser** calls `localStorage.setItem(...)` for each value.
- On page load, `renamer.js` calls `localStorage.getItem(...)` and pre-fills the fields.
- A **Clear Saved Keys** link is available to wipe them.

> [!IMPORTANT]
> `localStorage` is per-browser, per-origin. Keys survive page refresh and tab closes, but are not shared between devices. This matches the old PHP session behaviour but persists longer.

---

## GitHub → Vercel Deployment Workflow

### Step 1 — Create GitHub repo (gh CLI)
```bash
gh repo create aipdf-renamer --public --source=. --remote=origin --push
```
This creates the repo under `RayKruger`, sets `origin`, and pushes.

### Step 2 — Link to Vercel (Vercel MCP)
Use the **Vercel MCP** (`vercel` server already in `mcp_config.json`) to:
- Create a new Vercel project linked to the GitHub repo.
- Set the root directory to `app/`.
- No build command needed (static site).

### Step 3 — Auto-deploy on push
Every `git push` (or `gh pr merge`) triggers Vercel to redeploy automatically via the GitHub integration.

### Day-to-day update cycle
```bash
# edit files in app/
git add -A && git commit -m "feat: ..."
git push          # Vercel auto-deploys
```

---

## Removed Features

- **Search page** (`AiPDFsearchPage.php`): entirely removed. No database, no PDF library.
- **Signup flow**: Supabase handles it (optional — can restrict to invite only, or enable self-signup).
- All PHP files, `.htaccess`, `.bat` deploy scripts.

---

## Vercel Deployment

`vercel.json` routes `"/"` to `index.html` and `"/renamer"` to `renamer.html`.  
No build step required — Vercel serves static files directly.

---

## Verification Plan

1. Open `app/index.html` in browser locally — landing page renders correctly.
2. Click **Login**, sign in with a Supabase test account — modal closes, button changes.
3. Navigate to `renamer.html` directly when logged out — redirects to landing page.
4. On `renamer.html`: enter API config, save, reload — fields pre-fill from `localStorage`.
5. Upload a PDF, click **Extract Fields** — metadata populates.
6. Click **Download Renamed PDF** — file downloads with the new name.
7. Click **Logout** — session cleared, redirected to landing page.
