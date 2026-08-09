(function ($) {
    'use strict';

    var $scanBtn       = $('#d2g-scan');
    var $status        = $('#d2g-status');
    var $table         = $('#d2g-results');
    var $tbody         = $table.find('tbody');
    var $batchBar      = $('#d2g-batch-bar');
    var $convertBtn    = $('#d2g-convert-selected');
    var $batchProgress = $('#d2g-batch-progress');
    var $selectAll     = $('#d2g-select-all');
    var $modal         = $('#d2g-preview-modal');
    var $pagination    = $('#d2g-pagination');
    var $pagLinks      = $('#d2g-pagination-links');
    var $displayNum    = $('#d2g-displaying-num');
    var $filters       = $('#d2g-filters');
    var $filterType    = $('#d2g-filter-type');
    var $sortBy        = $('#d2g-sort-by');
    var $perPage       = $('#d2g-per-page');

    var currentPage = 1;
    var hasScanned  = false;

    // Every user-facing string comes from PHP so it can be translated.
    var i18n = (window.d2g && d2g.i18n) || {};

    function t(key) {
        return i18n[key] !== undefined ? i18n[key] : key;
    }

    // Minimal sprintf for the %s / %1$s placeholders WordPress uses. Argument
    // order matters in translation, so positional placeholders have to work.
    function fmt(template) {
        var args = Array.prototype.slice.call(arguments, 1);
        var next = 0;
        return String(template).replace(/%(\d+\$)?s/g, function (match, position) {
            var index = position ? parseInt(position, 10) - 1 : next++;
            return args[index] !== undefined ? args[index] : '';
        });
    }

    // Current query state. Kept in sync with the filter controls in both
    // directions: changing a control updates this, and clicking a sortable
    // column header updates both this and the sort dropdown.
    var query = {
        post_type: 'all',
        orderby: 'title',
        order: 'asc',
        per_page: '20'
    };

    // Post IDs with a request in flight. A row's actions all stay disabled
    // while any of them is running: two overlapping requests for one post is
    // how a conversion could once overwrite its own backup.
    var busy = {};

    function setRowBusy(postId, isBusy) {
        if (isBusy) {
            busy[postId] = true;
        } else {
            delete busy[postId];
        }
        rowFor(postId).find('button, input[type="checkbox"]').each(function () {
            var $el = $(this);
            if (isBusy) {
                // Remember what was already disabled so re-enabling does not
                // wake up a control that should have stayed off.
                $el.data('d2gWasDisabled', $el.prop('disabled'));
                $el.prop('disabled', true);
            } else if (!$el.data('d2gWasDisabled')) {
                $el.prop('disabled', false);
            }
        });
    }

    function rowFor(postId) {
        return $tbody.find('tr[data-id="' + postId + '"]');
    }

    // Status text is always inserted as text. It can carry a post title or a
    // server error message, and neither is HTML — putting them through .html()
    // made this an injection sink for anything that reached a title.
    function showStatus(msg, type) {
        $status.removeClass('d2g-error d2g-success')
            .addClass(type ? 'd2g-' + type : '')
            .text(msg)
            .show();
    }

    function hideStatus() {
        $status.hide().text('');
    }

    // ---------- Filter / sort state ----------

    function readControls() {
        query.post_type = $filterType.val() || 'all';
        query.per_page  = $perPage.val() || '20';

        var sort = ($sortBy.val() || 'title-asc').split('-');
        query.orderby = sort[0];
        query.order   = sort[1] === 'desc' ? 'desc' : 'asc';
    }

    function syncControls() {
        $filterType.val(query.post_type);
        $perPage.val(query.per_page);

        var combined = query.orderby + '-' + query.order;
        // Only apply if the dropdown actually offers that combination.
        if ($sortBy.find('option[value="' + combined + '"]').length) {
            $sortBy.val(combined);
        }

        $table.find('.d2g-sortable')
            .removeClass('d2g-sorted d2g-asc d2g-desc')
            .attr('aria-sort', 'none')
            .filter('[data-sort="' + query.orderby + '"]')
            .addClass('d2g-sorted d2g-' + query.order)
            .attr('aria-sort', query.order === 'asc' ? 'ascending' : 'descending');
    }

    // ---------- Pagination ----------

    function pageButton(cls, page, label, title, disabled) {
        var $btn = $('<button/>', {
            type: 'button',
            'class': cls + ' button',
            title: title,
            'aria-label': title,
            text: label
        }).data('page', page);

        if (disabled) {
            $btn.prop('disabled', true).addClass('disabled');
        }
        return $btn;
    }

    function renderPagination(data) {
        var totalItems = data.total_items;
        var totalPages = data.total_pages;
        var page       = data.current_page;

        $displayNum.text(fmt(t('items'), totalItems));

        if (totalPages <= 1) {
            $pagination.hide();
            return;
        }

        // Built as real elements rather than a concatenated string: these are
        // buttons, not links to nowhere, so they are reachable from the
        // keyboard and announced as controls.
        $pagLinks.empty()
            .append(pageButton('first-page', 1, '«', t('firstPage'), page <= 1))
            .append(document.createTextNode(' '))
            .append(pageButton('prev-page', page - 1, '‹', t('prevPage'), page <= 1))
            .append(document.createTextNode(' '))
            .append($('<span/>', { 'class': 'paging-input', text: fmt(t('pageOf'), page, totalPages) }))
            .append(document.createTextNode(' '))
            .append(pageButton('next-page', page + 1, '›', t('nextPage'), page >= totalPages))
            .append(document.createTextNode(' '))
            .append(pageButton('last-page', totalPages, '»', t('lastPage'), page >= totalPages));

        $pagination.show();
    }

    // ---------- Row rendering ----------

    // post ID -> md5 of the post_content this browser last saw.
    var sourceHash = {};

    function renderRow(page) {
        var convertible = page.has_divi;
        var restorable  = page.has_backup;

        // The server refuses a conversion that cannot say which version of the
        // page it is converting, so every row carries the token the scan issued
        // for it. Previously only Preview produced one, which left the two most
        // common paths — convert without previewing, and batch convert —
        // sending nothing and overwriting whatever the post happened to hold.
        sourceHash[page.id] = page.source_hash || '';

        var $row = $('<tr/>', { 'data-id': page.id });
        if (!convertible) {
            $row.addClass('d2g-row-done');
        }

        $('<td/>', { 'class': 'check-column' })
            .append($('<input/>', {
                type: 'checkbox',
                'class': 'd2g-select',
                value: page.id,
                disabled: !convertible,
                'aria-label': page.title || t('noTitle')
            }))
            .appendTo($row);

        $('<td/>')
            .append($('<a/>', {
                href: page.edit || '#',
                target: '_blank',
                rel: 'noopener noreferrer',
                text: page.title || t('noTitle')
            }))
            .appendTo($row);

        $('<td/>', { text: page.type }).appendTo($row);
        $('<td/>', { text: page.status }).appendTo($row);
        $('<td/>', { text: page.date }).appendTo($row);
        $('<td/>').append(backupCell(page.has_backup, page.backup_date)).appendTo($row);

        var $actions = $('<td/>', { 'class': 'd2g-actions' });
        $actions.append($('<button/>', {
            type: 'button',
            'class': 'button d2g-preview-btn',
            'data-id': page.id,
            disabled: !convertible,
            text: t('preview')
        }));
        $actions.append(document.createTextNode(' '));
        $actions.append($('<button/>', {
            type: 'button',
            'class': 'button button-primary d2g-convert-btn',
            'data-id': page.id,
            disabled: !convertible,
            text: convertible ? t('convert') : t('converted')
        }));
        if (restorable) {
            $actions.append(document.createTextNode(' '));
            $actions.append(restoreButton(page.id));
        }
        $actions.appendTo($row);

        return $row;
    }

    function restoreButton(postId) {
        return $('<button/>', {
            type: 'button',
            'class': 'button d2g-restore-btn',
            'data-id': postId,
            text: t('restore')
        });
    }

    function backupCell(hasBackup, backupDate) {
        if (!hasBackup) {
            return $('<span/>', { 'class': 'd2g-backup-no', text: '—' });
        }
        return $('<span/>', {
            'class': 'd2g-backup-yes',
            title: backupDate || '',
            text: backupDate ? backupDate.split(' ')[0] : t('yes')
        });
    }

    // ---------- Scan ----------

    function loadPage(page) {
        currentPage = page;
        $scanBtn.prop('disabled', true).text(t('scanning'));
        hideStatus();
        $tbody.empty();
        $table.hide();
        $batchBar.hide();
        $pagination.hide();
        resetConversionWarnings();
        busy = {};

        $.post(d2g.ajax_url, {
            action: 'd2g_scan_pages',
            nonce: d2g.nonce,
            paged: page,
            post_type: query.post_type,
            orderby: query.orderby,
            order: query.order,
            per_page: query.per_page
        }, function (res) {
            $scanBtn.prop('disabled', false).text(t('scan'));

            if (!res.success) {
                showStatus(res.data || t('scanFailed'), 'error');
                return;
            }

            var data = res.data;

            hasScanned = true;

            // Adopt whatever the server actually applied — it whitelists the
            // values, so this is the authoritative state.
            query.post_type = data.post_type;
            query.orderby   = data.orderby;
            query.order     = data.order;
            query.per_page  = String(data.per_page);
            currentPage     = data.current_page;

            // The filter bar stays visible even on an empty result set, so a
            // filter that matches nothing can be changed back.
            $filters.show();
            syncControls();

            // Divi 5 pages are not in `pages` and cannot be converted, so this
            // has to be said on the "nothing found" path too — that is the one
            // where a site with only Divi 5 content would otherwise be told it
            // has no Divi left.
            var divi5 = data.divi5_count ? ' ' + fmt(t('divi5'), data.divi5_count) : '';

            if (!data.total_items) {
                showStatus(t('noResults') + divi5, divi5 ? 'error' : 'success');
                return;
            }

            if (data.truncated) {
                showStatus(fmt(t('found'), data.total_items) + ' ' +
                    fmt(t('items'), data.shown) + ' — ' + t('truncated') + divi5, 'error');
            } else {
                showStatus(fmt(t('found'), data.total_items) + divi5, divi5 ? 'error' : 'success');
            }

            $.each(data.pages, function (i, page) {
                $tbody.append(renderRow(page));
            });

            $selectAll.prop('checked', false);
            $table.show();
            $batchBar.show();
            renderPagination(data);
        }).fail(function () {
            $scanBtn.prop('disabled', false).text(t('scan'));
            showStatus(t('scanNetworkError'), 'error');
        });
    }

    $scanBtn.on('click', function () {
        readControls();
        loadPage(1);
    });

    // Pagination clicks.
    $pagination.on('click', 'button:not(:disabled)', function () {
        var page = $(this).data('page');
        if (page) {
            loadPage(page);
        }
    });

    // ---------- Filter / sort controls ----------

    // Any control change re-queries from page 1 — the result set changes size,
    // so staying on the current page number would be meaningless.
    $filterType.add($sortBy).add($perPage).on('change', function () {
        readControls();
        if (hasScanned) {
            loadPage(1);
        }
    });

    // Clicking a sortable column header sorts by it, toggling direction if it
    // is already the active column.
    $table.on('click', '.d2g-sort-btn', function () {
        if (!hasScanned) {
            return;
        }

        var col = $(this).closest('.d2g-sortable').data('sort');
        if (!col) {
            return;
        }

        if (query.orderby === col) {
            query.order = query.order === 'asc' ? 'desc' : 'asc';
        } else {
            query.orderby = col;
            query.order   = 'asc';
        }

        syncControls();
        loadPage(1);
    });

    // ---------- Select all ----------

    $selectAll.on('change', function () {
        $tbody.find('.d2g-select:not(:disabled)').prop('checked', this.checked);
    });

    // ---------- Preview ----------

    $tbody.on('click', '.d2g-preview-btn', function () {
        var $btn = $(this);
        var postId = $btn.data('id');

        if (busy[postId]) {
            return;
        }

        setRowBusy(postId, true);
        $btn.text(t('loading'));

        $.post(d2g.ajax_url, {
            action: 'd2g_preview_conversion',
            nonce: d2g.nonce,
            post_id: postId
        }, function (res) {
            setRowBusy(postId, false);
            $btn.text(t('preview'));

            if (!res.success) {
                showStatus(res.data || t('previewFailed'), 'error');
                return;
            }

            sourceHash[postId] = res.data.source_hash || '';

            $('#d2g-preview-original').text(res.data.original);
            $('#d2g-preview-converted').text(res.data.converted);
            renderWarnings(res.data.warnings);
            openModal($btn);
        }).fail(function () {
            setRowBusy(postId, false);
            $btn.text(t('preview'));
            showStatus(t('previewNetworkError'), 'error');
        });
    });

    // Warnings accumulated by conversions on this screen, keyed so the same
    // loss reported for forty sections is listed once.
    var conversionWarnings = {};

    function resetConversionWarnings() {
        conversionWarnings = {};
        $('#d2g-warnings').empty().hide();
    }

    /**
     * Record the warnings a conversion returned, and show them.
     *
     * The server returns these with every conversion response, and the browser
     * used to drop them: they were rendered for a preview and nowhere else, so
     * converting without previewing lost them entirely. That is the one path
     * most users take.
     */
    function addConversionWarnings(warnings) {
        if (!warnings || !warnings.length) {
            return;
        }

        $.each(warnings, function (i, warning) {
            conversionWarnings[warning.module + '\u0000' + warning.message] = warning;
        });

        var list = $.map(conversionWarnings, function (w) { return w; });
        renderWarningsInto($('#d2g-warnings'), list);
    }

    function renderWarnings(warnings) {
        renderWarningsInto($('#d2g-preview-warnings'), warnings);
    }

    function renderWarningsInto($box, warnings) {
        $box.empty();

        if (!warnings || !warnings.length) {
            $box.hide();
            return;
        }

        $box.append($('<h3/>', { text: fmt(t('warningsCount'), warnings.length) }));
        var $list = $('<ul/>');
        $.each(warnings, function (i, warning) {
            $list.append($('<li/>').append(
                $('<code/>', { text: warning.module })
            ).append(document.createTextNode(' ' + warning.message)));
        });
        $box.append($list).show();
    }

    // ---------- Modal ----------

    var $lastFocus = null;

    /**
     * @param {jQuery} [$returnTo] Control to focus when the dialog closes.
     *
     * The element has to be passed in rather than read from
     * document.activeElement here. Opening the preview disables the row's
     * buttons for the duration of the request, and disabling the focused
     * element moves focus to <body> — so by the time this ran, "where focus
     * came from" was the document, and closing the dialog dumped a keyboard
     * user at the top of the page with no way back but tabbing.
     */
    function openModal($returnTo) {
        $lastFocus = ($returnTo && $returnTo.length) ? $returnTo : $(document.activeElement);
        $modal.show();
        $modal.find('.d2g-modal-close').trigger('focus');
        $(document).on('keydown.d2gModal', function (e) {
            if (e.key === 'Escape' || e.keyCode === 27) {
                closeModal();
                return;
            }
            if (e.key === 'Tab' || e.keyCode === 9) {
                trapFocus(e);
            }
        });
    }

    function closeModal() {
        $modal.hide();
        $(document).off('keydown.d2gModal');

        // The control that opened the dialog may since have been disabled — a
        // page converted from inside the preview, say. Focusing a disabled
        // element silently does nothing, which leaves focus stranded on the
        // hidden dialog, so fall back to something that can take it.
        var $target = ($lastFocus && $lastFocus.length) ? $lastFocus : $();
        if (!$target.length || $target.is(':disabled') || !$target.is(':visible')) {
            $target = $scanBtn;
        }
        $target.trigger('focus');
    }

    // Keep Tab inside the dialog while it is open, so a keyboard user cannot
    // wander into the page behind it and lose track of where they are.
    function trapFocus(e) {
        var $focusable = $modal.find('a[href], button:not(:disabled), textarea, input, select, [tabindex]:not([tabindex="-1"])').filter(':visible');
        if (!$focusable.length) {
            return;
        }

        var first = $focusable.first()[0];
        var last  = $focusable.last()[0];

        if (e.shiftKey && document.activeElement === first) {
            e.preventDefault();
            last.focus();
        } else if (!e.shiftKey && document.activeElement === last) {
            e.preventDefault();
            first.focus();
        }
    }

    $modal.on('click', '.d2g-modal-close', closeModal);
    $modal.on('click', function (e) {
        if (e.target === this) {
            closeModal();
        }
    });

    // ---------- Convert single ----------

    $tbody.on('click', '.d2g-convert-btn', function () {
        var postId = $(this).data('id');

        if (busy[postId]) {
            return;
        }

        if (!window.confirm(t('confirmConvert'))) {
            return;
        }

        convertPage(postId, $(this));
    });

    function convertPage(postId, $btn) {
        var $row = rowFor(postId);

        setRowBusy(postId, true);
        if ($btn) {
            $btn.text(t('converting'));
        }

        return $.post(d2g.ajax_url, {
            action: 'd2g_convert_page',
            nonce: d2g.nonce,
            post_id: postId,
            source_hash: sourceHash[postId] || ''
        }).then(function (res) {
            setRowBusy(postId, false);

            if (!res.success) {
                if ($btn) {
                    $btn.text(t('convert'));
                }
                $row.addClass('d2g-row-error');
                var message = fmt(t('convertError'), postId, res.data || t('unknownError'));
                showStatus(message, 'error');
                // Rejecting with the message lets the batch runner record which
                // page failed and why, instead of counting it as a success.
                return $.Deferred().reject(message).promise();
            }

            markConverted($row, res.data);

            // A successful conversion used to change the row and say nothing.
            // #d2g-status is the screen's aria-live region, so a screen reader
            // user got an announcement when a conversion failed and silence
            // when it worked.
            //
            // Only for a single conversion: $btn is absent during a batch, and
            // announcing every page in turn would talk over itself before the
            // batch summary replaced the lot.
            if ($btn && res.data && res.data.message) {
                showStatus(res.data.message, 'success');
            }
            addConversionWarnings(res.data && res.data.warnings);

            return res;
        }, function () {
            // Transport-level failure: jQuery's own rejection path.
            setRowBusy(postId, false);
            if ($btn) {
                $btn.text(t('convert'));
            }
            $row.addClass('d2g-row-error');
            var message = fmt(t('convertNetworkError'), postId);
            showStatus(message, 'error');
            return $.Deferred().reject(message).promise();
        });
    }

    // Update a row in place after a successful conversion: it no longer holds
    // Divi content, and it may now have a backup to restore from.
    function markConverted($row, data) {
        var postId = $row.data('id');

        $row.removeClass('d2g-row-error').addClass('d2g-row-converted d2g-row-done');
        $row.find('.d2g-select').prop('checked', false).prop('disabled', true);
        $row.find('.d2g-preview-btn').prop('disabled', true);
        $row.find('.d2g-convert-btn').text(t('converted')).prop('disabled', true);

        if (data && data.has_backup) {
            if (!$row.find('.d2g-restore-btn').length) {
                $row.find('.d2g-actions')
                    .append(document.createTextNode(' '))
                    .append(restoreButton(postId));
            }
            $row.find('td').eq(5).empty().append(backupCell(true, data.backup_date));
        }
    }

    // ---------- Restore ----------

    $tbody.on('click', '.d2g-restore-btn', function () {
        var $btn   = $(this);
        var postId = $btn.data('id');
        var $row   = rowFor(postId);
        var title  = $row.find('td').eq(1).text() || t('thisPage');

        if (busy[postId]) {
            return;
        }

        if (!window.confirm(fmt(t('confirmRestore'), title))) {
            return;
        }

        setRowBusy(postId, true);
        $btn.text(t('restoring'));

        $.post(d2g.ajax_url, {
            action: 'd2g_restore_page',
            nonce: d2g.nonce,
            post_id: postId
        }, function (res) {
            setRowBusy(postId, false);
            $btn.text(t('restore'));

            if (!res.success) {
                $row.addClass('d2g-row-error');
                showStatus(fmt(t('restoreError'), postId, res.data || t('unknownError')), 'error');
                return;
            }

            // The page holds Divi content again, so it is convertible again.
            $row.removeClass('d2g-row-converted d2g-row-error d2g-row-done')
                .addClass('d2g-row-restored');
            $row.find('.d2g-select').prop('disabled', false).data('d2gWasDisabled', false);
            $row.find('.d2g-preview-btn').prop('disabled', false).data('d2gWasDisabled', false);
            $row.find('.d2g-convert-btn').text(t('convert')).prop('disabled', false).data('d2gWasDisabled', false);
            sourceHash[postId] = res.data.source_hash || '';

            showStatus(res.data.message || t('restored'), 'success');
        }).fail(function () {
            setRowBusy(postId, false);
            $btn.text(t('restore'));
            $row.addClass('d2g-row-error');
            showStatus(t('restoreNetworkError'), 'error');
        });
    });

    // ---------- Settings ----------

    $('#d2g-delete-data').on('change', function () {
        var $box      = $(this);
        var $feedback = $('#d2g-settings-feedback');
        var enabled   = $box.is(':checked');

        if (enabled && !window.confirm(t('confirmDeleteData'))) {
            $box.prop('checked', false);
            return;
        }

        $box.prop('disabled', true);
        $feedback.removeClass('d2g-error').text(t('saving'));

        $.post(d2g.ajax_url, {
            action: 'd2g_save_settings',
            nonce: d2g.nonce,
            delete_data: enabled ? 'yes' : 'no'
        }, function (res) {
            $box.prop('disabled', false);

            if (!res.success) {
                // Put the control back to what the server still holds.
                $box.prop('checked', !enabled);
                $feedback.addClass('d2g-error').text(res.data || t('saveFailed'));
                return;
            }

            $feedback.text(res.data.message || t('saved'));
        }).fail(function () {
            $box.prop('disabled', false).prop('checked', !enabled);
            $feedback.addClass('d2g-error').text(t('saveNetworkError'));
        });
    });

    // ---------- Batch convert ----------

    $convertBtn.on('click', function () {
        var ids = [];
        $tbody.find('.d2g-select:checked:not(:disabled)').each(function () {
            ids.push($(this).val());
        });

        if (!ids.length) {
            showStatus(t('noSelection'), 'error');
            return;
        }

        if (!window.confirm(fmt(t('confirmBatch'), ids.length))) {
            return;
        }

        $convertBtn.prop('disabled', true);

        // Successes and failures are counted separately. They used to share one
        // counter incremented from .always(), so a batch where every page
        // failed still finished by reporting them all as converted — which in a
        // migration is how someone removes Divi too early.
        var total     = ids.length;
        var succeeded = 0;
        var failures  = [];

        $batchProgress.text('0 / ' + total);

        function step() {
            if (!ids.length) {
                $convertBtn.prop('disabled', false);
                if (failures.length) {
                    showStatus(
                        fmt(t('batchDoneErrors'), succeeded, total, failures.length) + '\n' + failures.join('\n'),
                        'error'
                    );
                } else {
                    showStatus(fmt(t('batchDone'), succeeded, total), 'success');
                }
                return;
            }

            var id = ids.shift();
            convertPage(id, null).then(function () {
                succeeded++;
            }, function (message) {
                failures.push(message || fmt(t('convertError'), id, t('unknownError')));
            }).always(function () {
                $batchProgress.text((succeeded + failures.length) + ' / ' + total);
                step();
            });
        }

        step();
    });

})(jQuery);
