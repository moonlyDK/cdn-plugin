<?php

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

// Function to check if domain is allowed
function is_domain_allowed($domain) {
    // Check if ACF is active
    if (!function_exists('get_field')) {
        return false;
    }

    // Get allowed domains from ACF options page
    $allowed_domains = get_field('global__allowed_domains', 'domains_acf_op');
    
    if (!$allowed_domains) {
        return false;
    }

    // Clean the input domain
    $domain = strtolower(trim($domain));

    // Check if domain exists in allowed domains
    foreach ($allowed_domains as $allowed) {
        if (isset($allowed['domain_name'])) {
            $allowed_domain = strtolower(trim($allowed['domain_name']));
            if ($domain === $allowed_domain) {
                return true;
            }
        }
    }

    return false;
}

// Automatically upload video files to CDN Hub, delete locally, and update attachment URLs
add_filter('wp_handle_upload', 'auto_upload_video_to_cdn_hub');

function auto_upload_video_to_cdn_hub($upload) {
    $filetype = wp_check_filetype($upload['file']);
    $video_types = ['mp4', 'webm'];

    if (!in_array(strtolower($filetype['ext']), $video_types)) {
        return $upload; // Skip if not video
    }

    // Get domain and convert from punycode to UTF-8 if needed
    $client_domain_raw = parse_url(get_site_url(), PHP_URL_HOST);
    if (function_exists('idn_to_utf8')) {
        $client_domain_raw = idn_to_utf8($client_domain_raw, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
    }

    // Check if domain is allowed
    if (!is_domain_allowed($client_domain_raw)) {
        return new WP_Error('upload_error', 'This domain is not authorized to upload videos to the CDN.');
    }

    // Set maximum allowed file size (e.g., 500MB)
    $max_file_size = 1024 * 1024 * 1024; // 500 MB in bytes

    $file_size = filesize($upload['file']);
    if ($file_size > $max_file_size) {
        unlink($upload['file']); // Delete file as it exceeds max size
        return new WP_Error('upload_error', 'Video is too large. Maximum allowed size is 500MB.');
    }

    // CDN Hub REST endpoint
    $cdn_api_url = 'https://moonlycdn.com/wp-json/cdn/v1/upload-video';

    // Replace Danish special characters
    $special_chars = ['æ', 'ø', 'å', 'Æ', 'Ø', 'Å'];
    $replacements = ['ae', 'oe', 'aa', 'ae', 'oe', 'aa'];

    $client_domain_converted = str_replace($special_chars, $replacements, $client_domain_raw);

    // Replace dots with dashes
    $client_domain = str_replace('.', '-', sanitize_file_name($client_domain_converted));

    // Prepare video file for upload
    $video_file = curl_file_create($upload['file'], $filetype['type'], basename($upload['file']));

    // Initiate cURL upload to CDN Hub
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $cdn_api_url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'X-API-KEY: 9mMUnMBspcEfFXqz6hMh8AxEByEE4ChB'
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, [
        'video' => $video_file,
        'client_name' => $client_domain,
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);

    $response_body = json_decode($response, true);

    if (isset($response_body['url'])) {
        unlink($upload['file']); // Remove local copy after upload

        // Update URL to point to CDN Hub video
        $upload['url'] = $response_body['url'];

        // Temporarily save CDN URL and file size
        update_option('latest_cdn_uploaded_video_url', $response_body['url']);
        update_option('latest_cdn_uploaded_video_size', $file_size);
    }

    return $upload;
}

// After attachment is created, update URL and file size
add_action('add_attachment', 'update_attachment_metadata_to_cdn');

function update_attachment_metadata_to_cdn($attachment_id) {
    $cdn_url = get_option('latest_cdn_uploaded_video_url');
    $cdn_file_size = get_option('latest_cdn_uploaded_video_size');

    if ($cdn_url) {
        update_post_meta($attachment_id, '_wp_attached_file', $cdn_url);

        if ($cdn_file_size) {
            update_post_meta($attachment_id, '_filesize', $cdn_file_size);
        }

        // Cleanup temporary options
        delete_option('latest_cdn_uploaded_video_url');
        delete_option('latest_cdn_uploaded_video_size');
    }
}

// Ensure CDN URLs and correct file sizes are used
add_filter('wp_get_attachment_url', 'use_cdn_url_for_attachments', 10, 2);
add_filter('wp_prepare_attachment_for_js', 'fix_media_library_file_size', 10, 3);

function use_cdn_url_for_attachments($url, $attachment_id) {
    $attached_file = get_post_meta($attachment_id, '_wp_attached_file', true);
    if (strpos($attached_file, 'https://') === 0) {
        return $attached_file;
    }
    return $url;
}

// Display correct file size in media library
function fix_media_library_file_size($response, $attachment, $meta) {
    $filesize = get_post_meta($attachment->ID, '_filesize', true);
    if ($filesize) {
        $response['filesizeHumanReadable'] = size_format($filesize);
        $response['filesizeInBytes'] = $filesize;
    }
    return $response;
}

// Trigger deletion from CDN when attachment is deleted locally
add_action('delete_attachment', 'delete_cdn_video_on_attachment_delete', 10, 1);

function delete_cdn_video_on_attachment_delete($attachment_id) {
    $cdn_url = wp_get_attachment_url($attachment_id);

    // Check if CDN URL is valid and from CDN domain
    if (strpos($cdn_url, 'moonlycdn.com') === false) {
        return;
    }

    $cdn_delete_endpoint = 'https://moonlycdn.com/wp-json/cdn/v1/delete-video';

    // Initiate cURL request to delete video on CDN Hub
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $cdn_delete_endpoint);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'X-API-KEY: 9mMUnMBspcEfFXqz6hMh8AxEByEE4ChB'
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, [
        'video_url' => $cdn_url
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_exec($ch);
    curl_close($ch);
} 