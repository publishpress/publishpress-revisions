<?php
defined('ABSPATH') || exit;

function _rvy_mail_send_limits() {
	$default_minute_limit = (defined('REVISIONARY_EMAIL_LIMIT_MINUTE')) ? REVISIONARY_EMAIL_LIMIT_MINUTE : 20;
	$default_hour_limit = (defined('REVISIONARY_EMAIL_LIMIT_HOUR')) ? REVISIONARY_EMAIL_LIMIT_HOUR : 100;
	$default_day_limit = (defined('REVISIONARY_EMAIL_LIMIT_DAY')) ? REVISIONARY_EMAIL_LIMIT_DAY : 1000;

	$send_limits = apply_filters(
		'revisionary_email_limits', 
		[
			'minute' => $default_minute_limit,
			'hour' => $default_hour_limit,
			'day' => $default_day_limit,
		]
	);

	return $send_limits;
}

function _rvy_mail_check_buffer($new_msg = [], $args = []) {
    return _rvy_mail_with_lock(static function () use ($new_msg, $args) {
        return _rvy_mail_check_buffer_locked($new_msg, $args);
    });
}

function _rvy_mail_with_lock($callback) {
    global $wpdb;
    $key = 'rvy-mail-' . md5(DB_NAME . ':' . $wpdb->prefix);
    if ('1' !== (string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 5)', $key))) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Connection-specific database advisory lock operation must execute immediately and cannot be cached.
        // Let the caller send directly; never silently discard a message on lock failure.
        do_action('revisionary_mail_buffer_error', 'lock_unavailable');
        return false;
    }
    try { return $callback(); }
    finally { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $key)); } // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Connection-specific database advisory lock operation must execute immediately and cannot be cached.
}

/** Preserve concurrent history entries when advisory locking is unavailable. */
function _rvy_mail_append_history($message) {
    global $wpdb;
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $stored = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM $wpdb->options WHERE option_name = %s", 'revisionary_sent_mail')); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        if (null === $stored) {
            if (add_option('revisionary_sent_mail', [$message], '', false)) { return true; }
            continue;
        }
        $history = is_serialized($stored) ? @unserialize($stored, ['allowed_classes' => false]) : []; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- Object instantiation is disabled for stored data.
        $history = is_array($history) ? $history : [];
        $oldest = time() - max(172800, (int) apply_filters('revisionary_mail_log_duration', 86400 * 30));
        $history = array_filter($history, static function ($entry) use ($oldest) {
            return !isset($entry['time_gmt']) || $entry['time_gmt'] >= $oldest;
        });
        $history[] = $message;
        // Conditional update provides atomic compare-and-swap when advisory locks are unavailable.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Required for this compare-and-swap write.
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE $wpdb->options SET option_value = %s WHERE option_name = %s AND MD5(option_value) = %s",
            maybe_serialize($history), 'revisionary_sent_mail', md5($stored)
        ));
        if (1 === $updated) {
            wp_cache_delete('revisionary_sent_mail', 'options');
            wp_cache_delete('alloptions', 'options');
            wp_cache_delete('notoptions', 'options');
            return true;
        }
    }
    do_action('revisionary_mail_buffer_error', 'history_update_failed');
    return false;
}

