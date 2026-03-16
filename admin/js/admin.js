(function ($) {
    'use strict';

    var $scanBtn       = $('#d2g-scan');
    var $status        = $('#d2g-status');
    var $table         = $('#d2g-results');
    var $tbody         = $table.find('tbody');
    var $batchBar      = $('#d2g-batch-bar');
    var $convertBtn    = $('#d2g-convert-selected');
    var $batchProgress = $('#d2g-batch-progress');
    var $selectAll    = $('#d2g-select-all');
    var $modal        = $('#d2g-preview-modal');
    var $pagination   = $('#d2g-pagination');
    var $pagLinks     = $('#d2g-pagination-links');
    var $displayNum   = $('#d2g-displaying-num');

    var currentPage = 1;

    function showStatus(msg, type) {
        $status.removeClass('d2g-error d2g-success')
            .addClass(type ? 'd2g-' + type : '')
            .html(msg)
            .show();
    }

    function hideStatus() {
        $status.hide();
    }

    function renderPagination(data) {
        var totalItems = data.total_items;
        var totalPages = data.total_pages;
        var page       = data.current_page;

        if (totalPages <= 1) {
            $pagination.hide();
            return;
        }

        $displayNum.text(totalItems + ' item(s)');

        var html = '';
        html += '<a class="first-page button' + (page <= 1 ? ' disabled' : '') + '" data-page="1" title="First page">&laquo;</a> ';
        html += '<a class="prev-page button' + (page <= 1 ? ' disabled' : '') + '" data-page="' + (page - 1) + '" title="Previous page">&lsaquo;</a> ';
        html += '<span class="paging-input">' + page + ' of <span class="total-pages">' + totalPages + '</span></span> ';
        html += '<a class="next-page button' + (page >= totalPages ? ' disabled' : '') + '" data-page="' + (page + 1) + '" title="Next page">&rsaquo;</a> ';
        html += '<a class="last-page button' + (page >= totalPages ? ' disabled' : '') + '" data-page="' + totalPages + '" title="Last page">&raquo;</a>';

        $pagLinks.html(html);
        $pagination.show();
    }

    function loadPage(page) {
        currentPage = page;
        $scanBtn.prop('disabled', true).text('Scanning…');
        hideStatus();
        allPages = [];
        filtered = [];
        $tbody.empty();
        $table.hide();
        $batchBar.hide();
        $pagination.hide();

        $.post(d2g.ajax_url, {
            action: 'd2g_scan_pages',
            nonce: d2g.nonce,
            paged: page
        }, function (res) {
            $scanBtn.prop('disabled', false).text('Scan for Divi Pages');

            if (!res.success) {
                showStatus(res.data || 'Scan failed.', 'error');
                return;
            }

            var data  = res.data;
            var pages = data.pages;

            if (!data.total_items) {
                showStatus('No Divi pages found.', 'success');
                return;
            }

            showStatus(data.total_items + ' Divi page(s) found.', 'success');

            $.each(pages, function (i, page) {
                var row = '<tr data-id="' + page.id + '">' +
                    '<td class="check-column"><input type="checkbox" class="d2g-select" value="' + page.id + '" /></td>' +
                    '<td><a href="' + escHtml(page.edit) + '" target="_blank">' + escHtml(page.title || '(no title)') + '</a></td>' +
                    '<td>' + escHtml(page.type) + '</td>' +
                    '<td>' + escHtml(page.status) + '</td>' +
                    '<td class="d2g-actions">' +
                        '<button type="button" class="button d2g-preview-btn" data-id="' + page.id + '">Preview</button> ' +
                        '<button type="button" class="button button-primary d2g-convert-btn" data-id="' + page.id + '">Convert</button>' +
                    '</td>' +
                    '</tr>';
                $tbody.append(row);
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

    // Scan for Divi pages.
    $scanBtn.on('click', function () {
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
