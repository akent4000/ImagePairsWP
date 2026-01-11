<?php
/**
 * Plugin Name: Image Pairs
 * Description: Пары изображений с независимым лайтбоксом (плейлист) и бесконечной прокруткой. Шорткод [image_pairs].
 * Version: 3.0.3
 * Author: Akent4000
 */

if (!defined('ABSPATH')) exit;

/* ---------------- 1) CPT ---------------- */
add_action('init', function () {
    register_post_type('image_pair', [
        'label' => 'Пары изображений',
        'labels' => [
            'name'          => 'Пары изображений',
            'singular_name' => 'Пара изображений',
            'add_new'       => 'Добавить пару',
            'add_new_item'  => 'Добавить новую пару',
            'edit_item'     => 'Редактировать пару',
            'new_item'      => 'Новая пара',
            'view_item'     => 'Смотреть пару',
            'search_items'  => 'Искать пары',
            'not_found'     => 'Пары не найдены',
            'menu_name'     => 'Пары изображений',
        ],
        'public'        => false,
        'show_ui'       => true,
        'menu_position' => 20,
        'menu_icon'     => 'dashicons-images-alt2',
        'supports'      => ['title'],
        'has_archive'   => false,
        'show_in_rest'  => true,
    ]);
});

/* ---------------- 2) Helper Functions (Logic) ---------------- */

function ip_normalize_atts($atts) {
    $defaults = [
        'orderby'   => 'date',
        'order'     => 'DESC',
        'size'      => 'large',
        'topic'     => '',
        'topic_ids' => '',
        'operator'  => 'IN',
        'shuffle'   => '0',
        'captions'  => '1',
        'per_page'  => '20',
        'hash_salt' => '',
        'load_all'  => '0',
    ];

    $a = shortcode_atts($defaults, $atts);

    $a['shuffle']  = in_array(strtolower((string)$a['shuffle']),  ['1','true','yes','on'], true) ? '1' : '0';
    $a['captions'] = in_array(strtolower((string)$a['captions']), ['0','false','no','off'], true) ? '0' : '1';
    $a['load_all'] = in_array(strtolower((string)$a['load_all']), ['1','true','yes','on'], true) ? '1' : '0';
    $a['per_page'] = max(1, (int)$a['per_page']);

    if ($a['shuffle'] === '1') {
        $a['orderby'] = 'hash';
        if (empty($a['hash_salt']) || $a['hash_salt'] === 'default') {
            $a['hash_salt'] = wp_generate_password(6, false);
        }
    }

    return $a;
}

function ip_build_base_query_args(array $a) {
    $tax_query = [];

    if (!empty($a['topic'])) {
        $slugs = array_filter(array_map('sanitize_title', array_map('trim', explode(',', $a['topic']))));
        if ($slugs) {
            $tax_query[] = [
                'taxonomy' => 'ip_topic',
                'field'    => 'slug',
                'terms'    => $slugs,
                'operator' => in_array($a['operator'], ['IN','AND','NOT IN'], true) ? $a['operator'] : 'IN',
            ];
        }
    }
    if (!empty($a['topic_ids'])) {
        $ids = array_filter(array_map('intval', explode(',', $a['topic_ids'])));
        if ($ids) {
            $tax_query[] = [
                'taxonomy' => 'ip_topic',
                'field'    => 'term_id',
                'terms'    => $ids,
                'operator' => in_array($a['operator'], ['IN','AND','NOT IN'], true) ? $a['operator'] : 'IN',
            ];
        }
    }
    if (count($tax_query) > 1) {
        $tax_query = array_merge(['relation' => 'AND'], $tax_query);
    }

    $args = [
        'post_type'      => 'image_pair',
        'posts_per_page' => -1, // Берем все ID для построения плейлиста
        'fields'         => 'ids',
        'orderby'        => sanitize_key($a['orderby']),
        'order'          => ($a['order'] === 'ASC' ? 'ASC' : 'DESC'),
        'no_found_rows'  => true,
    ];
    
    if (!empty($tax_query)) {
        $args['tax_query'] = $tax_query;
    }
    
    if ($a['orderby'] === 'caption') {
        $args['meta_key'] = '_ip_caption';
        $args['orderby']  = 'meta_value';
    }
    
    if ($a['orderby'] === 'hash') {
        $args['orderby'] = 'none';
    }

    return $args;
}

