<?php
/**
 * Copy this file to config.php and fill in the values.
 * config.php is gitignored — it holds secrets and must never be committed.
 *
 * Every value here is obtained by following README.md. Nothing in this file
 * is ever sent to the browser.
 */

return [
    // ---- Google OAuth ----------------------------------------------------
    // From the Google Cloud console, APIs & Services -> Credentials.
    'google_client_id'     => 'xxxxxxxxxxxx.apps.googleusercontent.com',
    'google_client_secret' => 'GOCSPX-xxxxxxxxxxxxxxxx',

    // Obtained once by running setup/get-refresh-token.php. Long-lived,
    // provided the OAuth consent screen is PUBLISHED (not left in Testing —
    // testing-mode refresh tokens expire after 7 days).
    'google_refresh_token' => '1//xxxxxxxxxxxxxxxxxxxx',

    // The Drive folder that receives every upload. Create an empty folder in
    // your own Drive, open it, and copy the id out of the URL:
    // https://drive.google.com/drive/folders/THIS_PART_HERE
    'drive_folder_id'      => 'xxxxxxxxxxxxxxxxxxxxxxxxxxx',

    // ---- Admin ------------------------------------------------------------
    // Generate with:  php -r "echo password_hash('your-password', PASSWORD_DEFAULT), PHP_EOL;"
    // Paste the resulting string (starts with $2y$) below.
    'admin_password_hash'  => '$2y$10$replace.this.with.a.real.hash',

    // ---- Upload policy ----------------------------------------------------
    // Applied server-side before an upload session is granted.
    'max_file_bytes'       => 3 * 1024 * 1024 * 1024,  // 3 GB per file
    'max_files_per_ip_per_hour' => 60,

    'allowed_mime_prefixes' => ['image/', 'video/'],

    // Explicit denylist for formats that are nominally image/* or video/* but
    // that we do not want to accept.
    'blocked_mimes' => ['image/svg+xml'],

    // ---- Presentation -----------------------------------------------------
    'couple_names'  => 'Ali & —',
    'wedding_date'  => '',

    // Set false to close uploads after the event without taking the page down.
    'uploads_open'  => true,
];
