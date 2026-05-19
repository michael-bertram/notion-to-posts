<?php
/**
 * Plugin Name: Notion to Native Blocks Importer
 * Description: Advanced 4-level cross-ZIP migration engine with dynamic linked database view extraction and link repair.
 * Version: 10.1
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

    $options = [
        'post_status'   => isset($_POST['ntp_status']) ? sanitize_text_field($_POST['ntp_status']) : 'draft',
        'taxonomy_mode' => isset($_POST['ntp_tax_mode']) ? sanitize_text_field($_POST['ntp_tax_mode']) : 'tags',
        'skip_media'    => isset($_POST['ntp_skip_media']) ? true : false,
        'clean_titles'  => isset($_POST['ntp_clean_titles']) ? true : false,
        'migration_level'=> isset($_POST['ntp_migration_level']) ? sanitize_text_field($_POST['ntp_migration_level']) : 'courses',
    ];

    ntp_handle_upload($options);
}

/**
 * GLOBAL POST-IMPORT RE-LINKER ACTION
 */
add_action('admin_post_ntp_execute_relink', 'ntp_handle_global_relink');
function ntp_handle_global_relink() {
    if (!isset($_POST['ntp_relink_nonce']) || !wp_verify_nonce($_POST['ntp_relink_nonce'], 'ntp_relink_action')) {
        wp_die('Security error.');
    }

    $ledger = get_option('_ntp_relational_ledger', []);
    if (empty($ledger)) {
        set_transient('ntp_success_message', 'Link Repair Aborted: Relational ledger is empty.');
        wp_redirect(admin_url('admin.php?page=notion-importer'));
        exit;
    }

    $link_map = [];
    foreach ($ledger as $notion_file => $wp_post_id) {
        $permalink = get_permalink($wp_post_id);
        if ($permalink) {
            $link_map[$notion_file] = $permalink;
            $link_map[rawurlencode($notion_file)] = $permalink;
        }
    }

    $all_posts = get_posts([
        'post_type'      => 'post',
        'post_status'    => ['any'],
        'posts_per_page' => -1
    ]);

    $relink_count = 0;

    foreach ($all_posts as $p) {
        $content = $p->post_content;
        $updated = false;

        foreach ($link_map as $notion_file => $wp_permalink) {
            if (strpos($content, $notion_file) !== false) {
                $content = str_replace($notion_file, $wp_permalink, $content);
                $updated = true;
            }
        }

        if (preg_match_all('/href="([^"]+\.html)"/i', $content, $matches)) {
            foreach ($matches[1] as $relative_path) {
                $base_name = basename($relative_path);
                if (isset($link_map[$base_name])) {
                    $content = str_replace($relative_path, $link_map[$base_name], $content);
                    $updated = true;
                }
            }
        }

        if ($updated) {
            wp_update_post([
                'ID'           => $p->ID,
                'post_content' => $content
            ]);
            $relink_count++;
        }
    }

    set_transient('ntp_success_message', "Link Repair Engine Complete! Processed and repaired cross-references across $relink_count articles.");
    wp_redirect(admin_url('admin.php?page=notion-importer'));
    exit;
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
    $ledger = get_option('_ntp_relational_ledger', []);
    $mapped_count = count($ledger);
    
    if (get_transient('ntp_success_message')) {
        echo '<div class="notice notice-success is-dismissible" style="margin-top:20px;"><p>' . esc_html(get_transient('ntp_success_message')) . '</p></div>';
        delete_transient('ntp_success_message');
    }
    ?>
    <div class="wrap notion-importer-wrap" style="max-width: 950px; margin-top: 20px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen-Sans, Ubuntu, Cantarell, 'Helvetica Neue', sans-serif;">
        <h1 style="font-weight: 700; font-size: 28px; margin-bottom: 20px;">Notion Content Migration Engine</h1>
        <hr class="wp-header-end">

        <div style="background: #fff; border-left: 4px solid #46b450; box-shadow: 0 1px 3px rgba(0,0,0,0.1); padding: 15px 20px; border-radius: 4px; margin: 20px 0; display: flex; align-items: center; justify-content: space-between;">
            <div>
                <span class="dashicons dashicons-networking" style="color: #46b450; margin-right: 8px; vertical-align: text-bottom;"></span>
                <strong>Relational Mapping Ledger:</strong> <span style="background:#e7f5ec; color:#2e7d32; padding:2px 8px; border-radius:10px; font-weight:600; font-size:12px;"><?php echo $mapped_count; ?> Nodes Indexed</span> Across Database Runs.
            </div>
            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="margin:0;" onsubmit="return confirm('Clear the mapping ledger?');">
                <input type="hidden" name="action" value="ntp_clear_ledger">
                <?php wp_nonce_field('ntp_ledger_clear_action', 'ntp_ledger_nonce'); ?>
                <input type="submit" class="button button-link-delete" value="Clear Ledger Map" style="color:#d63638; text-decoration:none; font-size:12px;">
            </form>
        </div>

        <div style="background: #fff; border: 1px solid #ccd0d4; border-radius: 4px; box-shadow: 0 1px 2px rgba(0,0,0,0.05); padding: 20px; margin-bottom: 25px;">
            <h3 style="margin-top:0; font-size:16px; font-weight:600; color:#1d2327;">
                <span class="dashicons dashicons-admin-links" style="vertical-align:text-bottom; color:#2271b1; margin-right:4px;"></span> Cross-ZIP Internal Link Repair Utility
            </h3>
            <p style="font-size:13px; color:#646970; margin-bottom:15px;">After uploading all separate Course, Curriculum, Content, and Task archive zip packages, click this utility button. The engine will loop through every document body, locate dead relative <code>.html</code> links, and transform them into live WordPress URLs using the indexing ledger maps.</p>
            
            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="margin:0;">
                <input type="hidden" name="action" value="ntp_execute_relink">
                <?php wp_nonce_field('ntp_relink_action', 'ntp_relink_nonce'); ?>
                <input type="submit" class="button button-secondary" value="Execute Global Link Repair Run" <?php echo empty($ledger) ? 'disabled' : ''; ?> style="font-weight:600;">
            </form>
        </div>

        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" enctype="multipart/form-data" id="notion-import-form">
            <input type="hidden" name="action" value="execute_notion_import">
            <?php wp_nonce_field('ntp_upload_action', 'ntp_nonce'); ?>

            <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 20px;">
                <div style="background: #fff; border: 1px solid #dcdcde; border-radius: 4px; box-shadow: 0 1px 2px rgba(0,0,0,0.05); padding: 25px; display: flex; flex-direction: column; justify-content: space-between;">
                    <div>
                        <h2 style="font-size: 18px; font-weight: 600; margin-top: 0; margin-bottom: 15px; padding-bottom: 10px; border-bottom: 1px solid #f0f0f1;">
                            <span class="dashicons dashicons-cloud-upload" style="margin-right: 6px; vertical-align: text-bottom;"></span> 3. Select Database ZIP
                        </h2>
                        
                        <div style="background: #f0f6fc; border: 1px solid #c8d7e1; padding: 15px; border-radius: 4px; margin-bottom: 20px;">
                            <label style="display: block; font-weight: 700; margin-bottom: 8px; color: #1d2327;">Current Migration Batch Target:</label>
                            <select name="ntp_migration_level" style="width: 100%; height: 38px; font-weight: 600; border-color: #2271b1;">
                                <option value="courses" selected>Level 1: Courses / Modules (Runs 1st)</option>
                                <option value="curriculum">Level 2: Curriculum / Topics (Connects to Courses)</option>
                                <option value="content">Level 3: Content / Lessons (Connects to Curriculum)</option>
                                <option value="tasks">Level 4: Tasks / Sub-tasks (Connects to Lessons)</option>
                            </select>
                        </div>

                        <div style="background: #f8f9fa; border: 2px dashed #c3c4c7; padding: 30px 20px; text-align: center; border-radius: 4px; margin-bottom: 10px;">
                            <input type="file" name="notion_zip" accept=".zip" required style="font-size: 14px;">
                        </div>
                    </div>

                    <div style="display: flex; align-items: center; gap: 15px; padding-top: 20px; border-top: 1px solid #f0f0f1; margin-top: 20px;">
                        <input type="submit" name="ntp_submit" id="submit-btn" class="button button-primary button-large" value="Execute Relational Compilation" style="height: 40px; padding: 0 25px; font-weight: 600;">
                        <div id="notion-spinner" style="display: none; align-items: center; gap: 8px; color: #2271b1; font-weight: 500;">
                            <span class="spinner is-active" style="float: none; margin: 0;"></span> Processing Relations...
                        </div>
                    </div>
                </div>

                <div style="background: #fff; border: 1px solid #dcdcde; border-radius: 4px; box-shadow: 0 1px 2px rgba(0,0,0,0.05); padding: 25px;">
                    <h2 style="font-size: 18px; font-weight: 600; margin-top: 0; margin-bottom: 15px; padding-bottom: 10px; border-bottom: 1px solid #f0f0f1;">
                        <span class="dashicons dashicons-admin-generic" style="margin-right: 6px; vertical-align: text-bottom;"></span> 4. Settings
                    </h2>

                    <div style="margin-bottom: 20px;">
                        <label style="display: block; font-weight: 600; margin-bottom: 8px; color: #3c434a;">Destination Post Status</label>
                        <select name="ntp_status" style="width: 100%; height: 35px;">
                            <option value="draft" selected>Save as Draft</option>
                            <option value="publish">Publish Immediately</option>
                        </select>
                    </div>

                    <div style="margin-bottom: 20px;">
                        <label style="display: block; font-weight: 600; margin-bottom: 8px; color: #3c434a;">Map Modules Column to:</label>
                        <select name="ntp_tax_mode" style="width: 100%; height: 35px;">
                            <option value="tags" selected>WordPress Tags (Default)</option>
                            <option value="categories">WordPress Categories</option>
                            <option value="both">Both Tags & Categories</option>
                        </select>
                    </div>

                    <hr style="border: 0; border-top: 1px solid #f0f0f1; margin: 20px 0;">

                    <div style="margin-bottom: 15px;">
                        <label style="display: flex; align-items: center; gap: 8px; font-weight: 500; color: #3c434a; cursor: pointer;">
                            <input type="checkbox" name="ntp_clean_titles" value="1" checked style="margin: 0;">
                            Strip Notion ID Hashes from Titles
                        </label>
                    </div>

                    <div style="margin-bottom: 15px;">
                        <label style="display: flex; align-items: center; gap: 8px; font-weight: 500; color: #3c434a; cursor: pointer;">
                            <input type="checkbox" name="ntp_skip_media" value="1" style="margin: 0;">
                            Skip Media Sideloading (Fast Text Import)
                        </label>
                    </div>
                </div>
            </div>
        </form>
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
 * RELATIONAL LEDGER RESET ROUTER
 */
add_action('admin_post_ntp_clear_ledger', 'ntp_reset_ledger_map');
function ntp_reset_ledger_map() {
    if (!isset($_POST['ntp_ledger_nonce']) || !wp_verify_nonce($_POST['ntp_ledger_nonce'], 'ntp_ledger_clear_action')) wp_die('Security error.');
    update_option('_ntp_relational_ledger', []);
    wp_redirect(admin_url('admin.php?page=notion-importer'));
    exit;
}

/**
 * 3. ZIP EXTRACTION ROUTINE
 */
function ntp_handle_upload($options) {
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
            if (strpos($item, '._') === 0) continue;

            $full_path = $current_dir . '/' . $item;

            if (is_dir($full_path)) {
                $directory_queue[] = $full_path;
            } elseif (is_file($full_path) && pathinfo($full_path, PATHINFO_EXTENSION) === 'html') {
                $filename_only = strtolower($item);
                
                if (in_array($filename_only, ['index.html', 'sitemap.html'])) continue;
                if (strpos($filename_only, 'tasks') !== false && !strpos($filename_only, 'navigation') && $options['migration_level'] !== 'tasks') continue;
                if (strpos($filename_only, 'content') !== false && $options['migration_level'] !== 'content') continue;
                if (strpos($filename_only, 'curriculum') !== false && $options['migration_level'] !== 'curriculum') continue;

                $html_files_to_process[] = $full_path;
            }
        }
    }

    if (empty($html_files_to_process)) wp_die("Diagnostic Check Notice: 0 matching structural HTML files found.");

    $count = 0;
    foreach ($html_files_to_process as $file_path) {
        ntp_process_to_clean_blocks($file_path, $options);
        $count++;
    }
    
    set_transient('ntp_success_message', "Pipeline Complete! Synthesized $count elements into the network ledger.");
    wp_redirect(admin_url('admin.php?page=notion-importer'));
    exit;
}