// Получает полный список ID (плейлист для лайтбокса) с учетом сортировки
function ip_get_all_ids(array $a) {
    $args = ip_build_base_query_args($a);
    $ids = get_posts($args);

    if ($a['orderby'] === 'hash' && count($ids) > 1) {
        $salt = (string) $a['hash_salt'];
        usort($ids, function ($id_a, $id_b) use ($salt) {
            $hash_a = md5($id_a . $salt);
            $hash_b = md5($id_b . $salt);
            return strcmp($hash_a, $hash_b);
        });
    }
    return $ids;
}

function ip_get_src_with_webp($url) {
    $result = ['orig' => $url, 'webp' => ''];
    if (empty($url)) return $result;

    $uploads = wp_get_upload_dir();
    if (empty($uploads['baseurl']) || empty($uploads['basedir'])) return $result;
    if (strpos($url, $uploads['baseurl']) !== 0) return $result;

    $relative = substr($url, strlen($uploads['baseurl']));
    if (!empty($relative) && $relative[0] === '/') {
        $path_jpg = $uploads['basedir'] . $relative;
    } else {
        $path_jpg = trailingslashit($uploads['basedir']) . ltrim($relative, '/');
    }
    $path_webp = $path_jpg . '.webp';
    if (file_exists($path_webp)) {
        $result['webp'] = $url . '.webp';
    }
    return $result;
}

// Функция подготавливает данные одной пары (используется и в шорткоде, и в AJAX лайтбокса)
function ip_prepare_pair_data($post_id, $size = 'large') {
    $img1_id = (int) get_post_meta($post_id, '_ip_img1', true);
    $img2_id = (int) get_post_meta($post_id, '_ip_img2', true);
    $caption = get_post_meta($post_id, '_ip_caption', true);

    if (!$img1_id && !$img2_id) return null;

    $data = [
        'id'      => $post_id,
        'caption' => $caption,
        'img1'    => null,
        'img2'    => null
    ];

    if ($img1_id) {
        $src  = wp_get_attachment_image_src($img1_id, $size);
        $full = wp_get_attachment_image_src($img1_id, 'full');
        $alt  = trim(get_post_meta($img1_id, '_wp_attachment_image_alt', true));
        $webp = $src ? ip_get_src_with_webp($src[0]) : null;
        
        if ($src) {
            $data['img1'] = [
                'src'      => $src[0],
                'full'     => $full ? $full[0] : $src[0],
                'webp_src' => $webp ? $webp['webp'] : '',
                'alt'      => $alt
            ];
        }
    }

    if ($img2_id) {
        $src  = wp_get_attachment_image_src($img2_id, $size);
        $full = wp_get_attachment_image_src($img2_id, 'full');
        $alt  = trim(get_post_meta($img2_id, '_wp_attachment_image_alt', true));
        $webp = $src ? ip_get_src_with_webp($src[0]) : null;

        if ($src) {
            $data['img2'] = [
                'src'      => $src[0],
                'full'     => $full ? $full[0] : $src[0],
                'webp_src' => $webp ? $webp['webp'] : '',
                'alt'      => $alt
            ];
        }
    }

    return $data;
}

/* ---------------- 3) Taxonomy & Metaboxes ---------------- */
add_action('init', function () {
    register_taxonomy('ip_topic', ['image_pair'], [
        'labels' => [
            'name'              => 'Темы',
            'singular_name'     => 'Тема',
            'menu_name'         => 'Темы',
        ],
        'public'            => false,
        'show_ui'           => true,
        'show_admin_column' => true,
        'hierarchical'      => true,
        'show_in_rest'      => true,
    ]);
});

add_action('add_meta_boxes', function () {
    add_meta_box('ip_images', 'Две картинки и подпись', 'ip_render_metabox', 'image_pair', 'normal', 'high');
});

