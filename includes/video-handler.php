<?php

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

// Add REST API endpoint to check ACF field values
add_action('rest_api_init', function () {
    register_rest_route('moonly-cdn/v1', '/check-domains', array(
        'methods' => 'GET',
        'callback' => 'check_acf_domains',
        'permission_callback' => '__return_true'
    ));
});

function check_acf_domains() {
    // Get the repeater field value
    $allowed_domains = get_field('global__allowed_domains', 'option');
    
    // Debug the structure
    error_log('Moonly CDN: ACF Field Structure: ' . print_r($allowed_domains, true));
    
    // If we have domains, extract just the domain names
    $domain_names = array();
    if (is_array($allowed_domains)) {
        foreach ($allowed_domains as $domain) {
            if (isset($domain['domain_name'])) {
                $domain_names[] = $domain['domain_name'];
            }
        }
    }
    
    return array(
        'raw_field_value' => $allowed_domains,
        'extracted_domains' => $domain_names,
        'field_exists' => !empty($allowed_domains)
    );
}

// Add JavaScript to log domain information
add_action('admin_footer', 'add_domain_debug_script');
function add_domain_debug_script() {
    ?>
    <script>
    console.log('Moonly CDN Debug Info:', {
        currentDomain: window.location.hostname,
        allowedDomains: <?php 
            if (function_exists('get_field')) {
                $allowed_domains = get_field('global__allowed_domains', 'option');
                error_log('Moonly CDN: ACF Field Value (Debug Script): ' . print_r($allowed_domains, true));
                echo json_encode($allowed_domains);
            } else {
                error_log('Moonly CDN: ACF is not active in debug script');
                echo '[]';
            }
        ?>,
        rawOptions: <?php
            $raw_options = get_option('options_global__allowed_domains');
            error_log('Moonly CDN: Raw Options Value: ' . print_r($raw_options, true));
            echo json_encode($raw_options);
        ?>,
        fieldGroup: <?php
            if (function_exists('acf_get_field_group')) {
                $field_group = acf_get_field_group('group_67e6ec5c1aaa9');
                echo json_encode($field_group);
            } else {
                echo 'null';
            }
        ?>
    });
    </script>
    <?php
}

// Function to check if plugin is active
function is_moonly_cdn_active() {
    return is_plugin_active('moonly-cdn/moonly-cdn.php');
}

// Function to check if domain is allowed
function is_domain_allowed($domain) {
    // Check if ACF is active
    if (!function_exists('get_field')) {
        error_log('Moonly CDN: ACF is not active');
        return false;
    }

    // Make a direct request to moonlycdn.com to check domain
    $check_url = 'https://moonlycdn.com/wp-json/cdn/v1/check-domain';
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $check_url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'X-API-KEY: 9mMUnMBspcEfFXqz6hMh8AxEByEE4ChB',
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'domain' => $domain
    ]));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code !== 200) {
        error_log('Moonly CDN: Failed to check domain against moonlycdn.com');
        return false;
    }

    $response_data = json_decode($response, true);
    if (!$response_data) {
        error_log('Moonly CDN: Invalid response from moonlycdn.com');
        return false;
    }

    return isset($response_data['allowed']) && $response_data['allowed'] === true;
}

// Function to check video access
function is_video_access_allowed() {
    // Check if plugin is active
    if (!is_moonly_cdn_active()) {
        error_log('Moonly CDN: Plugin is not active');
        return false; 
    }

    // Get current domain
    $client_domain_raw = parse_url(get_site_url(), PHP_URL_HOST);
    if (function_exists('idn_to_utf8')) {
        $client_domain_raw = idn_to_utf8($client_domain_raw, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
    }

    // Make a request to CDN to check access
    $check_url = 'https://moonlycdn.com/wp-json/cdn/v1/check-video-access';
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $check_url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'X-API-KEY: 9mMUnMBspcEfFXqz6hMh8AxEByEE4ChB',
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'client_domain' => $client_domain_raw
    ]));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code !== 200) {
        error_log('Moonly CDN: Failed to check video access');
        return false;
    }

    $response_data = json_decode($response, true);
    if (!$response_data) {
        error_log('Moonly CDN: Invalid response from CDN');
        return false;
    }

    return isset($response_data['allowed']) && $response_data['allowed'] === true;
}

// Automatically upload video files to CDN Hub, delete locally, and update attachment URLs
add_filter('wp_handle_upload', 'auto_upload_video_to_cdn_hub');

