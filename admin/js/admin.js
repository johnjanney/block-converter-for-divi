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
    var $filters       = $('#d2g-filters');
    var $pagination    = $('#d2g-pagination');

    // State.
    var allPages     = [];   // Full dataset from server.
    var filtered     = [];   // After filter + sort.
    var currentPage  = 1;

    function getPerPage() {
        var val = $('#d2g-per-page').val();
        return val === 'all' ? Infinity : parseInt(val, 10);
    }

    function getTotalPages() {
        var perPage = getPerPage();
        if (perPage === Infinity || !filtered.length) return 1;
        return Math.ceil(filtered.length / perPage);
    }

    // ---------- Status helpers ----------

    function showStatus(msg, type) {
        $status.removeClass('d2g-error d2g-success')
            .addClass(type ? 'd2g-' + type : '')
            .html(msg)
            .show();
    }

    function hideStatus() {
        $status.hide();
    }

    // ---------- Filter & Sort ----------

    function applyFilterAndSort() {
        var typeFilter = $('#d2g-filter-type').val();
        var sortVal    = $('#d2g-sort-by').val();

        // Filter.
        filtered = allPages.filter(function (p) {
            return typeFilter === 'all' || p.type === typeFilter;
        });

        // Sort.
        var parts = sortVal.split('-');
        var field = parts[0];
        var dir   = parts[1] === 'desc' ? -1 : 1;

        filtered.sort(function (a, b) {
            var aVal, bVal;
            if (field === 'date') {
                aVal = a.date || '';
                bVal = b.date || '';
            } else if (field === 'title') {
                aVal = (a.title || '').toLowerCase();
                bVal = (b.title || '').toLowerCase();
            } else if (field === 'type') {
                aVal = a.type;
                bVal = b.type;
            } else if (field === 'status') {
                aVal = a.status;
                bVal = b.status;
            } else {
                aVal = '';
                bVal = '';
            }
            if (aVal < bVal) return -1 * dir;
            if (aVal > bVal) return 1 * dir;
            return 0;
        });

        // Reset to page 1 and render.
        currentPage = 1;
        renderTable();
    }

    // ---------- Render ----------

    function renderTable() {
        $tbody.empty();
        $selectAll.prop('checked', false);

        var totalPages = getTotalPages();
        if (currentPage > totalPages) currentPage = totalPages;
        if (currentPage < 1) currentPage = 1;

        var perPage = getPerPage();
        var start   = (currentPage - 1) * perPage;
        var pageItems = perPage === Infinity ? filtered : filtered.slice(start, start + perPage);

        if (!pageItems.length) {
            $tbody.append('<tr><td colspan="6" style="text-align:center;">No results.</td></tr>');
        } else {
            $.each(pageItems, function (i, page) {
                var dateStr = page.date ? page.date.substring(0, 10) : '';
                var row = '<tr data-id="' + page.id + '">' +
                    '<td class="check-column"><input type="checkbox" class="d2g-select" value="' + page.id + '" /></td>' +
                    '<td><a href="' + escHtml(page.edit) + '" target="_blank">' + escHtml(page.title || '(no title)') + '</a></td>' +
                    '<td>' + escHtml(page.type) + '</td>' +
                    '<td>' + escHtml(page.status) + '</td>' +
                    '<td>' + escHtml(dateStr) + '</td>' +
                    '<td class="d2g-actions">' +
                        '<button type="button" class="button d2g-preview-btn" data-id="' + page.id + '">Preview</button> ' +
                        '<button type="button" class="button button-primary d2g-convert-btn" data-id="' + page.id + '">Convert</button>' +
                    '</td>' +
                    '</tr>';
                $tbody.append(row);
            });
        }

        renderPagination();
    }

    function renderPagination() {
        var totalPages = getTotalPages();
        var total      = filtered.length;
        var perPage    = getPerPage();
        var start, end;

        if (perPage === Infinity) {
            start = 1;
            end   = total;
        } else {
            start = total ? (currentPage - 1) * perPage + 1 : 0;
            end   = Math.min(currentPage * perPage, total);
        }

        $('#d2g-page-info').text(total ? start + '–' + end + ' of ' + total + ' items' : '0 items');
        $('#d2g-total-pages').text(totalPages);
        $('#d2g-page-input').val(currentPage).attr('max', totalPages);

        $('#d2g-page-first, #d2g-page-prev').prop('disabled', currentPage <= 1);
        $('#d2g-page-next, #d2g-page-last').prop('disabled', currentPage >= totalPages);

        $pagination.toggle(totalPages > 1 || total > 0);
    }

    // ---------- Pagination events ----------

    $('#d2g-page-first').on('click', function () { currentPage = 1; renderTable(); });
    $('#d2g-page-prev').on('click', function () { currentPage--; renderTable(); });
    $('#d2g-page-next').on('click', function () { currentPage++; renderTable(); });
    $('#d2g-page-last').on('click', function () { currentPage = getTotalPages(); renderTable(); });

    $('#d2g-page-input').on('change', function () {
        var val = parseInt($(this).val(), 10);
        if (val >= 1 && val <= getTotalPages()) {
            currentPage = val;
            renderTable();
        } else {
            $(this).val(currentPage);
        }
    });

    // Filter / sort / per-page changes.
    $('#d2g-filter-type, #d2g-sort-by').on('change', applyFilterAndSort);
    $('#d2g-per-page').on('change', function () {
        currentPage = 1;
        renderTable();
    });

    // ---------- Scan ----------

    $scanBtn.on('click', function () {
        var $btn = $(this);
        $btn.prop('disabled', true).text('Scanning…');
        hideStatus();
        allPages = [];
        filtered = [];
        $tbody.empty();
        $table.hide();
        $batchBar.hide();
        $filters.hide();
        $pagination.hide();

        $.post(d2g.ajax_url, {
            action: 'd2g_scan_pages',
            nonce: d2g.nonce
        }, function (res) {
            $btn.prop('disabled', false).text('Scan for Divi Pages');

            if (!res.success) {
                showStatus(res.data || 'Scan failed.', 'error');
                return;
            }

            allPages = res.data;
            if (!allPages.length) {
                showStatus('No Divi pages found.', 'success');
                return;
            }

            showStatus(allPages.length + ' Divi page(s) found.', 'success');
            $filters.show();
            $table.show();
            $batchBar.show();

            applyFilterAndSort();
        }).fail(function () {
            $btn.prop('disabled', false).text('Scan for Divi Pages');
            showStatus('Network error during scan.', 'error');
        });
    });

    // ---------- Select all ----------

    $selectAll.on('change', function () {
        $tbody.find('.d2g-select').prop('checked', this.checked);
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
            if ($btn) {
                $btn.prop('disabled', false).text('Convert');
            }

            if (!res.success) {
                $row.addClass('d2g-row-error');
                showStatus('Error converting page ' + postId + ': ' + (res.data || 'Unknown error'), 'error');
                return $.Deferred().reject();
            }

            $row.addClass('d2g-row-converted');
            $row.find('.d2g-convert-btn').text('Done').prop('disabled', true);
            return res;
        }).fail(function () {
            if ($btn) {
                $btn.prop('disabled', false).text('Convert');
            }
            $row.addClass('d2g-row-error');
        });
    }

    // ---------- Batch convert ----------

    $convertBtn.on('click', function () {
        var ids = [];
        $tbody.find('.d2g-select:checked').each(function () {
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

})(jQuery);
