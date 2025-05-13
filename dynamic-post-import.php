<?php
/**
 * Plugin Name: Dynamic Post Import
 * Description: Import post dari sumber manapun via AJAX, dengan penggantian kata pertama, deteksi duplikat, kategori, tag, dan featured image.
 * Version: 1.2
 * Author: Ashabul Kahfi
 */

add_action("admin_menu", "dpi_add_menu");

function dpi_add_menu() {
    add_menu_page(
        "Dynamic Post Import",
        "Import Dinamis",
        "manage_options",
        "dynamic-post-import",
        "dpi_import_page"
    );

    add_action("admin_enqueue_scripts", function ($hook) {
        if ($hook !== "toplevel_page_dynamic-post-import") return;
        
        wp_enqueue_script("dpi-ajax", plugin_dir_url(__FILE__) . "js/importer.js", ["jquery"], null, true);
        wp_localize_script("dpi-ajax", "dpi_ajax", [
            "ajax_url" => admin_url("admin-ajax.php"),
            "site_title" => get_bloginfo("name")
        ]);
    });
}

function dpi_import_page() {
    echo '<div class="wrap">
        <h1>Import Post Dinamis via AJAX</h1>
        <label><strong>URL Sumber:</strong></label><br>
        <input type="text" id="dpi-source" value="https://suarameratus.com" style="width:400px;" placeholder="https://example.com"><br><br>
        <label><strong>Kata Asal yang Ingin Diganti (opsional):</strong></label><br>
        <input type="text" id="dpi-replace" value="Suarameratus" style="width:300px;" placeholder="misalnya: Suarameratus"><br><br>
        <button id="dpi-start" class="button button-primary">Mulai Import</button>
        <div id="dpi-log" style="margin-top:20px;"></div>
    </div>';
}

add_action("wp_ajax_dpi_import_next", "dpi_import_next_ajax");

function dpi_import_next_ajax() {
    $page = isset($_POST["page"]) ? intval($_POST["page"]) : 1;
    $index = isset($_POST["index"]) ? intval($_POST["index"]) : 0;
    $base_url = isset($_POST["source"]) ? esc_url_raw($_POST["source"]) : "";
    $replace_word = isset($_POST["replace"]) ? sanitize_text_field($_POST["replace"]) : "";
    $site_title = get_bloginfo("name");

    if (!$base_url) {
        wp_send_json_error(["msg" => "URL sumber tidak valid"]);
    }

    $response = wp_remote_get("$base_url/wp-json/wp/v2/posts?per_page=50&page=$page&_embed");
    if (is_wp_error($response)) {
        wp_send_json_error(["msg" => "Gagal mengambil data dari sumber."]);
    }

    $posts = json_decode(wp_remote_retrieve_body($response), true);
    if (!isset($posts[$index])) {
        wp_send_json_error(["done" => true]);
    }

    $post = $posts[$index];
    $title = wp_strip_all_tags($post["title"]["rendered"]);
    $slug = $post["slug"];
    $source_post_id = intval($post["id"]);

    // Cek duplikat berdasarkan _source_post_id dan _source_url
    $existing = new WP_Query([
        "post_type" => "post",
        "meta_query" => [
            ["key" => "_source_post_id", "value" => $source_post_id],
            ["key" => "_source_url", "value" => $base_url]
        ],
        "posts_per_page" => 1
    ]);

    if ($existing->have_posts()) {
        wp_send_json_success(["msg" => "Lewati (sudah diimpor): $title", "next" => true]);
    }

    // Konten dan penggantian kata pertama
    $content = $post["content"]["rendered"];
    if ($replace_word) {
        $pattern = "/^<p>\s*(<strong>)?(" . preg_quote($replace_word, "/") . ")\s*[,–-]/i";
        $replacement = "<p><strong>$site_title</strong> –";
        $content = preg_replace($pattern, $replacement, $content, 1);
    }

    // Kategori
    $categories = [];
    foreach ($post["categories"] as $cat_id) {
        $cat = json_decode(wp_remote_retrieve_body(wp_remote_get("$base_url/wp-json/wp/v2/categories/$cat_id")), true);
        if (!empty($cat["name"])) {
            $term = term_exists($cat["name"], "category") ?: wp_insert_term($cat["name"], "category");
            if (!is_wp_error($term)) $categories[] = $term["term_id"];
        }
    }

    // Tag
    $tags = [];
    foreach ($post["tags"] as $tag_id) {
        $tag = json_decode(wp_remote_retrieve_body(wp_remote_get("$base_url/wp-json/wp/v2/tags/$tag_id")), true);
        if (!empty($tag["name"])) {
            $term = term_exists($tag["name"], "post_tag") ?: wp_insert_term($tag["name"], "post_tag");
            if (!is_wp_error($term)) $tags[] = $term["term_id"];
        }
    }

    // Buat post
    $post_id = wp_insert_post([
        "post_title" => $title,
        "post_content" => $content,
        "post_status" => "publish",
        "post_category" => $categories,
        "tags_input" => $tags,
        "post_type" => "post",
        "post_name" => $slug
    ]);

    if (is_wp_error($post_id)) {
        wp_send_json_error(["msg" => "Gagal menyimpan post"]);
    }

    update_post_meta($post_id, "_source_post_id", $source_post_id);
    update_post_meta($post_id, "_source_url", $base_url);

    // Featured image
    if (isset($post["_embedded"]["wp:featuredmedia"][0]["source_url"])) {
        $image_url = $post["_embedded"]["wp:featuredmedia"][0]["source_url"];
        require_once ABSPATH . "wp-admin/includes/media.php";
        require_once ABSPATH . "wp-admin/includes/file.php";
        require_once ABSPATH . "wp-admin/includes/image.php";
        
        $tmp = download_url($image_url);
        if (!is_wp_error($tmp)) {
            $file_array = [
                "name" => basename($image_url),
                "tmp_name" => $tmp
            ];
            $image_id = media_handle_sideload($file_array, $post_id);
            if (!is_wp_error($image_id)) {
                set_post_thumbnail($post_id, $image_id);
            }
        }
    }

    wp_send_json_success(["msg" => "✅ Berhasil import: $title", "next" => true]);
}