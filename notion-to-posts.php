<?php
/**
 * Plugin Name: Notion to Native Blocks Importer
 * Description: Converts Notion ZIPs into clean native blocks. Safely strips duplicate macOS metadata files.
 * Version: 6.0
 * Author: Coding Partner
 */

if (!defined('ABSPATH')) exit;

/**
 * 1. ADMIN ACTION ROUTER
 */
add_action('admin_post_execute_notion_import', 'ntp_intercept_form_submission');
function ntp_intercept_form_submission() {
    if (!isset($_POST['ntp_nonce']) || !wp_verify_nonce($_POST['ntp_nonce'], 'ntp_upload_action')) {
        wp_die('Security Check Failed: Nonce verification error.');
    }

    if (!current_user_can('manage_options')) {
        wp_die('Permissions Error: Insufficient user privileges.');
    }

    if (!isset($_FILES['notion_zip']) || $_FILES['notion_zip']['error'] !== UPLOAD_ERR_OK) {
        wp_die('Local Server File Block: File upload failed.');
    }

    ntp_handle_upload();
}

/**
 * 2. ADMIN MENU SETUP & STYLING
 */
add_action('admin_menu', 'ntp_add_admin_menu');
function ntp_add_admin_menu() {
    add_menu_page('Notion Importer', 'Notion Importer', 'manage_options', 'notion-importer', 'ntp_plugin_page', 'dashicons-import');
}

function ntp_plugin_page() {
    $max_upload = ini_get('upload_max_filesize');
    
    if (get_transient('ntp_success_message')) {
        echo '<div class="notice notice-success is-dismissible" style="margin-top:20px;"><p>' . esc_html(get_transient('ntp_success_message')) . '</p></div>';
        delete_transient('ntp_success_message');
    }
    ?>
    <div class="wrap notion-importer-wrap" style="max-width: 850px; margin-top: 20px;">
        <h1 class="wp-heading-inline" style="font-weight: 700; font-size: 28px; margin-bottom: 20px;">Notion Content Migration Engine</h1>
        <hr class="wp-header-end">

        <div style="background: #fff; border-left: 4px solid #72aee6; box-shadow: 0 1px 3px rgba(0,0,0,0.1); padding: 15px 20px; border-radius: 4px; margin: 20px 0 30px 0; display: flex; align-items: center; justify-content: space-between;">
            <div>
                <span class="dashicons dashicons-performance" style="color: #72aee6; margin-right: 8px; vertical-align: text-bottom;"></span>
                <strong>System Engine Status:</strong> Ready to process workspace exports.
            </div>
            <code style="background: #f0f0f1; padding: 4px 8px; border-radius: 3px;">Max ZIP Limit: <?php echo $max_upload; ?></code>
        </div>

        <div class="notion-grid" style="display: grid; grid-template-columns: 1fr; gap: 20px;">
            <div class="notion-card" style="background: #fff; border: 1px solid #dcdcde; border-radius: 4px; box-shadow: 0 1px 2px rgba(0,0,0,0.05); padding: 25px;">
                <h2 style="font-size: 18px; font-weight: 600; margin-top: 0; margin-bottom: 15px; padding-bottom: 10px; border-bottom: 1px solid #f0f0f1;">
                    <span class="dashicons dashicons-cloud-upload" style="margin-right: 6px; vertical-align: text-bottom;"></span> Upload Archive
                </h2>
                
                <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" enctype="multipart/form-data" id="notion-import-form">
                    <input type="hidden" name="action" value="execute_notion_import">
                    <?php wp_nonce_field('ntp_upload_action', 'ntp_nonce'); ?>
                    
                    <p style="color: #646970; margin-bottom: 20px;">Select your exported Notion database or workspace ZIP archive file below to compile directly into native WordPress Gutenberg blocks.</p>
                    
                    <div style="background: #f8f9fa; border: 2px dashed #c3c4c7; padding: 30px; text-align: center; border-radius: 4px; margin-bottom: 25px;">
                        <input type="file" name="notion_zip" accept=".zip" required style="font-size: 14px;">
                    </div>

                    <h3 style="font-size: 14px; font-weight: 600; color: #3c434a; margin-bottom: 12px;">Active Pipeline Features:</h3>
                    <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 10px; margin-bottom: 25px; background: #f0f6fc; padding: 15px; border-radius: 4px; border: 1px solid #c8d7e1;">
                        <div><span class="dashicons dashicons-yes" style="color: #46b450; vertical-align: text-bottom;"></span> Gutenberg Block Parsing</div>
                        <div><span class="dashicons dashicons-yes" style="color: #46b450; vertical-align: text-bottom;"></span> Video/Media Sideloading</div>
                        <div><span class="dashicons dashicons-yes" style="color: #46b450; vertical-align: text-bottom;"></span> Cover Image Thumbnail Setter</div>
                        <div><span class="dashicons dashicons-yes" style="color: #46b450; vertical-align: text-bottom;"></span> Contextual Tag Matrix Map</div>
                    </div>

                    <div style="display: flex; align-items: center; gap: 15px;">
                        <input type="submit" name="ntp_submit" id="submit-btn" class="button button-primary button-large" value="Execute Block Compilation" style="height: 40px; padding: 0 20px; font-weight: 600;">
                        
                        <div id="notion-spinner" style="display: none; align-items: center; gap: 8px; color: #2271b1; font-weight: 500;">
                            <span class="spinner is-active" style="float: none; margin: 0;"></span> Compiling components... Please hold.
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        document.getElementById('notion-import-form').addEventListener('submit', function() {
            document.getElementById('submit-btn').setAttribute('disabled', 'disabled');
            document.getElementById('notion-spinner').style.display = 'inline-flex';
        });
    </script>
    <?php
}