/**
 * 4. THE INTER-CONNECTED RELATIONAL PARSER (VERSION 10.1 WITH VIEW EXTRACTION)
 */
function ntp_process_to_clean_blocks($file_path, $options) {
    $html_content = file_get_contents($file_path);
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML(mb_convert_encoding($html_content, 'HTML-ENTITIES', 'UTF-8'));
    libxml_clear_errors();

    $xpath = new DOMXPath($dom);
    
    $title_node = $dom->getElementsByTagName('title')->item(0);
    $raw_title = $title_node ? $title_node->nodeValue : basename($file_path, '.html');
    $clean_title = $options['clean_titles'] ? trim(preg_replace('/[a-f0-9]{32}/i', '', $raw_title)) : trim($raw_title);

    // --- STEP 1: PARSE AND EXTRACT THE LINKED DATABASE VIEWS BEFORE DELETING THE TABLE ---
    $relation_notion_keys = [];
    $extracted_view_blocks = ""; // Holds HTML references converted to blocks

    $property_rows = $xpath->query('//table[contains(@class, "properties")]//tr');
    if ($property_rows->length > 0) {
        $extracted_view_blocks .= "\n<p><strong>Linked Database References:</strong></p>\n\n\n<ul>";
        $has_links = false;

        foreach ($property_rows as $row) {
            $anchors = $xpath->query('.//a', $row);
            foreach ($anchors as $anchor) {
                $href = $anchor->getAttribute('href');
                if (!empty($href) && strpos($href, '.html') !== false) {
                    $decoded_filename = rawurldecode(basename($href));
                    $relation_notion_keys[] = $decoded_filename;
                    
                    // Retain this linked view reference as a Gutenberg content list item
                    $extracted_view_blocks .= '<li><a href="' . esc_attr($decoded_filename) . '">' . trim($anchor->textContent) . '</a></li>';
                    $has_links = true;
                }
            }
        }
        $extracted_view_blocks .= "</ul>\n\n";
        if (!$has_links) {
            $extracted_view_blocks = ""; // Wipe placeholder out if no relational view links exist
        }
    }

    // Now safely erase the old properties table from DOM tree memory
    $properties_tables = $xpath->query('//table[contains(@class, "properties")]');
    foreach ($properties_tables as $tbl) {
        $tbl->parentNode->removeChild($tbl);
    }

    // Taxonomy Tags Matrix rules
    $full_body_text = strtolower($dom->textContent);
    $modules_found = [];
    $categories = ['Specific'];
    
    if (strpos($full_body_text, 'flexbox') !== false || strpos($full_body_text, 'sidebar layout') !== false || strpos($full_body_text, 'card') !== false) $modules_found[] = 'Flexbox';
    if (strpos($full_body_text, 'php') !== false || strpos($full_body_text, 'syntax') !== false || strpos($full_body_text, 'variables') !== false) $modules_found[] = 'PHP';
    if (strpos($full_body_text, 'animation') !== false || strpos($full_body_text, 'animated') !== false || strpos($full_body_text, 'hover') !== false) $modules_found[] = 'Advanced Animation';
    if (strpos($full_body_text, 'popover') !== false) $modules_found[] = 'Popover API';
    if (strpos($full_body_text, 'nav') !== false || strpos($full_body_text, 'navigation') !== false) $modules_found[] = 'Navigation';

    $wp_tags = []; $wp_cats = $categories;
    if ($options['taxonomy_mode'] === 'tags') { $wp_tags = $modules_found; }
    elseif ($options['taxonomy_mode'] === 'categories') { $wp_cats = array_merge($wp_cats, $modules_found); }
    elseif ($options['taxonomy_mode'] === 'both') { $wp_tags = $modules_found; $wp_cats = array_merge($wp_cats, $modules_found); }

    // Media Processing
    $featured_image_id = null;
    if (!$options['skip_media']) {
        $media_elements = $xpath->query('//img | //video | //div[@class="source"]/a');
        foreach ($media_elements as $media_node) {
            $src = $media_node->getAttribute('src') ?: $media_node->getAttribute('href');
            if (!empty($src)) {
                $media_data = ntp_sideload_media($src, $file_path);
                if ($media_data) {
                    if ($media_node->nodeName === 'img' && $featured_image_id === null) $featured_image_id = $media_data['id'];
                    if ($media_node->nodeName === 'img') { $media_node->setAttribute('src', $media_data['url']); }
                    else { $media_node->setAttribute('href', $media_data['url']); }
                }
            }
        }
    }

    // Core content layout sweep
    $content_blocks = $xpath->query('//body//h1 | //body//h2 | //body//h3 | //body//h4 | //body//p | //body//ul | //body//ol | //body//blockquote | //body//pre | //body//figure');
    
    $block_output = '';
    $first_h1_ignored = false;

    // STEP 2: Prepend the extracted linked views directly to the top of our block output cache!
    if (!empty($extracted_view_blocks)) {
        $block_output .= $extracted_view_blocks;
    }

    foreach ($content_blocks as $block) {
        if ($xpath->query('ancestor::h1 | ancestor::h2 | ancestor::h3 | ancestor::h4 | ancestor::p | ancestor::ul | ancestor::ol | ancestor::blockquote | ancestor::pre', $block)->length > 0) continue;

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
                $code_content = strip_tags($inner_html);
                $lang = 'code'; 
                if (strpos($code_content, '<?php') !== false || strpos($code_content, 'wp_') !== false) { $lang = 'php'; }
                elseif (strpos($code_content, 'const ') !== false || strpos($code_content, 'document.get') !== false) { $lang = 'javascript'; }
                elseif (strpos($code_content, '<html') !== false || strpos($code_content, '</div>') !== false) { $lang = 'html'; }
                $block_output .= "\n<pre class=\"wp-block-code\"><code class=\"language-$lang\">" . htmlspecialchars($code_content) . "</code></pre>\n\n";
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
                    if (!empty($v_url)) $block_output .= "\n<figure class=\"wp-block-video\"><video controls src=\"" . esc_url($v_url) . "\"></video></figure>\n\n";
                } elseif (strpos($inner_html, '<img') !== false) {
                    preg_match('/src="([^"]+)"/i', $inner_html, $img_match);
                    $i_url = isset($img_match[1]) ? $img_match[1] : '';
                    if (!empty($i_url)) $block_output .= "\n<figure class=\"wp-block-image size-full\"><img src=\"" . esc_url($i_url) . "\" alt=\"\"/></figure>\n\n";
                }
                break;
        }
    }

    // --- ANCESTRAL LINEAGE CHECKS ---
    $ledger = get_option('_ntp_relational_ledger', []);
    $target_parent_id = 0;

    if ($options['migration_level'] !== 'courses' && !empty($relation_notion_keys)) {
        foreach ($relation_notion_keys as $relation_key) {
            if (isset($ledger[$relation_key])) {
                $target_parent_id = intval($ledger[$relation_key]);
                break; 
            }
        }
    }

    $current_user_id = get_current_user_id() ? get_current_user_id() : 1;

    $existing_post = get_page_by_title($clean_title, OBJECT, 'post');
    if ($existing_post) wp_delete_post($existing_post->ID, true);

    $post_args = [
        'post_title'   => sanitize_text_field($clean_title),
        'post_content' => $block_output,
        'post_status'  => $options['post_status'],
        'post_type'    => 'post',
        'post_author'  => $current_user_id,
    ];

    if ($target_parent_id > 0) {
        $post_args['post_parent'] = $target_parent_id;
    }

    $post_id = wp_insert_post($post_args);

    if ($post_id) {
        if ($featured_image_id) set_post_thumbnail($post_id, $featured_image_id);
        if (!empty($wp_cats)) wp_set_object_terms($post_id, $wp_cats, 'category');
        if (!empty($wp_tags)) wp_set_object_terms($post_id, $wp_tags, 'post_tag');

        $original_filename_key = basename($file_path);
        $ledger[$original_filename_key] = $post_id;
        $ledger[rawurlencode($original_filename_key)] = $post_id;
        update_option('_ntp_relational_ledger', $ledger);
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