function ip_render_metabox($post) {
    wp_nonce_field('ip_save', 'ip_nonce');
    $img1 = (int) get_post_meta($post->ID, '_ip_img1', true);
    $img2 = (int) get_post_meta($post->ID, '_ip_img2', true);
    $caption = get_post_meta($post->ID, '_ip_caption', true);
    
    $alt1 = $img1 ? get_post_meta($img1, '_wp_attachment_image_alt', true) : '';
    $alt2 = $img2 ? get_post_meta($img2, '_wp_attachment_image_alt', true) : '';

    $src1 = $img1 ? wp_get_attachment_image_url($img1, 'medium') : '';
    $src2 = $img2 ? wp_get_attachment_image_url($img2, 'medium') : '';
    ?>
    <style>
      .ip-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px}
      .ip-item img{max-width:100%; border-radius:8px; display:block; cursor:pointer; transition: opacity 0.2s; margin-bottom: 8px;}
      .ip-item img:hover {opacity: 0.8; box-shadow: 0 0 0 2px #2271b1;}
      .ip-buttons{margin-top:8px; display: flex; gap: 5px;}
      .ip-caption-wrap label{display:block;margin-bottom:6px;font-weight:600}
      .ip-caption-wrap input[type="text"], .ip-caption-wrap textarea{width:100%}
      .ip-alt-input { width: 100%; margin-top: 5px; margin-bottom: 5px; }
      .ip-notice { font-size: 11px; color: #d63638; margin-top: 2px; }
    </style>
    
    <div class="ip-grid">
      <div class="ip-item">
        <label><strong>Картинка 1</strong></label>
        <div><img id="ip_img1_preview" src="<?php echo esc_url($src1); ?>" <?php echo $src1?'':'style="display:none"'; ?>></div>
        <label class="screen-reader-text">Alt текст 1</label>
        <input type="text" name="ip_img1_alt" class="ip-alt-input" value="<?php echo esc_attr($alt1); ?>" placeholder="Alt текст (Глобальный)">
        <p class="ip-notice">⚠️ Меняет Alt исходного файла в библиотеке!</p>
        <div class="ip-buttons">
          <input type="hidden" name="ip_img1" id="ip_img1" value="<?php echo esc_attr($img1); ?>">
          <button type="button" class="button" id="ip_img1_select">Выбрать</button>
          <button type="button" class="button" id="ip_img1_clear">Очистить</button>
        </div>
      </div>

      <div class="ip-item">
        <label><strong>Картинка 2</strong></label>
        <div><img id="ip_img2_preview" src="<?php echo esc_url($src2); ?>" <?php echo $src2?'':'style="display:none"'; ?>></div>
        <label class="screen-reader-text">Alt текст 2</label>
        <input type="text" name="ip_img2_alt" class="ip-alt-input" value="<?php echo esc_attr($alt2); ?>" placeholder="Alt текст (Глобальный)">
        <p class="ip-notice">⚠️ Меняет Alt исходного файла в библиотеке!</p>
        <div class="ip-buttons">
          <input type="hidden" name="ip_img2" id="ip_img2" value="<?php echo esc_attr($img2); ?>">
          <button type="button" class="button" id="ip_img2_select">Выбрать</button>
          <button type="button" class="button" id="ip_img2_clear">Очистить</button>
        </div>
      </div>
    </div>

    <div class="ip-caption-wrap">
      <label for="ip_caption">Подпись под парой (текст):</label>
      <input type="text" id="ip_caption" name="ip_caption" value="<?php echo esc_attr($caption); ?>" placeholder="Например: Реставрация цеха, 2023">
      <p class="description">Этот текст отобразится под парой в теге &lt;span&gt;</p>
    </div>

    <script>
      jQuery(function($){
        function initImageControl(selectBtnId, inputId, previewId, clearBtnId, altInputName){
            let frame;
            const $selectBtn = $('#' + selectBtnId);
            const $previewImg = $('#' + previewId);
            const $input = $('#' + inputId);
            const $clearBtn = $('#' + clearBtnId);
            const $altInput = $('input[name="'+altInputName+'"]');

            const openMedia = function(e) {
                e.preventDefault();
                if (frame) { frame.open(); return; }
                frame = wp.media({ title: 'Выбор изображения', button: { text: 'Применить' }, multiple: false });
                frame.on('open', function() {
                    const selection = frame.state().get('selection');
                    const currentId = $input.val();
                    if (currentId) {
                        const attachment = wp.media.attachment(currentId);
                        attachment.fetch(); 
                        selection.add(attachment);
                    }
                });
                frame.on('select', function(){
                    const att = frame.state().get('selection').first().toJSON();
                    $input.val(att.id);
                    $previewImg.attr('src', (att.sizes && att.sizes.medium ? att.sizes.medium.url : att.url)).show();
                    if(att.alt && $altInput.val() === '') { $altInput.val(att.alt); }
                });
                frame.open();
            };
            $selectBtn.on('click', openMedia);
            $previewImg.on('click', openMedia);
            $clearBtn.on('click', function(){
                $input.val('');
                $previewImg.hide().attr('src','');
                $altInput.val('');
            });
        }
        initImageControl('ip_img1_select', 'ip_img1', 'ip_img1_preview', 'ip_img1_clear', 'ip_img1_alt');
        initImageControl('ip_img2_select', 'ip_img2', 'ip_img2_preview', 'ip_img2_clear', 'ip_img2_alt');
      });
    </script>
    <?php
}

add_action('save_post_image_pair', function ($post_id) {
    if (!isset($_POST['ip_nonce']) || !wp_verify_nonce($_POST['ip_nonce'], 'ip_save')) return;
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!current_user_can('edit_post', $post_id)) return;

    $img1 = isset($_POST['ip_img1']) ? (int) $_POST['ip_img1'] : 0;
    $img2 = isset($_POST['ip_img2']) ? (int) $_POST['ip_img2'] : 0;
    $caption = isset($_POST['ip_caption']) ? sanitize_text_field($_POST['ip_caption']) : '';
    
    update_post_meta($post_id, '_ip_img1', $img1);
    update_post_meta($post_id, '_ip_img2', $img2);
    update_post_meta($post_id, '_ip_caption', $caption);

    if ($img1 && isset($_POST['ip_img1_alt'])) {
        update_post_meta($img1, '_wp_attachment_image_alt', sanitize_text_field($_POST['ip_img1_alt']));
    }
    if ($img2 && isset($_POST['ip_img2_alt'])) {
        update_post_meta($img2, '_wp_attachment_image_alt', sanitize_text_field($_POST['ip_img2_alt']));
    }
}, 10, 1);

