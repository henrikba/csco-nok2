(function () {
    'use strict';

    const LWC = window.LightweightCharts;
    const TZ = 'Europe/Oslo';
    const RANGES = ['1d', '1w', '1m', '6m', '1y', '3y', '5y', '10y'];
    const INTRADAY = new Set(['1d', '1w', '1m']);
    const RANGE_LABELS = { '1d': '1D', '1w': '1U', '1m': '1M', '6m': '6M', '1y': '1Å', '3y': '3Å', '5y': '5Å', '10y': '10Å' };
    const PERIOD_LABELS = {
        '1d': 'i dag',
        '1w': 'siste uke',
        '1m': 'siste måned',
        '6m': 'siste 6 måneder',
        '1y': 'siste år',
        '3y': 'siste 3 år',
        '5y': 'siste 5 år',
        '10y': 'siste 10 år',
    };
    const COLORS = { up: '#00873c', down: '#eb0f29', prevClose: '#8a9199' };
    const REFRESH_MS = 60 * 1000;
    const CLIENT_CACHE_MS = 60 * 1000;

    const fmtNok = new Intl.NumberFormat('nb-NO', { style: 'currency', currency: 'NOK' });
    const fmt2 = new Intl.NumberFormat('nb-NO', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const fmt4 = new Intl.NumberFormat('nb-NO', { minimumFractionDigits: 4, maximumFractionDigits: 4 });
    const fmtSigned = new Intl.NumberFormat('nb-NO', { minimumFractionDigits: 2, maximumFractionDigits: 2, signDisplay: 'exceptZero' });
    const fmtPct = new Intl.NumberFormat('nb-NO', { style: 'percent', minimumFractionDigits: 2, maximumFractionDigits: 2, signDisplay: 'exceptZero' });
    const fmtDateTime = new Intl.DateTimeFormat('nb-NO', { timeZone: TZ, weekday: 'short', day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
    const fmtDate = new Intl.DateTimeFormat('nb-NO', { timeZone: TZ, weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' });
    const fmtShortDate = new Intl.DateTimeFormat('nb-NO', { timeZone: 'UTC', day: 'numeric', month: 'short' });

    // The chart works in UTC, so times are shifted to Oslo wall-clock time before plotting;
    // tick labels are then formatted with timeZone UTC.
    const tickFormats = {
        [LWC.TickMarkType.Year]: new Intl.DateTimeFormat('nb-NO', { timeZone: 'UTC', year: 'numeric' }),
        [LWC.TickMarkType.Month]: new Intl.DateTimeFormat('nb-NO', { timeZone: 'UTC', month: 'short' }),
        [LWC.TickMarkType.DayOfMonth]: new Intl.DateTimeFormat('nb-NO', { timeZone: 'UTC', day: 'numeric', month: 'short' }),
        [LWC.TickMarkType.Time]: new Intl.DateTimeFormat('nb-NO', { timeZone: 'UTC', hour: '2-digit', minute: '2-digit' }),
        [LWC.TickMarkType.TimeWithSeconds]: new Intl.DateTimeFormat('nb-NO', { timeZone: 'UTC', hour: '2-digit', minute: '2-digit', second: '2-digit' }),
    };
    const osloParts = new Intl.DateTimeFormat('en-US', {
        timeZone: TZ, hourCycle: 'h23', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit',
    });

    function toOsloChartTime(t) {
        const p = {};
        for (const part of osloParts.formatToParts(new Date(t * 1000))) {
            p[part.type] = part.value;
        }
        return Date.UTC(+p.year, +p.month - 1, +p.day, +p.hour, +p.minute, +p.second) / 1000;
    }

    function rgba(hex, alpha) {
        const n = parseInt(hex.slice(1), 16);
        return `rgba(${(n >> 16) & 255}, ${(n >> 8) & 255}, ${n & 255}, ${alpha})`;
    }

    const $ = (id) => document.getElementById(id);
    const el = {
        price: $('price'),
        change: $('change'),
        changePeriod: $('change-period'),
        marketStatus: $('market-status'),
        asOf: $('as-of'),
        stale: $('stale-notice'),
        ranges: $('ranges'),
        chartWrap: document.querySelector('.chart-wrap'),
        chart: $('chart'),
        message: $('chart-message'),
        tooltip: $('tooltip'),
        ttNok: $('tt-nok'),
        ttCsco: $('tt-csco'),
        ttFx: $('tt-fx'),
        ttTime: $('tt-time'),
        statBaseLabel: $('stat-base-label'),
        statBase: $('stat-base'),
        statRangeLabel: $('stat-range-label'),
        statRange: $('stat-range'),
        stat52w: $('stat-52w'),
        statCsco: $('stat-csco'),
        statFx: $('stat-fx'),
    };

    const state = {
        range: null,
        data: null,
        pointsByTime: new Map(),
        prevCloseLine: null,
        prevCloseValue: null,
        requestSeq: 0,
        clientCache: new Map(),
    };

    const chart = LWC.createChart(el.chart, {
        autoSize: true,
        layout: {
            background: { type: LWC.ColorType.Solid, color: '#ffffff' },
            textColor: '#5b636a',
            fontFamily: getComputedStyle(document.documentElement).fontFamily,
            // Attribution required by the library's license is in the page footer instead.
            attributionLogo: false,
        },
        grid: { vertLines: { visible: false }, horzLines: { color: '#f0f3f5' } },
        rightPriceScale: { borderVisible: false, scaleMargins: { top: 0.12, bottom: 0.08 } },
        timeScale: {
            borderVisible: false,
            tickMarkFormatter: (time, type) => {
                if (type >= LWC.TickMarkType.Time && state.range !== '1d') {
                    return '';
                }
                return (tickFormats[type] || tickFormats[LWC.TickMarkType.DayOfMonth]).format(new Date(time * 1000));
            },
        },
        crosshair: {
            mode: LWC.CrosshairMode.Magnet,
            vertLine: { labelVisible: false, style: LWC.LineStyle.Solid, color: '#c3c8cd' },
            horzLine: { color: '#c3c8cd' },
        },
        localization: { locale: 'nb-NO', priceFormatter: (p) => fmt2.format(p) },
        handleScroll: false,
        handleScale: false,
    });

    const series = chart.addAreaSeries({
        lineWidth: 2,
        priceLineVisible: false,
        lastValueVisible: true,
        crosshairMarkerRadius: 4,
        autoscaleInfoProvider: (original) => {
            const res = original();
            if (res && state.prevCloseValue !== null) {
                res.priceRange.minValue = Math.min(res.priceRange.minValue, state.prevCloseValue);
                res.priceRange.maxValue = Math.max(res.priceRange.maxValue, state.prevCloseValue);
            }
            return res;
        },
    });

    function renderChart(range, data) {
        const s = data.summary;
        const color = s.changeNok >= 0 ? COLORS.up : COLORS.down;
        series.applyOptions({ lineColor: color, topColor: rgba(color, 0.28), bottomColor: rgba(color, 0.02) });
        chart.applyOptions({ timeScale: { timeVisible: INTRADAY.has(range), secondsVisible: false } });

        // Lightweight Charts silently misdraws unless times are strictly ascending.
        state.pointsByTime.clear();
        const seriesData = [];
        for (const p of data.points) {
            const time = toOsloChartTime(p.t);
            if (seriesData.length && time <= seriesData[seriesData.length - 1].time) {
                continue;
            }
            state.pointsByTime.set(time, p);
            seriesData.push({ time, value: p.nok });
        }

        // Empty slots up to the close, so the 1D chart spans the whole trading session like Yahoo's.
        if (s.sessionEnd && data.points.length) {
            const lastT = data.points[data.points.length - 1].t;
            for (let t = Math.floor(lastT / 60) * 60 + 60; t < s.sessionEnd; t += 60) {
                seriesData.push({ time: toOsloChartTime(t) });
            }
        }

        if (state.prevCloseLine) {
            series.removePriceLine(state.prevCloseLine);
            state.prevCloseLine = null;
        }
        state.prevCloseValue = range === '1d' && s.prevCloseNok !== null ? s.prevCloseNok : null;

        series.setData(seriesData);

        if (state.prevCloseValue !== null) {
            state.prevCloseLine = series.createPriceLine({
                price: state.prevCloseValue,
                color: COLORS.prevClose,
                lineWidth: 1,
                lineStyle: LWC.LineStyle.Dashed,
                axisLabelVisible: true,
                title: 'Forrige slutt',
            });
        }
        chart.timeScale().fitContent();
    }

    function renderHeader(range, data) {
        const s = data.summary;
        const dir = s.changeNok >= 0 ? 'up' : 'down';

        el.price.textContent = fmtNok.format(s.lastNok);
        el.change.textContent = `${fmtSigned.format(s.changeNok)} (${fmtPct.format(s.changePct / 100)})`;
        el.change.className = `change ${dir}`;
        el.changePeriod.textContent = PERIOD_LABELS[range];
        el.marketStatus.textContent = s.marketOpen ? 'Markedet er åpent' : 'Markedet er stengt';
        el.marketStatus.classList.toggle('open', s.marketOpen);
        el.asOf.textContent = `Sist oppdatert ${fmtDateTime.format(new Date(s.asOf * 1000))}`;
        el.stale.hidden = !data.stale;

        el.statBaseLabel.textContent = range === '1d' ? 'Forrige slutt' : `Startkurs ${RANGE_LABELS[range]}`;
        el.statBase.textContent = fmtNok.format(s.baseNok);
        el.statRangeLabel.textContent = `Spenn ${RANGE_LABELS[range]}`;
        el.statRange.textContent = `${fmt2.format(s.rangeLowNok)} – ${fmt2.format(s.rangeHighNok)} kr`;
        el.stat52w.textContent = `${fmt2.format(s.w52LowNok)} – ${fmt2.format(s.w52HighNok)} kr`;
        el.statCsco.textContent = `${fmt2.format(s.lastCsco)} USD`;
        el.statFx.textContent = fmt4.format(s.lastFx);

        document.title = `${fmtNok.format(s.lastNok)} · CSCO i NOK`;
    }

    function fxNote(p) {
        const notes = [];
        if (INTRADAY.has(state.range) && p.fxSource === 'daily') {
            notes.push('dagskurs');
        }
        if (p.fxDate) {
            notes.push(`fra ${fmtShortDate.format(new Date(`${p.fxDate}T00:00:00Z`))}`);
        }
        return notes.length ? ` (${notes.join(' ')})` : '';
    }

    chart.subscribeCrosshairMove((param) => {
        const p = param.time !== undefined ? state.pointsByTime.get(param.time) : undefined;
        if (!p || !param.point || param.point.x < 0 || param.point.y < 0) {
            el.tooltip.hidden = true;
            return;
        }

        el.ttNok.textContent = fmtNok.format(p.nok);
        el.ttCsco.textContent = `${fmt2.format(p.csco)} USD`;
        el.ttFx.textContent = fmt4.format(p.fx) + fxNote(p);
        el.ttTime.textContent = (INTRADAY.has(state.range) ? fmtDateTime : fmtDate).format(new Date(p.t * 1000));
        el.tooltip.hidden = false;

        const gap = 16;
        const width = el.tooltip.offsetWidth;
        const height = el.tooltip.offsetHeight;
        const chartWidth = el.chart.clientWidth;
        const chartHeight = el.chart.clientHeight;

        let left = param.point.x + gap;
        if (left + width > chartWidth - 70) {
            left = param.point.x - width - gap;
        }
        let top = param.point.y - height - gap;
        if (top < 0) {
            top = Math.min(param.point.y + gap, chartHeight - height);
        }
        el.tooltip.style.left = `${Math.max(0, left)}px`;
        el.tooltip.style.top = `${Math.max(0, top)}px`;
    });

    function showMessage(text) {
        el.message.textContent = text;
        el.message.hidden = !text;
    }

    function setActiveButton(range) {
        for (const btn of el.ranges.querySelectorAll('button')) {
            btn.classList.toggle('active', btn.dataset.range === range);
        }
    }

    function render(range, data) {
        state.data = data;
        showMessage('');
        renderChart(range, data);
        renderHeader(range, data);
    }

    async function load(range, { force = false } = {}) {
        const seq = ++state.requestSeq;
        state.range = range;
        setActiveButton(range);
        el.tooltip.hidden = true;

        const cached = state.clientCache.get(range);
        if (!force && cached && Date.now() - cached.at < CLIENT_CACHE_MS) {
            render(range, cached.data);
            return;
        }

        el.chartWrap.classList.add('loading');
        try {
            const res = await fetch(`api/data.php?range=${encodeURIComponent(range)}`, { cache: 'no-store' });
            const data = await res.json().catch(() => null);
            if (!res.ok || !data || data.error) {
                throw new Error((data && data.error) || `HTTP ${res.status}`);
            }
            state.clientCache.set(range, { at: Date.now(), data });
            if (seq === state.requestSeq) {
                render(range, data);
            }
        } catch (err) {
            if (seq === state.requestSeq) {
                showMessage(err.message || 'Klarte ikke å hente kursdata.');
            }
        } finally {
            if (seq === state.requestSeq) {
                el.chartWrap.classList.remove('loading');
            }
        }
    }

    function rangeFromHash() {
        const r = location.hash.slice(1);
        return RANGES.includes(r) ? r : '1d';
    }

    el.ranges.addEventListener('click', (e) => {
        const btn = e.target.closest('button[data-range]');
        if (btn) {
            location.hash = btn.dataset.range;
        }
    });
    window.addEventListener('hashchange', () => load(rangeFromHash()));

    setInterval(() => {
        if (state.range === '1d' && state.data && state.data.summary.marketOpen && !document.hidden) {
            load('1d', { force: true });
        }
    }, REFRESH_MS);

    load(rangeFromHash());
})();
