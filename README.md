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

You need about 20 minutes and a Google account.

### 1. Google Cloud project

1. Go to [console.cloud.google.com](https://console.cloud.google.com) → create a
   project (any name).
2. **APIs & Services → Library** → search "Google Drive API" → **Enable**.
3. **APIs & Services → OAuth consent screen**:
   - User type: **External**
   - Fill in app name, your email, developer contact.
   - Scopes: add `.../auth/drive.file` — this is a **non-sensitive** scope, so
     Google does *not* require a verification review.
   - ⚠️ **Publish the app** (click *Publish app* → confirm). If you leave it in
     *Testing*, your refresh token silently expires after **7 days** — which
     would mean uploads breaking a week after you set it up.
4. **APIs & Services → Credentials → Create credentials → OAuth client ID**:
   - Type: **Web application**
   - Authorised redirect URI: `https://yourdomain.com/setup-token.php`
     (use the real path where you upload these files)
   - Save the **Client ID** and **Client secret**.

### 2. Configure

```bash
cp lib/config.sample.php lib/config.php
```

Edit `lib/config.php` and fill in `google_client_id` and `google_client_secret`.

Generate your admin password hash and paste it into `admin_password_hash`:

```bash
php -r "echo password_hash('pick-a-good-password', PASSWORD_DEFAULT), PHP_EOL;"
```

Leave `google_refresh_token` and `drive_folder_id` as-is for now.

### 3. Upload to Bluehost

Put the files on your server so that `public/` is what the web sees:

| Local path        | On the server                           |
|-------------------|-----------------------------------------|
| `public/*`        | `public_html/photos/`  (or a subdomain) |
| `lib/`, `data/`   | **one level above** `public_html/` if you can |

Keeping `lib/` and `data/` outside the web root is best, because `lib/config.php`
holds your Google secret. If your setup makes that awkward, the bundled
`.htaccess` files in `lib/` and `data/` deny direct web access as a fallback —
but outside the web root is stronger.

If you move `lib/` and `data/`, update the `require_once` paths at the top of the
files in `public/` and `public/api/` to point at the new location.

Make sure `data/` is writable:

```bash
chmod 775 data data/cache
```

### 4. Connect Drive

Visit `https://yourdomain.com/photos/setup-token.php`, enter your admin password,
and follow the two steps. It will:

- run the Google consent flow,
- **create** a Drive folder called *Wedding Guest Uploads*,
- print your `google_refresh_token` and `drive_folder_id`.

Paste both into `lib/config.php`.

> The folder is created through the API on purpose. The `drive.file` scope only
> reaches files the app itself created, so a folder you made by hand in the Drive
> UI may not be writable. Letting setup create it avoids that trap.

### 5. Delete the setup file

```bash
rm public/setup-token.php
```

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

## Restyling

Every colour, font and measurement is a CSS custom property in the `:root` block
at the top of `public/assets/styles.css`. Drop in the tokens from the design
system and the whole site follows — no other file needs to change. The current
values are a deliberately plain placeholder.

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
