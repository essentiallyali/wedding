# Wedding guest photo & video uploads

A page guests reach by scanning a QR code, where they add photos and videos from
the day. No app, no account, no sign-in. Files land in **your Google Drive**;
nothing appears publicly until you approve it.

---

## How it works

```
Guest's phone ──── file bytes ────────────────────────▶ Google Drive
      │                                                      ▲
      └── filename/size ──▶ Bluehost (PHP) ──── mints ───────┘
                                  │          upload session
                                  │
                            approval queue
                                  │
                                  ▼
                          public gallery ──▶ streams media back through PHP
```

The important part: **file bytes never pass through Bluehost.** The server only
hands out a short-lived Google upload URL, and the phone uploads straight to
Google. That sidesteps the 50 MB PHP upload cap, the execution timeout, and your
hosting bandwidth entirely — a 2 GB video is no harder than a photo.

Media is served *back* through `api/media.php` rather than linked directly,
because Drive's public hotlink endpoints no longer work for third-party sites
(`uc?export=view` returns 403, and the undocumented `/thumbnail` endpoint
rate-limits once a page loads many images). Reading through the API also means
approval is enforced at the only door: **a pending file has no URL a guest can
reach.** Thumbnails are cached on disk after first view, so the gallery is fast
and quiet.

---

## Setup

About 20 minutes and a Google account. There is no file editing and no command
line — an install wizard does the configuring.

### 1. Google project, and switch on Drive