/* ---------------- 3.1) Админ медиа ---------------- */
add_action('admin_enqueue_scripts', function(){
    global $post_type;
    if ($post_type === 'image_pair') wp_enqueue_media();
});

/* ---------------- 3.2) Фильтр в списке ---------------- */
add_action('restrict_manage_posts', function(){
    global $typenow;
    if ($typenow !== 'image_pair') return;
    $taxonomy = 'ip_topic';
    $selected = isset($_GET[$taxonomy]) ? sanitize_text_field($_GET[$taxonomy]) : '';
    wp_dropdown_categories([
        'show_option_all' => 'Все темы',
        'taxonomy'        => $taxonomy,
        'name'            => $taxonomy,
        'orderby'         => 'name',
        'selected'        => $selected,
        'hierarchical'    => true,
        'hide_empty'      => false,
        'value_field'     => 'slug',
    ]);
});
add_filter('parse_query', function($query){
    global $pagenow;
    if ($pagenow !== 'edit.php' || !isset($query->query['post_type']) || $query->query['post_type'] !== 'image_pair') return;
    $taxonomy = 'ip_topic';
    if (!empty($_GET[$taxonomy])) {
        $term = sanitize_text_field($_GET[$taxonomy]);
        $query->query_vars[$taxonomy] = $term;
    }
});