/**
 * 3. ZIP EXTRACTION ROUTINE
 */
function ntp_handle_upload() {
    $upload_dir = wp_upload_dir();
    $unzip_dir = $upload_dir['basedir'] . '/notion_temp_' . time();

    if (!file_exists($unzip_dir)) wp_mkdir_p($unzip_dir);

    $zip = new ZipArchive;
    $res = $zip->open($_FILES['notion_zip']['tmp_name']);
    if ($res === TRUE) {
        $zip->extractTo($unzip_dir);
        $zip->close();
    } else {
        wp_die('Extraction System Failure. Error Code: ' . $res);
    }

    $html_files_to_process = [];
    $directory_queue = [$unzip_dir];

    while (!empty($directory_queue)) {
        $current_dir = array_shift($directory_queue);
        $items = scandir($current_dir);
        if ($items === false) continue;

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            
            // CRITICAL SYSTEM FILE FILTER: Skip macOS hidden structural metadata files completely
            if (strpos($item, '._') === 0) continue;

            $full_path = $current_dir . '/' . $item;

            if (is_dir($full_path)) {
                $directory_queue[] = $full_path;
            } elseif (is_file($full_path) && pathinfo($full_path, PATHINFO_EXTENSION) === 'html') {
                $filename_only = strtolower($item);
                if (in_array($filename_only, ['index.html', 'sitemap.html']) || (strpos($filename_only, 'tasks') !== false && !strpos($filename_only, 'navigation'))) {
                    continue;
                }
                $html_files_to_process[] = $full_path;
            }
        }
    }

    if (empty($html_files_to_process)) wp_die("Diagnostic Check Notice: 0 HTML files found.");

    $count = 0;
    foreach ($html_files_to_process as $file_path) {
        ntp_process_to_clean_blocks($file_path);
        $count++;
    }
    
    set_transient('ntp_success_message', "Pipeline Complete! Successfully synthesized $count native block documents into Drafts.");
    wp_redirect(admin_url('admin.php?page=notion-importer'));
    exit;
}

/**
 * 4. THE GLOBAL LEVEL PERSISTENT BLOCK GENERATOR
 */