function _rvy_mail_check_buffer_locked($new_msg = [], $args = []) {
	global $wpdb;
	
    wp_cache_delete('alloptions', 'options');
    wp_cache_delete('notoptions', 'options');
	$log_only = !empty($args['log_only']);
	
	if (!$log_only) {
		wp_cache_delete('revisionary_mail_buffer', 'options');
		
        $buffer = get_option('revisionary_mail_buffer', []);
        $buffer = is_array($buffer) ? $buffer : [];
        $first_buffer = !get_option('revisionary_mail_buffer', false);
	}

	$new_msg_buffered = false;

	wp_cache_delete('revisionary_sent_mail', 'options');
	
	if (!$sent_mail = get_option('revisionary_sent_mail')) {
		$sent_mail = [];
		$first_mail_log = true;
	}

	$current_time = time();

	// check sending limits
	$durations = ['minute' => 60, 'hour' => 3600, 'day' => 86400];
	$sent_counts = ['minute' => 0, 'hour' => 0, 'day' => 0];
	
	// by default, purge mail log entries older than 30 days
	// @todo: purge mail log even when buffer is disabled
	$purge_time = apply_filters('revisionary_mail_log_duration', 86400 * 30);
	
	if ($purge_time < $durations['day'] * 2) {
		$purge_time = $durations['day'] * 2;
	}

	$send_limits = _rvy_mail_send_limits();

	foreach($sent_mail as $k => $mail) {
		if (!isset($mail['time_gmt'])) {
			continue;
		}

		$elapsed = $current_time - $mail['time_gmt'];

		foreach($durations as $limit_key => $duration) {
			if ($elapsed < $duration) {
				$sent_counts[$limit_key]++;
			}

			if ($new_msg && ($sent_counts[$limit_key] >= $send_limits[$limit_key])) {
				$new_msg_buffered = true;
			}
		}
		
		if ($elapsed > $purge_time) {
			unset($sent_mail[$k]);
			$purged = true;
		}
	}

	if (!$log_only && $new_msg_buffered) {
        if (count($buffer) >= 1000) {
            do_action('revisionary_mail_buffer_error', 'queue_full');
            $new_msg_buffered = false; // Caller sends directly instead of losing the notice.
        } else {
		$buffer = array_merge([$new_msg], $buffer);
        }
		update_option('revisionary_mail_buffer', $buffer);
	}
    if (!isset($buffer)) $buffer = [];

    // Persist pruning with the next atomic append, never a stale snapshot.

	if (!empty($first_mail_log) && $sent_mail) {
		$wpdb->query("UPDATE $wpdb->options SET autoload = 'no' WHERE option_name = 'revisionary_sent_mail'");			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	if (!empty($first_buffer) && $buffer) {
		$wpdb->query("UPDATE $wpdb->options SET autoload = 'no' WHERE option_name = 'revisionary_mail_buffer'");		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	return (object) compact('buffer', 'sent_mail', 'send_limits', 'sent_counts', 'new_msg_buffered');
}

// called by WP-cron hook
function _rvy_send_buffered_mail() {
    return _rvy_mail_with_lock('_rvy_send_buffered_mail_locked');
}

function _rvy_send_buffered_mail_locked() {
    $buffer_status = _rvy_mail_check_buffer_locked();

	if (empty($buffer_status->buffer)) {
		return false;
	}

	$q = $buffer_status->buffer;

	$attempts = 0;
	while ($q && $attempts++ < 20) {
		foreach($buffer_status->sent_counts as $limit_key => $count) {
			$buffer_status->sent_counts[$limit_key]++;

			if ($count >= $buffer_status->send_limits[$limit_key]) {
				// A send limit has been reached
				break 2;
			}
		}

		$next_mail = array_pop($q);

        // The drain lock prevents a second worker from sending the same queue.
        // Remove only after delivery so a failed send stays retryable.

		// If buffered notification is missing vital data, discard it
		if (empty($next_mail['address']) || empty($next_mail['title']) || empty($next_mail['message']) || empty($next_mail['time_gmt'])) {
            update_option('revisionary_mail_buffer', $q);
			continue;
		}

		// If notification was buffered more than a week ago, discard it
		if (time() - $next_mail['time_gmt'] > 3600 * 24 * 7 ) {
            do_action('revisionary_mail_buffer_error', 'expired');
            update_option('revisionary_mail_buffer', $q);
			continue;
		}

		if (defined('RS_DEBUG')) {
			$success = wp_mail($next_mail['address'], $next_mail['title'], $next_mail['message'], $next_mail['headers'] ?? []);	// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_mail_wp_mail
		} else {
			$success = @wp_mail($next_mail['address'], $next_mail['title'], $next_mail['message'], $next_mail['headers'] ?? []);	// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_mail_wp_mail
		}

		if (!$success) {
			// message was not sent successfully, so put it back in the buffer
			if ($q) {
				$q = array_merge([$next_mail], $q);
			} else {
				$q = [$next_mail];
			}
            update_option('revisionary_mail_buffer', $q);
            do_action('revisionary_mail_buffer_error', 'delivery_failed');
            break;
		} else {
            update_option('revisionary_mail_buffer', $q);
			// log the sent mail
			$next_mail['time'] = strtotime(current_time( 'mysql' ));
			$next_mail['time_gmt'] = time();
			$next_mail['success'] = intval(boolval($success));

			if (!defined('RS_DEBUG') && !defined('REVISIONARY_LOG_EMAIL_MESSAGE')) {
				unset($next_mail['message']);
			}

			$buffer_status->sent_mail[]= $next_mail;
			_rvy_mail_append_history($next_mail);
		}
	}
}