/* ---------------- 3.3) Столбцы в админке ---------------- */
add_filter('manage_image_pair_posts_columns', function($columns){
    $new_columns = [];
    $new_columns['cb'] = $columns['cb']; 
    $new_columns['taxonomy-ip_topic'] = isset($columns['taxonomy-ip_topic']) ? $columns['taxonomy-ip_topic'] : 'Темы';
    $new_columns['title'] = $columns['title'];
    $new_columns['ip_caption_col'] = 'Подпись';
    $new_columns['ip_alts_col'] = 'Alt (Исходный)';
    $new_columns['date'] = $columns['date'];
    return $new_columns;
});

add_action('manage_image_pair_posts_custom_column', function($column, $post_id){
    if ($column === 'ip_caption_col') {
        echo esc_html(get_post_meta($post_id, '_ip_caption', true));
    }
    if ($column === 'ip_alts_col') {
        $img1_id = (int) get_post_meta($post_id, '_ip_img1', true);
        $img2_id = (int) get_post_meta($post_id, '_ip_img2', true);
        
        $alt1 = $img1_id ? get_post_meta($img1_id, '_wp_attachment_image_alt', true) : '';
        $alt2 = $img2_id ? get_post_meta($img2_id, '_wp_attachment_image_alt', true) : '';
        $caption = get_post_meta($post_id, '_ip_caption', true); 

        $alt2_clean = trim((string)$alt2);
        $caption_clean = trim((string)$caption);

        $style2_wrapper = '';
        if ($alt2_clean !== $caption_clean) {
            $style2_wrapper = 'background:#ffebeb; border:1px solid #f8cbcb; padding:2px 6px; border-radius:4px; display:inline-block; color:#d63638;';
        }

        echo '<div style="margin-bottom:6px;"><strong>1:</strong> ' . ($alt1 ? esc_html($alt1) : '<span style="color:#ccc">—</span>') . '</div>';
        echo '<div><strong>2:</strong> ';
        if ($style2_wrapper) {
            echo '<span style="' . $style2_wrapper . '">' . ($alt2 ? esc_html($alt2) : '<em>(пусто)</em>') . '</span>';
        } else {
            echo esc_html($alt2); 
        }
        echo '</div>';
    }
}, 10, 2);

add_filter('manage_edit-image_pair_sortable_columns', function($columns){
    $columns['taxonomy-ip_topic'] = 'ip_topic_sort';
    $columns['ip_caption_col']    = 'ip_caption_sort';
    return $columns;
});

add_filter('posts_clauses', function($clauses, $query){
    global $wpdb;
    if (!is_admin() || !$query->is_main_query() || $query->get('post_type') !== 'image_pair') return $clauses;
    $orderby = $query->get('orderby');
    $order   = $query->get('order');

    if ($orderby === 'ip_topic_sort') {
        $clauses['join'] .= " LEFT JOIN {$wpdb->term_relationships} AS tr ON {$wpdb->posts}.ID = tr.object_id LEFT JOIN {$wpdb->term_taxonomy} AS tt ON tr.term_taxonomy_id = tt.term_taxonomy_id AND tt.taxonomy = 'ip_topic' LEFT JOIN {$wpdb->terms} AS t ON tt.term_id = t.term_id LEFT JOIN {$wpdb->postmeta} AS pm_cap ON ({$wpdb->posts}.ID = pm_cap.post_id AND pm_cap.meta_key = '_ip_caption') ";
        $clauses['orderby'] = "t.name $order, pm_cap.meta_value $order";
        $clauses['groupby'] = "{$wpdb->posts}.ID";
    }
    elseif ($orderby === 'ip_caption_sort') {
        $clauses['join'] .= " LEFT JOIN {$wpdb->postmeta} AS pm_cap ON ({$wpdb->posts}.ID = pm_cap.post_id AND pm_cap.meta_key = '_ip_caption') LEFT JOIN {$wpdb->term_relationships} AS tr ON {$wpdb->posts}.ID = tr.object_id LEFT JOIN {$wpdb->term_taxonomy} AS tt ON tr.term_taxonomy_id = tt.term_taxonomy_id AND tt.taxonomy = 'ip_topic' LEFT JOIN {$wpdb->terms} AS t ON tt.term_id = t.term_id ";
        $clauses['orderby'] = "pm_cap.meta_value $order, t.name $order";
        $clauses['groupby'] = "{$wpdb->posts}.ID";
    }
    return $clauses;
}, 10, 2);

