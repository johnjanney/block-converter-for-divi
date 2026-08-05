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

    // Current query state. Kept in sync with the filter controls in both
    // directions: changing a control updates this, and clicking a sortable
    // column header updates both this and the sort dropdown.
    var query = {
        post_type: 'all',
        orderby: 'title',
        order: 'asc',
        per_page: '20'
    };

    function showStatus(msg, type) {
        $status.removeClass('d2g-error d2g-success')
            .addClass(type ? 'd2g-' + type : '')
            .html(msg)
            .show();
    }

    function hideStatus() {
        $status.hide();
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
            .filter('[data-sort="' + query.orderby + '"]')
            .addClass('d2g-sorted d2g-' + query.order);
    }

    // ---------- Pagination ----------

    function renderPagination(data) {
        var totalItems = data.total_items;
        var totalPages = data.total_pages;
        var page       = data.current_page;

        $displayNum.text(totalItems + ' item(s)');

        if (totalPages <= 1) {
            $pagination.hide();
            return;
        }

        var html = '';
        html += '<a class="first-page button' + (page <= 1 ? ' disabled' : '') + '" data-page="1" title="First page">&laquo;</a> ';
        html += '<a class="prev-page button' + (page <= 1 ? ' disabled' : '') + '" data-page="' + (page - 1) + '" title="Previous page">&lsaquo;</a> ';
        html += '<span class="paging-input">' + page + ' of <span class="total-pages">' + totalPages + '</span></span> ';
        html += '<a class="next-page button' + (page >= totalPages ? ' disabled' : '') + '" data-page="' + (page + 1) + '" title="Next page">&rsaquo;</a> ';
        html += '<a class="last-page button' + (page >= totalPages ? ' disabled' : '') + '" data-page="' + totalPages + '" title="Last page">&raquo;</a>';

        $pagLinks.html(html);
        $pagination.show();
    }

    // ---------- Row rendering ----------

    function renderRow(page) {
        var convertible = page.has_divi;
        var restorable  = page.has_backup;

        var backupCell = restorable
            ? '<span class="d2g-backup-yes" title="' + escAttr(page.backup_date) + '">' +
                  escHtml(page.backup_date ? page.backup_date.split(' ')[0] : 'Yes') +
              '</span>'
            : '<span class="d2g-backup-no">&mdash;</span>';

        var actions = '<button type="button" class="button d2g-preview-btn" data-id="' + page.id + '"' +
                          (convertible ? '' : ' disabled') + '>Preview</button> ' +
                      '<button type="button" class="button button-primary d2g-convert-btn" data-id="' + page.id + '"' +
                          (convertible ? '' : ' disabled') + '>' +
                          (convertible ? 'Convert' : 'Converted') +
                      '</button>';

        if (restorable) {
            actions += ' <button type="button" class="button d2g-restore-btn" data-id="' + page.id + '">Restore</button>';
        }

        return '<tr data-id="' + page.id + '"' + (convertible ? '' : ' class="d2g-row-done"') + '>' +
            '<td class="check-column">' +
                '<input type="checkbox" class="d2g-select" value="' + page.id + '"' + (convertible ? '' : ' disabled') + ' />' +
            '</td>' +
            '<td><a href="' + escAttr(page.edit) + '" target="_blank">' + escHtml(page.title || '(no title)') + '</a></td>' +
            '<td>' + escHtml(page.type) + '</td>' +
            '<td>' + escHtml(page.status) + '</td>' +
            '<td>' + escHtml(page.date) + '</td>' +
            '<td>' + backupCell + '</td>' +
            '<td class="d2g-actions">' + actions + '</td>' +
            '</tr>';
    }

    // ---------- Scan ----------

    function loadPage(page) {
        currentPage = page;
        $scanBtn.prop('disabled', true).text('Scanning…');
        hideStatus();
        $tbody.empty();
        $table.hide();
        $batchBar.hide();
        $pagination.hide();

        $.post(d2g.ajax_url, {
            action: 'd2g_scan_pages',
            nonce: d2g.nonce,
            paged: page,
            post_type: query.post_type,
            orderby: query.orderby,
            order: query.order,
            per_page: query.per_page
        }, function (res) {
            $scanBtn.prop('disabled', false).text('Scan for Divi Pages');

            if (!res.success) {
                showStatus(res.data || 'Scan failed.', 'error');
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

            if (!data.total_items) {
                showStatus('No Divi pages found for the current filter.', 'success');
                return;
            }

            showStatus(data.total_items + ' page(s) found.', 'success');

            $.each(data.pages, function (i, page) {
                $tbody.append(renderRow(page));
            });

            $selectAll.prop('checked', false);
            $table.show();
            $batchBar.show();
            renderPagination(data);
        }).fail(function () {
            $scanBtn.prop('disabled', false).text('Scan for Divi Pages');
            showStatus('Network error during scan.', 'error');
        });
    }

    $scanBtn.on('click', function () {
        readControls();
        loadPage(1);
    });

    // Pagination clicks.
    $pagination.on('click', 'a:not(.disabled)', function (e) {
        e.preventDefault();
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
    $table.on('click', '.d2g-sortable', function () {
        if (!hasScanned) {
            return;
        }

        var col = $(this).data('sort');
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
        $btn.prop('disabled', true).text('Loading…');

        $.post(d2g.ajax_url, {
            action: 'd2g_preview_conversion',
            nonce: d2g.nonce,
            post_id: postId
        }, function (res) {
            $btn.prop('disabled', false).text('Preview');

            if (!res.success) {
                showStatus(res.data || 'Preview failed.', 'error');
                return;
            }

            $('#d2g-preview-original').text(res.data.original);
            $('#d2g-preview-converted').text(res.data.converted);
            $modal.show();
        }).fail(function () {
            $btn.prop('disabled', false).text('Preview');
            showStatus('Network error during preview.', 'error');
        });
    });

    // ---------- Modal ----------

    $modal.on('click', '.d2g-modal-close', function () {
        $modal.hide();
    });
    $modal.on('click', function (e) {
        if (e.target === this) {
            $modal.hide();
        }
    });

    // ---------- Convert single ----------

    $tbody.on('click', '.d2g-convert-btn', function () {
        var $btn = $(this);
        var postId = $btn.data('id');

        if (!confirm('Convert this page to Gutenberg blocks? This will modify the page content.')) {
            return;
        }

        convertPage(postId, $btn);
    });

    function convertPage(postId, $btn) {
        var backup = $('#d2g-backup').is(':checked') ? 'yes' : 'no';
        var $row = $tbody.find('tr[data-id="' + postId + '"]');

        if ($btn) {
            $btn.prop('disabled', true).text('Converting…');
        }

        return $.post(d2g.ajax_url, {
            action: 'd2g_convert_page',
            nonce: d2g.nonce,
            post_id: postId,
            backup: backup
        }).then(function (res) {
            if (!res.success) {
                if ($btn) {
                    $btn.prop('disabled', false).text('Convert');
                }
                $row.addClass('d2g-row-error');
                showStatus('Error converting page ' + postId + ': ' + (res.data || 'Unknown error'), 'error');
                return $.Deferred().reject();
            }

            markConverted($row, res.data);
            return res;
        }).fail(function () {
            if ($btn) {
                $btn.prop('disabled', false).text('Convert');
            }
            $row.addClass('d2g-row-error');
        });
    }

    // Update a row in place after a successful conversion: it no longer holds
    // Divi content, and it may now have a backup to restore from.
    function markConverted($row, data) {
        $row.removeClass('d2g-row-error').addClass('d2g-row-converted d2g-row-done');
        $row.find('.d2g-select').prop('checked', false).prop('disabled', true);
        $row.find('.d2g-preview-btn').prop('disabled', true);
        $row.find('.d2g-convert-btn').text('Converted').prop('disabled', true);

        if (data && data.has_backup) {
            if (!$row.find('.d2g-restore-btn').length) {
                $row.find('.d2g-actions').append(
                    ' <button type="button" class="button d2g-restore-btn" data-id="' + $row.data('id') + '">Restore</button>'
                );
            }
            var label = data.backup_date ? data.backup_date.split(' ')[0] : 'Yes';
            $row.find('td').eq(5).html(
                '<span class="d2g-backup-yes" title="' + escAttr(data.backup_date || '') + '">' + escHtml(label) + '</span>'
            );
        }
    }

    // ---------- Restore ----------

    $tbody.on('click', '.d2g-restore-btn', function () {
        var $btn   = $(this);
        var postId = $btn.data('id');
        var $row   = $tbody.find('tr[data-id="' + postId + '"]');
        var title  = $row.find('td').eq(1).text() || 'this page';

        if (!confirm('Restore "' + title + '" to its original Divi content?\n\nThis replaces the current Gutenberg content and hands the page back to the Divi Builder.')) {
            return;
        }

        $btn.prop('disabled', true).text('Restoring…');

        $.post(d2g.ajax_url, {
            action: 'd2g_restore_page',
            nonce: d2g.nonce,
            post_id: postId
        }, function (res) {
            $btn.prop('disabled', false).text('Restore');

            if (!res.success) {
                $row.addClass('d2g-row-error');
                showStatus('Error restoring page ' + postId + ': ' + (res.data || 'Unknown error'), 'error');
                return;
            }

            // The page holds Divi content again, so it is convertible again.
            $row.removeClass('d2g-row-converted d2g-row-error d2g-row-done')
                .addClass('d2g-row-restored');
            $row.find('.d2g-select').prop('disabled', false);
            $row.find('.d2g-preview-btn').prop('disabled', false);
            $row.find('.d2g-convert-btn').text('Convert').prop('disabled', false);

            showStatus(res.data.message || 'Page restored.', 'success');
        }).fail(function () {
            $btn.prop('disabled', false).text('Restore');
            $row.addClass('d2g-row-error');
            showStatus('Network error during restore.', 'error');
        });
    });

    // ---------- Settings ----------

    $('#d2g-delete-data').on('change', function () {
        var $box      = $(this);
        var $feedback = $('#d2g-settings-feedback');
        var enabled   = $box.is(':checked');

        if (enabled && !confirm('Delete every Divi backup when this plugin is deleted?\n\nBackups are the only way to restore a converted page. Once the plugin is removed with this on, they cannot be recovered.')) {
            $box.prop('checked', false);
            return;
        }

        $box.prop('disabled', true);
        $feedback.removeClass('d2g-error').text('Saving…');

        $.post(d2g.ajax_url, {
            action: 'd2g_save_settings',
            nonce: d2g.nonce,
            delete_data: enabled ? 'yes' : 'no'
        }, function (res) {
            $box.prop('disabled', false);

            if (!res.success) {
                // Put the control back to what the server still holds.
                $box.prop('checked', !enabled);
                $feedback.addClass('d2g-error').text(res.data || 'Could not save setting.');
                return;
            }

            $feedback.text(res.data.message || 'Saved.');
        }).fail(function () {
            $box.prop('disabled', false).prop('checked', !enabled);
            $feedback.addClass('d2g-error').text('Network error — setting not saved.');
        });
    });

    // ---------- Batch convert ----------

    $convertBtn.on('click', function () {
        var ids = [];
        $tbody.find('.d2g-select:checked:not(:disabled)').each(function () {
            ids.push($(this).val());
        });

        if (!ids.length) {
            showStatus('No pages selected.', 'error');
            return;
        }

        if (!confirm('Convert ' + ids.length + ' page(s) to Gutenberg blocks?')) {
            return;
        }

        $convertBtn.prop('disabled', true);
        var done = 0;
        var total = ids.length;
        $batchProgress.text('0 / ' + total);

        function next() {
            if (!ids.length) {
                $convertBtn.prop('disabled', false);
                showStatus('Batch conversion complete. ' + done + ' / ' + total + ' pages converted.', 'success');
                return;
            }

            var id = ids.shift();
            convertPage(id, null).always(function () {
                done++;
                $batchProgress.text(done + ' / ' + total);
                next();
            });
        }

        next();
    });

    // ---------- Utility ----------

    function escHtml(str) {
        if (!str) return '';
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

    function escAttr(str) {
        return escHtml(str).replace(/"/g, '&quot;');
    }

})(jQuery);
