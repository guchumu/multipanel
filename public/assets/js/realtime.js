/**
 * MultiPanel realtime client — WebSocket → SSE → long-polling fallback.
 */
(function () {
    'use strict';

    const WS_PORT = window.MULTIPANEL_WS_PORT || 8081;
    const WS_HOST = window.MULTIPANEL_WS_HOST || location.hostname;
    const wsUrl = `ws://${WS_HOST}:${WS_PORT}?channel=dashboard`;

    window.MultiPanelRealtime = {
        onDashboard: null,
        onEvent: null,

        connect() {
            if (this._tryWebSocket()) return;
            if (this._trySSE()) return;
            this._startPolling();
        },

        _tryWebSocket() {
            if (typeof WebSocket === 'undefined') return false;
            try {
                const ws = new WebSocket(wsUrl);
                ws.onmessage = (e) => {
                    try {
                        const msg = JSON.parse(e.data);
                        this._handleEvent(msg.event, msg.data);
                    } catch (_) {}
                };
                ws.onopen = () => this._setLive('WebSocket');
                ws.onerror = () => ws.close();
                ws.onclose = () => setTimeout(() => this._trySSE() || this._startPolling(), 3000);
                this._ws = ws;
                return true;
            } catch (_) {
                return false;
            }
        },

        _trySSE() {
            if (typeof EventSource === 'undefined') return false;
            const sse = new EventSource('/stream/events');
            sse.addEventListener('dashboard', (e) => {
                try { this._handleDashboard(JSON.parse(e.data)); } catch (_) {}
            });
            sse.addEventListener('message', (e) => {
                try {
                    const d = JSON.parse(e.data);
                    this._handleEvent(e.type, d);
                } catch (_) {}
            });
            sse.onopen = () => this._setLive('SSE');
            sse.onerror = () => { sse.close(); this._startPolling(); };
            this._sse = sse;
            return true;
        },

        _startPolling() {
            if (this._pollTimer) return;
            this._setLive('Polling');
            let since = 0;
            const poll = async () => {
                try {
                    const res = await fetch(`/api/v1/events/poll?since=${since}&timeout=10`);
                    const data = await res.json();
                    (data.events || []).forEach(ev => {
                        this._handleEvent(ev.event, ev.data);
                        since = ev.at || since;
                    });
                    if (data.hub) this._handleHub(data.hub);
                } catch (_) {}
            };
            poll();
            this._pollTimer = setInterval(poll, 15000);
        },

        _handleDashboard(data) {
            document.querySelectorAll('[data-live="sessions"]').forEach((el, i) => {
                const srv = data.servers?.[i];
                if (srv) el.textContent = srv.sessions;
            });
            if (data.stats) {
                document.querySelectorAll('[data-live="users-active"]').forEach(el => {
                    el.textContent = data.stats.users_active ?? el.textContent;
                });
            }
            this._setLive('Live');
            if (typeof this.onDashboard === 'function') this.onDashboard(data);
        },

        _handleEvent(event, data) {
            if (typeof this.onEvent === 'function') this.onEvent(event, data);
        },

        _handleHub(hub) {
            const el = document.getElementById('hub-jobs');
            if (el && hub.jobs_pending !== undefined) el.textContent = hub.jobs_pending;
        },

        _setLive(mode) {
            const live = document.getElementById('live-indicator');
            if (live) {
                live.textContent = '● ' + mode + ' ' + new Date().toLocaleTimeString();
                live.classList.add('text-success');
            }
        }
    };
})();
