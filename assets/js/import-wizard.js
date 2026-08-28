(function ($) {

    'use strict';

    function t(key, fallback) {
        return rmmigrateAdminUI.i18n(key, fallback);
    }

    function importDoneUrl() {

        var url = rmmigrateAdmin.importDoneUrl || '';

        if (url) {

            return url;

        }

        return window.location.href.split('?')[0] + '?page=multisite-migrate-import&import_step=done&imported=1';

    }



    function redirectToDone(jobId) {
        $(window).off('beforeunload.mmImport');
        var doneUrl = importDoneUrl();
        if (jobId) {
            persistImportJobId(jobId);
            window.location.href = doneUrl + (doneUrl.indexOf('?') >= 0 ? '&' : '?') + 'job_id=' + jobId;
            return;
        }
        window.location.href = doneUrl;
    }





    function formatBytes(bytes) {
        if (!bytes || bytes <= 0) return '0 B';
        var k = 1024;
        var sizes = ['B', 'KB', 'MB', 'GB'];
        var i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
    }

    function uploadLocalFile(file) {
        var status = $('#mm-import-local-status');
        var progressWrap = $('#mm-import-chunk-progress');
        var dropzone = $('#mm-import-dropzone');
        var leadText = $('.mm-import-source-lead');
        var chunkSize = rmmigrateAdmin.importChunkSize || 1048576;
        var totalChunks = Math.max(1, Math.ceil(file.size / chunkSize));
        var uploadId = 'u' + Date.now();
        var chunkIndex = 0;
        var archivePassphrase = $('#mm-import-passphrase').val() || '';

        if (/\.venc$/i.test(file.name) && archivePassphrase === '') {
            $('#mm-import-passphrase-wrap').removeClass('mm-hidden');
            rmmigrateAdminUI.toast(t('importPassphraseRequired', 'Enter the archive passphrase for .venc files.'), 'error');
            return;
        }

        // Hide upload dropzone box & lead text while upload is active
        dropzone.addClass('mm-hidden');
        leadText.addClass('mm-hidden');
        $('#mm-import-passphrase-wrap').addClass('mm-hidden');

        $(window).on('beforeunload.mmImport', function (e) {
            var msg = t('importInProgressWarn', 'An import upload is currently in progress. Navigating away will cancel the upload.');
            e.preventDefault();
            e.returnValue = msg;
            return msg;
        });

        var maxImportPercent = 0;
        var verifyInterval = null;

        function setImportProgress(targetPct, statusText, detailsText) {
            targetPct = Math.max(maxImportPercent, Math.min(100, targetPct));
            maxImportPercent = targetPct;

            progressWrap.removeClass('mm-hidden');
            progressWrap.find('.mm-progress-fill').css({
                'width': targetPct.toFixed(1) + '%',
                'transition': 'width 0.35s cubic-bezier(0.4, 0, 0.2, 1)'
            });
            progressWrap.find('.mm-import-progress-text').text(detailsText || (Math.round(targetPct) + '%'));
            if (statusText) {
                status.text(statusText);
            }

            var $sticky = $('#mm-import-sticky-progress');
            if (!$sticky.length) {
                var $belowHeader = $('.mm-below-header');
                if (!$belowHeader.length) {
                    var $header = $('.mm-app-header');
                    if ($header.length) {
                        $belowHeader = $('<div class="mm-below-header"></div>').insertAfter($header);
                    } else {
                        $belowHeader = $('<div class="mm-below-header"></div>').prependTo('.multisite-migrate-wrap');
                    }
                }
                $sticky = $(
                    '<div id="mm-import-sticky-progress" class="mm-progress-wrap mm-import-sticky-banner">' +
                    '<div id="mm-import-sticky-status" class="mm-import-progress-status"></div>' +
                    '<div class="mm-progress-bar">' +
                    '<div class="mm-progress-fill"></div>' +
                    '</div>' +
                    '<div class="mm-import-progress-text"></div>' +
                    '</div>'
                ).prependTo($belowHeader);
            }

            if (statusText) {
                $sticky.find('#mm-import-sticky-status').text(statusText);
            }
            $sticky.find('.mm-progress-fill').css('width', targetPct.toFixed(1) + '%');
            $sticky.find('.mm-import-progress-text').text(detailsText || (Math.round(targetPct) + '%'));
        }

        function stopVerifyInterval() {
            if (verifyInterval) {
                clearInterval(verifyInterval);
                verifyInterval = null;
            }
        }

        function restoreUploadUI() {
            stopVerifyInterval();
            $(window).off('beforeunload.mmImport');
            $('#mm-import-sticky-progress').remove();
            dropzone.removeClass('mm-hidden');
            leadText.removeClass('mm-hidden');
            progressWrap.addClass('mm-hidden');
        }

        setImportProgress(
            0,
            t('phaseUploading', 'Phase 1 of 2: Uploading archive') + ' — ' + file.name + ' (0 B / ' + formatBytes(file.size) + ')',
            '0% (0 B / ' + formatBytes(file.size) + ')'
        );

        var chunkRetries = 0;
        var maxChunkRetries = 4;
        var lastResumeOffset = -1;

        function uploadChunk() {
            var start = chunkIndex * chunkSize;
            if (start >= file.size) {
                stopVerifyInterval();
                restoreUploadUI();
                status.text(t('importFailed', 'Import failed'));
                rmmigrateAdminUI.toast(t('importFailed', 'Import failed'), 'error');
                return;
            }
            var end = Math.min(start + chunkSize, file.size);
            var blob = file.slice(start, end);
            var isFinalChunk = (chunkIndex + 1 >= totalChunks) || (end >= file.size);

            var form = new FormData();
            form.append('action', 'rmmigrate_import_local_chunk');
            form.append('nonce', rmmigrateAdmin.nonce);
            form.append('upload_id', uploadId);
            form.append('filename', file.name);
            form.append('chunk_index', chunkIndex);
            form.append('total_chunks', totalChunks);
            form.append('expected_offset', String(start));
            form.append('archive_passphrase', archivePassphrase);
            form.append('chunk', blob, file.name + '.part');

            $.ajax({
                url: rmmigrateAdmin.ajaxUrl,
                method: 'POST',
                data: form,
                processData: false,
                contentType: false,
                timeout: 0,
                xhr: function () {
                    var xhr = $.ajaxSettings.xhr();
                    if (xhr.upload) {
                        xhr.upload.addEventListener('progress', function (e) {
                            if (e.lengthComputable && e.total > 0 && file.size > 0) {
                                var currentBytes = Math.min(file.size, start + e.loaded);
                                var uploadPct = (currentBytes / file.size) * 90;
                                var headerMsg = t('phaseUploading', 'Phase 1 of 2: Uploading archive') + ' — ' + file.name + ' (' + formatBytes(currentBytes) + ' / ' + formatBytes(file.size) + ')';
                                var detailMsg = Math.round(uploadPct) + '% (' + formatBytes(currentBytes) + ' / ' + formatBytes(file.size) + ')';
                                setImportProgress(uploadPct, headerMsg, detailMsg);

                                if (isFinalChunk && e.loaded >= e.total) {
                                    stopVerifyInterval();
                                    var verifyHeader = t('phaseVerifying', 'Phase 2 of 2: Verifying & validating archive…') + ' — ' + file.name + ' (' + formatBytes(file.size) + ')';
                                    setImportProgress(
                                        Math.max(90, maxImportPercent),
                                        verifyHeader,
                                        '90% (' + t('verifyingStructure', 'Verifying archive integrity & structure…') + ')'
                                    );
                                    verifyInterval = setInterval(function () {
                                        if (maxImportPercent < 98) {
                                            var nextPct = maxImportPercent + 1;
                                            setImportProgress(
                                                nextPct,
                                                verifyHeader,
                                                Math.round(nextPct) + '% (' + t('verifyingStructure', 'Verifying archive integrity & structure…') + ')'
                                            );
                                        }
                                    }, 800);
                                }
                            }
                        }, false);
                    }
                    return xhr;
                }
            }).done(function (res) {
                if (!res || res.success === false) {
                    stopVerifyInterval();
                    if (res && res.data && res.data.downsize && chunkSize > 65536) {
                        var currentUploaded = start;
                        chunkSize = Math.max(65536, Math.floor(chunkSize / 2));
                        totalChunks = Math.ceil(file.size / chunkSize);
                        chunkIndex = Math.floor(currentUploaded / chunkSize);
                        chunkRetries = 0;
                        setTimeout(uploadChunk, 300);
                        return;
                    }

                    if (res && res.data && typeof res.data.resume_offset === 'number' && res.data.resume_offset >= 0) {
                        var nextChunk = Math.floor(res.data.resume_offset / chunkSize);
                        if (res.data.resume_offset !== lastResumeOffset) {
                            lastResumeOffset = res.data.resume_offset;
                            chunkIndex = nextChunk;
                            chunkRetries = 0;
                            setTimeout(uploadChunk, 500);
                            return;
                        }
                    }

                    restoreUploadUI();
                    var errMsg = (res && res.data && res.data.message) ? res.data.message : t('importFailed', 'Import failed');
                    status.text(errMsg);
                    rmmigrateAdminUI.toast(errMsg, 'error');
                    return;
                }

                chunkRetries = 0;
                chunkIndex++;

                if (res && res.success && res.data && res.data.complete && res.data.job_id) {
                    stopVerifyInterval();
                    setImportProgress(
                        100,
                        t('importComplete', 'Archive verified & registered successfully. Completing import…'),
                        '100%'
                    );
                    redirectToDone(res.data.job_id);
                    return;
                }

                uploadChunk();

            }).fail(function (xhr) {
                stopVerifyInterval();
                if (chunkSize > 65536 && xhr && (xhr.status === 413 || xhr.status === 500 || xhr.status === 502 || xhr.status === 504 || xhr.status === 0)) {
                    var currentUploaded = start;
                    chunkSize = Math.max(65536, Math.floor(chunkSize / 2));
                    totalChunks = Math.ceil(file.size / chunkSize);
                    chunkIndex = Math.floor(currentUploaded / chunkSize);
                    chunkRetries = 0;
                    setTimeout(uploadChunk, 500);
                    return;
                }

                if (chunkRetries < maxChunkRetries) {
                    chunkRetries++;
                    setTimeout(uploadChunk, 1000 * chunkRetries);
                    return;
                }

                restoreUploadUI();
                status.text(t('importFailed', 'Import failed'));
                rmmigrateAdminUI.toast(t('importFailed', 'Import failed'), 'error');
            });
        }

        if (file.size <= chunkSize) {
            var form = new FormData();
            form.append('action', 'rmmigrate_import_local');
            form.append('nonce', rmmigrateAdmin.nonce);
            form.append('archive', file);
            form.append('archive_passphrase', archivePassphrase);

            stopVerifyInterval();
            setImportProgress(
                0,
                t('phaseUploading', 'Phase 1 of 2: Uploading archive') + ' — ' + file.name + ' (0 B / ' + formatBytes(file.size) + ')',
                '0% (0 B / ' + formatBytes(file.size) + ')'
            );

            $.ajax({
                url: rmmigrateAdmin.ajaxUrl,
                method: 'POST',
                data: form,
                processData: false,
                contentType: false,
                xhr: function () {
                    var xhr = $.ajaxSettings.xhr();
                    if (xhr.upload) {
                        xhr.upload.addEventListener('progress', function (e) {
                            if (e.lengthComputable && e.total > 0) {
                                var uploadPct = (e.loaded / e.total) * 90;
                                var headerMsg = t('phaseUploading', 'Phase 1 of 2: Uploading archive') + ' — ' + file.name + ' (' + formatBytes(e.loaded) + ' / ' + formatBytes(e.total) + ')';
                                var detailMsg = Math.round(uploadPct) + '% (' + formatBytes(e.loaded) + ' / ' + formatBytes(e.total) + ')';
                                setImportProgress(uploadPct, headerMsg, detailMsg);

                                if (e.loaded >= e.total) {
                                    stopVerifyInterval();
                                    var verifyHeader = t('phaseVerifying', 'Phase 2 of 2: Verifying & validating archive…') + ' — ' + file.name + ' (' + formatBytes(file.size) + ')';
                                    setImportProgress(
                                        Math.max(90, maxImportPercent),
                                        verifyHeader,
                                        '90% (' + t('verifyingStructure', 'Verifying archive integrity & structure…') + ')'
                                    );
                                    verifyInterval = setInterval(function () {
                                        if (maxImportPercent < 98) {
                                            var nextPct = maxImportPercent + 1;
                                            setImportProgress(
                                                nextPct,
                                                verifyHeader,
                                                Math.round(nextPct) + '% (' + t('verifyingStructure', 'Verifying archive integrity & structure…') + ')'
                                            );
                                        }
                                    }, 800);
                                }
                            }
                        }, false);
                    }
                    return xhr;
                }
            }).done(function (res) {
                if (res && res.success && res.data && res.data.job_id) {
                    stopVerifyInterval();
                    setImportProgress(100, t('importComplete', 'Archive verified & registered successfully. Completing import…'), '100%');
                    redirectToDone(res.data.job_id);
                } else if (res && res.data && res.data.downsize && chunkSize > 65536) {
                    stopVerifyInterval();
                    chunkSize = Math.max(65536, Math.floor(chunkSize / 2));
                    totalChunks = Math.ceil(file.size / chunkSize);
                    maxImportPercent = 0;
                    chunkIndex = 0;
                    uploadChunk();
                } else {
                    restoreUploadUI();
                    var errMsg = (res && res.data && res.data.message) ? res.data.message : t('importFailed', 'Import failed');
                    status.text(errMsg);
                    rmmigrateAdminUI.toast(errMsg, 'error');
                }
            }).fail(function (xhr) {
                stopVerifyInterval();
                var retryable = xhr && (xhr.status === 413 || xhr.status === 500 || xhr.status === 502 || xhr.status === 504 || xhr.status === 0);
                if (retryable && chunkSize > 65536) {
                    chunkSize = Math.max(65536, Math.floor(chunkSize / 2));
                    totalChunks = Math.ceil(file.size / chunkSize);
                    maxImportPercent = 0;
                    chunkIndex = 0;
                    uploadChunk();
                    return;
                }

                restoreUploadUI();
                var failMsg = rmmigrateAdminUI.ajaxErrorMessage
                    ? rmmigrateAdminUI.ajaxErrorMessage(xhr, t('importFailed', 'Import failed'))
                    : t('importFailed', 'Import failed');
                status.text(failMsg);
                rmmigrateAdminUI.toast(failMsg, 'error');
            });
            return;
        }

        uploadChunk();
    }



    $('#mm-import-local-file').on('change', function () {

        if (this.files[0]) {

            uploadLocalFile(this.files[0]);
            this.value = '';

        }

    });



    $('#mm-import-dropzone').on('click', function () {

        $('#mm-import-local-file').trigger('click');

    }).on('dragover dragenter', function (e) {

        e.preventDefault();

        $(this).addClass('is-dragover');

    }).on('dragleave drop', function (e) {

        e.preventDefault();

        $(this).removeClass('is-dragover');

        if (e.type === 'drop' && e.originalEvent.dataTransfer.files.length) {

            uploadLocalFile(e.originalEvent.dataTransfer.files[0]);

        }

    });



    $('#mm-import-browse').on('click', function (e) {

        e.stopPropagation();

    });

























    (function initMigrationGuide() {
        var $guide = $('.mm-migration-guide');
        if (!$guide.length) {
            return;
        }

        var step = 1;
        var total = 2;

        function showGuideStep(n) {
            step = Math.max(1, Math.min(total, n));
            $guide.find('.mm-migration-guide-steps li').each(function () {
                var current = parseInt($(this).data('guide-step'), 10);
                $(this).toggleClass('is-active', current === step);
                $(this).toggleClass('is-done', current < step);
            });
            $guide.find('.multisite-migrate-wizard-panel[data-guide-step]').each(function () {
                var current = parseInt($(this).data('guide-step'), 10);
                $(this).toggleClass('is-active', current === step);
            });
            $guide.find('.mm-migration-guide-back').toggleClass('mm-hidden', step <= 1);
            $guide.find('.mm-migration-guide-next').toggle(step < total);
        }

        $guide.find('.mm-migration-guide-steps li').on('click', function () {
            showGuideStep(parseInt($(this).data('guide-step'), 10));
        });

        $guide.find('.mm-migration-guide-next').on('click', function () {
            showGuideStep(step + 1);
        });

        $guide.find('.mm-migration-guide-back').on('click', function () {
            showGuideStep(step - 1);
        });

        showGuideStep(1);
    })();

    var IMPORT_JOB_KEY = 'mm_last_import_job_id';
    function persistImportJobId(id) {
        try {
            sessionStorage.setItem(IMPORT_JOB_KEY, String(id));
        } catch (e) {
            // Storage unavailable or quota exceeded; redirect should still proceed.
        }
    }

    $('#mm-toggle-import-settings').on('click', function () {
        $('#mm-import-settings-panel').toggleClass('mm-hidden');
        $(this).text($('#mm-import-settings-panel').hasClass('mm-hidden') ? t('showImportSettings', 'Show import settings') : t('hideImportSettings', 'Hide import settings'));
    });

})(jQuery);