function auto_upload_video_to_cdn_hub($upload) {
    try {
        if (!isset($upload['file'])) {
            return $upload;
        }

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

        if (!file_exists($upload['file'])) {
            return new WP_Error('upload_error', 'File not found.');
        }

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
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        
        $response = curl_exec($ch);
        
        if (curl_errno($ch)) {
            curl_close($ch);
            return new WP_Error('upload_error', 'Failed to upload to CDN: ' . curl_error($ch));
        }
        
        curl_close($ch);

        $response_body = json_decode($response, true);

        if (isset($response_body['url'])) {
            unlink($upload['file']); // Remove local copy after upload

            // Update URL to point to CDN Hub video
            $upload['url'] = $response_body['url'];

            // Temporarily save CDN URL and file size
            update_option('latest_cdn_uploaded_video_url', $response_body['url']);
            update_option('latest_cdn_uploaded_video_size', $file_size);
        } else {
            return new WP_Error('upload_error', 'Failed to get CDN URL from response.');
        }

        return $upload;
    } catch (Exception $e) {
        return new WP_Error('upload_error', 'An error occurred during upload: ' . $e->getMessage());
    }
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
    
    // Only check access for CDN URLs
    if (strpos($attached_file, 'https://') === 0) {
        // If access is not allowed, return a placeholder URL
        if (!is_video_access_allowed()) {
            return plugins_url('assets/video-disabled.mp4', dirname(__FILE__));
        }
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

// Add server-side protection for direct video access
add_action('template_redirect', 'protect_cdn_video_access');
function protect_cdn_video_access() {
    // Only check if the request is for a video file
    if (!isset($_SERVER['REQUEST_URI'])) {
        return;
    }

    $request_uri = $_SERVER['REQUEST_URI'];
    if (strpos($request_uri, '.mp4') === false && strpos($request_uri, '.webm') === false) {
        return;
    }

    // Check if the request is for a CDN video
    if (strpos($request_uri, 'moonlycdn.com') === false) {
        return;
    }

    // Check if access is allowed
    if (!is_video_access_allowed()) {
        header('HTTP/1.0 403 Forbidden');
        header('Content-Type: text/plain');
        echo 'Access to this video has been disabled.';
        exit;
    }
}

// Add JavaScript to check access on video elements
add_action('wp_footer', 'add_video_access_check_script');
function add_video_access_check_script() {
    ?>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Function to check video access
        function checkVideoAccess() {
            return fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=check_video_access'
            })
            .then(response => response.json())
            .then(data => data.allowed);
        }

        // Function to handle video elements
        function handleVideoElements() {
            document.querySelectorAll('video').forEach(function(video) {
                // If video source is from CDN
                if (video.src && video.src.includes('moonlycdn.com')) {
                    // Check access before allowing playback
                    checkVideoAccess().then(function(allowed) {
                        if (!allowed) {
                            // Stop playback
                            video.pause();
                            video.currentTime = 0;
                            
                            // Show error message
                            const errorDiv = document.createElement('div');
                            errorDiv.style.cssText = 'position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: rgba(0,0,0,0.8); color: white; padding: 20px; border-radius: 5px; text-align: center; z-index: 1000;';
                            errorDiv.innerHTML = 'Video access has been disabled. Please contact your administrator.';
                            
                            // Add error message to video container
                            video.parentNode.style.position = 'relative';
                            video.parentNode.appendChild(errorDiv);
                        }
                    });
                }
            });
        }

        // Initial check
        handleVideoElements();

        // Check every 30 seconds
        setInterval(handleVideoElements, 30000);

        // Check when video starts playing
        document.addEventListener('play', function(e) {
            if (e.target.tagName === 'VIDEO' && e.target.src.includes('moonlycdn.com')) {
                checkVideoAccess().then(function(allowed) {
                    if (!allowed) {
                        e.target.pause();
                        e.target.currentTime = 0;
                    }
                });
            }
        }, true);

        // Check when video source changes
        const observer = new MutationObserver(function(mutations) {
            mutations.forEach(function(mutation) {
                if (mutation.type === 'attributes' && mutation.attributeName === 'src') {
                    const video = mutation.target;
                    if (video.src && video.src.includes('moonlycdn.com')) {
                        checkVideoAccess().then(function(allowed) {
                            if (!allowed) {
                                video.pause();
                                video.currentTime = 0;
                            }
                        });
                    }
                }
            });
        });

        // Observe all video elements for source changes
        document.querySelectorAll('video').forEach(function(video) {
            observer.observe(video, {
                attributes: true,
                attributeFilter: ['src']
            });
        });
    });
    </script>
    <?php
}

// Add AJAX endpoint for video access check
add_action('wp_ajax_check_video_access', 'ajax_check_video_access');
add_action('wp_ajax_nopriv_check_video_access', 'ajax_check_video_access');
function ajax_check_video_access() {
    wp_send_json([
        'allowed' => is_video_access_allowed()
    ]);
} 
