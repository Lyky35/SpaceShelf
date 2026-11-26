/**
 * Overview page statistics timeline charts (score history + ships
 * built/destroyed). Dependency-free SVG renderer: the in-game bundle is
 * plain concatenated scripts, so no chart library is loaded.
 *
 * Data is provided by the overview blade as the globals STATS_SCORE_DATA
 * and STATS_SHIP_DATA. The charts render inside the compact stats column
 * next to the queue boxes, so text sizes are scaled for a ~200px-wide
 * box (viewBox units shrink with CSS scaling).
 */
(function () {
    'use strict';

    var PALETTE = [
        '#e6262b', '#f69b1f', '#f4d009', '#2fb42f', '#19b0e8',
        '#4b3fbf', '#c864c8', '#8b5a2b', '#11a67f', '#d95b92',
        '#6f7fd0', '#a0a33a', '#cc7832', '#5a768f', '#9b2d5e'
    ];

    var DEFAULT_OPTIONS = {
        width: 340,
        height: 250,
        fontSize: 15,
        gridLines: 3,
        maxLabels: 3
    };

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
        if (value >= 1000000000) {
            return (value / 1000000000).toFixed(1).replace(/\.0$/, '') + 'B';
        }
        if (value >= 1000000) {
            return (value / 1000000).toFixed(1).replace(/\.0$/, '') + 'M';
        }
        if (value >= 10000) {
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
        series.forEach(function (s) {
            s.values.forEach(function (v) {
                if (v !== null && v > max) {
                    max = v;
                }
            });
        });
        max = niceCeil(max);

        var count = dates.length;
        var xStep = count > 1 ? plotWidth / (count - 1) : 0;

        function x(index) {
            return count > 1 ? padLeft + index * xStep : padLeft + plotWidth / 2;
        }

        function y(value) {
            return padTop + plotHeight - (value / max) * plotHeight;
        }

        var parts = [];
        parts.push('<svg viewBox="0 0 ' + width + ' ' + height + '" preserveAspectRatio="xMidYMid meet" role="img">');

        // Horizontal grid + y-axis labels.
        for (var line = 0; line <= opts.gridLines; line++) {
            var value = (max / opts.gridLines) * line;
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

    function init() {
        if (typeof STATS_SCORE_DATA === 'undefined' || typeof STATS_SHIP_DATA === 'undefined') {
            return;
        }

        var scoreContainer = document.getElementById('statsScoreChart');
        if (scoreContainer && STATS_SCORE_DATA.dates.length > 0) {
            var scoreSeries = STATS_SCORE_DATA.series.map(function (s, index) {
                return {name: s.name, bot: s.bot, color: PALETTE[index % PALETTE.length], values: s.values};
            });
            renderLineChart(scoreContainer, STATS_SCORE_DATA.dates, scoreSeries);
            renderLegend(document.getElementById('statsScoreLegend'), scoreSeries);
        }

        var shipContainer = document.getElementById('statsShipChart');
        if (shipContainer && STATS_SHIP_DATA.dates.length > 0) {
            var shipSeries = [
                {name: STATS_SHIP_DATA.label_built || 'Ships built', color: '#2fb42f', values: STATS_SHIP_DATA.built},
                {name: STATS_SHIP_DATA.label_destroyed || 'Ships destroyed', color: '#e6262b', values: STATS_SHIP_DATA.destroyed}
            ];
            renderLineChart(shipContainer, STATS_SHIP_DATA.dates, shipSeries);
            renderLegend(document.getElementById('statsShipLegend'), shipSeries);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
