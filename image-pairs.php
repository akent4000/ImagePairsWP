<?php
/**
 * Plugin Name: Image Pairs
 * Description: Пары изображений с темами, подписями, лайтбоксом и динамической подгрузкой. Шорткод [image_pairs].
 * Version: 2.2.8
 * Author: you
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
        'seed'      => '',
        'hash_salt' => 'default',
    ];

    $a = shortcode_atts($defaults, $atts);

    $a['shuffle']  = in_array(strtolower((string)$a['shuffle']),  ['1','true','yes','on'], true) ? '1' : '0';
    $a['captions'] = in_array(strtolower((string)$a['captions']), ['0','false','no','off'], true) ? '0' : '1';

    if (empty($a['seed'])) {
        $a['seed'] = (string) wp_rand(1, PHP_INT_MAX);
    }

    $a['per_page'] = max(1, (int)$a['per_page']);

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
        'posts_per_page' => -1,
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
    return $args;
}

function ip_shuffle_with_seed(array $items, $seed) {
    if (count($items) < 2) return $items;
    $result = array_values($items);
    $seed = (int) $seed;
    mt_srand($seed);
    for ($i = count($result) - 1; $i > 0; $i--) {
        $j = mt_rand(0, $i);
        $tmp = $result[$i];
        $result[$i] = $result[$j];
        $result[$j] = $tmp;
    }
    mt_srand();
    return $result;
}

function ip_get_pairs_page_ids(array $a, $page, $per_page, &$total) {
    $args = ip_build_base_query_args($a);
    $ids = get_posts($args);
    $total = count($ids);

    if ($a['shuffle'] === '1' && $total > 1) {
        $ids = ip_shuffle_with_seed($ids, $a['seed']);
    }
    elseif ($a['orderby'] === 'hash' && $total > 1) {
        $salt = (string) $a['hash_salt'];
        $direction = ($a['order'] === 'ASC') ? 1 : -1;
        usort($ids, function ($id_a, $id_b) use ($salt, $direction) {
            $hash_a = md5($id_a . $salt);
            $hash_b = md5($id_b . $salt);
            return strcmp($hash_a, $hash_b) * $direction;
        });
    }

    $page = max(1, (int)$page);
    $per_page = max(1, (int)$per_page);
    $offset = ($page - 1) * $per_page;

    return array_slice($ids, $offset, $per_page);
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

/* ---------------- 2) Taxonomy ---------------- */
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

/* ---------------- 3) Metabox ---------------- */
add_action('add_meta_boxes', function () {
    add_meta_box('ip_images', 'Две картинки и подпись', 'ip_render_metabox', 'image_pair', 'normal', 'high');
});

function ip_render_metabox($post) {
    wp_nonce_field('ip_save', 'ip_nonce');
    $img1 = (int) get_post_meta($post->ID, '_ip_img1', true);
    $img2 = (int) get_post_meta($post->ID, '_ip_img2', true);
    $caption = get_post_meta($post->ID, '_ip_caption', true);
    
    // ЧИТАЕМ ГЛОБАЛЬНЫЕ АЛЬТЫ (из самого вложения)
    $alt1 = $img1 ? get_post_meta($img1, '_wp_attachment_image_alt', true) : '';
    $alt2 = $img2 ? get_post_meta($img2, '_wp_attachment_image_alt', true) : '';

    $src1 = $img1 ? wp_get_attachment_image_url($img1, 'medium') : '';
    $src2 = $img2 ? wp_get_attachment_image_url($img2, 'medium') : '';
    ?>
    <style>
      .ip-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px}
      .ip-item img{
          max-width:100%; 
          border-radius:8px; 
          display:block; 
          cursor:pointer; 
          transition: opacity 0.2s;
          margin-bottom: 8px;
      }
      .ip-item img:hover {
          opacity: 0.8;
          box-shadow: 0 0 0 2px #2271b1;
      }
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

                frame = wp.media({ 
                    title: 'Выбор изображения', 
                    button: { text: 'Применить' }, 
                    multiple: false 
                });

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
                    
                    // Если поле альта пустое, подставим текущий альт картинки для удобства
                    if(att.alt && $altInput.val() === '') {
                        $altInput.val(att.alt);
                    }
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
    
    // Сохраняем привязки к посту
    update_post_meta($post_id, '_ip_img1', $img1);
    update_post_meta($post_id, '_ip_img2', $img2);
    update_post_meta($post_id, '_ip_caption', $caption);

    // ОБНОВЛЯЕМ ГЛОБАЛЬНЫЕ АЛЬТЫ В МЕДИАБИБЛИОТЕКЕ
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