/* ---------------- 3.4) CSS Настройки ---------------- */
add_action('admin_head', function() {
    global $typenow;
    if ($typenow !== 'image_pair') return;
    ?>
    <style>
        th.column-taxonomy-ip_topic, td.column-taxonomy-ip_topic { width: 10%; }
        th.column-title, td.column-title { width: 15%; font-weight: 600; }
        th.column-ip_caption_col, td.column-ip_caption_col { width: 32%; }
        th.column-ip_alts_col, td.column-ip_alts_col { width: 32%; color: #444; }
        th.column-date, td.column-date { width: 10%; }
    </style>
    <?php
});

add_action('admin_menu', function() {
    add_submenu_page('edit.php?post_type=image_pair', 'Настройки CSS', 'Настройки CSS', 'manage_options', 'image-pairs-css', 'ip_render_css_page');
});

function ip_render_css_page() {
    if (isset($_POST['ip_save_css']) && check_admin_referer('ip_css_action', 'ip_css_nonce')) {
        $css = isset($_POST['ip_custom_css']) ? stripslashes($_POST['ip_custom_css']) : '';
        update_option('ip_custom_css', $css);
        echo '<div class="notice notice-success is-dismissible"><p>CSS сохранен!</p></div>';
    }
    $current_css = get_option('ip_custom_css', '');
    ?>
    <div class="wrap">
        <h1>Настройки CSS для Image Pairs</h1>
        <form method="post" action="">
            <?php wp_nonce_field('ip_css_action', 'ip_css_nonce'); ?>
            <textarea name="ip_custom_css" rows="15" class="large-text code" style="font-family: monospace; background: #282c34; color: #abb2bf; padding: 15px; border-radius: 5px;"><?php echo esc_textarea($current_css); ?></textarea>
            <p class="submit"><input type="submit" name="ip_save_css" class="button button-primary" value="Сохранить изменения"></p>
        </form>
    </div>
    <?php
}

add_filter('edit_posts_per_page', function($per_page, $post_type){
    if ($post_type === 'image_pair') return 999;
    return $per_page;
}, 10, 2);

/* ---------------- 4) Frontend Assets ---------------- */
add_action('wp_enqueue_scripts', function () {
    wp_enqueue_style('image-pairs-style', plugin_dir_url(__FILE__) . 'image-pairs.css', [], '3.0.1');
    $custom_css = get_option('ip_custom_css');
    if (!empty($custom_css)) {
        wp_add_inline_style('image-pairs-style', $custom_css);
    }
    wp_enqueue_script('image-pairs-frontend', plugin_dir_url(__FILE__) . 'image-pairs-frontend.js', [], '3.0.1', true);
    wp_localize_script('image-pairs-frontend', 'ipPairs', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce'   => wp_create_nonce('ip_pairs_nonce'),
    ]);
});

/* ---------------- 5) Shortcode (Playlist Logic) ---------------- */
$GLOBALS['ip_enqueue_lightbox'] = false;

