<?php
/**
 * Plugin Name: Image Pairs
 * Description: Пары изображений с темами, подписями и лайтбоксом. Шорткод [image_pairs].
 * Version: 1.8.0
 * Author: akent4000
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

/* ---------------- 2) Taxonomy ---------------- */
add_action('init', function () {
    register_taxonomy('ip_topic', ['image_pair'], [
        'labels' => [
            'name'              => 'Темы',
            'singular_name'     => 'Тема',
            'search_items'      => 'Искать темы',
            'all_items'         => 'Все темы',
            'edit_item'         => 'Редактировать тему',
            'update_item'       => 'Обновить тему',
            'add_new_item'      => 'Добавить тему',
            'new_item_name'     => 'Название новой темы',
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
    $src1 = $img1 ? wp_get_attachment_image_url($img1, 'medium') : '';
    $src2 = $img2 ? wp_get_attachment_image_url($img2, 'medium') : '';
    ?>
    <style>
      .ip-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px}
      .ip-item img{max-width:100%;border-radius:8px;display:block}
      .ip-buttons{margin-top:8px}
      .ip-caption-wrap label{display:block;margin-bottom:6px;font-weight:600}
      .ip-caption-wrap input[type="text"], .ip-caption-wrap textarea{width:100%}
    </style>
    <div class="ip-grid">
      <div class="ip-item">
        <label><strong>Картинка 1</strong></label>
        <div><img id="ip_img1_preview" src="<?php echo esc_url($src1); ?>" <?php echo $src1?'':'style="display:none"'; ?>></div>
        <div class="ip-buttons">
          <input type="hidden" name="ip_img1" id="ip_img1" value="<?php echo esc_attr($img1); ?>">
          <button type="button" class="button" id="ip_img1_select">Выбрать</button>
          <button type="button" class="button" id="ip_img1_clear">Очистить</button>
        </div>
      </div>
      <div class="ip-item">
        <label><strong>Картинка 2</strong></label>
        <div><img id="ip_img2_preview" src="<?php echo esc_url($src2); ?>" <?php echo $src2?'':'style="display:none"'; ?>></div>
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
      <p class="description">Этот текст отобразится под парой в теге &lt;span&gt;.</p>
    </div>

    <script>
      jQuery(function($){
        function bindMedia(selectBtn, inputId, previewId, clearBtn){
          let frame;
          $('#'+selectBtn).on('click', function(e){
            e.preventDefault();
            frame = wp.media({ title: 'Выберите изображение', button: { text: 'Использовать' }, multiple: false });
            frame.on('select', function(){
              const att = frame.state().get('selection').first().toJSON();
              $('#'+inputId).val(att.id);
              $('#'+previewId).attr('src', (att.sizes && att.sizes.medium ? att.sizes.medium.url : att.url)).show();
            });
            frame.open();
          });
          $('#'+clearBtn).on('click', function(){
            $('#'+inputId).val('');
            $('#'+previewId).hide().attr('src','');
          });
        }
        bindMedia('ip_img1_select','ip_img1','ip_img1_preview','ip_img1_clear');
        bindMedia('ip_img2_select','ip_img2','ip_img2_preview','ip_img2_clear');
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
}, 10, 1);

/* ---------------- 4) Shortcode с фильтрами, подписями и shuffle ---------------- */
$GLOBALS['ip_enqueue_lightbox'] = false;

add_shortcode('image_pairs', function ($atts) {
    $a = shortcode_atts([
        'orderby'   => 'date',
        'order'     => 'DESC',
        'size'      => 'large',
        'topic'     => '',
        'topic_ids' => '',
        'operator'  => 'IN',
        'shuffle'   => '0',
		'captions'  => '1',
    ], $atts);

    $shuffle = in_array(strtolower((string) $a['shuffle']), ['1','true','yes','on'], true);
	$show_captions = !in_array(strtolower((string) $a['captions']), ['0','false','no','off'], true);

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
    if (count($tax_query) > 1) $tax_query = array_merge(['relation' => 'AND'], $tax_query);

    $args = [
        'post_type'      => 'image_pair',
        'posts_per_page' => -1,
        'orderby'        => sanitize_key($a['orderby']),
        'order'          => $a['order'] === 'ASC' ? 'ASC' : 'DESC',
        'no_found_rows'  => true,
    ];
    if (!empty($tax_query)) $args['tax_query'] = $tax_query;

    $q = new WP_Query($args);
    if (!$q->have_posts()) return '';

    if ($shuffle && !empty($q->posts)) {
        $q->posts = array_values($q->posts);
        shuffle($q->posts);
        $q->rewind_posts();
    }

    $GLOBALS['ip_enqueue_lightbox'] = true;

    $instance = wp_unique_id('ip-instance-');

    ob_start(); ?>
    <style>
      .ip-wrap{display:grid;grid-template-columns:1fr;gap:0}
      .ip-pair{margin-bottom:20px} /* расстояние между парами после подписи */
      .ip-row{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}
      .ip-row a{display:block;border-radius:10px;overflow:hidden}
      .ip-row img{width:100%;height:auto;display:block}

      .ip-caption{
        display:block;
        margin-top:8px;
        /* типографика как на скрине: */
        font-family: "Manrope", Inter, "Segoe UI", Roboto, Arial, sans-serif;
        font-size:14px;
        font-weight:600;
        text-transform:uppercase;
        line-height:1.4;
      }

      @media (max-width:640px){
        .ip-row{grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}
      }
    </style>
    <div class="ip-wrap" data-ip-instance="<?php echo esc_attr($instance); ?>">
      <?php while($q->have_posts()):
        $q->the_post();
        $img1 = (int) get_post_meta(get_the_ID(), '_ip_img1', true);
        $img2 = (int) get_post_meta(get_the_ID(), '_ip_img2', true);
        $caption = get_post_meta(get_the_ID(), '_ip_caption', true);
        if(!$img1 && !$img2) continue;

        $src1  = $img1 ? wp_get_attachment_image_src($img1, $a['size']) : null;
        $src2  = $img2 ? wp_get_attachment_image_src($img2, $a['size']) : null;
        $full1 = $img1 ? wp_get_attachment_image_src($img1, 'full') : null;
        $full2 = $img2 ? wp_get_attachment_image_src($img2, 'full') : null;

        $alt1 = $img1 ? trim(get_post_meta($img1, '_wp_attachment_image_alt', true)) : '';
        if(!$alt1 && $img1) $alt1 = get_the_title($img1);
        $alt2 = $img2 ? trim(get_post_meta($img2, '_wp_attachment_image_alt', true)) : '';
        if(!$alt2 && $img2) $alt2 = get_the_title($img2);
      ?>
        <div class="ip-pair">
          <div class="ip-row">
            <div>
              <?php if($src1){ ?>
                <a href="<?php echo esc_url($full1[0]); ?>" class="ip-zoom" data-ipbox="<?php echo esc_attr($instance); ?>">
                  <img loading="lazy" decoding="async" src="<?php echo esc_url($src1[0]); ?>" alt="<?php echo esc_attr($alt1); ?>">
                </a>
              <?php } ?>
            </div>
            <div>
              <?php if($src2){ ?>
                <a href="<?php echo esc_url($full2[0]); ?>" class="ip-zoom" data-ipbox="<?php echo esc_attr($instance); ?>">
                  <img loading="lazy" decoding="async" src="<?php echo esc_url($src2[0]); ?>" alt="<?php echo esc_attr($alt2); ?>">
                </a>
              <?php } ?>
            </div>
          </div>
          <?php if ($show_captions && $caption !== '') { ?>
            <span class="ip-caption"><?php echo esc_html($caption); ?></span>
          <?php } ?>

        </div>
      <?php endwhile; wp_reset_postdata(); ?>
    </div>
    <?php
    return ob_get_clean();
});

/* ---------------- 5) Админ медиа ---------------- */
add_action('admin_enqueue_scripts', function(){
    global $post_type;
    if ($post_type === 'image_pair') wp_enqueue_media();
});

/* ---------------- 6) Фильтр в списке ---------------- */
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

/* ---------------- 7) Лайтбокс: CSS/JS ---------------- */
add_action('wp_footer', function(){
    if (empty($GLOBALS['ip_enqueue_lightbox'])) return;
    ?>
    <style id="ipbox-css">
      .ipbox-overlay{
        position:fixed; inset:0; display:none;
        align-items:center; justify-content:center;
        z-index:2147483646;
      }
      .ipbox-overlay.is-open{ display:flex; }
      .ipbox-overlay::before{
        content:""; position:absolute; inset:0;
        background:#000 !important; opacity:.92; pointer-events:none;
      }
      .ipbox-stage{ position:relative; max-width:90vw; max-height:90vh; z-index:1; }
      .ipbox-img{ max-width:90vw; max-height:90vh; display:block; }
      .ipbox-close,.ipbox-prev,.ipbox-next{
        position:absolute; top:50%; transform:translateY(-50%);
        padding:12px 14px; background:rgba(0,0,0,.55);
        color:#fff; border-radius:8px; border:none; cursor:pointer;
        font-size:18px; line-height:1; z-index:2; outline:none; box-shadow:none !important;
      }
      .ipbox-close{ top:16px; right:16px; transform:none; }
      .ipbox-prev{ left:-56px; }
      .ipbox-next{ right:-56px; }
      @media (max-width:768px){ .ipbox-prev{ left:8px; } .ipbox-next{ right:8px; } }
      body.ipbox-open .elementor-lightbox,
      body.ipbox-open .pswp,
      body.ipbox-open .mfp-wrap,
      body.ipbox-open .glightbox-container,
      body.ipbox-open .lg-container,
      body.ipbox-open .fancybox__container { display:none !important; }
    </style>

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
          prevBtn.style.display = showNav ? '' : 'none';
          nextBtn.style.display = showNav ? '' : 'none';
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

        prevBtn.addEventListener('click', function(){ show(-1); });
        nextBtn.addEventListener('click', function(){ show(1); });
        closeBtn.addEventListener('click', close);
      })();
    </script>
    <?php
});
