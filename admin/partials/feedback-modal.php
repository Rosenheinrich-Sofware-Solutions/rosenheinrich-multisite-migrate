<?php
if (!defined('ABSPATH')) {
    exit;
}
?>
<div id="mm-feedback-dialog" class="mm-hidden" role="dialog" aria-modal="true" aria-labelledby="mm-feedback-title" hidden>
    <div class="mm-restore-overlay mm-feedback-overlay">
        <div class="mm-restore-modal mm-feedback-modal" role="document">
            <div class="multisite-migrate-wizard-modal-head mm-modal-head">
                <h2 id="mm-feedback-title"><?php esc_html_e('Enjoying Multisite Migrate?', 'rosenheinrich-multisite-migrate'); ?></h2>
                <button type="button" class="mm-feedback-close button-link" aria-label="<?php esc_attr_e('Close', 'rosenheinrich-multisite-migrate'); ?>">&times;</button>
            </div>
            <div class="multisite-migrate-wizard-modal-body mm-modal-body">
                <div class="mm-feedback-review-cta">
                    <p class="mm-feedback-review-body">
                        <?php esc_html_e('A short WordPress.org review helps others find Multisite Migrate too.', 'rosenheinrich-multisite-migrate'); ?>
                    </p>
                </div>
                <div class="mm-feedback-sentiments-wrap mm-hidden">
                    <div class="mm-feedback-sentiments" role="group" aria-label="<?php esc_attr_e('How did that go?', 'rosenheinrich-multisite-migrate'); ?>">
                        <button type="button" class="button mm-feedback-sentiment" data-sentiment="positive" aria-pressed="false">
                            <span aria-hidden="true">😊</span>
                            <span class="mm-feedback-sentiment__label"><?php esc_html_e('Great', 'rosenheinrich-multisite-migrate'); ?></span>
                        </button>
                        <button type="button" class="button mm-feedback-sentiment" data-sentiment="neutral" aria-pressed="false">
                            <span aria-hidden="true">😐</span>
                            <span class="mm-feedback-sentiment__label"><?php esc_html_e('Okay', 'rosenheinrich-multisite-migrate'); ?></span>
                        </button>
                        <button type="button" class="button mm-feedback-sentiment" data-sentiment="negative" aria-pressed="false">
                            <span aria-hidden="true">😞</span>
                            <span class="mm-feedback-sentiment__label"><?php esc_html_e('Not great', 'rosenheinrich-multisite-migrate'); ?></span>
                        </button>
                    </div>
                    <div class="mm-feedback-details mm-hidden">
                        <p class="mm-feedback-attached-error description mm-hidden" aria-live="polite"></p>
                        <label for="mm-feedback-message" class="mm-feedback-message-label"><?php esc_html_e('What should we know?', 'rosenheinrich-multisite-migrate'); ?></label>
                        <textarea id="mm-feedback-message" class="large-text" rows="4" maxlength="1000"></textarea>
                        <p class="mm-feedback-error description mm-hidden" role="alert"></p>
                        <label class="mm-feedback-bug-label">
                            <input type="checkbox" id="mm-feedback-bug" value="1" />
                            <?php esc_html_e('This is a bug', 'rosenheinrich-multisite-migrate'); ?>
                        </label>
                        <p class="description mm-feedback-consent"></p>
                    </div>
                </div>
            </div>
            <div class="multisite-migrate-wizard-modal-footer mm-modal-footer mm-feedback-footer">
                <button type="button" class="button mm-feedback-later"><?php esc_html_e('Maybe later', 'rosenheinrich-multisite-migrate'); ?></button>
                <a id="mm-feedback-review-link" class="button button-primary mm-btn-teal mm-feedback-review-cta-btn" href="<?php echo esc_url(class_exists('Rmmigrate_Review_Nudge', false) ? Rmmigrate_Review_Nudge::review_url() : 'https://wordpress.org/support/plugin/rosenheinrich-multisite-migrate/reviews/#new-post'); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Leave a review', 'rosenheinrich-multisite-migrate'); ?></a>
                <button type="button" class="button button-primary mm-btn-teal mm-feedback-submit mm-hidden" disabled><?php esc_html_e('Send feedback', 'rosenheinrich-multisite-migrate'); ?></button>
            </div>
        </div>
    </div>
</div>
