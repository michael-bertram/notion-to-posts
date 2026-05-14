<?php
/**
 * Plugin Name: Notion to Native Blocks Importer
 * Description: Converts Notion ZIP exports into perfectly clean, native WordPress blocks.
 * Version: 2.5
 * Author: Coding Partner
 */

if (!defined('ABSPATH')) exit;

/**
 * 1. ADMIN MENU SETUP
 */
add_action('admin_menu', 'ntp_add_admin_menu');
function ntp_add_admin_menu() {
    add_menu_page('Notion Importer', 'Notion Importer', 'manage_options', 'notion-importer', 'ntp_plugin_page', 'dashicons-import');
}

function ntp_plugin_page() {
    $max_upload = ini_get('upload_max_filesize');
    ?>
    <div class="wrap">
        <h1>Notion Native Block Importer</h1>
        <div class="notice notice-info" style="margin-top:20px;">
            <p><strong>Server Limit:</strong> Max Upload Size: <?php echo $max_upload; ?>. Ensure your ZIP is smaller than this.</p>
        </div>
        <form method="post" enctype="multipart/form-data" style="background:#fff; padding:20px; border:1px solid #ccd0d4; margin-top:20px;">
            <?php wp_nonce_field('ntp_upload_action', 'ntp_nonce'); ?>
            <p><label><strong>Select Notion ZIP file:</strong></label></p>
            <input type="file" name="notion_zip" accept=".zip" required>
            <p class="submit"><input type="submit" name="ntp_submit" class="button button-primary" value="Import as Native Blocks"></p>
        </form>
    </div>
    <?php
    if (isset($_POST['ntp_submit'])) ntp_handle_upload();
}

/**
 * 2. ZIP EXTRACTION & FILE LOOP
 */
function ntp_handle_upload() {
    set_time_limit(600);
    header('X-Accel-Buffering: no');

    if (!isset($_POST['ntp_nonce']) || !wp_verify_nonce($_POST['ntp_nonce'], 'ntp_upload_action')) return;
    if (empty($_FILES['notion_zip']['tmp_name'])) return;

    require_once(ABSPATH . 'wp-admin/includes/file.php');
    require_once(ABSPATH . 'wp-admin/includes/media.php');
    require_once(ABSPATH . 'wp-admin/includes/image.php');

    $unzip_dir = wp_upload_dir()['basedir'] . '/notion_temp_' . time();
    WP_Filesystem();
    $unzip_status = unzip_file($_FILES['notion_zip']['tmp_name'], $unzip_dir);

    if (is_wp_error($unzip_status)) {
        echo '<div class="error"><p>Error: ' . $unzip_status->get_error_message() . '</p></div>';
        return;
    }

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($unzip_dir));
    $count = 0;
    foreach ($files as $file) {
        if ($file->isFile() && $file->getExtension() === 'html') {
            ntp_process_to_clean_blocks($file->getPathname());
            $count++;
        }
    }
    echo "<div class='updated'><p>Success! $count posts created. Check your Drafts.</p></div>";
}

/**
 * 3. THE "CLEAN-SPACING" NATIVE BLOCK CONVERTER
 */