add_shortcode('image_pairs', function ($atts) {
    $a = ip_normalize_atts($atts);
    
    // 1. Получаем ПОЛНЫЙ список ID (Playlist)
    $all_ids = ip_get_all_ids($a);
    if (empty($all_ids)) return '';

    $total_items = count($all_ids);
    $per_page    = $a['per_page'];
    
    // 2. ID для первой страницы
    if ($a['load_all'] === '1') {
        $page_ids = $all_ids;
        $has_more = false;
    } else {
        $page_ids = array_slice($all_ids, 0, $per_page);
        $has_more = ($total_items > $per_page);
    }

    $GLOBALS['ip_enqueue_lightbox'] = true;

    $instance = wp_unique_id('ip-instance-');
    $show_captions = ($a['captions'] === '1');
    
    // Передаем параметры для пагинации
    $atts_for_js = wp_json_encode($a);
    // Передаем плейлист (весь список ID)
    $playlist_json = wp_json_encode($all_ids);

    ob_start(); ?>
    <div class="ip-wrap"
         id="<?php echo esc_attr($instance); ?>"
         data-ip-instance="<?php echo esc_attr($instance); ?>"
         data-page="1"
         data-atts="<?php echo esc_attr($atts_for_js); ?>"
         data-playlist='<?php echo esc_attr($playlist_json); ?>'>
         
      <?php foreach ($page_ids as $post_id): 
          $data = ip_prepare_pair_data($post_id, $a['size']);
          if (!$data) continue;
      ?>
        <div class="ip-pair" data-pair-id="<?php echo esc_attr($post_id); ?>">
          <div class="ip-row">
            <div>
              <?php if (!empty($data['img1'])) { ?>
                <a href="<?php echo esc_url($data['img1']['full']); ?>" class="ip-zoom" 
                   data-ipbox="<?php echo esc_attr($instance); ?>"
                   data-pair-id="<?php echo esc_attr($post_id); ?>"
                   data-img-index="1"
                   data-alt="<?php echo esc_attr($data['img1']['alt']); ?>">
                  <picture>
                    <?php if ($data['img1']['webp_src']): ?>
                      <source srcset="<?php echo esc_url($data['img1']['webp_src']); ?>" type="image/webp">
                    <?php endif; ?>
                    <img loading="lazy" decoding="async" src="<?php echo esc_url($data['img1']['src']); ?>" alt="<?php echo esc_attr($data['img1']['alt']); ?>">
                  </picture>
                </a>
              <?php } ?>
            </div>
            <div>
              <?php if (!empty($data['img2'])) { ?>
                <a href="<?php echo esc_url($data['img2']['full']); ?>" class="ip-zoom" 
                   data-ipbox="<?php echo esc_attr($instance); ?>"
                   data-pair-id="<?php echo esc_attr($post_id); ?>"
                   data-img-index="2"
                   data-alt="<?php echo esc_attr($data['img2']['alt']); ?>">
                  <picture>
                    <?php if ($data['img2']['webp_src']): ?>
                      <source srcset="<?php echo esc_url($data['img2']['webp_src']); ?>" type="image/webp">
                    <?php endif; ?>
                    <img loading="lazy" decoding="async" src="<?php echo esc_url($data['img2']['src']); ?>" alt="<?php echo esc_attr($data['img2']['alt']); ?>">
                  </picture>
                </a>
              <?php } ?>
            </div>
          </div>
          <?php if ($show_captions && $data['caption']) { ?>
            <span class="ip-caption"><?php echo esc_html($data['caption']); ?></span>
          <?php } ?>
        </div>
      <?php endforeach; ?>
      
      <?php if ($has_more) { ?>
        <div class="ip-scroll-sentinel"></div>
      <?php } ?>
    </div>
    <?php
    return ob_get_clean();
});

/* ---------------- 6) AJAX: Grid Pagination ---------------- */
add_action('wp_ajax_ip_load_pairs', 'ip_ajax_load_pairs');
add_action('wp_ajax_nopriv_ip_load_pairs', 'ip_ajax_load_pairs');