1. Go to [console.cloud.google.com](https://console.cloud.google.com), signed in
   as the account whose Drive should receive the photos.
2. Top bar → project dropdown → **New Project**. Name it anything. Skip any
   prompt about billing or a free trial; none of this costs money.
3. Make sure the new project is the one selected in that dropdown.
4. Search the top bar for **Google Drive API** → **Enable**.

### 2. Google Auth Platform

Google renamed this in 2025 — what used to be "OAuth consent screen" is now
**Google Auth Platform**, split into **Branding**, **Audience** and **Clients**.
Search the top bar for it.

**Branding** — app name (e.g. *Wedding Photo Uploads*), user support email, and
developer contact email. Google will not let you publish until these are filled
in.

> Leave the **app logo** empty. Uploading one puts the app into Google's
> verification queue, which you do not want and do not need.

**Audience** — set to **External**, then click **Publish app** so the status
reads **In production**.

> ⚠️ This is the one step that fails silently if skipped. An app left in
> *Testing* has its refresh token expired by Google after **7 days**, so uploads
> would work when you set them up and quietly stop about a week later. If it
> offers "prepare for verification", ignore that — it is not needed for the
> `drive.file` scope, which is non-sensitive.

**Clients** → **Create client**:

- Application type: **Web application**
- **Authorised redirect URIs** → Add: `https://yourdomain.com/setup-token.php`
- Leave **Authorised JavaScript origins** empty — this site does not use them,
  and that box rejects anything with a path, which is a common source of the
  error *"URIs must not contain a path"*.

Keep the **Client ID** and **Client Secret** it gives you.

### 3. Upload to Bluehost

Put the files on the server so that `public/` is what the web sees:

| Local path        | On the server                              |
|-------------------|--------------------------------------------|
| `public/*`        | the web root of your domain or subdomain   |
| `lib/`, `data/`   | **one level above** the web root if you can |

Keeping `lib/` and `data/` outside the web root is best, because `lib/config.php`
will hold your Google secret. If that is awkward, the bundled `.htaccess` files
in both folders deny direct web access as a fallback — but outside the web root
is stronger.

If you do move them, update the `require_once` path at the top of each file in
`public/` and `public/api/`.

Both `data/` and `lib/` need to be writable (755 or 775): `data/` for the upload
records and cached thumbnails, `lib/` so the wizard can write its config. You can
tighten `lib/` again afterwards.

### 4. Run the wizard

Visit `https://yourdomain.com/setup-token.php`. It asks for:

- the **Client ID** and **Client Secret** from step 2,
- an **admin password** of your choosing — this is what you will type to approve
  photos. It is not your Google password.

Then it sends you to Google to approve access. You will see a warning that the
app is not verified: click **Advanced**, then **Go to … (unsafe)**. That is
expected — it is your own app, and you are the only person who will ever see
that screen. Guests never sign in to anything.

The wizard then creates a Drive folder called **Wedding Guest Uploads** and
writes `lib/config.php` itself.

> The folder is created through the API deliberately. The `drive.file` scope only
> reaches files the app itself created, so a folder made by hand in Drive may not
> be writable.

### 5. Delete the wizard

Delete `public/setup-token.php` from the server. Setup is done and it is not
needed again. While it exists and no config file is present, anyone who finds the
URL could point the site at their own Drive.

### 6. Check your Google storage

A wedding realistically produces **20–60 GB**. The free tier is 15 GB shared
across Gmail, Drive and Photos, so you will almost certainly want
[Google One](https://one.google.com): 200 GB is $2.99/mo, 2 TB is $9.99/mo.
Sort this out *before* the day — uploads fail once the quota is full.

---

## Using it

| Page | What it is |
|------|-----------|
| `/` | The upload page. This is what your QR code points at. |
| `/gallery.php` | Public gallery — approved items only. |
| `/admin.php` | Your approval queue. Not linked from anywhere. |

In the approval queue: tap tiles to select, then **Approve**, **Hide**, or
**Delete**.

- **Approve** — appears in the public gallery.
- **Hide** — stays out of the gallery, file kept in Drive. Reversible.
- **Delete** — removes it from Drive permanently.

To close uploads after the event without taking the page down, set
`'uploads_open' => false` in `lib/config.php`.

---

## Design

The site is built on the **Ali & Robert** design system: pale gold (`#f7e7a7`)
on deep indigo (`#0a0935`), Alice throughout, lit by the string-light garland
and anchored by the Round Barn engraving.

Tokens live in the `:root` block at the top of `public/assets/styles.css`, taken
verbatim from the system's `tokens.json`. Page furniture — garland, confetti,
barn, scroll divider — is in `lib/view.php` so the three pages cannot drift
apart.

How the system's rules landed here:

- **One ink, one ground.** Every word is gold on indigo. There is no second
  text colour, so upload progress and errors are distinguished by wording and
  by `gold-confetti`, never by red or green.
- **Text never sits on a light ground.** A photograph *is* a light ground, so
  the uploader's name sits below its tile rather than over it. The video marker
  is a gold rule on an indigo disc, keeping gold-on-indigo even on top of a
  photo.
- **No icon set.** There are no ticks, arrows or emoji. Selection is a gold dot;
  the only glyph-like mark used is the `|` separator.
- **Square corners**, 4px on buttons only. No borders, shadows or gradients —
  the sole glow is the garland artwork.

### Brand assets

`public/assets/brand/` holds web-sized copies of the system artwork. The
originals are print-resolution (the barn is 1800px, 708KB); these are resized
and palette-quantised to roughly a seventh of that, which is invisible at
display size and matters a great deal to a guest on hotel wifi.

| File | From | Web size |
|------|------|----------|
| `round-barn.png` | `Illustrations/round-barn.png` | 900px, 105KB |
| `string-lights.png` | `Illustrations/string-lights.png` | 1400px, 80KB |
| `scroll-divider.png` | `Ornaments/scroll-divider.png` | 480px, 3KB |
| `confetti-corner.svg` | `Ornaments/confetti-corner.svg` | unchanged, 3KB |

Fonts are **self-hosted** in `public/assets/fonts/` (Alice 400, Cardo 400/700,
latin and latin-ext only). Guests open this on bad connections; pulling fonts
from Google would add a third-party origin and a round trip for no benefit.
Regenerate them only if the type stack changes.

To restyle, edit the `:root` tokens. Nothing else hard-codes a colour.

---

## Before you print the QR codes

- **Test the real URL on a real iPhone and a real Android**, over cellular with
  wifi switched off. Upload an actual video, not a test image.
- **Print the URL underneath the QR code.** Scanners fail, and some people just
  want to type it.
- Use a short URL. `yoursite.com/photos` beats a long path.
- Have a fallback. If the page misbehaves at the reception you cannot re-shoot a
  wedding — a [Dropbox File Request](https://www.dropbox.com/features/file-requests)
  link needs no account from the uploader and takes two minutes to set up. Keep
  it in your pocket.

## What to expect on the day

- **Most uploads arrive in the following days**, not during the reception. Venue
  wifi is always oversubscribed. The page is built for this: uploads resume from
  where they stopped, even if the guest closes the tab and comes back tomorrow.
- Uploads run one file at a time on purpose. Six parallel videos on a weak
  connection finish slower and fail more often than six sequential ones.
- Drive takes a few minutes to generate video thumbnails. New clips show a plain
  placeholder tile until it catches up. This is normal.

---

## Notes on the design

- **Flat JSON files, not a database.** A wedding produces a few thousand records.
  `pdo_sqlite` is not guaranteed on shared hosting; `flock()` is available
  everywhere. Writes go to a temp file and are renamed, so a reader never sees a
  half-written manifest.
- **Rate limited per IP** (default 60 uploads/hour). The upload endpoint is on
  the open internet.
- **Server-side validation** of type and size before any upload session is
  granted. Empty MIME types fall back to the file extension, because iOS Safari
  regularly reports no type at all for HEIC straight off the camera roll.
- **CSRF tokens** on every admin action; admin session cookie is `httponly`.
- Full-size media is streamed, never cached to disk — caching multi-hundred-
  megabyte originals on shared hosting is the disk usage this design avoids.
  `Range` headers are passed through so video seeking works.

## Requirements

PHP 8.0+ with cURL. Both are standard on Bluehost shared hosting.