/* ---------------- 3.3) Столбцы в админке: Тема -> Заголовок -> Подпись -> Альты -> Дата ---------------- */

// 1. Порядок столбцов
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

// 2. Вывод контента с ЛОГИКОЙ ПОДСВЕТКИ
add_action('manage_image_pair_posts_custom_column', function($column, $post_id){
    // Подпись
    if ($column === 'ip_caption_col') {
        echo esc_html(get_post_meta($post_id, '_ip_caption', true));
    }
    
    // Вывод АЛЬТОВ
    if ($column === 'ip_alts_col') {
        $img1_id = (int) get_post_meta($post_id, '_ip_img1', true);
        $img2_id = (int) get_post_meta($post_id, '_ip_img2', true);
        
        // Получаем значения
        $alt1 = $img1_id ? get_post_meta($img1_id, '_wp_attachment_image_alt', true) : '';
        $alt2 = $img2_id ? get_post_meta($img2_id, '_wp_attachment_image_alt', true) : '';
        $caption = get_post_meta($post_id, '_ip_caption', true); 

        // Чистим строки для сравнения
        $alt2_clean = trim((string)$alt2);
        $caption_clean = trim((string)$caption);

        // ЛОГИКА: Если Альт 2 не равен Подписи — выделяем красным
        $style2_wrapper = '';
        if ($alt2_clean !== $caption_clean) {
            $style2_wrapper = 'background:#ffebeb; border:1px solid #f8cbcb; padding:2px 6px; border-radius:4px; display:inline-block; color:#d63638;';
        }

        // Вывод 1-й картинки
        echo '<div style="margin-bottom:6px;"><strong>1:</strong> ' . ($alt1 ? esc_html($alt1) : '<span style="color:#ccc">—</span>') . '</div>';
        
        // Вывод 2-й картинки
        echo '<div><strong>2:</strong> ';
        
        if ($style2_wrapper) {
            // Если есть различие — выделяем
            echo '<span style="' . $style2_wrapper . '">' . ($alt2 ? esc_html($alt2) : '<em>(пусто)</em>') . '</span>';
        } else {
            // Если совпадает — выводим обычным черным текстом (как остальной текст в таблице)
            echo esc_html($alt2); 
        }
        
        echo '</div>';
    }

}, 10, 2);

// 3. Сортируемые столбцы
add_filter('manage_edit-image_pair_sortable_columns', function($columns){
    $columns['taxonomy-ip_topic'] = 'ip_topic_sort';
    $columns['ip_caption_col']    = 'ip_caption_sort';
    return $columns;
});

// 4. SQL для сортировки
add_filter('posts_clauses', function($clauses, $query){
    global $wpdb;

    if (!is_admin() || !$query->is_main_query() || $query->get('post_type') !== 'image_pair') {
        return $clauses;
    }

    $orderby = $query->get('orderby');
    $order   = $query->get('order');

    if ($orderby === 'ip_topic_sort') {
        $clauses['join'] .= "
            LEFT JOIN {$wpdb->term_relationships} AS tr ON {$wpdb->posts}.ID = tr.object_id
            LEFT JOIN {$wpdb->term_taxonomy} AS tt ON tr.term_taxonomy_id = tt.term_taxonomy_id AND tt.taxonomy = 'ip_topic'
            LEFT JOIN {$wpdb->terms} AS t ON tt.term_id = t.term_id
            LEFT JOIN {$wpdb->postmeta} AS pm_cap ON ({$wpdb->posts}.ID = pm_cap.post_id AND pm_cap.meta_key = '_ip_caption')
        ";
        $clauses['orderby'] = "t.name $order, pm_cap.meta_value $order";
        $clauses['groupby'] = "{$wpdb->posts}.ID";
    }
    elseif ($orderby === 'ip_caption_sort') {
        $clauses['join'] .= "
            LEFT JOIN {$wpdb->postmeta} AS pm_cap ON ({$wpdb->posts}.ID = pm_cap.post_id AND pm_cap.meta_key = '_ip_caption')
            LEFT JOIN {$wpdb->term_relationships} AS tr ON {$wpdb->posts}.ID = tr.object_id
            LEFT JOIN {$wpdb->term_taxonomy} AS tt ON tr.term_taxonomy_id = tt.term_taxonomy_id AND tt.taxonomy = 'ip_topic'
            LEFT JOIN {$wpdb->terms} AS t ON tt.term_id = t.term_id
        ";
        $clauses['orderby'] = "pm_cap.meta_value $order, t.name $order";
        $clauses['groupby'] = "{$wpdb->posts}.ID";
    }

    return $clauses;
}, 10, 2);