function ntp_process_to_clean_blocks($file_path) {
    $html_content = file_get_contents($file_path);
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML(mb_convert_encoding($html_content, 'HTML-ENTITIES', 'UTF-8'));
    libxml_clear_errors();

    $xpath = new DOMXPath($dom);
    
    // Targeted query to avoid the "wrapper" traps
    $elements = $xpath->query('//h1 | //h2 | //h3 | //h4 | //p | //ul | //ol | //img | //video | //a[contains(@href, ".mov") or contains(@href, ".mp4")]');

    $block_output = '';
    $first_h1_ignored = false;

    foreach ($elements as $node) {
        $tag = strtolower($node->nodeName);
        
        if ($tag === 'h1' && !$first_h1_ignored) {
            $first_h1_ignored = true;
            continue;
        }

        // --- MEDIA HANDLING ---
        $src = $node->getAttribute('src') ?: $node->getAttribute('href');
        if (in_array($tag, ['img', 'video', 'a']) && !empty($src)) {
            $is_video = preg_match('/\.(mp4|mov|webm)$/i', $src);
            $is_img = preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', $src) || $tag === 'img';

            if ($is_video || $is_img) {
                $media = ntp_sideload_media($src, $file_path);
                if ($media) {
                    if ($is_video) {
                        $block_output .= "\n<figure class=\"wp-block-video\"><video controls src=\"" . $media['url'] . "\"></video></figure>\n\n";
                    } else {
                        $block_output .= "\n<figure class=\"wp-block-image size-full\"><img src=\"" . $media['url'] . "\" alt=\"\" class=\"wp-image-" . $media['id'] . "\"/></figure>\n\n";
                    }
                    continue;
                }
            }
        }

        // --- TEXT CONTENT CLEANING ---
        $inner_html = '';
        foreach ($node->childNodes as $child) { $inner_html .= $dom->saveHTML($child); }
        
        // Remove all attributes and Notion-specific spans
        $clean_inner = preg_replace('/ (class|style|id|dir|data-[a-z0-9-]+)="[^"]*"| (class|style|id|dir|data-[a-z0-9-]+)=\'[^\']*\'/i', '', $inner_html);
        $clean_inner = preg_replace('/<span[^>]*>|<\/span>/i', '', $clean_inner);
        $clean_inner = str_replace(array("\r", "\n", "\t"), ' ', $clean_inner);
        $clean_inner = preg_replace('/\s+/', ' ', $clean_inner);
        $clean_inner = trim($clean_inner);

        if (empty($clean_inner)) continue;

        // --- BLOCK WRAPPING ---
        if (in_array($tag, ['h1', 'h2', 'h3', 'h4'])) {
            $level = substr($tag, 1);
            $block_output .= "\n<h$level>" . strip_tags($clean_inner, '<b><i><strong><em><a><code>') . "</h$level>\n\n";
        } 
        elseif ($tag === 'ul' || $tag === 'ol') {
            $is_ord = ($tag === 'ol') ? ' {"ordered":true}' : '';
            
            // SPECIAL FIX FOR LIST GAPS: Strip all tags inside LI except basic formatting
            $list_dom = new DOMDocument();
            @$list_dom->loadHTML(mb_convert_encoding($inner_html, 'HTML-ENTITIES', 'UTF-8'));
            $li_elements = $list_dom->getElementsByTagName('li');
            $processed_li = '';
            
            foreach ($li_elements as $li) {
                $li_content = strip_tags($list_dom->saveHTML($li), '<b><i><strong><em><a><code><li>');
                $processed_li .= trim($li_content);
            }

            $block_output .= "\n<$tag>" . $processed_li . "</$tag>\n\n";
        } 
        elseif ($tag === 'p') {
            $block_output .= "\n<p>$clean_inner</p>\n\n";
        }
    }

    wp_insert_post([
        'post_title'   => sanitize_text_field(($dom->getElementsByTagName('title')->item(0)) ? $dom->getElementsByTagName('title')->item(0)->nodeValue : basename($file_path, '.html')),
        'post_content' => trim($block_output),
        'post_status'  => 'draft',
        'post_type'    => 'post'
    ]);
}

/**
 * 4. MEDIA HANDLER (Cleaned for Notion IDs)
 */
function ntp_sideload_media($src, $file_path) {
    if (empty($src)) return false;

    // Notion URLs often have "attachment:..." or are URL encoded
    $clean_src = preg_replace('/^attachment:[^:]+:/i', '', $src);
    $abs_path = dirname($file_path) . '/' . rawurldecode($clean_src);

    if (!file_exists($abs_path)) return false;

    require_once(ABSPATH . 'wp-admin/includes/media.php');
    require_once(ABSPATH . 'wp-admin/includes/file.php');
    require_once(ABSPATH . 'wp-admin/includes/image.php');

    $id = media_handle_sideload(['name' => basename($abs_path), 'tmp_name' => $abs_path], 0);
    if (is_wp_error($id)) return false;

    return ['id' => $id, 'url' => wp_get_attachment_url($id)];
}