/**
 * Tarjetas de reproducción en vivo (En directo + modal del dashboard).
 */
(function (window) {
    'use strict';

    const playLabels = { direct_play: 'Direct Play', direct_stream: 'Direct Stream', transcode: 'Transcode' };

    function escapeHtml(value) {
        return String(value ?? '').replace(/[&<>"']/g, (ch) => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
        }[ch]));
    }

    function toBase64Url(str) {
        const bytes = new TextEncoder().encode(String(str));
        let bin = '';
        bytes.forEach((b) => { bin += String.fromCharCode(b); });
        return btoa(bin).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    }

    function sessionThumbUrl(s) {
        const uuid = String(s.server_uuid || '');
        let url = String(s.thumb_url || '');

        if (url.includes('/activity/thumb/') && url.includes('?p=')) return url;
        if (url.includes('/activity/thumb/') && url.includes('?item=')) return url;

        if (uuid && s.art_path) {
            return '/activity/thumb/' + uuid + '?p=' + toBase64Url(s.art_path);
        }
        if (uuid && s.item_id) {
            return '/activity/thumb/' + uuid + '?item=' + encodeURIComponent(String(s.item_id));
        }

        const pathIdx = url.indexOf('?path=');
        if (pathIdx !== -1 && url.includes('/activity/thumb/')) {
            const uuidPart = url.slice(0, pathIdx).split('/activity/thumb/')[1] || '';
            const pathPart = url.slice(pathIdx + 6).split('&')[0];
            if (uuidPart && pathPart) {
                try {
                    return '/activity/thumb/' + uuidPart + '?p=' + toBase64Url(decodeURIComponent(pathPart));
                } catch (e) { /* ignore */ }
            }
        }

        return url.includes('/activity/thumb/') ? url : '';
    }

    const thumbFallbackSrc = 'data:image/svg+xml,' + encodeURIComponent(
        '<svg xmlns="http://www.w3.org/2000/svg" width="150" height="225" viewBox="0 0 150 225">'
        + '<rect width="150" height="225" fill="#1a1a1a"/>'
        + '<text x="75" y="118" fill="#666" text-anchor="middle" font-family="sans-serif" font-size="12">N/A</text>'
        + '</svg>'
    );

    function onSessionThumbError(img) {
        img.onerror = null;
        const poster = img.previousElementSibling;
        if (poster && poster.classList.contains('session-poster')) {
            poster.style.backgroundImage = 'url(' + thumbFallbackSrc + ')';
        }
    }

    function posterSmsBtnHtml(s) {
        if (!s.can_kill || !s.session_id) return '';
        return `<button type="button" class="session-poster-sms" title="Enviar mensaje / detener reproducción" aria-label="Enviar mensaje o detener reproducción" data-server-id="${Number(s.server_id || 0)}" data-session-id="${escapeHtml(String(s.session_id))}"><i class="bi bi-x-lg" aria-hidden="true"></i></button>`;
    }

    function thumbBlockHtml(s) {
        const url = sessionThumbUrl(s);
        if (!url) {
            return `<div class="session-poster session-poster--fallback"><i class="bi bi-film" aria-hidden="true"></i></div>`;
        }
        return `<div class="session-poster" style="background-image:url('${escapeHtml(url)}')" role="img" aria-label=""></div>`
            + `<img class="session-poster-img-probe" src="${escapeHtml(url)}" alt="" decoding="async" onerror="onSessionThumbError(this)">`;
    }

    function infoItemHtml(label, value, warn) {
        const v = String(value ?? '').trim() || '—';
        return `<li class="session-info-item"><span class="session-info-key">${escapeHtml(label)}</span><span class="session-info-val${warn ? ' session-info-val--warn' : ''}">${escapeHtml(v)}</span></li>`;
    }

    function streamInfoPanelHtml(s) {
        const info = s.stream_info || {};
        const method = s.play_method || '';
        const isTranscode = method === 'transcode';
        const methodLabel = playLabels[method] || method || 'Direct Play';
        const streamLine = String(info.stream || '').trim() || methodLabel;
        const product = String(s.product || s.platform || '—');
        const player = String(s.player || '—');
        const quality = String(info.quality || '—');
        const container = String(info.container || '—');
        const video = String(info.video || s.video_label || s.video_decision || '—');
        const audio = String(info.audio || s.audio_label || s.audio_decision || '—');
        const subtitle = String(info.subtitle || 'None');
        const location = String(s.location || '').trim();
        const ip = String(s.client_ip || '').trim();
        let locationLine = location || String(s.server_type || '').toUpperCase();
        if (ip) locationLine = (locationLine ? locationLine + ': ' : '') + ip;
        const bandwidth = String(s.bandwidth || '').trim() || String(s.server_name || '—');
        const progress = Math.max(0, Math.min(100, Number(s.progress || 0)));
        const state = String(s.state || '').toLowerCase();
        const cls = 'session-info-panel session-stream-info' + (isTranscode ? ' session-stream-info--transcode expanded' : '');
        const aria = isTranscode ? 'true' : 'false';
        const title = isTranscode ? 'Detalle de Transcode' : 'Clic para ampliar detalle';

        return `<div class="${cls}" role="button" tabindex="0" aria-expanded="${aria}" title="${title}">
        <div class="session-info-scroller">
            <ul class="session-info-list">
                ${infoItemHtml('Product', product)}
                ${infoItemHtml('Player', player)}
                ${infoItemHtml('Quality', quality)}
            </ul>
            <ul class="session-info-list">
                ${infoItemHtml('Stream', streamLine, isTranscode)}
                ${infoItemHtml('Container', container)}
                ${infoItemHtml('Video', video, isTranscode && /transcode/i.test(video))}
                ${infoItemHtml('Audio', audio)}
                ${infoItemHtml('Subtitle', subtitle)}
            </ul>
            <ul class="session-info-list">
                ${infoItemHtml('Dónde', (String(s.household || '') === 'home' ? 'Casa' : 'Fuera') + (locationLine ? ' · ' + locationLine : ''))}
                ${infoItemHtml('Bandwidth', bandwidth)}
            </ul>
        </div>
        <div class="session-info-time">
            ${state ? `<span class="session-state">${escapeHtml(state)}</span>` : ''}
            <span class="session-pct">${progress}%</span>
        </div>
    </div>`;
    }

    function overLimitBadgeHtml(s) {
        if (!s.over_limit && !s.would_cut) return '';
        const away = String(s.cut_reason || '') === 'away';
        const label = away ? 'Otra casa' : 'De más';
        const title = away ? 'Otra casa / fuera' : 'Demasiadas teles en casa';
        return `<span class="badge bg-danger session-limit-badge" title="${escapeHtml(title)}">${label}</span>`;
    }

    function householdBadgeHtml(s) {
        const home = String(s.household || '') === 'home';
        const label = home ? 'Casa' : 'Fuera';
        const cls = home ? 'bg-success' : 'bg-warning text-dark';
        const src = String(s.household_source || '');
        let title = home ? 'Casa' : 'Fuera';
        if (src === 'device_tv') title = 'Tele / Fire Stick';
        else if (src === 'device_mobile') title = 'Móvil / tablet';
        else if (src === 'lan') title = 'Misma red que el servidor';
        else if (src === 'home_ip') title = 'IP marcada como hogar';
        return `<span class="badge session-household-badge ${cls}" title="${escapeHtml(title)}">${label}</span>`;
    }

    function stateIconClass(state) {
        switch (String(state || '').toLowerCase()) {
            case 'paused': return 'bi-pause-fill';
            case 'buffering': return 'bi-arrow-repeat';
            case 'error': return 'bi-exclamation-triangle-fill';
            default: return 'bi-play-fill';
        }
    }

    function mediaIconClass(mediaType) {
        const t = String(mediaType || '').toLowerCase();
        if (['episode', 'show', 'series'].includes(t)) return 'bi-tv';
        if (['track', 'audio', 'music'].includes(t)) return 'bi-music-note-beamed';
        if (t === 'photo') return 'bi-image';
        return 'bi-film';
    }

    function sessionUserHtml(s) {
        const name = escapeHtml(s.user || '-');
        const uuid = String(s.media_user_uuid || '').trim();
        const badge = householdBadgeHtml(s);
        if (uuid) {
            return `${badge} <a href="/media-users/${encodeURIComponent(uuid)}" class="session-user-link text-decoration-none">${name}</a>`;
        }
        return `${badge} <span class="session-user-name">${name}</span>`;
    }

    function sessionCardHtml(s) {
        const method = s.play_method || '';
        const progress = Math.max(0, Math.min(100, Number(s.progress || 0)));
        const isTranscode = method === 'transcode';
        const thumb = thumbBlockHtml(s);
        const streamPanel = streamInfoPanelHtml(s);
        const overLimit = overLimitBadgeHtml(s);
        const title = escapeHtml(s.title || 'Sin título');
        const sid = escapeHtml(String(s.session_id || ''));
        const state = String(s.state || '');
        const sms = posterSmsBtnHtml(s);
        const platformLabel = String(s.platform || s.product || 'Plex');
        const platformInitial = escapeHtml(platformLabel.charAt(0).toUpperCase() || 'P');
        const platformShort = escapeHtml(platformLabel.length > 10 ? platformLabel.slice(0, 9) + '…' : platformLabel);
        const subtitle = String(s.subtitle || '').trim() || String(s.year || '').trim() || String(s.server_name || '');
        const bgUrl = sessionThumbUrl(s);
        const bgStyle = bgUrl ? `background-image:url('${escapeHtml(bgUrl)}')` : '';
        const bufferPct = Math.min(100, progress + (isTranscode ? 8 : 0));

        return `<div class="col-12 col-lg-6 col-xxl-4 session-col">
        <div class="session-card session-row${s.over_limit ? ' session-row--over-limit' : ''}${isTranscode ? ' session-row--transcode' : ''}" data-session-id="${sid}" data-server-id="${Number(s.server_id || 0)}" data-play-method="${escapeHtml(method)}">
            <div class="session-activity-container">
                <div class="session-activity-background" style="${bgStyle}">
                    <div class="session-poster-wrap">${thumb}</div>
                    <div class="session-platform-slot${s.can_kill ? '' : ' session-platform-slot--no-terminate'}">
                        <div class="session-platform-badge" title="${escapeHtml(platformLabel)}">
                            <span class="session-platform-initial">${platformInitial}</span>
                            <span class="session-platform-name">${platformShort}</span>
                        </div>
                        ${sms}
                    </div>
                    ${streamPanel}
                </div>
                <div class="session-activity-progress" title="${progress}%">
                    <div class="session-activity-progress-track">
                        ${isTranscode ? `<div class="session-buffer-bar" style="width:${bufferPct}%">${bufferPct}%</div>` : ''}
                        <div class="session-progress-bar${isTranscode ? ' session-progress-bar--transcode' : ''}" style="width:${progress}%">${progress}%</div>
                    </div>
                </div>
            </div>
            <div class="session-metadata">
                <div class="session-meta-title-row">
                    <span class="session-state-icon" title="${escapeHtml(state || 'playing')}"><i class="bi ${stateIconClass(state)}" aria-hidden="true"></i></span>
                    <h6 class="session-title text-truncate mb-0" role="button" tabindex="0" aria-expanded="false" title="${title} — clic para ver completo">${title}</h6>
                    ${overLimit}
                </div>
                <div class="session-meta-sub-row">
                    <span class="session-media-icon" title="${escapeHtml(String(s.media_type || 'media'))}"><i class="bi ${mediaIconClass(s.media_type)}" aria-hidden="true"></i></span>
                    <span class="session-subtitle text-truncate">${escapeHtml(subtitle)}</span>
                    <span class="session-meta-user">${sessionUserHtml(s)}</span>
                </div>
            </div>
        </div>
    </div>`;
    }

    function emptyHtml(message) {
        return `<div class="card border-0 shadow-sm"><div class="card-body text-center text-muted py-5"><i class="bi bi-tv fs-1 d-block mb-2"></i>${escapeHtml(message)}</div></div>`;
    }

    function sessionsGridHtml(sessions, emptyMessage) {
        const rows = Array.isArray(sessions) ? sessions : [];
        if (!rows.length) {
            return `<div class="row g-2 g-xl-3"><div class="col-12">${emptyHtml(emptyMessage || 'No hay reproducciones activas')}</div></div>`;
        }
        return `<div class="row g-2 g-xl-3">${rows.map(sessionCardHtml).join('')}</div>`;
    }

    window.onSessionThumbError = onSessionThumbError;
    window.MPSessionCards = {
        escapeHtml,
        sessionThumbUrl,
        sessionCardHtml,
        emptyHtml,
        sessionsGridHtml,
        playLabels,
    };
})(window);