/* ---------------- 3.4) Настройка ширины колонок (CSS) ---------------- */
add_action('admin_head', function() {
    global $typenow;
    if ($typenow !== 'image_pair') return;
    ?>
    <style>
        /* 1. Темы - делаем уже (10%) */
        th.column-taxonomy-ip_topic, 
        td.column-taxonomy-ip_topic { 
            width: 10%; 
        }

        /* 2. Заголовок - компактнее (15%) */
        th.column-title, 
        td.column-title { 
            width: 15%; 
            font-weight: 600; 
        }

        /* 3. Подпись (32%) */
        th.column-ip_caption_col, 
        td.column-ip_caption_col { 
            width: 32%; 
        }

        /* 4. Альты (32%) - шрифт стандартный */
        th.column-ip_alts_col, 
        td.column-ip_alts_col { 
            width: 32%; 
            color: #444; /* Чуть темнее для читаемости */
        }

        /* 5. Дата (остаток) */
        th.column-date, 
        td.column-date { 
            width: 10%; 
        }
    </style>
    <?php
});

/* ---------------- 3.5) Страница настроек CSS ---------------- */
add_action('admin_menu', function() {
    add_submenu_page(
        'edit.php?post_type=image_pair', // Родительское меню (Пары изображений)
        'Настройки CSS',                 // Заголовок страницы
        'Настройки CSS',                 // Название в меню
        'manage_options',                // Права доступа
        'image-pairs-css',               // Slug страницы
        'ip_render_css_page'             // Функция вывода
    );
});

function ip_render_css_page() {
    // Сохранение данных
    if (isset($_POST['ip_save_css']) && check_admin_referer('ip_css_action', 'ip_css_nonce')) {
        // Сохраняем "как есть", чтобы не ломать спецсимволы CSS (>, ", ' и т.д.)
        // stripslashes нужен, так как WP экранирует кавычки при POST
        $css = isset($_POST['ip_custom_css']) ? stripslashes($_POST['ip_custom_css']) : '';
        update_option('ip_custom_css', $css);
        echo '<div class="notice notice-success is-dismissible"><p>CSS сохранен!</p></div>';
    }

    $current_css = get_option('ip_custom_css', '');
    ?>
    <div class="wrap">
        <h1>Настройки CSS для Image Pairs</h1>
        <p>Здесь вы можете переопределить стандартные стили плагина. Этот код загружается <strong>после</strong> основного файла стилей.</p>
        
        <form method="post" action="">
            <?php wp_nonce_field('ip_css_action', 'ip_css_nonce'); ?>
            
            <textarea name="ip_custom_css" 
                      id="ip_custom_css" 
                      rows="15" 
                      class="large-text code" 
                      placeholder=".ip-pair { margin-bottom: 40px; }&#10;.ip-caption { color: red; }"
                      style="font-family: monospace; background: #282c34; color: #abb2bf; padding: 15px; border-radius: 5px;"><?php echo esc_textarea($current_css); ?></textarea>
            
            <p class="description">
                Примеры селекторов: <code>.ip-caption</code> (подпись), <code>.ip-row</code> (сетка), <code>.ipbox-overlay</code> (фон лайтбокса).
            </p>
            
            <p class="submit">
                <input type="submit" name="ip_save_css" id="submit" class="button button-primary" value="Сохранить изменения">
            </p>
        </form>
    </div>
    <?php
}

