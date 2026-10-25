document.addEventListener('DOMContentLoaded', () => {
    const form         = document.getElementById('download-form');
    const urlInput     = document.getElementById('url-input');
    const pasteBtn     = document.getElementById('paste-btn');
    const analyzeBtn   = document.getElementById('analyze-btn');
    const errorMsg     = document.getElementById('error-message');

    const resultsSection  = document.getElementById('results-section');
    const loadingSkeleton = document.getElementById('loading-skeleton');
    const resultContent   = document.getElementById('result-content');

    const resThumb    = document.getElementById('res-thumbnail');
    const resDuration = document.getElementById('res-duration');
    const resTitle    = document.getElementById('res-title');
    const resPlatform = document.getElementById('res-platform');
    const resUploader = document.getElementById('res-uploader');

    // Download Progress Modal Elements
    const dlModal       = document.getElementById('dl-modal');
    const dlModalClose  = document.getElementById('dl-modal-close');
    const dlModalTitle  = document.getElementById('dl-modal-title');
    const dlModalBadge  = document.getElementById('dl-modal-badge');
    const dlModalFile   = document.getElementById('dl-modal-filename');
    const dlModalPercent = document.getElementById('dl-modal-percent');
    const dlModalTimer   = document.getElementById('dl-modal-timer');
    const dlProgressBar  = document.getElementById('dl-progress-bar');
    const dlModalStatus  = document.getElementById('dl-modal-status');
    const dlSpinner      = document.getElementById('dl-spinner');
    const dlCheck        = document.getElementById('dl-check');
    const dlModalFooter  = document.getElementById('dl-modal-footer');
    const dlModalDirect  = document.getElementById('dl-modal-direct');
    const dlIframe       = document.getElementById('dl-iframe');
    const dlBackdrop     = document.querySelector('.dl-modal-backdrop');

    let currentVideoUrl = '';
    let pollTimer       = null;
    let autoCloseTimer  = null;
    let progressTimer   = null;

    // ── Clipboard Paste ───────────────────────────────────────────
    pasteBtn.addEventListener('click', async () => {
        try {
            const text = await navigator.clipboard.readText();
            urlInput.value = text;
        } catch {
            showError('Clipboard permission denied.');
        }
    });

    // ── Form Submit ───────────────────────────────────────────────
    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const url = urlInput.value.trim();
        if (!url) return;

        resetUI();
        showLoading();

        try {
            const resp = await fetch('api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'analyze', url })
            });

            const json = await resp.json();
            if (!resp.ok || !json.success) {
                throw new Error(json.error?.message || 'Server error');
            }

            currentVideoUrl = url;
            renderResults(json.data);

        } catch (err) {
            hideLoading();
            showError(err.message);
        }
    });

    // ── UI Helpers ────────────────────────────────────────────────
    function resetUI() {
        errorMsg.classList.add('hidden');
        resultsSection.classList.add('hidden');
        resultContent.classList.add('hidden');
        const fc = document.querySelector('.formats-container');
        if (fc) fc.innerHTML = '';
    }

    function showLoading() {
        analyzeBtn.disabled = true;
        analyzeBtn.innerHTML = '<span>Analyzing...</span>';
        resultsSection.classList.remove('hidden');
        loadingSkeleton.classList.remove('hidden');
    }

    function hideLoading() {
        analyzeBtn.disabled  = false;
        analyzeBtn.innerHTML = '<span>Analyze</span>';
        loadingSkeleton.classList.add('hidden');
    }

    function showError(msg) {
        if (msg.includes('bot verification') || msg.includes('not a bot') || msg.includes('High-Traffic')) {
            errorMsg.innerHTML = `
                <div class="bot-notice-box">
                    <strong>⚠️ YouTube Temporary Traffic Restriction</strong>
                    <p>YouTube has temporarily challenged automated access to this video from your current network connection. This usually resets automatically in a short while, or immediately by reconnecting your internet (or mobile hotspot) to obtain a fresh IP.</p>
                </div>
            `;
        } else {
            errorMsg.textContent = msg;
        }
        errorMsg.classList.remove('hidden');
    }

    // ── Render Results ────────────────────────────────────────────
    function renderResults(data) {
        hideLoading();
        resultContent.classList.remove('hidden');

        resThumb.src            = data.thumbnail || 'https://placehold.co/640x360/1e293b/94a3b8?text=No+Thumbnail';
        resTitle.textContent    = data.title     || 'Unknown Title';
        resPlatform.textContent = (data.platform || 'Unknown').toUpperCase();
        resDuration.textContent = formatDuration(data.duration);

        if (data.uploader && resUploader) {
            resUploader.textContent = '👤 ' + data.uploader;
            resUploader.classList.remove('hidden');
        } else if (resUploader) {
            resUploader.classList.add('hidden');
        }

        const videoOpts = data.video_options || [];
        const audioOpts = data.audio_options || [];
        const subtitles = data.subtitles || [];
        const ffmpegOK  = data.ffmpeg_available === true;

        if (!videoOpts.length && !audioOpts.length && !subtitles.length) {
            showError('No downloadable formats or media found. The URL may be private or unsupported.');
            resultContent.classList.add('hidden');
            return;
        }

        const container = document.querySelector('.formats-container');
        container.innerHTML = '';

        // ffmpeg warning banner
        if (!ffmpegOK && videoOpts.some(v => v.needs_ffmpeg)) {
            const warn = document.createElement('div');
            warn.className = 'ffmpeg-warning';
            warn.innerHTML = `
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20">
                    <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path>
                    <line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line>
                </svg>
                <div>
                    <strong>ffmpeg not found.</strong> Video+audio merging and MP3 conversion require ffmpeg.exe in the project folder.
                    Place <code>ffmpeg.exe</code> in <code>d:/Projects/Video downloader/</code> to enable full features.
                </div>`;
            container.appendChild(warn);
        }

        // ── Tab Navigation ─────────────────────────────────────────
        const tabsNav = document.createElement('div');
        tabsNav.className = 'format-tabs-nav';
        tabsNav.innerHTML = `
            <button class="tab-btn active" data-pane="pane-video">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                    <polygon points="23 7 16 12 23 17 23 7"></polygon>
                    <rect x="1" y="5" width="15" height="14" rx="2" ry="2"></rect>
                </svg>
                Video <span class="tab-count">${videoOpts.length}</span>
            </button>
            <button class="tab-btn" data-pane="pane-audio">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                    <path d="M9 18V5l12-2v13"></path>
                    <circle cx="6" cy="18" r="3"></circle>
                    <circle cx="18" cy="16" r="3"></circle>
                </svg>
                Audio <span class="tab-count">${audioOpts.length ? (audioOpts.length + 1) : 0}</span>
            </button>
            <button class="tab-btn" data-pane="pane-subs">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                    <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>
                </svg>
                Subtitles <span class="tab-count">${subtitles.length}</span>
            </button>
        `;
        container.appendChild(tabsNav);

        // ── Panes Container ────────────────────────────────────────
        const panesWrapper = document.createElement('div');
        panesWrapper.className = 'format-panes-wrapper';

        // 1. Video Pane
        const videoPane = document.createElement('div');
        videoPane.id = 'pane-video';
        videoPane.className = 'tab-pane active';
        if (videoOpts.length) {
            const list = document.createElement('div');
            list.className = 'formats-list';
            videoOpts.forEach(v => list.appendChild(buildVideoCard(v, data, ffmpegOK)));
            videoPane.appendChild(list);
        } else {
            videoPane.innerHTML = `<div class="empty-pane-msg">No separate video streams available for this link.</div>`;
        }
        panesWrapper.appendChild(videoPane);

        // 2. Audio Pane
        const audioPane = document.createElement('div');
        audioPane.id = 'pane-audio';
        audioPane.className = 'tab-pane';
        const audioList = document.createElement('div');
        audioList.className = 'formats-list';

        // Featured Universal MP3 Card
        if (ffmpegOK || audioOpts.length) {
            audioList.appendChild(buildUniversalMp3Card(data, ffmpegOK));
        }

        // Original Audio Formats / Languages
        audioOpts.forEach(a => audioList.appendChild(buildAudioCard(a, data)));
        audioPane.appendChild(audioList);
        panesWrapper.appendChild(audioPane);

        // 3. Subtitles Pane
        const subsPane = document.createElement('div');
        subsPane.id = 'pane-subs';
        subsPane.className = 'tab-pane';
        renderSubtitlesPane(subsPane, subtitles, data);
        panesWrapper.appendChild(subsPane);

        container.appendChild(panesWrapper);

        // ── Setup Tab Switching ────────────────────────────────────
        tabsNav.querySelectorAll('.tab-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                tabsNav.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
                panesWrapper.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));

                btn.classList.add('active');
                const targetPane = document.getElementById(btn.dataset.pane);
                if (targetPane) targetPane.classList.add('active');
            });
        });

        // Setup Fast Download Action Listeners on all download buttons
        container.querySelectorAll('[data-dl-url]').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                const url   = btn.dataset.dlUrl;
                const title = btn.dataset.dlTitle || data.title || 'media';
                const badge = btn.dataset.dlBadge || 'Download';
                startDownload(url, title, badge);
            });
        });

        // If no video but audio exists, select audio tab by default
        if (!videoOpts.length && audioOpts.length) {
            const audioBtn = tabsNav.querySelector('[data-pane="pane-audio"]');
            if (audioBtn) audioBtn.click();
        }
    }

    // ── Video Card ────────────────────────────────────────────────
    function buildVideoCard(fmt, data, ffmpegOK) {
        const card  = document.createElement('div');
        card.className = 'download-item';

        const h    = fmt.height || 0;
        const fps  = fmt.fps   || 0;
        const dr   = fmt.dynamic_range || '';
        const ext  = fmt.ext   || 'mp4';
        const tbr  = fmt.tbr   || fmt.vbr || 0;
        const size = fmt.filesize ? formatBytes(fmt.filesize) : '';
        const isMerged    = (fmt.download_format_id || '').includes('+');
        const needsFFmpeg = fmt.needs_ffmpeg === true;
        const disabled    = needsFFmpeg && !ffmpegOK;

        const qualLabel = h ? `${h}p` : (fmt.resolution || '?');

        const badges = [];
        if (h >= 2160) badges.push(tag('badge-4k', '4K Ultra HD'));
        else if (h >= 1440) badges.push(tag('badge-4k', '1440p QHD'));
        if (fps > 30)  badges.push(tag('badge-fps', `${fps}fps`));
        if (dr && dr !== 'SDR') badges.push(tag('badge-hdr', dr));
        if (isMerged)  badges.push(tag('badge-merged', '🔀 HD Merge'));

        const vcShort = (fmt.vcodec || '').split('.')[0] || 'video';
        let codecLine = ext.toUpperCase() + ' · ' + vcShort;
        if (tbr) codecLine += ` · ${tbr}kbps`;

        let audioLine = '';
        if (isMerged) {
            audioLine = `<div class="audio-note">🎵 ${fmt.audio_info || 'Audio included'}</div>`;
        } else if (!isMerged && fmt.acodec === 'none') {
            audioLine = `<div class="audio-note no-audio">🔇 No Audio (video only stream)</div>`;
        }

        const dlUrl = buildDlUrl(fmt, data, ext, 'media');

        card.innerHTML = `
            <div class="format-quality">
                <span class="format-res">${qualLabel}</span>
                <span class="format-badges">${badges.join('')}</span>
            </div>
            <div class="format-codec">${codecLine}</div>
            ${size ? `<div class="format-size">~${size}</div>` : ''}
            ${audioLine}
            ${disabled
                ? `<span class="btn-download btn-download-disabled" title="ffmpeg required">⚠ Needs ffmpeg</span>`
                : `<button type="button" class="btn-download" data-dl-url="${dlUrl}" data-dl-title="${safeAttr(data.title)}" data-dl-badge="${qualLabel} · ${ext.toUpperCase()}">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" width="15" height="15">
                            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                            <polyline points="7 10 12 15 17 10"></polyline>
                            <line x1="12" y1="15" x2="12" y2="3"></line>
                        </svg>
                        Download ${ext.toUpperCase()}
                   </button>`
            }
        `;
        return card;
    }

    // ── Universal High-Quality MP3 Card ────────────────────────────
    function buildUniversalMp3Card(data, ffmpegOK) {
        const card = document.createElement('div');
        card.className = 'download-item download-item-audio download-item-featured';

        const dlUrl = 'download.php'
            + '?url='       + encodeURIComponent(data.source_url || currentVideoUrl)
            + '&format_id=' + encodeURIComponent('bestaudio/best')
            + '&ext=mp3'
            + '&type=audio'
            + '&title='     + encodeURIComponent(data.title || 'audio');

        card.innerHTML = `
            <div class="format-quality">
                <span class="format-res">MP3 Audio</span>
                <span class="badge badge-featured">★ UNIVERSAL</span>
            </div>
            <div class="format-codec">320kbps High Quality · MP3</div>
            <div class="audio-lang-label">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="13" height="13">
                    <circle cx="12" cy="12" r="10"></circle><line x1="2" y1="12" x2="22" y2="12"></line>
                    <path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"></path>
                </svg>
                Plays on all phones, cars & players
            </div>
            <button type="button" class="btn-download btn-download-audio btn-download-mp3" data-dl-url="${dlUrl}" data-dl-title="${safeAttr(data.title)}" data-dl-badge="320kbps · MP3">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" width="15" height="15">
                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                    <polyline points="7 10 12 15 17 10"></polyline>
                    <line x1="12" y1="15" x2="12" y2="3"></line>
                </svg>
                Download MP3
            </button>
        `;
        return card;
    }

    // ── Audio Card ────────────────────────────────────────────────
    function buildAudioCard(fmt, data) {
        const card = document.createElement('div');
        card.className = 'download-item download-item-audio';

        const abr   = fmt.abr  || fmt.tbr || 0;
        const ext   = (fmt.ext === 'webm' || fmt.ext === 'opus') ? 'm4a' : (fmt.ext || 'm4a');
        const lang  = fmt.audio_track_name || fmt.audio_lang || 'Original Audio';
        const codec = (fmt.acodec || 'aac').split('.')[0];
        const size  = fmt.filesize ? formatBytes(fmt.filesize) : '';

        const dlUrl = buildDlUrl(fmt, data, ext, 'audio');

        card.innerHTML = `
            <div class="format-quality">
                <span class="format-res">${abr ? abr + 'kbps' : 'Audio Track'}</span>
                <span class="badge badge-audio">${ext.toUpperCase()}</span>
            </div>
            <div class="format-codec">${codec}${size ? ' · ~' + size : ''}</div>
            <div class="audio-lang-label">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="13" height="13">
                    <circle cx="12" cy="12" r="10"></circle><line x1="2" y1="12" x2="22" y2="12"></line>
                    <path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"></path>
                </svg>
                ${lang}
            </div>
            <button type="button" class="btn-download btn-download-audio" data-dl-url="${dlUrl}" data-dl-title="${safeAttr(data.title)}" data-dl-badge="${ext.toUpperCase()} · ${abr ? abr + 'kbps' : 'Audio'}">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" width="15" height="15">
                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                    <polyline points="7 10 12 15 17 10"></polyline>
                    <line x1="12" y1="15" x2="12" y2="3"></line>
                </svg>
                Download ${ext.toUpperCase()}
            </button>
        `;
        return card;
    }

    // ── Subtitles Pane ────────────────────────────────────────────
    function renderSubtitlesPane(container, subtitles, data) {
        const autoTranslations = data.auto_translations || [];

        if (!subtitles.length && !autoTranslations.length) {
            container.innerHTML = `
                <div class="empty-subs-box">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="40" height="40">
                        <circle cx="12" cy="12" r="10"></circle>
                        <line x1="8" y1="12" x2="16" y2="12"></line>
                    </svg>
                    <h4>No Subtitles Available</h4>
                    <p>This video does not have closed captions or subtitles uploaded.</p>
                </div>`;
            return;
        }

        container.innerHTML = '';

        // Header for Present Subtitles
        const header = document.createElement('div');
        header.className = 'subs-present-header';
        header.innerHTML = `
            <div class="subs-header-left">
                <h4 class="subs-section-title">Available Subtitles</h4>
                <span class="subs-count-badge">${subtitles.length} track${subtitles.length === 1 ? '' : 's'} present on this video</span>
            </div>
        `;
        container.appendChild(header);

        // 1. Grid of Present Subtitles (Creator uploaded or original transcript only)
        if (subtitles.length) {
            const grid = document.createElement('div');
            grid.className = 'subs-grid';
            subtitles.forEach(sub => grid.appendChild(createSubCard(sub, data)));
            container.appendChild(grid);
        } else {
            const noOrig = document.createElement('div');
            noOrig.className = 'subs-note';
            noOrig.textContent = 'No official creator subtitles uploaded. Auto-translated captions may be available below.';
            container.appendChild(noOrig);
        }

        // 2. Expandable Auto-Translations Drawer (for translated machine captions)
        if (autoTranslations.length) {
            const transSection = document.createElement('div');
            transSection.className = 'auto-trans-section';
            transSection.innerHTML = `
                <button type="button" class="btn-toggle-auto-trans" id="toggle-trans-btn">
                    <div class="toggle-btn-left">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                            <circle cx="12" cy="12" r="10"></circle>
                            <line x1="2" y1="12" x2="22" y2="12"></line>
                            <path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"></path>
                        </svg>
                        <span>Need another language? Show Auto-Translated Captions (${autoTranslations.length})</span>
                    </div>
                    <svg class="chevron-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" width="16" height="16">
                        <polyline points="6 9 12 15 18 9"></polyline>
                    </svg>
                </button>
                <div class="auto-trans-drawer hidden" id="trans-drawer">
                    <div class="subs-toolbar">
                        <div class="subs-search-box">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16">
                                <circle cx="11" cy="11" r="8"></circle>
                                <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                            </svg>
                            <input type="text" id="trans-filter-input" placeholder="Search auto-translated languages (e.g. Spanish, Telugu, French)...">
                        </div>
                        <span class="subs-count-badge" id="trans-count-badge">${autoTranslations.length} languages</span>
                    </div>
                    <div class="subs-grid" id="trans-grid"></div>
                </div>
            `;
            container.appendChild(transSection);

            const toggleBtn   = transSection.querySelector('#toggle-trans-btn');
            const drawer      = transSection.querySelector('#trans-drawer');
            const transGrid   = transSection.querySelector('#trans-grid');
            const filterInput = transSection.querySelector('#trans-filter-input');
            const countBadge  = transSection.querySelector('#trans-count-badge');
            let isRendered    = false;

            toggleBtn.addEventListener('click', () => {
                const isOpen = !drawer.classList.contains('hidden');
                if (isOpen) {
                    drawer.classList.add('hidden');
                    toggleBtn.classList.remove('active');
                } else {
                    drawer.classList.remove('hidden');
                    toggleBtn.classList.add('active');
                    if (!isRendered) {
                        autoTranslations.forEach(sub => transGrid.appendChild(createSubCard(sub, data)));
                        isRendered = true;
                    }
                }
            });

            if (filterInput) {
                filterInput.addEventListener('input', (e) => {
                    const query = e.target.value.trim().toLowerCase();
                    const cards = transGrid.querySelectorAll('.sub-card');
                    let visibleCount = 0;
                    cards.forEach(card => {
                        const match = card.dataset.langName.includes(query) || card.dataset.langCode.includes(query);
                        card.style.display = match ? 'flex' : 'none';
                        if (match) visibleCount++;
                    });
                    if (countBadge) {
                        countBadge.textContent = query ? `${visibleCount} of ${autoTranslations.length} shown` : `${autoTranslations.length} languages`;
                    }
                });
            }
        }
    }

    function createSubCard(sub, data) {
        const item = document.createElement('div');
        item.className = 'sub-card';
        item.dataset.langName = (sub.name || '').toLowerCase();
        item.dataset.langCode = (sub.lang || '').toLowerCase();

        const srtUrl = 'download.php'
            + '?url='      + encodeURIComponent(data.source_url || currentVideoUrl)
            + '&type=subtitle'
            + '&sub_lang=' + encodeURIComponent(sub.lang)
            + '&ext=srt'
            + '&title='    + encodeURIComponent(data.title || 'subtitles');

        const vttUrl = 'download.php'
            + '?url='      + encodeURIComponent(data.source_url || currentVideoUrl)
            + '&type=subtitle'
            + '&sub_lang=' + encodeURIComponent(sub.lang)
            + '&ext=vtt'
            + '&title='    + encodeURIComponent(data.title || 'subtitles');

        const badgeHtml = sub.is_original
            ? (sub.is_auto ? '<span class="badge badge-auto-sub">🎙️ Original Spoken Transcript</span>' : '<span class="badge badge-official-sub">✓ Creator Uploaded</span>')
            : '<span class="badge badge-auto-sub">🌐 Machine Translation</span>';

        item.innerHTML = `
            <div class="sub-info">
                <div class="sub-name-row">
                    <strong class="sub-name">${sub.name || sub.lang}</strong>
                    <span class="sub-code">${sub.lang}</span>
                </div>
                ${badgeHtml}
            </div>
            <div class="sub-actions">
                <button type="button" class="btn-sub-dl" data-dl-url="${srtUrl}" data-dl-title="${safeAttr(data.title)}" data-dl-badge="${sub.name || sub.lang} · SRT" title="Download SubRip (.SRT) subtitle">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="13" height="13">
                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                        <polyline points="7 10 12 15 17 10"></polyline>
                        <line x1="12" y1="15" x2="12" y2="3"></line>
                    </svg>
                    .SRT
                </button>
                <button type="button" class="btn-sub-dl btn-sub-vtt" data-dl-url="${vttUrl}" data-dl-title="${safeAttr(data.title)}" data-dl-badge="${sub.name || sub.lang} · VTT" title="Download WebVTT (.VTT) subtitle">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="13" height="13">
                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                        <polyline points="7 10 12 15 17 10"></polyline>
                        <line x1="12" y1="15" x2="12" y2="3"></line>
                    </svg>
                    .VTT
                </button>
            </div>
        `;
        return item;
    }

    // ── Fast In-Page Download Execution with Progress Feedback ────
    function startDownload(url, title, badge) {
        clearInterval(pollTimer);
        clearTimeout(autoCloseTimer);
        clearInterval(progressTimer);

        const token = 'dl_' + Date.now() + '_' + Math.random().toString(36).substring(2, 8);
        const fullUrl = url + (url.includes('?') ? '&' : '?') + 'token=' + encodeURIComponent(token);

        // Configure Modal
        dlModalTitle.textContent   = 'Preparing High-Speed Download';
        dlModalBadge.textContent   = badge || 'Fast Download';
        dlModalFile.textContent    = title || 'media';
        dlModalStatus.textContent  = '⚡ Initializing 5x parallel stream connection...';
        dlProgressBar.style.width  = '15%';
        if (dlModalPercent) dlModalPercent.textContent = '15%';
        if (dlModalTimer) dlModalTimer.textContent     = '⏱️ 0s';
        dlSpinner.classList.remove('hidden');
        dlCheck.classList.add('hidden');
        dlModalFooter.classList.add('hidden');
        dlModalDirect.href         = fullUrl;

        dlModal.classList.remove('hidden');

        // Trigger file download in background via hidden iframe (No blank tabs!)
        dlIframe.src = fullUrl;

        // Animate realistic progress milestones and live elapsed timer
        let currentPct = 15;
        let elapsed = 0;
        let startTime = Date.now();

        progressTimer = setInterval(() => {
            elapsed = Math.floor((Date.now() - startTime) / 1000);
            if (dlModalTimer) dlModalTimer.textContent = `⏱️ ${elapsed}s`;

            if (elapsed <= 2) {
                currentPct = Math.min(35, currentPct + 4);
                dlModalStatus.textContent = '⚡ Initializing 5x parallel multi-threaded connection...';
            } else if (elapsed > 2 && elapsed <= 5) {
                currentPct = Math.min(65, currentPct + 6);
                dlModalStatus.textContent = '📥 Fetching video & audio fragments in 5 parallel streams...';
            } else if (elapsed > 5 && elapsed <= 9) {
                currentPct = Math.min(88, currentPct + 3);
                dlModalStatus.textContent = '🔀 Merging & encoding media into full HD stream...';
            } else if (elapsed > 9) {
                currentPct = Math.min(95, currentPct + 1);
                dlModalStatus.textContent = '🚀 Finalizing file handoff to your browser...';
                dlModalFooter.classList.remove('hidden');
            }

            dlProgressBar.style.width = currentPct + '%';
            if (dlModalPercent) dlModalPercent.textContent = `${Math.round(currentPct)}%`;
        }, 500);

        // Poll document.cookie for completion (Cookie set when streamFile starts)
        const cookieName = 'vidloader_dl_' + token;
        pollTimer = setInterval(() => {
            if (document.cookie.includes(cookieName + '=done')) {
                clearInterval(pollTimer);
                clearInterval(progressTimer);

                // Expire tracking cookie
                document.cookie = cookieName + '=; Path=/; Max-Age=0';

                // Complete State
                dlProgressBar.style.width  = '100%';
                if (dlModalPercent) dlModalPercent.textContent = '100%';
                dlSpinner.classList.add('hidden');
                dlCheck.classList.remove('hidden');
                dlModalTitle.textContent   = '🎉 Download Started!';
                dlModalStatus.textContent  = 'File handed over to your browser! Check your Downloads folder.';
                dlModalFooter.classList.remove('hidden');

                // Auto-close modal after 4 seconds
                autoCloseTimer = setTimeout(() => {
                    closeDlModal();
                }, 4000);
            }
        }, 300);

        // Fallback display direct link if taking longer
        setTimeout(() => {
            if (dlModalFooter) dlModalFooter.classList.remove('hidden');
        }, 8000);
    }

    function closeDlModal() {
        if (dlModal) dlModal.classList.add('hidden');
        clearInterval(pollTimer);
        clearInterval(progressTimer);
        clearTimeout(autoCloseTimer);
    }

    if (dlModalClose) {
        dlModalClose.addEventListener('click', closeDlModal);
    }
    if (dlBackdrop) {
        dlBackdrop.addEventListener('click', closeDlModal);
    }

    // ── Build Download URL ────────────────────────────────────────
    function buildDlUrl(fmt, data, overrideExt, type) {
        return 'download.php'
            + '?url='       + encodeURIComponent(data.source_url || currentVideoUrl)
            + '&format_id=' + encodeURIComponent(fmt.download_format_id || fmt.format_id)
            + '&ext='       + encodeURIComponent(overrideExt || fmt.ext || 'mp4')
            + '&type='      + encodeURIComponent(type || 'media')
            + '&title='     + encodeURIComponent(data.title || 'video');
    }

    function tag(cls, text) {
        return `<span class="badge ${cls}">${text}</span>`;
    }
    function safeAttr(s) {
        return String(s || '').replace(/"/g, '&quot;').replace(/[<>]/g, '');
    }
    function formatDuration(sec) {
        if (!sec) return '0:00';
        const h = Math.floor(sec / 3600);
        const m = Math.floor((sec % 3600) / 60);
        const s = Math.floor(sec % 60);
        return h > 0
            ? `${h}:${String(m).padStart(2,'0')}:${String(s).padStart(2,'0')}`
            : `${m}:${String(s).padStart(2,'0')}`;
    }
    function formatBytes(bytes) {
        if (!bytes) return '';
        const k = 1024, sizes = ['B','KB','MB','GB'];
        const i = Math.min(Math.floor(Math.log(bytes) / Math.log(k)), 3);
        return (bytes / Math.pow(k, i)).toFixed(1) + ' ' + sizes[i];
    }
});
