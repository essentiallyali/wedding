<?php
/**
 * Flat-file persistence for the upload manifest and rate-limit counters.
 *
 * A wedding produces a few thousand records at most, arriving in bursts of a
 * few per second at worst. SQLite would be tidier but is not guaranteed to be
 * compiled into PHP on shared hosting, so this uses JSON files guarded by
 * flock(), which works everywhere.
 */

declare(strict_types=1);

function store_dir(): string
{
    $dir = dirname(__DIR__) . '/data';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    return $dir;
}

function store_path(string $name): string
{
    return store_dir() . '/' . $name;
}

function store_read_json(string $path): ?array
{
    if (!is_file($path)) {
        return null;
    }
    $raw = file_get_contents($path);
    if ($raw === false || $raw === '') {
        return null;
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

function store_write_json(string $path, array $data): void
{
    // Write to a temp file and rename, so a reader never sees a half-written
    // manifest even if the process dies mid-write.
    $tmp = $path . '.tmp';
    file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    rename($tmp, $path);
}

/**
 * Run $fn against the manifest while holding an exclusive lock.
 *
 * Two guests finishing an upload in the same instant would otherwise race and
 * one record would be lost.
 */
function store_mutate(string $name, callable $fn)
{
    $path = store_path($name);
    $lock = fopen($path . '.lock', 'c');
    if ($lock === false) {
        throw new RuntimeException('Could not open lock file for ' . $name);
    }

    flock($lock, LOCK_EX);
    try {
        $data   = store_read_json($path) ?? [];
        $result = $fn($data);
        // A callback may return false to signal "no change; do not write".
        if ($result !== false) {
            store_write_json($path, $result);
        }
        return $result;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

// ---------------------------------------------------------------------------
// Upload manifest
// ---------------------------------------------------------------------------

const MANIFEST = 'uploads.json';

/**
 * Record a completed upload. Everything lands as 'pending' — nothing is
 * visible in the gallery until it is explicitly approved.
 */
function manifest_add(array $entry): void
{
    store_mutate(MANIFEST, function (array $data) use ($entry) {
        $data[] = $entry + [
            'status'      => 'pending',
            'uploaded_at' => time(),
        ];
        return $data;
    });
}

function manifest_all(): array
{
    return store_read_json(store_path(MANIFEST)) ?? [];
}

function manifest_by_status(string $status): array
{
    return array_values(array_filter(manifest_all(), fn($e) => ($e['status'] ?? '') === $status));
}

function manifest_find(string $fileId): ?array
{
    foreach (manifest_all() as $entry) {
        if (($entry['file_id'] ?? '') === $fileId) {
            return $entry;
        }
    }
    return null;
}

function manifest_set_status(string $fileId, string $status): bool
{
    $found = false;
    store_mutate(MANIFEST, function (array $data) use ($fileId, $status, &$found) {
        foreach ($data as $i => $entry) {
            if (($entry['file_id'] ?? '') === $fileId) {
                $data[$i]['status']    = $status;
                $data[$i]['decided_at'] = time();
                $found = true;
            }
        }
        return $data;
    });
    return $found;
}

function manifest_remove(string $fileId): void
{
    store_mutate(MANIFEST, function (array $data) use ($fileId) {
        return array_values(array_filter($data, fn($e) => ($e['file_id'] ?? '') !== $fileId));
    });
}

// ---------------------------------------------------------------------------
// Rate limiting
// ---------------------------------------------------------------------------

/**
 * Crude per-IP hourly cap. This endpoint is on the open internet; without a
 * cap, one bored person with a script can fill the Drive quota.
 */
function rate_limit_ok(string $ip, int $maxPerHour): bool
{
    $allowed = true;

    store_mutate('ratelimit.json', function (array $data) use ($ip, $maxPerHour, &$allowed) {
        $now    = time();
        $cutoff = $now - 3600;

        // Drop expired timestamps for every IP so the file cannot grow forever.
        foreach ($data as $key => $stamps) {
            $data[$key] = array_values(array_filter($stamps, fn($t) => $t > $cutoff));
            if (!$data[$key]) {
                unset($data[$key]);
            }
        }

        $mine = $data[$ip] ?? [];
        if (count($mine) >= $maxPerHour) {
            $allowed = false;
            return $data;
        }

        $mine[]     = $now;
        $data[$ip]  = $mine;
        return $data;
    });

    return $allowed;
}

// ---------------------------------------------------------------------------
// Thumbnail cache
// ---------------------------------------------------------------------------

function thumb_cache_path(string $fileId, int $width): string
{
    $dir = store_dir() . '/cache';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    // Hash the id so the filename is always filesystem-safe.
    return $dir . '/' . sha1($fileId . '@' . $width) . '.jpg';
}