/* ---------------- 3.6) Показывать ВСЕ записи на одной странице ---------------- */
add_filter('edit_posts_per_page', function($per_page, $post_type){
    // Применяем только для нашего типа записи
    if ($post_type === 'image_pair') {
        return 999; // Устанавливаем лимит 999 записей на страницу
    }
    return $per_page;
}, 10, 2);

/* ---------------- 4) Подключение JS и CSS (Frontend) ---------------- */
add_action('wp_enqueue_scripts', function () {
    // 1. Подключаем основной файл стилей (image-pairs.css)
    wp_enqueue_style(
        'image-pairs-style',
        plugin_dir_url(__FILE__) . 'image-pairs.css',
        [],
        '1.0.1' 
    );

    // 2. Подключаем Пользовательский CSS (если есть)
    // Он вставится СРАЗУ ПОСЛЕ основного файла, перекрывая его стили
    $custom_css = get_option('ip_custom_css');
    if (!empty($custom_css)) {
        wp_add_inline_style('image-pairs-style', $custom_css);
    }

    // 3. Подключаем JS
    wp_enqueue_script(
        'image-pairs-frontend',
        plugin_dir_url(__FILE__) . 'image-pairs-frontend.js',
        [],
        '1.0.0',
        true
    );

    wp_localize_script('image-pairs-frontend', 'ipPairs', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce'   => wp_create_nonce('ip_pairs_nonce'),
    ]);
});

/* ---------------- 5) Shortcode ---------------- */
$GLOBALS['ip_enqueue_lightbox'] = false;

