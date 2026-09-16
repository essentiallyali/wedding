/**
 * Chunked, resumable uploads straight from the guest's phone to Google Drive.
 *
 * Design notes, all of which exist because of how people actually use this:
 *
 *  - Venue wifi is bad and cellular in a reception hall is worse. Uploads go up
 *    in chunks, and a dropped connection resumes from the last confirmed byte
 *    rather than starting the file again.
 *  - The session URL is kept in localStorage, so closing the tab mid-upload and
 *    coming back the next morning still resumes instead of re-sending.
 *  - Files go one at a time. Six parallel 200 MB videos on a weak connection
 *    finish slower than six sequential ones, and fail more often.
 */

(function () {
  'use strict';

  // Google requires resumable chunks to be a multiple of 256 KB.
  var CHUNK_SIZE = 8 * 1024 * 1024;
  var MAX_ATTEMPTS = 6;
  var STORAGE_PREFIX = 'wedding-upload:';

  var form = document.querySelector('[data-uploader]');
  if (!form) return;

  var input     = form.querySelector('input[type=file]');
  var pickBtn   = form.querySelector('[data-pick]');
  var startBtn  = form.querySelector('[data-start]');
  var listEl    = form.querySelector('[data-list]');
  var statusEl  = form.querySelector('[data-status]');
  var nameInput = form.querySelector('[name=from]');
  var noteInput = form.querySelector('[name=note]');

  var queue = [];
  var running = false;

  // --- helpers -------------------------------------------------------------

  function fileKey(file) {
    return STORAGE_PREFIX + [file.name, file.size, file.lastModified].join(':');
  }

  function remember(file, url) {
    try { localStorage.setItem(fileKey(file), url); } catch (e) { /* private mode */ }
  }

  function recall(file) {
    try { return localStorage.getItem(fileKey(file)); } catch (e) { return null; }
  }

  function forget(file) {
    try { localStorage.removeItem(fileKey(file)); } catch (e) { /* ignore */ }
  }

  function humanSize(bytes) {
    if (bytes >= 1073741824) return (bytes / 1073741824).toFixed(1) + ' GB';
    if (bytes >= 1048576) return Math.round(bytes / 1048576) + ' MB';
    return Math.max(1, Math.round(bytes / 1024)) + ' KB';
  }

  function sleep(ms) {
    return new Promise(function (r) { setTimeout(r, ms); });
  }

  // --- rendering -----------------------------------------------------------

  function render() {
    listEl.innerHTML = '';
    queue.forEach(function (item) {
      var row = document.createElement('li');
      row.className = 'up-item is-' + item.state;

      var label = document.createElement('div');
      label.className = 'up-item__name';
      label.textContent = item.file.name;

      var meta = document.createElement('div');
      meta.className = 'up-item__meta';
      meta.textContent = item.state === 'done'  ? 'Uploaded'
                       : item.state === 'error' ? (item.message || 'Failed')
                       : item.state === 'busy'  ? item.percent + '%'
                       : humanSize(item.file.size);

      var bar = document.createElement('div');
      bar.className = 'up-item__bar';
      var fill = document.createElement('span');
      fill.style.width = (item.state === 'done' ? 100 : item.percent) + '%';
      bar.appendChild(fill);

      row.appendChild(label);
      row.appendChild(meta);
      row.appendChild(bar);
      listEl.appendChild(row);
    });

    var pending = queue.filter(function (i) { return i.state === 'queued' || i.state === 'error'; }).length;
    startBtn.hidden = queue.length === 0 || running;
    startBtn.textContent = pending && queue.some(function (i) { return i.state === 'error'; })
      ? 'Retry ' + pending + ' file' + (pending === 1 ? '' : 's')
      : 'Upload ' + pending + ' file' + (pending === 1 ? '' : 's');
  }

  function setStatus(message, tone) {
    statusEl.textContent = message || '';
    statusEl.dataset.tone = tone || '';
  }

  // --- upload mechanics ----------------------------------------------------

  /** Ask our server for a Drive upload session. The file bytes never go here. */
  function createSession(file) {
    return fetch('api/create-upload.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        name: file.name,
        mime: file.type || 'application/octet-stream',
        size: file.size,
        from: nameInput ? nameInput.value : ''
      })
    }).then(function (res) {
      return res.json().then(function (data) {
        if (!res.ok) throw new Error(data.error || 'Could not start upload.');
        return data.upload_url;
      });
    });
  }

  /**
   * Ask Google how much of the file it already has.
   * Returns the byte offset to continue from, or -1 if the session is dead.
   */
  function probeOffset(url, total) {
    return new Promise(function (resolve) {
      var xhr = new XMLHttpRequest();
      xhr.open('PUT', url, true);
      xhr.setRequestHeader('Content-Range', 'bytes */' + total);
      xhr.onload = function () {
        if (xhr.status === 200 || xhr.status === 201) return resolve(total);
        if (xhr.status === 308) {
          var range = xhr.getResponseHeader('Range');
          // "bytes=0-1048575" means Google holds through byte 1048575.
          if (range) {
            var end = parseInt(range.split('-')[1], 10);
            if (!isNaN(end)) return resolve(end + 1);
          }
          return resolve(0);
        }
        resolve(-1); // 404/410: session expired, start over.
      };
      xhr.onerror = function () { resolve(0); };
      xhr.send();
    });
  }

  /** PUT one chunk. Resolves with the parsed file object once the last one lands. */
  function sendChunk(url, file, start, onProgress) {
    return new Promise(function (resolve, reject) {
      var end   = Math.min(start + CHUNK_SIZE, file.size);
      var slice = file.slice(start, end);

      var xhr = new XMLHttpRequest();
      xhr.open('PUT', url, true);
      xhr.setRequestHeader('Content-Range',
        'bytes ' + start + '-' + (end - 1) + '/' + file.size);

      xhr.upload.onprogress = function (evt) {
        if (evt.lengthComputable) onProgress(start + evt.loaded);
      };

      xhr.onload = function () {
        if (xhr.status === 200 || xhr.status === 201) {
          var body = {};
          try { body = JSON.parse(xhr.responseText); } catch (e) { /* ignore */ }
          return resolve({ complete: true, id: body.id });
        }
        if (xhr.status === 308) {
          return resolve({ complete: false, next: end });
        }
        reject(new Error('Upload rejected (' + xhr.status + ')'));
      };

      xhr.onerror   = function () { reject(new Error('Connection lost')); };
      xhr.ontimeout = function () { reject(new Error('Connection timed out')); };
      xhr.send(slice);
    });
  }

  /** Tell our server the upload finished, so it enters the approval queue. */
  function registerUpload(fileId) {
    return fetch('api/register.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        file_id: fileId,
        from: nameInput ? nameInput.value : '',
        note: noteInput ? noteInput.value : ''
      })
    });
  }

  async function uploadOne(item) {
    var file = item.file;
    item.state = 'busy';
    render();

    var url = recall(file);
    var offset = 0;

    if (url) {
      // Resuming a session from a previous attempt or a previous visit.
      offset = await probeOffset(url, file.size);
      if (offset < 0) { forget(file); url = null; offset = 0; }
    }

    if (!url) {
      url = await createSession(file);
      remember(file, url);
    }

    var attempts = 0;

    while (offset < file.size) {
      try {
        var result = await sendChunk(url, file, offset, function (sent) {
          item.percent = Math.min(99, Math.floor((sent / file.size) * 100));
          render();
        });

        attempts = 0;

        if (result.complete) {
          item.driveId = result.id;
          offset = file.size;
          break;
        }
        offset = result.next;
      } catch (err) {
        attempts++;
        if (attempts >= MAX_ATTEMPTS) throw err;

        // Back off, then ask Google where it actually got to. Re-probing
        // matters: a chunk can land even though the response never arrived.
        item.message = 'Reconnecting…';
        render();
        await sleep(Math.min(30000, 1000 * Math.pow(2, attempts)));

        var probed = await probeOffset(url, file.size);
        if (probed < 0) {
          forget(file);
          url = await createSession(file);
          remember(file, url);
          offset = 0;
        } else {
          offset = probed;
        }
      }
    }

    // A completed session returns the id on the final chunk. If a retry raced
    // past it, ask Google once more.
    if (!item.driveId) {
      var final = await probeOffset(url, file.size);
      if (final !== file.size) throw new Error('Upload did not finish');
    }

    if (item.driveId) {
      await registerUpload(item.driveId);
    }

    forget(file);
    item.state = 'done';
    item.percent = 100;
    render();
  }

  async function runQueue() {
    if (running) return;
    running = true;
    startBtn.hidden = true;
    setStatus('Uploading — please keep this page open.', 'busy');

    for (var i = 0; i < queue.length; i++) {
      var item = queue[i];
      if (item.state === 'done' || item.state === 'busy') continue;
      try {
        await uploadOne(item);
      } catch (err) {
        item.state = 'error';
        item.message = err.message || 'Failed';
        render();
      }
    }

    running = false;
    var failed = queue.filter(function (i) { return i.state === 'error'; }).length;
    var ok     = queue.filter(function (i) { return i.state === 'done'; }).length;

    if (failed) {
      setStatus(ok + ' uploaded, ' + failed + ' did not make it. Tap retry — nothing already sent will be re-sent.', 'error');
    } else {
      setStatus('All done — thank you! You can add more any time.', 'done');
    }
    render();
  }

  // --- wiring --------------------------------------------------------------

  if (pickBtn) {
    pickBtn.addEventListener('click', function () { input.click(); });
  }

  input.addEventListener('change', function () {
    Array.prototype.forEach.call(input.files, function (file) {
      var already = queue.some(function (i) {
        return i.file.name === file.name && i.file.size === file.size;
      });
      if (!already) {
        queue.push({ file: file, state: 'queued', percent: 0, message: '' });
      }
    });
    input.value = '';
    setStatus('');
    render();
  });

  startBtn.addEventListener('click', runQueue);

  // Warn before navigating away mid-upload. Progress is resumable, but people
  // assume leaving loses everything, so it is kinder to just ask.
  window.addEventListener('beforeunload', function (evt) {
    if (running) {
      evt.preventDefault();
      evt.returnValue = '';
    }
  });

  render();
})();