function ntp_process_to_clean_blocks($file_path) {
    $html_content = file_get_contents($file_path);
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML(mb_convert_encoding($html_content, 'HTML-ENTITIES', 'UTF-8'));
    libxml_clear_errors();

    $xpath = new DOMXPath($dom);
    
    // 1. Title Normalization
    $title_node = $dom->getElementsByTagName('title')->item(0);
    $raw_title = $title_node ? $title_node->nodeValue : basename($file_path, '.html');
    $clean_title = trim(preg_replace('/[a-f0-9]{32}/i', '', $raw_title));

    // 2. Erase the Properties Table upfront
    $properties_tables = $xpath->query('//table[contains(@class, "properties")]');
    foreach ($properties_tables as $tbl) {
        $tbl->parentNode->removeChild($tbl);
    }

    // 3. Absolute Text Content Extract for Taxonomy Scanning
    $full_body_text = strtolower($dom->textContent);
    
    $tags = [];
    $categories = ['Specific'];
    
    if (strpos($full_body_text, 'flexbox') !== false || strpos($full_body_text, 'sidebar layout') !== false || strpos($full_body_text, 'card') !== false || strpos($full_body_text, 'clip-path') !== false) {
        $tags[] = 'Flexbox';
    }
    if (strpos($full_body_text, 'php') !== false || strpos($full_body_text, 'syntax') !== false || strpos($full_body_text, 'variables') !== false || strpos($full_body_text, 'conditionals') !== false || strpos($full_body_text, 'loops') !== false || strpos($full_body_text, 'arrays') !== false || strpos($full_body_text, 'functions') !== false || strpos($full_body_text, 'sql') !== false || strpos($full_body_text, 'superglobals') !== false) {
        $tags[] = 'PHP';
    }
    if (strpos($full_body_text, 'animation') !== false || strpos($full_body_text, 'animated') !== false || strpos($full_body_text, 'hover') !== false || strpos($full_body_text, 'transition') !== false) {
        $tags[] = 'Advanced Animation';
    }
    if (strpos($full_body_text, 'popover') !== false) {
        $tags[] = 'Popover API';
    }
    if (strpos($full_body_text, 'nav') !== false || strpos($full_body_text, 'navigation') !== false || strpos($full_body_text, 'menu') !== false || strpos($full_body_text, 'sliding nav') !== false) {
        $tags[] = 'Navigation';
    }

    // 4. Global Media Processing
    $media_elements = $xpath->query('//img | //video | //div[@class="source"]/a');
    $featured_image_id = null;

    foreach ($media_elements as $media_node) {
        $src = $media_node->getAttribute('src') ?: $media_node->getAttribute('href');
        if (!empty($src)) {
            $media_data = ntp_sideload_media($src, $file_path);
            if ($media_data) {
                if ($media_node->nodeName === 'img' && $featured_image_id === null) {
                    $featured_image_id = $media_data['id'];
                }
                if ($media_node->nodeName === 'img') {
                    $media_node->setAttribute('src', $media_data['url']);
                } else {
                    $media_node->setAttribute('href', $media_data['url']);
                }
            }
        }
    }

    // 5. Global Search Collection
    $content_blocks = $xpath->query('//body//h1 | //body//h2 | //body//h3 | //body//h4 | //body//p | //body//ul | //body//ol | //body//blockquote | //body//pre | //body//figure');
    
    $block_output = '';
    $first_h1_ignored = false;

    foreach ($content_blocks as $block) {
        if ($xpath->query('ancestor::h1 | ancestor::h2 | ancestor::h3 | ancestor::h4 | ancestor::p | ancestor::ul | ancestor::ol | ancestor::blockquote | ancestor::pre', $block)->length > 0) {
            continue;
        }

        $tag = strtolower($block->nodeName);
        if ($tag === 'h1' && !$first_h1_ignored && trim($block->textContent) === $raw_title) {
            $first_h1_ignored = true;
            continue;
        }

        $inner_html = '';
        foreach ($block->childNodes as $child) { $inner_html .= $dom->saveHTML($child); }

        $clean_inner = preg_replace("/ (class|style|id|dir|data-[a-z0-9-]+)=\"[^\"]*\"| (class|style|id|dir|data-[a-z0-9-]+)='[^']*'/i", "", $inner_html);
        $clean_inner = preg_replace('/<span[^>]*>|<\/span>/i', '', $clean_inner);
        $clean_inner = str_replace(array("\r", "\n", "\t"), ' ', $clean_inner);
        $clean_inner = preg_replace('/\s+/', ' ', $clean_inner);
        $clean_inner = trim($clean_inner);

        if (empty($clean_inner) && !in_array($tag, ['pre', 'figure'])) continue;

        switch ($tag) {
            case 'h1': case 'h2': case 'h3': case 'h4':
                $level = substr($tag, 1);
                $block_output .= "\n<h$level>" . strip_tags($clean_inner, '<b><i><strong><em><a><code>') . "</h$level>\n\n";
                break;
            case 'blockquote':
                $block_output .= "\n<blockquote class=\"wp-block-quote\"><p>" . strip_tags($clean_inner, '<b><i><strong><em><a>') . "</p></blockquote>\n\n";
                break;
            case 'pre':
                $block_output .= "\n<pre class=\"wp-block-code\"><code>" . htmlspecialchars(strip_tags($inner_html)) . "</code></pre>\n\n";
                break;
            case 'ul': case 'ol':
                $is_ord = ($tag === 'ol') ? ' {"ordered":true}' : '';
                $list_dom = new DOMDocument();
                @$list_dom->loadHTML(mb_convert_encoding($inner_html, 'HTML-ENTITIES', 'UTF-8'));
                $processed_li = '';
                foreach ($list_dom->getElementsByTagName('li') as $li) {
                    $processed_li .= '<li>' . trim(strip_tags($list_dom->saveHTML($li), '<b><i><strong><em><a><code>')) . '</li>';
                }
                $block_output .= "\n<$tag>$processed_li</$tag>\n\n";
                break;
            case 'p':
                $block_output .= "\n<p>$clean_inner</p>\n\n";
                break;
            case 'figure':
                if (strpos($inner_html, '<video') !== false || strpos($inner_html, '.mov') !== false || strpos($inner_html, '.mp4') !== false) {
                    preg_match('/(src|href)="([^"]+)"/i', $inner_html, $video_match);
                    $v_url = isset($video_match[2]) ? $video_match[2] : '';
                    if (!empty($v_url)) {
                        $block_output .= "\n<figure class=\"wp-block-video\"><video controls src=\"" . esc_url($v_url) . "\"></video></figure>\n\n";
                    }
                } elseif (strpos($inner_html, '<img') !== false) {
                    preg_match('/src="([^"]+)"/i', $inner_html, $img_match);
                    $i_url = isset($img_match[1]) ? $img_match[1] : '';
                    if (!empty($i_url)) {
                        $block_output .= "\n<figure class=\"wp-block-image size-full\"><img src=\"" . esc_url($i_url) . "\" alt=\"\"/></figure>\n\n";
                    }
                }
                break;
        }
    }

    if (empty(trim($block_output))) {
        $block_output = "\n<p>Workspace content compiled successfully.</p>\n";
    }

    $current_user_id = get_current_user_id() ? get_current_user_id() : 1;

    // Wipe out old matching titles automatically on re-upload to keep directory fresh
    $existing_post = get_page_by_title($clean_title, OBJECT, 'post');
    if ($existing_post) {
        wp_delete_post($existing_post->ID, true);
    }

    $post_id = wp_insert_post([
        'post_title'   => sanitize_text_field($clean_title),
        'post_content' => $block_output,
        'post_status'  => 'draft',
        'post_type'    => 'post',
        'post_author'  => $current_user_id
    ]);

    if ($post_id) {
        if ($featured_image_id) set_post_thumbnail($post_id, $featured_image_id);
        if (!empty($categories)) wp_set_object_terms($post_id, $categories, 'category');
        if (!empty($tags)) wp_set_object_terms($post_id, $tags, 'post_tag');
    }
}

/**
 * 5. MEDIA HANDLER
 */
function ntp_sideload_media($src, $file_path) {
    if (empty($src)) return false;
    $clean_src = preg_replace('/^attachment:[^:]+:/i', '', $src);
    $abs_path = dirname($file_path) . '/' . rawurldecode($clean_src);
    if (!file_exists($abs_path)) return false;
    require_once(ABSPATH . 'wp-admin/includes/media.php');
    require_once(ABSPATH . 'wp-admin/includes/file.php');
    require_once(ABSPATH . 'wp-admin/includes/image.php');
    $id = media_handle_sideload(['name' => basename($abs_path), 'tmp_name' => $abs_path], 0);
    return is_wp_error($id) ? false : ['id' => $id, 'url' => wp_get_attachment_url($id)];
}