function ip_ajax_load_pairs() {
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'ip_pairs_nonce')) {
        wp_send_json_error(['message' => 'Bad nonce']);
    }

    $page     = isset($_POST['page']) ? (int) $_POST['page'] : 2;
    $atts_raw = isset($_POST['atts']) ? stripslashes($_POST['atts']) : '{}';
    $instance = isset($_POST['instance']) ? sanitize_text_field($_POST['instance']) : '';

    $atts = json_decode($atts_raw, true);
    if (!is_array($atts)) $atts = [];
    $a = ip_normalize_atts($atts);

    // Получаем ВСЕ ID снова (чтобы соблюсти порядок сортировки)
    $all_ids = ip_get_all_ids($a);
    $total   = count($all_ids);
    
    $per_page = $a['per_page'];
    $offset   = ($page - 1) * $per_page;
    $slice    = array_slice($all_ids, $offset, $per_page);

    if (empty($slice)) {
        wp_send_json_success(['html' => '', 'has_more' => false]);
    }

    $show_captions = ($a['captions'] === '1');
    $has_more = ($total > ($offset + count($slice)));

    ob_start();
    foreach ($slice as $post_id) {
        $data = ip_prepare_pair_data($post_id, $a['size']);
        if (!$data) continue;
        ?>
        <div class="ip-pair" data-pair-id="<?php echo esc_attr($post_id); ?>">
          <div class="ip-row">
            <div>
              <?php if (!empty($data['img1'])) { ?>
                <a href="<?php echo esc_url($data['img1']['full']); ?>" class="ip-zoom" 
                   data-ipbox="<?php echo esc_attr($instance); ?>"
                   data-pair-id="<?php echo esc_attr($post_id); ?>"
                   data-img-index="1"
                   data-alt="<?php echo esc_attr($data['img1']['alt']); ?>">
                  <picture>
                    <?php if ($data['img1']['webp_src']): ?>
                      <source srcset="<?php echo esc_url($data['img1']['webp_src']); ?>" type="image/webp">
                    <?php endif; ?>
                    <img loading="lazy" decoding="async" src="<?php echo esc_url($data['img1']['src']); ?>" alt="<?php echo esc_attr($data['img1']['alt']); ?>">
                  </picture>
                </a>
              <?php } ?>
            </div>
            <div>
              <?php if (!empty($data['img2'])) { ?>
                <a href="<?php echo esc_url($data['img2']['full']); ?>" class="ip-zoom" 
                   data-ipbox="<?php echo esc_attr($instance); ?>"
                   data-pair-id="<?php echo esc_attr($post_id); ?>"
                   data-img-index="2"
                   data-alt="<?php echo esc_attr($data['img2']['alt']); ?>">
                  <picture>
                    <?php if ($data['img2']['webp_src']): ?>
                      <source srcset="<?php echo esc_url($data['img2']['webp_src']); ?>" type="image/webp">
                    <?php endif; ?>
                    <img loading="lazy" decoding="async" src="<?php echo esc_url($data['img2']['src']); ?>" alt="<?php echo esc_attr($data['img2']['alt']); ?>">
                  </picture>
                </a>
              <?php } ?>
            </div>
          </div>
          <?php if ($show_captions && $data['caption']) { ?>
            <span class="ip-caption"><?php echo esc_html($data['caption']); ?></span>
          <?php } ?>
        </div>
        <?php
    }
    $html = ob_get_clean();

    wp_send_json_success([
        'html'     => $html,
        'has_more' => $has_more
    ]);
}

/* ---------------- 7) AJAX: Lightbox Data ---------------- */
add_action('wp_ajax_ip_get_lightbox_data', 'ip_ajax_get_lightbox_data');
add_action('wp_ajax_nopriv_ip_get_lightbox_data', 'ip_ajax_get_lightbox_data');

function ip_ajax_get_lightbox_data() {
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'ip_pairs_nonce')) {
        wp_send_json_error(['message' => 'Bad nonce']);
    }

    $pair_id = isset($_POST['pair_id']) ? (int) $_POST['pair_id'] : 0;
    if (!$pair_id) wp_send_json_error(['message' => 'No ID']);

    $data = ip_prepare_pair_data($pair_id, 'large');
    
    if ($data) {
        wp_send_json_success($data);
    } else {
        wp_send_json_error(['message' => 'Not found']);
    }
}

/* ---------------- 8) Lightbox HTML (Footer) ---------------- */
add_action('wp_footer', function(){
    // Убрали style="display:none;", чтобы CSS класс .is-open мог включить отображение
    ?>
    <div class="ipbox-overlay" role="dialog" aria-modal="true" aria-label="Image viewer">
      <div class="ipbox-loader"></div>
      <div class="ipbox-stage">
        <button class="ipbox-close" aria-label="Закрыть">✕</button>
        <button class="ipbox-prev" aria-label="Предыдущее">‹</button>
        <img class="ipbox-img" src="" alt="">
        <button class="ipbox-next" aria-label="Следующее">›</button>
      </div>
    </div>
    <?php
});