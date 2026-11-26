/**
 * Highscore page statistics timeline graphs. The main graph follows the
 * active highscore tab (points / economy / research / military incl. its
 * built-destroyed-lost-honor sub-categories / alliances), plus a fixed
 * server-wide ships built-vs-destroyed graph. Dependency-free SVG
 * renderer: the in-game bundle is plain concatenated scripts, so no chart
 * library is loaded and tab state is read straight from the DOM.
 *
 * Data is provided by the highscore blade as the globals STATS_TIMELINES
 * (keyed per tab: player_* / alliance_* / ships), STATS_SUBTITLES and
 * STATS_SHIP_LABELS. The charts render full width inside the 670px
 * content column stats box (contentbox skin), so the viewBox matches
 * that width.
 */
(function () {
    'use strict';

    var PALETTE = [
        '#e6262b', '#f69b1f', '#f4d009', '#2fb42f', '#19b0e8',
        '#4b3fbf', '#c864c8', '#8b5a2b', '#11a67f', '#d95b92',
        '#6f7fd0', '#a0a33a', '#cc7832', '#5a768f', '#9b2d5e'
    ];

    var DEFAULT_OPTIONS = {
        width: 640,
        height: 260,
        fontSize: 12,
        gridLines: 4,
        maxLabels: 6
    };

    // Highscore tab rel attributes (see the legacy initHighscore() click
    // handler) mapped to STATS_TIMELINES key suffixes.
    var TYPE_KEY_BY_REL = {'0': 'general', '1': 'economy', '2': 'research', '3': 'military'};
    var SUBNAV_KEY_BY_REL = {'5': 'military_built', '6': 'military_destroyed', '4': 'military_lost', '7': 'honor'};

    function esc(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function niceCeil(value) {
        if (value <= 0) {
            return 10;
        }
        var magnitude = Math.pow(10, Math.floor(Math.log(value) / Math.LN10));
        var scaled = value / magnitude;
        var nice = scaled <= 1 ? 1 : scaled <= 2 ? 2 : scaled <= 5 ? 5 : 10;
        return nice * magnitude;
    }

    function shortNumber(value) {
        var abs = Math.abs(value);
        if (abs >= 1000000000) {
            return (value / 1000000000).toFixed(1).replace(/\.0$/, '') + 'B';
        }
        if (abs >= 1000000) {
            return (value / 1000000).toFixed(1).replace(/\.0$/, '') + 'M';
        }
        if (abs >= 10000) {
            return Math.round(value / 1000) + 'k';
        }
        return String(value);
    }

    /**
     * Renders a multi-series line chart as inline SVG.
     *
     * series: [{name, color, values: [number|null]}]
     */
    function renderLineChart(container, dates, series, options) {
        var opts = {};
        for (var key in DEFAULT_OPTIONS) {
            opts[key] = (options && options[key] !== undefined) ? options[key] : DEFAULT_OPTIONS[key];
        }

        var width = opts.width;
        var height = opts.height;
        var fontSize = opts.fontSize;
        var dotRadius = Math.max(2, fontSize / 6);
        var lineWidth = Math.max(1.5, fontSize / 7);
        var padLeft = Math.round(fontSize * 3.2);
        var padRight = Math.round(fontSize * 0.6);
        var padTop = Math.round(fontSize * 0.8);
        var padBottom = Math.round(fontSize * 2);
        var plotWidth = width - padLeft - padRight;
        var plotHeight = height - padTop - padBottom;

        var max = 0;
        var min = 0;
        series.forEach(function (s) {
            s.values.forEach(function (v) {
                if (v === null) {
                    return;
                }
                if (v > max) {
                    max = v;
                }
                if (v < min) {
                    min = v;
                }
            });
        });
        // Honor points can go negative, so the scale gets a floor too.
        max = niceCeil(max);
        min = min < 0 ? -niceCeil(-min) : 0;
        var range = max - min;

        var count = dates.length;
        var xStep = count > 1 ? plotWidth / (count - 1) : 0;

        function x(index) {
            return count > 1 ? padLeft + index * xStep : padLeft + plotWidth / 2;
        }

        function y(value) {
            return padTop + plotHeight - ((value - min) / range) * plotHeight;
        }

        var parts = [];
        parts.push('<svg viewBox="0 0 ' + width + ' ' + height + '" preserveAspectRatio="xMidYMid meet" role="img">');

        // Horizontal grid + y-axis labels.
        for (var line = 0; line <= opts.gridLines; line++) {
            var value = min + (range / opts.gridLines) * line;
            var gy = y(value).toFixed(1);
            parts.push('<line x1="' + padLeft + '" y1="' + gy + '" x2="' + (width - padRight) + '" y2="' + gy + '" stroke="#3a3f47" stroke-width="1"/>');
            parts.push('<text x="' + (padLeft - 5) + '" y="' + (parseFloat(gy) + fontSize * 0.35) + '" text-anchor="end" font-size="' + fontSize + '" fill="#9aa0a8">' + esc(shortNumber(value)) + '</text>');
        }

        // X-axis labels (up to maxLabels, evenly spaced).
        var labelEvery = Math.max(1, Math.ceil(count / opts.maxLabels));
        for (var i = 0; i < count; i += labelEvery) {
            parts.push('<text x="' + x(i).toFixed(1) + '" y="' + (height - fontSize * 0.4) + '" text-anchor="middle" font-size="' + (fontSize - 2) + '" fill="#9aa0a8">' + esc(dates[i]) + '</text>');
        }

        // Series polylines; null values break the line.
        series.forEach(function (s) {
            var segment = [];
            function flush() {
                if (segment.length === 1) {
                    parts.push('<circle cx="' + segment[0][0] + '" cy="' + segment[0][1] + '" r="' + dotRadius + '" fill="' + s.color + '"/>');
                } else if (segment.length > 1) {
                    parts.push('<polyline fill="none" stroke="' + s.color + '" stroke-width="' + lineWidth + '" points="' + segment.map(function (p) { return p.join(','); }).join(' ') + '" stroke-linejoin="round"/>');
                }
                segment = [];
            }

            s.values.forEach(function (v, index) {
                if (v === null) {
                    flush();
                    return;
                }
                var px = x(index).toFixed(1);
                var py = y(v).toFixed(1);
                segment.push([px, py]);

                // Dots (with tooltip) when the point count stays reasonable.
                if (count <= 250) {
                    parts.push('<circle cx="' + px + '" cy="' + py + '" r="' + dotRadius + '" fill="' + s.color + '"><title>' + esc(s.name + ' — ' + dates[index] + ': ' + v) + '</title></circle>');
                }
            });
            flush();
        });

        parts.push('</svg>');
        container.innerHTML = parts.join('');
    }

    function renderLegend(el, items) {
        if (!el) {
            return;
        }
        el.innerHTML = '';
        items.forEach(function (item) {
            var entry = document.createElement('span');
            entry.className = 'statsLegendItem' + (item.bot ? ' statsLegendBot' : '');

            var swatch = document.createElement('i');
            swatch.style.backgroundColor = item.color;
            entry.appendChild(swatch);

            var label = document.createElement('span');
            label.textContent = item.name;
            entry.appendChild(label);

            el.appendChild(entry);
        });
    }

    /**
     * STATS_TIMELINES key for the currently active highscore tab, read
     * from the active button classes the legacy highscore JS maintains.
     */
    function currentTimelineKey() {
        var categoryBtn = document.querySelector('#categoryButtons a.active');
        var prefix = (categoryBtn && categoryBtn.getAttribute('rel') === '2') ? 'alliance_' : 'player_';

        var typeBtn = document.querySelector('#typeButtons a.active');
        var metric = 'general';
        if (typeBtn) {
            metric = TYPE_KEY_BY_REL[typeBtn.getAttribute('rel')] || 'general';
        }

        // The military tab has its own sub-tabs for the military
        // sub-counters; alliances have no such sub-metrics.
        if (prefix === 'player_' && metric === 'military') {
            var subnavBtn = document.querySelector('#subnav_fleet a.active');
            if (subnavBtn) {
                metric = SUBNAV_KEY_BY_REL[subnavBtn.getAttribute('rel')] || metric;
            }
        }

        return prefix + metric;
    }

    function renderScoreChart() {
        var container = document.getElementById('statsScoreChart');
        if (!container || typeof STATS_TIMELINES === 'undefined') {
            return;
        }

        var key = currentTimelineKey();
        var payload = STATS_TIMELINES[key];
        var subtitle = document.getElementById('statsChartSubtitle');
        if (subtitle && typeof STATS_SUBTITLES !== 'undefined' && STATS_SUBTITLES[key]) {
            subtitle.textContent = STATS_SUBTITLES[key];
        }

        if (!payload || payload.dates.length === 0) {
            container.innerHTML = typeof STATS_EMPTY_TEXT !== 'undefined'
                ? '<p class="statsEmpty">' + esc(STATS_EMPTY_TEXT) + '</p>'
                : '';
            renderLegend(document.getElementById('statsScoreLegend'), []);
            return;
        }

        var scoreSeries = payload.series.map(function (s, index) {
            return {name: s.name, bot: s.bot, color: PALETTE[index % PALETTE.length], values: s.values};
        });
        renderLineChart(container, payload.dates, scoreSeries);
        renderLegend(document.getElementById('statsScoreLegend'), scoreSeries);
    }

    function renderShipChart() {
        var container = document.getElementById('statsShipChart');
        if (!container || typeof STATS_TIMELINES === 'undefined') {
            return;
        }

        var ships = STATS_TIMELINES.ships;
        if (!ships || ships.dates.length === 0) {
            return;
        }

        var labels = typeof STATS_SHIP_LABELS !== 'undefined' ? STATS_SHIP_LABELS : {};
        var shipSeries = [
            {name: labels.built || 'Ships built', color: '#2fb42f', values: ships.built},
            {name: labels.destroyed || 'Ships destroyed', color: '#e6262b', values: ships.destroyed}
        ];
        renderLineChart(container, ships.dates, shipSeries);
        renderLegend(document.getElementById('statsShipLegend'), shipSeries);
    }

    function init() {
        if (typeof STATS_TIMELINES === 'undefined') {
            return;
        }

        renderScoreChart();
        renderShipChart();

        // Follow the legacy tab buttons: they toggle the .active class
        // synchronously on click, so a deferred render sees the new state.
        document.addEventListener('click', function (event) {
            var btn = event.target.closest
                ? event.target.closest('#categoryButtons a, #typeButtons a, #subnav_fleet a')
                : null;
            if (btn) {
                window.setTimeout(renderScoreChart, 0);
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
