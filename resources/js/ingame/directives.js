/**
 * Directives (quest system) overlay interaction.
 *
 * The overlay content itself is rendered server-side (ingame/directives/overlay)
 * and opened through the vanilla overlay framework; this script drives the
 * chapter tabs, mission accordions and claim buttons on top of it using
 * event delegation, so re-rendered partials need no re-initialisation.
 */
(function () {
    'use strict';

    var overlayUrl = function (chapter) {
        var base = document.querySelector('#ipiInnerMenuContentHolder');
        var url = base ? base.getAttribute('href') : '/ajax/directives/overlay';
        return url + (chapter ? '?chapter=' + encodeURIComponent(chapter) : '');
    };

    var csrfToken = function () {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    };

    var currentChapter = function () {
        var layer = document.querySelector('#ipioverviewlayer');
        if (!layer) {
            return '';
        }
        var active = layer.querySelector('.ipiChapterItem.active a[data-directive-chapter]');
        return active ? active.getAttribute('data-directive-chapter') : '';
    };

    var updateBadge = function (unclaimed) {
        var panel = document.querySelector('#ipimenucomponent .ipiMenuHead');
        if (!panel) {
            return;
        }
        var badge = panel.querySelector('.ipiHintCollect');
        if (unclaimed > 0) {
            if (badge === null) {
                badge = document.createElement('span');
                badge.className = 'ipiHintCollect';
                panel.appendChild(badge);
            }
            badge.textContent = unclaimed;
        } else if (badge !== null) {
            badge.remove();
        }
    };

    var showLayer = function (html) {
        var container = document.querySelector('#ipioverviewlayer');
        if (container === null) {
            document.body.insertAdjacentHTML('beforeend', html);
            return;
        }
        container.outerHTML = html;
    };

    var reload = function (chapter) {
        fetch(overlayUrl(chapter), { headers: { Accept: 'text/html' } })
            .then(function (response) {
                return response.text();
            })
            .then(showLayer)
            .catch(function () {
                if (typeof window.fadeBox === 'function') {
                    window.fadeBox('Could not load the directive list', true);
                }
            });
    };

    var post = function (url, body, chapter) {
        var request = new Request(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
                Accept: 'application/json',
            },
            body: JSON.stringify(body),
        });

        fetch(request)
            .then(function (response) {
                return response.json();
            })
            .then(function (data) {
                if (data.unclaimed !== undefined) {
                    updateBadge(parseInt(data.unclaimed, 10));
                }
                if (!data.success && data.message && typeof window.fadeBox === 'function') {
                    window.fadeBox(data.message, true);
                }
                reload(chapter);
            })
            .catch(function () {
                if (typeof window.fadeBox === 'function') {
                    window.fadeBox('Request failed', true);
                }
            });
    };

    document.addEventListener('click', function (event) {
        var layer = event.target.closest('#ipioverviewlayer');
        if (layer === null) {
            return;
        }

        var tab = event.target.closest('a[data-directive-chapter]');
        if (tab !== null) {
            event.preventDefault();
            reload(tab.getAttribute('data-directive-chapter'));
            return;
        }

        var claimAll = event.target.closest('.ipiOverviewCollectRewards:not(.disabled)');
        if (claimAll !== null) {
            post('/ajax/directives/claim-all', {
                directive: claimAll.getAttribute('data-directive-claim-all'),
            }, currentChapter());
            return;
        }

        var claim = event.target.closest('button[data-directive-claim]:not([disabled])');
        if (claim !== null) {
            post('/ajax/directives/claim', {
                directive: claim.getAttribute('data-directive'),
                mission: claim.getAttribute('data-mission'),
            }, currentChapter());
            return;
        }

        // Accordion toggle: expand/collapse the mission details below its header.
        var header = event.target.closest('.ipiTaskItemHeader');
        if (header !== null) {
            var task = header.closest('.ipiTaskItem');
            task.classList.toggle('active');
            var content = task.querySelector('.ipiTaskItemContent');
            if (content !== null) {
                content.style.display = task.classList.contains('active') ? 'block' : 'none';
            }
        }
    });

    // Keep the panel badge in sync with the rendered layer (e.g. after a chapter
    // reload that changed the claimable count).
    var syncBadge = function () {
        var layer = document.querySelector('#ipioverviewlayer');
        if (layer !== null && layer.hasAttribute('data-unclaimed')) {
            updateBadge(parseInt(layer.getAttribute('data-unclaimed'), 10));
        }
    };
    document.addEventListener('click', syncBadge);
}());
