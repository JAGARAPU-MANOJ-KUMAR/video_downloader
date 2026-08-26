document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('download-form');
    const urlInput = document.getElementById('url-input');
    const pasteBtn = document.getElementById('paste-btn');
    const analyzeBtn = document.getElementById('analyze-btn');
    const errorMsg = document.getElementById('error-message');
    
    const resultsSection = document.getElementById('results-section');
    const loadingSkeleton = document.getElementById('loading-skeleton');
    const resultContent = document.getElementById('result-content');
    
    const resThumb = document.getElementById('res-thumbnail');
    const resDuration = document.getElementById('res-duration');
    const resTitle = document.getElementById('res-title');
    const resPlatform = document.getElementById('res-platform');
    
    // Clipboard Paste
    pasteBtn.addEventListener('click', async () => {
        try {
            const text = await navigator.clipboard.readText();
            urlInput.value = text;
        } catch (err) {
            showError("Clipboard permission denied or failed to read.");
        }
    });

    // Form Submission
    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const url = urlInput.value.trim();
        if (!url) return;

        resetUI();
        showLoading();

        try {
            // Wait slightly for better UX so skeleton is visible
            const response = await fetch('api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'analyze', url: url })
            });

            const data = await response.json();

            if (!response.ok || !data.success) {
                throw new Error(data.error?.message || "An unknown error occurred.");
            }

            renderResults(data.data);
            
        } catch (error) {
            hideLoading();
            showError(error.message);
        }
    });

    function resetUI() {
        errorMsg.classList.add('hidden');
        resultsSection.classList.add('hidden');
        resultContent.classList.add('hidden');
        document.querySelector('.formats-container').innerHTML = '';
    }

    function showLoading() {
        analyzeBtn.disabled = true;
        analyzeBtn.innerHTML = '<span>Analyzing...</span>';
        resultsSection.classList.remove('hidden');
        loadingSkeleton.classList.remove('hidden');
    }

    function hideLoading() {
        analyzeBtn.disabled = false;
        analyzeBtn.innerHTML = '<span>Analyze</span>';
        loadingSkeleton.classList.add('hidden');
    }

    function showError(msg) {
        errorMsg.textContent = msg;
        errorMsg.classList.remove('hidden');
    }

    function renderResults(data) {
        hideLoading();
        resultContent.classList.remove('hidden');

        resThumb.src = data.thumbnail || 'https://via.placeholder.com/640x360?text=No+Thumbnail';
        resTitle.textContent = data.title;
        resPlatform.textContent = data.platform.toUpperCase();
        resDuration.textContent = formatDuration(data.duration);

        if (!data.formats || data.formats.length === 0) {
            showError("No downloadable formats found for this URL.");
            resultContent.classList.add('hidden');
            return;
        }

        const videosWithAudio = [];
        const videosNoAudio = [];
        const audios = [];

        data.formats.forEach(format => {
            if (format.vcodec === 'none') {
                audios.push(format);
            } else if (format.acodec === 'none') {
                videosNoAudio.push(format);
            } else {
                videosWithAudio.push(format);
            }
        });

        // Deduplicate
        const uniqueVideosWithAudio = [];
        const seenResWith = new Set();
        videosWithAudio.reverse().forEach(v => {
            if (!seenResWith.has(v.resolution)) {
                seenResWith.add(v.resolution);
                uniqueVideosWithAudio.push(v);
            }
        });

        const uniqueVideosNoAudio = [];
        const seenResNo = new Set();
        videosNoAudio.reverse().forEach(v => {
            if (!seenResNo.has(v.resolution)) {
                seenResNo.add(v.resolution);
                uniqueVideosNoAudio.push(v);
            }
        });

        // Clear and rebuild sections
        const container = document.querySelector('.formats-container');
        container.innerHTML = '';

        if (uniqueVideosWithAudio.length > 0) {
            const section = document.createElement('div');
            section.className = 'format-group';
            section.innerHTML = `<h3><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="2" width="20" height="20" rx="2.18" ry="2.18"></rect><line x1="7" y1="2" x2="7" y2="22"></line><line x1="17" y1="2" x2="17" y2="22"></line><line x1="2" y1="12" x2="22" y2="12"></line><line x1="2" y1="7" x2="7" y2="7"></line><line x1="2" y1="17" x2="7" y2="17"></line><line x1="17" y1="17" x2="22" y2="17"></line><line x1="17" y1="7" x2="22" y2="7"></line></svg> Video (With Audio)</h3><div class="formats-list"></div>`;
            const list = section.querySelector('.formats-list');
            uniqueVideosWithAudio.reverse().forEach(v => list.appendChild(createFormatCard(v, false)));
            container.appendChild(section);
        }

        if (audios.length > 0) {
            const section = document.createElement('div');
            section.className = 'format-group';
            section.innerHTML = `<h3><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 18V5l12-2v13"></path><circle cx="6" cy="18" r="3"></circle><circle cx="18" cy="16" r="3"></circle></svg> Audio Only</h3><div class="formats-list"></div>`;
            const list = section.querySelector('.formats-list');
            audios.slice(0, 4).forEach(a => list.appendChild(createFormatCard(a, true)));
            container.appendChild(section);
        }

        if (uniqueVideosNoAudio.length > 0) {
            const section = document.createElement('div');
            section.className = 'format-group';
            section.innerHTML = `<h3><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"></polygon><line x1="23" y1="9" x2="17" y2="15"></line><line x1="17" y1="9" x2="23" y2="15"></line></svg> Video (No Audio / Silent)</h3><div class="formats-list"></div>`;
            const list = section.querySelector('.formats-list');
            uniqueVideosNoAudio.reverse().forEach(v => list.appendChild(createFormatCard(v, false)));
            container.appendChild(section);
        }
    }

    function createFormatCard(format, isAudio = false) {
        const card = document.createElement('div');
        card.className = 'download-item';
        
        const sizeStr = format.filesize ? formatBytes(format.filesize) : 'Unknown size';
        
        // Format resolution to look like 1080p
        let label = format.resolution;
        if (!isAudio && format.height) {
            label = format.height + 'p';
        } else if (isAudio) {
            label = format.ext.toUpperCase();
        }

        let codec = isAudio ? (format.acodec !== 'none' ? format.acodec : '') : format.ext.toUpperCase();
        if (!isAudio && format.acodec === 'none') {
            codec += ' <span style="color: var(--error); font-size: 0.8rem; margin-left: 5px;">🔇 No Audio</span>';
        }

        card.innerHTML = `
            <div class="format-meta">
                <span class="format-res">${label}</span>
                <span class="format-size">${sizeStr}</span>
            </div>
            <div class="format-meta" style="margin-bottom: 5px;">
                <span>${codec}</span>
            </div>
            <a href="${format.url}" class="btn-download" target="_blank" rel="noopener noreferrer" download>Download</a>
        `;
        
        // Handling direct download behavior for modern browsers using fetch/blob if cross-origin allows, 
        // else fallback to normal link with target="_blank" (current default).
        return card;
    }

    function formatDuration(seconds) {
        if (!seconds) return '0:00';
        const h = Math.floor(seconds / 3600);
        const m = Math.floor((seconds % 3600) / 60);
        const s = seconds % 60;
        if (h > 0) {
            return `${h}:${m.toString().padStart(2, '0')}:${s.toString().padStart(2, '0')}`;
        }
        return `${m}:${s.toString().padStart(2, '0')}`;
    }

    function formatBytes(bytes, decimals = 2) {
        if (bytes === 0) return '0 Bytes';
        const k = 1024;
        const dm = decimals < 0 ? 0 : decimals;
        const sizes = ['Bytes', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(dm)) + ' ' + sizes[i];
    }
});