add_shortcode('image_pairs', function ($atts) {
    $a = ip_normalize_atts($atts);

    $page     = 1;
    $per_page = $a['per_page'];
    $total = 0;
    $ids   = ip_get_pairs_page_ids($a, $page, $per_page, $total);

    if (empty($ids)) return '';

    $GLOBALS['ip_enqueue_lightbox'] = true;

    $instance   = wp_unique_id('ip-instance-');
    $show_captions = ($a['captions'] === '1');
    $atts_for_js = wp_json_encode($a);
    $has_more = ($per_page < $total);

    ob_start(); ?>
    <div class="ip-wrap"
         data-ip-instance="<?php echo esc_attr($instance); ?>"
         data-per-page="<?php echo esc_attr($per_page); ?>"
         data-page="<?php echo esc_attr($page); ?>"
         data-atts="<?php echo esc_attr($atts_for_js); ?>">
      <?php
      foreach ($ids as $post_id):
        $img1    = (int) get_post_meta($post_id, '_ip_img1', true);
        $img2    = (int) get_post_meta($post_id, '_ip_img2', true);
        $caption = get_post_meta($post_id, '_ip_caption', true);
        if (!$img1 && !$img2) continue;

        $src1  = $img1 ? wp_get_attachment_image_src($img1, $a['size']) : null;
        $src2  = $img2 ? wp_get_attachment_image_src($img2, $a['size']) : null;
        $full1 = $img1 ? wp_get_attachment_image_src($img1, 'full') : null;
        $full2 = $img2 ? wp_get_attachment_image_src($img2, 'full') : null;
        $src1_webp = $src1 ? ip_get_src_with_webp($src1[0]) : null;
        $src2_webp = $src2 ? ip_get_src_with_webp($src2[0]) : null;

        $alt1 = $img1 ? trim(get_post_meta($img1, '_wp_attachment_image_alt', true)) : '';
        if (!$alt1 && $img1) $alt1 = get_the_title($img1);
        $alt2 = $img2 ? trim(get_post_meta($img2, '_wp_attachment_image_alt', true)) : '';
        if (!$alt2 && $img2) $alt2 = get_the_title($img2);
      ?>
        <div class="ip-pair">
          <div class="ip-row">
            <div>
              <?php if ($src1) { ?>
                <a href="<?php echo esc_url($full1[0]); ?>" class="ip-zoom" data-ipbox="<?php echo esc_attr($instance); ?>">
                  <picture>
                    <?php if (!empty($src1_webp['webp'])): ?>
                      <source srcset="<?php echo esc_url($src1_webp['webp']); ?>" type="image/webp">
                    <?php endif; ?>
                    <img loading="lazy" decoding="async" src="<?php echo esc_url($src1_webp['orig']); ?>" alt="<?php echo esc_attr($alt1); ?>">
                  </picture>
                </a>
              <?php } ?>
            </div>
            <div>
              <?php if ($src2) { ?>
                <a href="<?php echo esc_url($full2[0]); ?>" class="ip-zoom" data-ipbox="<?php echo esc_attr($instance); ?>">
                  <picture>
                    <?php if (!empty($src2_webp['webp'])): ?>
                      <source srcset="<?php echo esc_url($src2_webp['webp']); ?>" type="image/webp">
                    <?php endif; ?>
                    <img loading="lazy" decoding="async" src="<?php echo esc_url($src2_webp['orig']); ?>" alt="<?php echo esc_attr($alt2); ?>">
                  </picture>
                </a>
              <?php } ?>
            </div>
          </div>
          <?php if ($show_captions && $caption !== '') { ?>
            <span class="ip-caption"><?php echo esc_html($caption); ?></span>
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

/* ---------------- 6) AJAX Load Pairs ---------------- */
add_action('wp_ajax_ip_load_pairs', 'ip_ajax_load_pairs');
add_action('wp_ajax_nopriv_ip_load_pairs', 'ip_ajax_load_pairs');

function ip_ajax_load_pairs() {
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'ip_pairs_nonce')) {
        wp_send_json_error(['message' => 'Bad nonce']);
    }

    $page     = isset($_POST['page']) ? (int) $_POST['page'] : 1;
    $per_page = isset($_POST['per_page']) ? (int) $_POST['per_page'] : 20;
    $atts_raw = isset($_POST['atts']) ? stripslashes($_POST['atts']) : '{}';

    $atts = json_decode($atts_raw, true);
    if (!is_array($atts)) $atts = [];

    $a = ip_normalize_atts($atts);
    $total = 0;
    $ids   = ip_get_pairs_page_ids($a, $page, $per_page, $total);

    if (empty($ids)) {
        wp_send_json_success([
            'html'     => '',
            'has_more' => false,
        ]);
    }

    $instance = isset($_POST['instance']) ? sanitize_text_field($_POST['instance']) : wp_unique_id('ip-instance-');
    $show_captions = ($a['captions'] === '1');

    ob_start();
    foreach ($ids as $post_id) {
        $img1    = (int) get_post_meta($post_id, '_ip_img1', true);
        $img2    = (int) get_post_meta($post_id, '_ip_img2', true);
        $caption = get_post_meta($post_id, '_ip_caption', true);
        if (!$img1 && !$img2) continue;

        $src1  = $img1 ? wp_get_attachment_image_src($img1, $a['size']) : null;
        $src2  = $img2 ? wp_get_attachment_image_src($img2, $a['size']) : null;
        $full1 = $img1 ? wp_get_attachment_image_src($img1, 'full') : null;
        $full2 = $img2 ? wp_get_attachment_image_src($img2, 'full') : null;
        $src1_webp = $src1 ? ip_get_src_with_webp($src1[0]) : null;
        $src2_webp = $src2 ? ip_get_src_with_webp($src2[0]) : null;

        $alt1 = $img1 ? trim(get_post_meta($img1, '_wp_attachment_image_alt', true)) : '';
        if (!$alt1 && $img1) $alt1 = get_the_title($img1);
        $alt2 = $img2 ? trim(get_post_meta($img2, '_wp_attachment_image_alt', true)) : '';
        if (!$alt2 && $img2) $alt2 = get_the_title($img2);
        ?>
        <div class="ip-pair">
          <div class="ip-row">
            <div>
              <?php if ($src1) { ?>
                <a href="<?php echo esc_url($full1[0]); ?>" class="ip-zoom" data-ipbox="<?php echo esc_attr($instance); ?>">
                  <picture>
                    <?php if (!empty($src1_webp['webp'])): ?>
                      <source srcset="<?php echo esc_url($src1_webp['webp']); ?>" type="image/webp">
                    <?php endif; ?>
                    <img loading="lazy" decoding="async" src="<?php echo esc_url($src1_webp['orig']); ?>" alt="<?php echo esc_attr($alt1); ?>">
                  </picture>
                </a>
              <?php } ?>
            </div>
            <div>
              <?php if ($src2) { ?>
                <a href="<?php echo esc_url($full2[0]); ?>" class="ip-zoom" data-ipbox="<?php echo esc_attr($instance); ?>">
                  <picture>
                    <?php if (!empty($src2_webp['webp'])): ?>
                      <source srcset="<?php echo esc_url($src2_webp['webp']); ?>" type="image/webp">
                    <?php endif; ?>
                    <img loading="lazy" decoding="async" src="<?php echo esc_url($src2_webp['orig']); ?>" alt="<?php echo esc_attr($alt2); ?>">
                  </picture>
                </a>
              <?php } ?>
            </div>
          </div>
          <?php if ($show_captions && $caption !== '') { ?>
            <span class="ip-caption"><?php echo esc_html($caption); ?></span>
          <?php } ?>
        </div>
        <?php
    }
    $html = ob_get_clean();

    $max_page = (int) ceil($total / max(1, $per_page));
    $has_more = ($page < $max_page);

    wp_send_json_success([
        'html'      => $html,
        'has_more'  => $has_more,
        'next_page' => $page,
    ]);
}

/* ---------------- 7) Lightbox HTML & JS ---------------- */
add_action('wp_footer', function(){
    if (empty($GLOBALS['ip_enqueue_lightbox'])) return;
    ?>
    <div class="ipbox-overlay" role="dialog" aria-modal="true" aria-label="Image viewer">
      <div class="ipbox-stage">
        <button class="ipbox-close" aria-label="Закрыть">✕</button>
        <button class="ipbox-prev" aria-label="Предыдущее">‹</button>
        <img class="ipbox-img" src="" alt="">
        <button class="ipbox-next" aria-label="Следующее">›</button>
      </div>
    </div>

    <script id="ipbox-js">
      (function(){
        const overlay = document.querySelector('.ipbox-overlay');
        
        // Защита: если разметки нет, выходим, чтобы не ломать JS
        if (!overlay) return;

        const img = overlay.querySelector('.ipbox-img');
        const prevBtn = overlay.querySelector('.ipbox-prev');
        const nextBtn = overlay.querySelector('.ipbox-next');
        const closeBtn= overlay.querySelector('.ipbox-close');

        let group = [];
        let index = -1;
        let currentAnchor = null;
        let currentInstance = null;

        function open(anchor, g, i){
          group = g; index = i; currentAnchor = anchor;
          img.src = anchor.href;
          overlay.classList.add('is-open');
          document.body.style.overflow = 'hidden';
          document.body.classList.add('ipbox-open');
          updateArrows();
        }
        function close(){
          overlay.classList.remove('is-open');
          img.src = '';
          document.body.style.overflow = '';
          document.body.classList.remove('ipbox-open');
          group = []; index = -1; currentAnchor = null; currentInstance = null;
        }
        function updateArrows(){
          const showNav = group.length > 1;
          if(prevBtn) prevBtn.style.display = showNav ? '' : 'none';
          if(nextBtn) nextBtn.style.display = showNav ? '' : 'none';
        }
        function show(delta){
          if (!group.length) return;
          index = (index + delta + group.length) % group.length;
          currentAnchor = group[index];
          img.src = currentAnchor.href;
        }

        document.addEventListener('click', function(e){
          const a = e.target.closest('a.ip-zoom');
          if (!a) return;
          e.preventDefault();

          const inst = a.getAttribute('data-ipbox') || 'default';

          if (overlay.classList.contains('is-open') && currentAnchor === a && currentInstance === inst) {
            close();
            return;
          }

          currentInstance = inst;
          const siblings = Array.from(document.querySelectorAll('a.ip-zoom[data-ipbox="'+inst+'"]'));
          const i = Math.max(0, siblings.indexOf(a));
          open(a, siblings, i);
        });

        overlay.addEventListener('click', function(e){
          if (e.target === overlay || e.target === img) close();
        });

        document.addEventListener('keydown', function(e){
          if (!overlay.classList.contains('is-open')) return;
          if (e.key === 'Escape') close();
          if (e.key === 'ArrowLeft') show(-1);
          if (e.key === 'ArrowRight') show(1);
        });

        if(prevBtn) prevBtn.addEventListener('click', function(){ show(-1); });
        if(nextBtn) nextBtn.addEventListener('click', function(){ show(1); });
        if(closeBtn) closeBtn.addEventListener('click', close);
      })();
    </script>
    <?php
});