<?php
/*
Plugin Name: Cursos y Lecciones
Description: Mini academia con cursos y lecciones visuales.
Version: 1.4
Author: Wembleys Studios
*/

if ( ! defined( 'ABSPATH' ) ) exit;

define('CL_CIE_VERSION', '1.4');

/* =====================================================
   META KEYS / CONSTANTES
===================================================== */
define('CL_META_ACCESS_MODE', '_cl_access_mode'); // libre | inscripcion
define('CL_META_ENROLLED_USERS', '_cl_enrolled_users'); // array user IDs
define('CL_META_COURSE_AUTOEVAL', '_cl_course_autoeval'); // 0/1
define('CL_META_COURSE_EXAM_NOTIFY_USER', '_cl_exam_notify_user_id'); // int user id
define('CL_META_EXAM_TIME_SECONDS', '_cl_exam_time_seconds'); // int seconds

// Lecciones (reemplazo de ACF "Contenido de lección")
define('CL_META_LESSON_TYPE', '_cl_tipo_de_leccion'); // normal | video | examen
define('CL_META_LESSON_VIDEO', '_cl_video_tracking'); // string (embed/html/shortcode)
define('CL_META_LESSON_VIDEO_ATTACHMENT_ID', '_cl_video_attachment_id'); // int attachment ID (wp media)
define('CL_META_LESSON_MIN_SECONDS', '_cl_tiempo_minimo_seconds'); // int seconds

/* =====================================================
   COMPAT ACF (para poder desinstalar ACF sin fatal)
===================================================== */
function cl_get_field_compat($key, $post_id) {
    if (function_exists('get_field')) {
        return get_field($key, $post_id);
    }
    return get_post_meta($post_id, $key, true);
}

/* =====================================================
   DB: HISTÓRICO DE VISUALIZACIÓN (POR SEGUNDO)
===================================================== */
function cl_get_hist_table_name() {
    global $wpdb;
    return $wpdb->prefix . 'cl_vistas_lecciones';
}

function cl_create_hist_table() {
    global $wpdb;
    $table_name = cl_get_hist_table_name();
    $charset_collate = $wpdb->get_charset_collate();

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $sql = "CREATE TABLE {$table_name} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        user_id bigint(20) unsigned NOT NULL,
        curso_id bigint(20) unsigned NOT NULL,
        leccion_id bigint(20) unsigned NOT NULL,
        segundo int(10) unsigned NOT NULL,
        tipo varchar(20) NOT NULL DEFAULT 'pantalla',
        created_at datetime NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY user_leccion_segundo_tipo (user_id, leccion_id, segundo, tipo),
        KEY curso_leccion (curso_id, leccion_id),
        KEY user_curso (user_id, curso_id)
    ) {$charset_collate};";

    dbDelta($sql);
}

register_activation_hook(__FILE__, function() {
    cl_create_hist_table();
    update_option('cl_db_version', 1);
});

add_action('plugins_loaded', function() {
    $db_version = intval(get_option('cl_db_version', 0));
    if($db_version < 1){
        cl_create_hist_table();
        update_option('cl_db_version', 1);
    }
});

/* =====================================================
   UTILS
===================================================== */
function cl_parse_time_to_seconds($time_value) {
    if ($time_value === null || $time_value === false || $time_value === '') return 0;

    // Si ya viene numérico (minutos/segundos), respetar
    if (is_numeric($time_value)) return max(0, intval($time_value));

    $time_value = trim((string)$time_value);

    // ACF suele devolver HH:MM:SS (pero a veces HH:MM).
    // En algunos setups (duraciones) se ven valores tipo "MM:SS:00".
    $parts = explode(':', $time_value);
    $parts = array_map('intval', $parts);

    if (count($parts) === 3) {
        [$h, $m, $s] = $parts;
        // Heurística: si viene como "MM:00:00", interpretarlo como minutos (duración),
        // ya que muchos campos de duración acaban devolviendo 3 partes.
        if ($m === 0 && $s === 0) {
            return max(0, $h * 60);
        }
        return max(0, ($h * 3600) + ($m * 60) + $s);
    }

    if (count($parts) === 2) {
        [$m, $s] = $parts;
        return max(0, ($m * 60) + $s);
    }

    // Formato desconocido
    return 0;
}

function cl_seconds_to_human_mmss($seconds) {
    $seconds = max(0, (int)$seconds);
    $m = (int) floor($seconds / 60);
    $s = $seconds % 60;
    return sprintf('%02d:%02d', $m, $s);
}

function cl_parse_mmss_to_seconds($value) {
    if ($value === null || $value === false) return 0;
    $value = trim((string)$value);
    if ($value === '') return 0;
    if (is_numeric($value)) return max(0, (int)$value);

    // 00:00 (i:s)
    if (preg_match('/^\d+:\d{2}$/', $value)) {
        [$m, $s] = array_map('intval', explode(':', $value));
        return max(0, ($m * 60) + $s);
    }

    // 00:00:00 (H:i:s)
    if (preg_match('/^\d+:\d{2}:\d{2}$/', $value)) {
        [$h, $m, $s] = array_map('intval', explode(':', $value));
        return max(0, ($h * 3600) + ($m * 60) + $s);
    }

    return 0;
}

/* =====================================================
   CPT: CURSOS
===================================================== */
add_action( 'init', function() {
    register_post_type( 'curso-cie', [
        'label' => 'Cursos',
        'public' => true,
        'menu_icon' => 'dashicons-welcome-learn-more',
        'supports' => [ 'title', 'editor', 'thumbnail' ],
        'show_in_rest' => false,
    ]);
});

/* =====================================================
   CPT: LECCIONES
===================================================== */
add_action( 'init', function() {
    register_post_type( 'lecciones-cie', [
        'label' => 'Lecciones',
        'public' => true,
        'menu_icon' => 'dashicons-media-document',
        'supports' => [ 'title', 'editor', 'thumbnail' ],
        'hierarchical' => true,
        'show_in_rest' => false,
    ]);
});

/* =====================================================
   CURSO (admin): lecciones dentro del curso + configuración
===================================================== */
add_action('add_meta_boxes', function() {
    add_meta_box(
        'cl_curso_lecciones',
        'Lecciones del curso',
        'cl_render_curso_lecciones_metabox',
        'curso-cie',
        'normal',
        'high'
    );

    add_meta_box(
        'cl_curso_inscritos',
        'Usuarios inscritos',
        'cl_render_curso_inscritos_metabox',
        'curso-cie',
        'normal',
        'default'
    );

    add_meta_box(
        'cl_curso_config',
        'Configuración del curso (acceso + exámenes)',
        'cl_render_curso_config_metabox',
        'curso-cie',
        'side',
        'high'
    );
});

/* =====================================================
   OCULTAR SLUG
===================================================== */
add_action('add_meta_boxes', function() {
    remove_meta_box('slugdiv', 'curso-cie', 'normal');
    remove_meta_box('slugdiv', 'lecciones-cie', 'normal');
}, 100);

/* =====================================================
   ENCOLAR FRONTEND
===================================================== */
add_action('wp_enqueue_scripts', function(){
    if(!is_singular('curso-cie')) return;

    wp_enqueue_script(
        'cl-frontend-js',
        plugin_dir_url(__FILE__) . 'assets/js/frontend.js',
        ['jquery'],
        CL_CIE_VERSION,
        true
    );

    wp_localize_script('cl-frontend-js','cl_ajax',[
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce'    => wp_create_nonce('cl_ajax_nonce')
    ]);

    wp_enqueue_style(
        'cl-frontend-css',
        plugin_dir_url(__FILE__) . 'assets/css/frontend.css',
        [],
        CL_CIE_VERSION
    );
});

/* =====================================================
   CPT: INTENTOS DE EXAMEN (privado)
===================================================== */
add_action('init', function() {
    register_post_type('cl-exam-attempt', [
        'label' => 'Intentos de examen',
        'public' => false,
        'show_ui' => false, // gestión desde pantalla propia
        'supports' => ['title'],
        'capability_type' => 'post',
    ]);
});

/* =====================================================
   OBTENER LECCIONES ORDENADAS
===================================================== */
function cl_get_lecciones_ordenadas($curso_id){
    return get_posts([
        'post_type'   => 'lecciones-cie',
        'post_parent' => $curso_id,
        'numberposts' => -1,
        'orderby'     => 'menu_order',
        'order'       => 'ASC',
    ]);
}

/* =====================================================
   ACCESO POR INSCRIPCIÓN (utils)
===================================================== */
function cl_course_access_mode($curso_id) {
    $mode = get_post_meta($curso_id, CL_META_ACCESS_MODE, true);
    return in_array($mode, ['libre', 'inscripcion'], true) ? $mode : 'libre';
}

function cl_get_enrolled_user_ids($curso_id) {
    $ids = get_post_meta($curso_id, CL_META_ENROLLED_USERS, true);
    if (!is_array($ids)) $ids = [];
    $ids = array_values(array_unique(array_map('absint', $ids)));
    return array_values(array_filter($ids));
}

function cl_is_user_enrolled_in_course($user_id, $curso_id) {
    $user_id = absint($user_id);
    if (!$user_id) return false;
    $curso = get_post($curso_id);
    if (!$curso || $curso->post_type !== 'curso-cie') return false;
    if ((int)$curso->post_author === (int)$user_id) return true;
    if (user_can($user_id, 'manage_options')) return true;
    if (cl_course_access_mode($curso_id) === 'libre') return true;
    return in_array($user_id, cl_get_enrolled_user_ids($curso_id), true);
}

/* =====================================================
   CURSO (admin): metaboxes
===================================================== */
function cl_render_curso_lecciones_metabox($post) {
    wp_nonce_field('cl_curso_lecciones_save', 'cl_curso_lecciones_nonce');
    $lecciones = cl_get_lecciones_ordenadas($post->ID);
    ?>
    <div class="cl-curso-lecciones-metabox">
        <p style="margin:0 0 8px;">
            <label for="cl-nueva-leccion-titulo" style="display:block; font-weight:600; margin-bottom:6px;">Añadir nueva lección</label>
            <input type="text" id="cl-nueva-leccion-titulo" style="width:100%;" placeholder="Título de la lección" />
        </p>
        <p style="margin:0 0 12px;">
            <button type="button" class="button button-primary" id="cl-btn-crear-leccion">Añadir lección</button>
        </p>

        <p class="description" style="margin-top:0;">Arrastra para ordenar. Puedes cambiar el título en línea y abrir el editor en otra pestaña.</p>

        <ul id="cl-lecciones-list">
            <?php if (empty($lecciones)): ?>
                <li>No hay lecciones todavía.</li>
            <?php else: ?>
                <?php foreach ($lecciones as $l): ?>
                    <li data-id="<?php echo esc_attr($l->ID); ?>">
                        <input type="text" class="cl-leccion-title" value="<?php echo esc_attr($l->post_title); ?>" style="width:55%; max-width:420px;" />
                        <a href="<?php echo esc_url(get_edit_post_link($l->ID, '')); ?>" target="_blank" style="margin-left:10px;">Editar</a>
                        <button type="button" class="button-link-delete cl-btn-eliminar" data-id="<?php echo esc_attr($l->ID); ?>" style="margin-left:10px;">Eliminar</button>
                    </li>
                <?php endforeach; ?>
            <?php endif; ?>
        </ul>
    </div>
    <?php
}

function cl_render_curso_inscritos_metabox($post) {
    wp_nonce_field('cl_curso_inscritos_save', 'cl_curso_inscritos_nonce');
    $enrolled = cl_get_enrolled_user_ids($post->ID);
    $users = get_users(['orderby' => 'display_name', 'order' => 'ASC']);
    ?>
    <p class="description">Selecciona usuarios para inscribirlos manualmente. Solo afecta si el curso está configurado como “Por inscripción”.</p>
    <div style="max-height:260px; overflow:auto; border:1px solid #ddd; padding:10px; background:#fff;">
        <?php foreach ($users as $u): ?>
            <label style="display:block; margin:4px 0;">
                <input type="checkbox" name="cl_enrolled_users[]" value="<?php echo esc_attr($u->ID); ?>" <?php checked(in_array($u->ID, $enrolled, true)); ?> />
                <?php echo esc_html($u->display_name . ' (' . $u->user_login . ')'); ?>
            </label>
        <?php endforeach; ?>
    </div>
    <?php
}

function cl_render_curso_config_metabox($post) {
    wp_nonce_field('cl_curso_config_save', 'cl_curso_config_nonce');
    $access_mode = cl_course_access_mode($post->ID);
    $autoeval = (int) get_post_meta($post->ID, CL_META_COURSE_AUTOEVAL, true);
    $notify_uid = (int) get_post_meta($post->ID, CL_META_COURSE_EXAM_NOTIFY_USER, true);
    $admins = get_users(['role' => 'administrator', 'orderby' => 'display_name', 'order' => 'ASC']);
    ?>
    <p>
        <label style="display:block; font-weight:600; margin-bottom:6px;">Acceso al curso</label>
        <select name="cl_access_mode" style="width:100%;">
            <option value="libre" <?php selected($access_mode, 'libre'); ?>>Libre acceso</option>
            <option value="inscripcion" <?php selected($access_mode, 'inscripcion'); ?>>Por inscripción (manual)</option>
        </select>
        <span class="description">Si es “Por inscripción”, solo accederán usuarios inscritos manualmente.</span>
    </p>

    <p>
        <label style="display:block; font-weight:600; margin-bottom:6px;">Auto evaluación</label>
        <label>
            <input type="checkbox" name="cl_course_autoeval" value="1" <?php checked($autoeval, 1); ?> />
            No requiere validación del admin (auto-aprueba el examen al enviarlo)
        </label>
    </p>

    <p>
        <label style="display:block; font-weight:600; margin-bottom:6px;">Enviar email de examen realizado a</label>
        <select name="cl_exam_notify_user_id" style="width:100%;">
            <option value="0">Autor del curso (por defecto)</option>
            <?php foreach ($admins as $u): ?>
                <option value="<?php echo esc_attr($u->ID); ?>" <?php selected($notify_uid, $u->ID); ?>>
                    <?php echo esc_html($u->display_name . ' (' . $u->user_login . ')'); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </p>
    <?php
}

add_action('save_post_curso-cie', function($post_id) {
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!current_user_can('edit_post', $post_id)) return;

    if (isset($_POST['cl_curso_config_nonce']) && wp_verify_nonce($_POST['cl_curso_config_nonce'], 'cl_curso_config_save')) {
        $mode = isset($_POST['cl_access_mode']) ? sanitize_text_field($_POST['cl_access_mode']) : 'libre';
        if (!in_array($mode, ['libre', 'inscripcion'], true)) $mode = 'libre';
        update_post_meta($post_id, CL_META_ACCESS_MODE, $mode);

        $autoeval = !empty($_POST['cl_course_autoeval']) ? 1 : 0;
        update_post_meta($post_id, CL_META_COURSE_AUTOEVAL, $autoeval);

        $notify_uid = isset($_POST['cl_exam_notify_user_id']) ? absint($_POST['cl_exam_notify_user_id']) : 0;
        update_post_meta($post_id, CL_META_COURSE_EXAM_NOTIFY_USER, $notify_uid);
    }

    if (isset($_POST['cl_curso_inscritos_nonce']) && wp_verify_nonce($_POST['cl_curso_inscritos_nonce'], 'cl_curso_inscritos_save')) {
        $ids = isset($_POST['cl_enrolled_users']) ? (array) $_POST['cl_enrolled_users'] : [];
        $ids = array_values(array_unique(array_filter(array_map('absint', $ids))));
        update_post_meta($post_id, CL_META_ENROLLED_USERS, $ids);
    }
});

/* =====================================================
   SIDEBAR
===================================================== */
function cl_render_sidebar_lecciones($curso_id, $leccion_actual_id){
    $lecciones = cl_get_lecciones_ordenadas($curso_id);
    $user_id = get_current_user_id();
    $completadas = get_user_meta($user_id, "cl_curso_{$curso_id}_completadas", true);
    if(!is_array($completadas)) $completadas = [];

    echo '<ul class="cl-sidebar-lecciones">';
    foreach($lecciones as $l){
        $class = '';
        if($l->ID === $leccion_actual_id) $class = 'actual';
        elseif(in_array($l->ID, $completadas)) $class = 'completada';

        echo '<li class="'.esc_attr($class).'">'.esc_html($l->post_title).'</li>';
    }
    echo '</ul>';
}

/* =====================================================
   LECCIÓN: CONTENIDO (tipo/video/tiempo mínimo) + DEFINICIÓN DE EXAMEN
===================================================== */
add_action('add_meta_boxes', function() {
    add_meta_box(
        'cl_leccion_contenido',
        'Contenido de lección',
        'cl_render_leccion_contenido_metabox',
        'lecciones-cie',
        'normal',
        'high'
    );

    add_meta_box(
        'cl_leccion_video',
        'Vídeo de la lección',
        'cl_render_leccion_video_metabox',
        'lecciones-cie',
        'normal',
        'high'
    );

    add_meta_box(
        'cl_leccion_examen',
        'Examen (preguntas y respuestas)',
        'cl_render_leccion_examen_metabox',
        'lecciones-cie',
        'normal',
        'high'
    );
});

function cl_get_tipo_de_leccion($leccion_id) {
    $tipo = get_post_meta($leccion_id, CL_META_LESSON_TYPE, true);
    if (is_string($tipo) && $tipo !== '') {
        $tipo = strtolower(trim($tipo));
        if (in_array($tipo, ['normal', 'video', 'examen'], true)) return $tipo;
    }

    // Backward compatibility: ACF (antiguo y nuevo)
    $acf_tipo = cl_get_field_compat('tipo_de_leccion', $leccion_id);
    if (is_string($acf_tipo) && $acf_tipo !== '') {
        $acf_tipo = strtolower(trim($acf_tipo));
        if (in_array($acf_tipo, ['normal', 'video', 'examen'], true)) return $acf_tipo;
        if (in_array($acf_tipo, ['exam'], true)) return 'examen';
    }
    $acf_tipo2 = cl_get_field_compat('tipo_leccion', $leccion_id);
    if (is_string($acf_tipo2) && $acf_tipo2 !== '') {
        $acf_tipo2 = strtolower(trim($acf_tipo2));
        if (in_array($acf_tipo2, ['examen', 'exam'], true)) return 'examen';
    }

    // Backward compatibility: meta antiguo (solo normal/examen)
    $old = get_post_meta($leccion_id, '_cl_leccion_tipo', true);
    if (in_array($old, ['normal', 'examen'], true)) return $old;

    return 'normal';
}

function cl_get_leccion_tipo($leccion_id) {
    // API interna existente (solo normal/examen)
    return cl_get_tipo_de_leccion($leccion_id) === 'examen' ? 'examen' : 'normal';
}

function cl_get_leccion_video_content($leccion_id) {
    $v = get_post_meta($leccion_id, CL_META_LESSON_VIDEO, true);
    if (is_string($v) && trim($v) !== '') return $v;
    // Backward compat ACF/meta
    $v = cl_get_field_compat('video-tracking', $leccion_id);
    if (is_string($v) && trim($v) !== '') return $v;
    $v = cl_get_field_compat('video_tracking', $leccion_id);
    if (is_string($v) && trim($v) !== '') return $v;
    return '';
}

function cl_get_leccion_video_attachment_id($leccion_id) {
    $id = (int) get_post_meta($leccion_id, CL_META_LESSON_VIDEO_ATTACHMENT_ID, true);
    return $id > 0 ? $id : 0;
}

function cl_render_leccion_video_frontend($leccion_id) {
    $att_id = cl_get_leccion_video_attachment_id($leccion_id);
    if ($att_id > 0) {
        $url = wp_get_attachment_url($att_id);
        if (!$url) return '';
        $mime = get_post_mime_type($att_id);
        $type_attr = $mime ? ' type="' . esc_attr($mime) . '"' : '';
        $html = '<video controls preload="metadata" style="max-width:100%; height:auto;">';
        $html .= '<source src="' . esc_url($url) . '"' . $type_attr . ' />';
        $html .= 'Tu navegador no soporta vídeo HTML5.';
        $html .= '</video>';
        return $html;
    }

    // Fallback: contenido legacy (embed/shortcode)
    $raw = cl_get_leccion_video_content($leccion_id);
    if ($raw === '') return '';
    return apply_filters('the_content', $raw);
}

function cl_get_leccion_min_time_seconds($leccion_id) {
    $sec = get_post_meta($leccion_id, CL_META_LESSON_MIN_SECONDS, true);
    if ($sec !== '' && $sec !== null) return max(0, (int)$sec);
    // Backward compat: ACF i:s / H:i:s
    $raw = cl_get_field_compat('tiempo_minimo', $leccion_id);
    if (is_string($raw) || is_numeric($raw)) {
        $s = cl_parse_mmss_to_seconds($raw);
        if ($s > 0) return $s;
        return cl_parse_time_to_seconds($raw);
    }
    return 0;
}

function cl_render_leccion_contenido_metabox($post) {
    wp_nonce_field('cl_leccion_contenido_save', 'cl_leccion_contenido_nonce');
    $tipo = cl_get_tipo_de_leccion($post->ID);
    $min_seconds = cl_get_leccion_min_time_seconds($post->ID);
    $min_mmss = $min_seconds > 0 ? cl_seconds_to_human_mmss($min_seconds) : '';
    ?>
    <p style="margin-top:0;">
        <label style="display:block; font-weight:600; margin-bottom:6px;">Tipo de lección</label>
        <select name="cl_tipo_de_leccion" id="cl-tipo-de-leccion" style="width:100%;">
            <option value="normal" <?php selected($tipo, 'normal'); ?>>Normal</option>
            <option value="video" <?php selected($tipo, 'video'); ?>>Vídeo</option>
            <option value="examen" <?php selected($tipo, 'examen'); ?>>Examen</option>
        </select>
        <span class="description">Este campo controla qué bloques se muestran (vídeo/examen).</span>
    </p>

    <div class="cl-leccion-field cl-leccion-time-field" style="margin-top:10px;">
        <label style="display:block; font-weight:600; margin-bottom:6px;">Tiempo mínimo (mm:ss)</label>
        <input type="text" name="cl_tiempo_minimo_mmss" value="<?php echo esc_attr($min_mmss); ?>" placeholder="00:00" style="width:100%;" />
        <span class="description">Opcional. Si está vacío o 00:00, no hay mínimo.</span>
    </div>
    <?php
}

function cl_render_leccion_video_metabox($post) {
    wp_nonce_field('cl_leccion_video_save', 'cl_leccion_video_nonce');
    $video_attachment_id = (int) get_post_meta($post->ID, CL_META_LESSON_VIDEO_ATTACHMENT_ID, true);
    $video_attachment_id = $video_attachment_id > 0 ? $video_attachment_id : 0;
    ?>
    <div class="cl-leccion-video-metabox">
        <input type="hidden" name="cl_video_attachment_id" id="cl-video-attachment-id" value="<?php echo esc_attr($video_attachment_id); ?>" />
        <div id="cl-video-preview" style="margin:0 0 10px;">
            <?php if ($video_attachment_id): ?>
                <?php echo wp_get_attachment_link($video_attachment_id, '', false, false, 'Ver archivo'); ?>
                <code style="margin-left:6px;">ID: <?php echo esc_html($video_attachment_id); ?></code>
            <?php else: ?>
                <em>Sin archivo seleccionado</em>
            <?php endif; ?>
        </div>
        <p style="margin:0;">
            <button type="button" class="button button-primary" id="cl-pick-video">Seleccionar vídeo</button>
            <button type="button" class="button" id="cl-clear-video">Quitar</button>
        </p>
        <p class="description" style="margin-top:10px;">Solo se mostrará si el tipo de lección es “Vídeo”.</p>
    </div>
    <?php
}

function cl_render_leccion_examen_metabox($post) {
    $tipo = cl_get_leccion_tipo($post->ID);
    $exam = get_post_meta($post->ID, '_cl_exam_definition', true);
    if (!is_array($exam)) $exam = [];
    if (!isset($exam['questions']) || !is_array($exam['questions'])) $exam['questions'] = [];
    $exam_time = (int) get_post_meta($post->ID, CL_META_EXAM_TIME_SECONDS, true);
    $exam_time_minutes = $exam_time > 0 ? (int) ceil($exam_time / 60) : 0;

    wp_nonce_field('cl_leccion_examen_save', 'cl_leccion_examen_nonce');
    ?>
    <div class="cl-exam-metabox" data-tipo="<?php echo esc_attr($tipo); ?>">
        <p style="margin-top:0;">
            <label style="display:block; font-weight:600; margin-bottom:6px;">Tiempo máximo del examen (minutos)</label>
            <input type="number" min="0" step="1" name="cl_exam_time_minutes" value="<?php echo esc_attr($exam_time_minutes); ?>" style="width:120px;" />
            <span class="description">0 = sin límite.</span>
        </p>

        <p class="description">
            Este examen se guarda <strong>solo al enviar</strong>. Si el alumno abandona, no se guarda nada y empezará de cero.
        </p>

        <div id="cl-exam-builder">
            <input type="hidden" id="cl-exam-definition" name="cl_exam_definition" value="<?php echo esc_attr(wp_json_encode($exam)); ?>" />
            <div id="cl-exam-questions"></div>
            <button type="button" class="button" id="cl-exam-add-question">Añadir pregunta</button>
        </div>

        <p class="description" style="margin-top:10px;">
            Tipos: <strong>Single choice</strong> (una correcta) y <strong>Multi choice</strong> (varias correctas). Puedes añadir imagen por pregunta.
        </p>
        <?php if ($tipo !== 'examen'): ?>
            <p class="description" style="margin-top:10px;"><em>Esta lección no es de tipo “examen”. El builder queda oculto/ignorado.</em></p>
        <?php endif; ?>
    </div>
    <?php
}

add_action('save_post_lecciones-cie', function($post_id) {
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!current_user_can('edit_post', $post_id)) return;

    if (isset($_POST['cl_leccion_contenido_nonce']) && wp_verify_nonce($_POST['cl_leccion_contenido_nonce'], 'cl_leccion_contenido_save')) {
        $tipo = isset($_POST['cl_tipo_de_leccion']) ? sanitize_text_field($_POST['cl_tipo_de_leccion']) : 'normal';
        $tipo = strtolower(trim((string)$tipo));
        if (!in_array($tipo, ['normal', 'video', 'examen'], true)) $tipo = 'normal';
        update_post_meta($post_id, CL_META_LESSON_TYPE, $tipo);

        // Mantener compat: meta antigua solo normal/examen (para código legacy)
        update_post_meta($post_id, '_cl_leccion_tipo', $tipo === 'examen' ? 'examen' : 'normal');

        $mmss = isset($_POST['cl_tiempo_minimo_mmss']) ? sanitize_text_field(wp_unslash($_POST['cl_tiempo_minimo_mmss'])) : '';
        $seconds = cl_parse_mmss_to_seconds($mmss);
        update_post_meta($post_id, CL_META_LESSON_MIN_SECONDS, max(0, (int)$seconds));
    }

    if (isset($_POST['cl_leccion_video_nonce']) && wp_verify_nonce($_POST['cl_leccion_video_nonce'], 'cl_leccion_video_save')) {
        $video_attachment_id = isset($_POST['cl_video_attachment_id']) ? absint($_POST['cl_video_attachment_id']) : 0;
        update_post_meta($post_id, CL_META_LESSON_VIDEO_ATTACHMENT_ID, $video_attachment_id);
    }

    if (isset($_POST['cl_leccion_examen_nonce']) && wp_verify_nonce($_POST['cl_leccion_examen_nonce'], 'cl_leccion_examen_save')) {
        // Guardar tiempo máximo (siempre)
        $minutes = isset($_POST['cl_exam_time_minutes']) ? absint($_POST['cl_exam_time_minutes']) : 0;
        $seconds = $minutes > 0 ? ($minutes * 60) : 0;
        update_post_meta($post_id, CL_META_EXAM_TIME_SECONDS, $seconds);

        // Solo guardar definición si la lección es de tipo examen
        if (cl_get_leccion_tipo($post_id) === 'examen') {
            $raw = isset($_POST['cl_exam_definition']) ? wp_unslash($_POST['cl_exam_definition']) : '';
            $decoded = json_decode($raw, true);
            $normalized = cl_normalize_exam_definition($decoded);
            update_post_meta($post_id, '_cl_exam_definition', $normalized);
        }
    }
});

function cl_normalize_exam_definition($decoded) {
    $out = ['questions' => []];
    if (!is_array($decoded) || !isset($decoded['questions']) || !is_array($decoded['questions'])) {
        return $out;
    }

    foreach ($decoded['questions'] as $q) {
        if (!is_array($q)) continue;

        $text = isset($q['text']) ? wp_kses_post($q['text']) : '';
        $type = isset($q['type']) && in_array($q['type'], ['single', 'multi'], true) ? $q['type'] : 'single';
        $image_id = isset($q['image_id']) ? absint($q['image_id']) : 0;

        $options = [];
        if (isset($q['options']) && is_array($q['options'])) {
            foreach ($q['options'] as $opt) {
                if (!is_array($opt)) continue;
                $opt_text = isset($opt['text']) ? wp_kses_post($opt['text']) : '';
                $is_correct = !empty($opt['is_correct']);
                if ($opt_text === '') continue;
                $options[] = [
                    'text' => $opt_text,
                    'is_correct' => $is_correct ? 1 : 0,
                ];
            }
        }

        if ($text === '' || count($options) < 2) continue;

        // En single, mantener solo una correcta (la primera marcada).
        if ($type === 'single') {
            $found = false;
            foreach ($options as $i => $opt) {
                if ($opt['is_correct'] && !$found) {
                    $found = true;
                } elseif ($opt['is_correct'] && $found) {
                    $options[$i]['is_correct'] = 0;
                }
            }
        }

        $out['questions'][] = [
            'text' => $text,
            'type' => $type,
            'image_id' => $image_id,
            'options' => array_values($options),
        ];
    }

    return $out;
}

add_action('admin_enqueue_scripts', function($hook) {
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;

    // Builder del examen en editor de lecciones
    if ($screen && $screen->post_type === 'lecciones-cie' && in_array($hook, ['post.php', 'post-new.php'], true)) {
        wp_enqueue_media();
        wp_enqueue_script(
            'cl-admin-exam-js',
            plugin_dir_url(__FILE__) . 'assets/js/admin-exam.js',
            ['jquery'],
            CL_CIE_VERSION,
            true
        );
        wp_localize_script('cl-admin-exam-js', 'cl_admin_exam', [
            'media_title' => 'Selecciona una imagen',
            'media_button' => 'Usar esta imagen',
        ]);
        wp_enqueue_style(
            'cl-admin-css',
            plugin_dir_url(__FILE__) . 'assets/css/admin.css',
            [],
            CL_CIE_VERSION
        );
    }

    // Gestión de lecciones dentro del curso
    if ($screen && $screen->post_type === 'curso-cie' && in_array($hook, ['post.php', 'post-new.php'], true)) {
        wp_enqueue_script('jquery-ui-sortable');
        wp_enqueue_script(
            'cl-admin-js',
            plugin_dir_url(__FILE__) . 'assets/js/admin.js',
            ['jquery', 'jquery-ui-sortable'],
            CL_CIE_VERSION,
            true
        );
        wp_localize_script('cl-admin-js', 'cl_ajax', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('cl_ajax_nonce'),
            'post_id'  => isset($_GET['post']) ? absint($_GET['post']) : 0,
        ]);
        wp_enqueue_style(
            'cl-admin-css',
            plugin_dir_url(__FILE__) . 'assets/css/admin.css',
            [],
            CL_CIE_VERSION
        );
    }

    // Pantalla de gestión de exámenes (se añade más abajo)
    if ($hook === 'curso-cie_page_cl_examenes') {
        wp_enqueue_style(
            'cl-admin-css',
            plugin_dir_url(__FILE__) . 'assets/css/admin.css',
            [],
            CL_CIE_VERSION
        );
    }
});

/* =====================================================
   ADMIN AJAX: crear / eliminar / ordenar / renombrar lecciones (dentro del curso)
===================================================== */
add_action('wp_ajax_cl_crear_leccion', function() {
    check_ajax_referer('cl_ajax_nonce', 'nonce');
    $curso_id = isset($_POST['curso_id']) ? absint($_POST['curso_id']) : 0;
    $titulo = isset($_POST['titulo']) ? sanitize_text_field(wp_unslash($_POST['titulo'])) : '';
    if (!$curso_id || $titulo === '') wp_send_json_error('Datos incompletos');
    if (get_post_type($curso_id) !== 'curso-cie') wp_send_json_error('Curso inválido');
    if (!current_user_can('edit_post', $curso_id)) wp_send_json_error('Permisos insuficientes');

    $lecciones = cl_get_lecciones_ordenadas($curso_id);
    $menu_order = is_array($lecciones) ? count($lecciones) : 0;

    $id = wp_insert_post([
        'post_type' => 'lecciones-cie',
        'post_status' => 'publish',
        'post_title' => $titulo,
        'post_parent' => $curso_id,
        'menu_order' => $menu_order,
    ], true);

    if (is_wp_error($id)) wp_send_json_error($id->get_error_message());

    wp_send_json_success([
        'ID' => $id,
        'title' => get_the_title($id),
        'edit_link' => get_edit_post_link($id, ''),
    ]);
});

add_action('wp_ajax_cl_eliminar_leccion', function() {
    check_ajax_referer('cl_ajax_nonce', 'nonce');
    $leccion_id = isset($_POST['leccion_id']) ? absint($_POST['leccion_id']) : 0;
    if (!$leccion_id) wp_send_json_error('Lección inválida');
    if (get_post_type($leccion_id) !== 'lecciones-cie') wp_send_json_error('Lección inválida');
    if (!current_user_can('delete_post', $leccion_id)) wp_send_json_error('Permisos insuficientes');
    wp_trash_post($leccion_id);
    wp_send_json_success(true);
});

add_action('wp_ajax_cl_ordenar_lecciones', function() {
    check_ajax_referer('cl_ajax_nonce', 'nonce');
    $orden = isset($_POST['orden']) ? (array) $_POST['orden'] : [];
    $orden = array_values(array_filter(array_map('absint', $orden)));
    if (empty($orden)) wp_send_json_success(true);

    foreach ($orden as $i => $leccion_id) {
        if (get_post_type($leccion_id) !== 'lecciones-cie') continue;
        if (!current_user_can('edit_post', $leccion_id)) continue;
        wp_update_post([
            'ID' => $leccion_id,
            'menu_order' => $i,
        ]);
    }
    wp_send_json_success(true);
});

add_action('wp_ajax_cl_actualizar_titulo_leccion', function() {
    check_ajax_referer('cl_ajax_nonce', 'nonce');
    $leccion_id = isset($_POST['leccion_id']) ? absint($_POST['leccion_id']) : 0;
    $titulo = isset($_POST['titulo']) ? sanitize_text_field(wp_unslash($_POST['titulo'])) : '';
    if (!$leccion_id || $titulo === '') wp_send_json_error('Datos incompletos');
    if (get_post_type($leccion_id) !== 'lecciones-cie') wp_send_json_error('Lección inválida');
    if (!current_user_can('edit_post', $leccion_id)) wp_send_json_error('Permisos insuficientes');
    wp_update_post(['ID' => $leccion_id, 'post_title' => $titulo]);
    wp_send_json_success(true);
});

/* =====================================================
   SHORTCODE: CL_LECCION_CURSO
===================================================== */
add_shortcode('cl_curso_acceso', function(){
    if (!is_user_logged_in()) return 'Debes iniciar sesión.';
    global $post;
    if (!$post || $post->post_type !== 'curso-cie') return '';
    if (cl_is_user_enrolled_in_course(get_current_user_id(), $post->ID)) return '';
    return '<div class="cl-no-access">No tienes acceso a este curso. Debes estar inscrito por un administrador.</div>';
});

add_shortcode('cl_leccion_curso', function(){

    if(!is_user_logged_in()) return 'Debes iniciar sesión para ver el curso.';

    global $post;
    if(!$post || $post->post_type !== 'curso-cie') return '';

    $curso_id = $post->ID;
    $user_id  = get_current_user_id();
    if (!cl_is_user_enrolled_in_course($user_id, $curso_id)) {
        return '<div class="cl-no-access">No tienes acceso a este curso. Debes estar inscrito por un administrador.</div>';
    }
    $lecciones = cl_get_lecciones_ordenadas($curso_id);
    if(empty($lecciones)) return 'No hay lecciones.';

    $completadas = get_user_meta($user_id,"cl_curso_{$curso_id}_completadas",true);
    if(!is_array($completadas)) $completadas = [];

    /* ---------- LECCIÓN REAL (PROGRESO) ---------- */
    $leccion_real = null;
    foreach($lecciones as $l){
        if(!in_array($l->ID, $completadas)){
            $leccion_real = $l;
            break;
        }
    }
    if(!$leccion_real) $leccion_real = end($lecciones);

    /* ---------- LECCIÓN CONSULTADA (VISUAL) ---------- */
    $leccion_consulta = isset($_GET['leccion']) ? intval($_GET['leccion']) : 0;
    $leccion_actual = $leccion_real;

    if($leccion_consulta){
        foreach($lecciones as $l){
            if($l->ID === $leccion_consulta){
                $leccion_actual = $l;
                break;
            }
        }
    }

    $contenido = apply_filters('the_content', $leccion_actual->post_content);
    $video = cl_render_leccion_video_frontend($leccion_actual->ID);
    $tiempo_minimo_seg = cl_get_leccion_min_time_seconds($leccion_actual->ID);

    // Tiempo guardado previamente (para no resetear al volver a entrar)
    $tiempos_guardados = get_user_meta($user_id, "cl_curso_{$curso_id}_tiempos", true);
    if(!is_array($tiempos_guardados)) $tiempos_guardados = [];
    $tiempo_guardado_actual = isset($tiempos_guardados[$leccion_actual->ID]) ? intval($tiempos_guardados[$leccion_actual->ID]) : 0;


    $ids = wp_list_pluck($lecciones,'ID');
    $index = array_search($leccion_actual->ID, $ids);

    $leccion_tipo = cl_get_leccion_tipo($leccion_actual->ID);
    $exam_ui = '';
    $exam_status = null;
    if ($leccion_tipo === 'examen') {
        $attempt = cl_get_latest_exam_attempt($user_id, $curso_id, $leccion_actual->ID);
        $exam_status = $attempt ? get_post_meta($attempt->ID, '_cl_status', true) : 'not_started';
        $exam_ui = cl_render_exam_frontend($curso_id, $leccion_actual->ID, $attempt);
    }

    $disable_next_by_exam = false;
    if ($leccion_tipo === 'examen' && $exam_status !== 'approved') {
        $disable_next_by_exam = true;
    }

    ob_start(); ?>
    <div class="cl-layout">

        <aside class="cl-sidebar">
            <?php cl_render_sidebar_lecciones($curso_id, $leccion_actual->ID); ?>
        </aside>

        <main class="cl-contenido">

            <?php
                // Determinar si la lección actual está completada
                $leccion_completada = in_array($leccion_actual->ID, $completadas);
                // Obtener la siguiente lección (si existe)
                $next_leccion = isset($lecciones[$index + 1]) ? $lecciones[$index + 1] : null;

                $porcentaje_barra = 0;
                if($leccion_completada){
                    $porcentaje_barra = 100;
                } elseif($tiempo_minimo_seg > 0 && $tiempo_guardado_actual > 0){
                    $porcentaje_barra = min(100, ($tiempo_guardado_actual / $tiempo_minimo_seg) * 100);
                }

                $should_disable_next_by_time = (!$leccion_completada && $tiempo_minimo_seg > 0 && $tiempo_guardado_actual < $tiempo_minimo_seg);
            ?>
            <div class="cl-barra-tiempo">
                <div class="cl-barra-llenado" style="width: <?php echo esc_attr($porcentaje_barra); ?>%"></div>
            </div>


            <h2><?php echo esc_html($leccion_actual->post_title); ?></h2>

            <?php if($video): ?>
                <div class="cl-video">
                    <?php echo apply_filters('the_content', $video); ?>
                </div>
            <?php endif; ?>

            <div class="cl-texto">
                <?php echo $contenido; ?>
            </div>

            <div id="cl-progress-data"
                data-curso="<?php echo esc_attr($curso_id); ?>"
                data-leccion="<?php echo esc_attr($leccion_actual->ID); ?>"
                data-tiempo="<?php echo esc_attr($tiempo_minimo_seg); ?>"
                data-last="<?php echo esc_attr($tiempo_guardado_actual); ?>"
                data-is-video="<?php echo $video ? '1' : '0'; ?>"
                data-state="<?php echo esc_attr($leccion_completada); ?>"
                data-exam-lock="<?php echo $disable_next_by_exam ? '1' : '0'; ?>"
            ></div>

            <?php if($leccion_tipo === 'examen'): ?>
                <div class="cl-exam-wrap">
                    <?php echo $exam_ui; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                </div>
            <?php endif; ?>

            <div class="cl-navegacion">

                <?php if($index > 0):
                        $prev = $lecciones[$index - 1]; ?>
                        <a class="cl-btn"
                        href="<?php echo esc_url( get_permalink($curso_id) . '?leccion=' . $prev->ID ); ?>">
                        ← Lección anterior
                        </a>
                        <?php endif; ?>

                        <?php if($next_leccion): ?>
                        <button id="cl-btn-siguiente"
                        class="cl-btn"
                        data-next="<?php echo esc_url( get_permalink($curso_id) . '?leccion=' . $next_leccion->ID ); ?>"
                        data-curso="<?php echo esc_attr($curso_id); ?>"
                        data-leccion="<?php echo esc_attr($leccion_actual->ID); ?>"
                        data-tiempo="<?php echo esc_attr($tiempo_minimo_seg); ?>"
                        data-last="<?php echo esc_attr($tiempo_guardado_actual); ?>"
                        data-is-video="<?php echo $video ? '1' : '0'; ?>"
                        data-state="<?php echo esc_attr($leccion_completada); ?>"
                        data-exam-lock="<?php echo $disable_next_by_exam ? '1' : '0'; ?>"
                        <?php echo ($should_disable_next_by_time || $disable_next_by_exam) ? 'disabled' : ''; ?>>
                        Siguiente lección →
                        </button>
                        <?php endif; ?>
            </div>

            <?php if(!empty($disable_next_by_exam)): ?>
                <p class="cl-exam-note">Para continuar, primero debes realizar el examen y esperar a que el profesor lo valide.</p>
            <?php endif; ?>

        </main>
    </div>
    <?php
    return ob_get_clean();
});

/* =====================================================
   FRONTEND: EXAMEN (render + ajax submit)
===================================================== */
function cl_get_latest_exam_attempt($user_id, $curso_id, $leccion_id) {
    $posts = get_posts([
        'post_type' => 'cl-exam-attempt',
        'numberposts' => 1,
        'orderby' => 'date',
        'order' => 'DESC',
        'meta_query' => [
            [
                'key' => '_cl_user_id',
                'value' => (string) absint($user_id),
                'compare' => '=',
            ],
            [
                'key' => '_cl_course_id',
                'value' => (string) absint($curso_id),
                'compare' => '=',
            ],
            [
                'key' => '_cl_lesson_id',
                'value' => (string) absint($leccion_id),
                'compare' => '=',
            ],
        ],
    ]);
    return !empty($posts) ? $posts[0] : null;
}

function cl_can_user_take_exam($attempt) {
    if (!$attempt) return true;
    $status = (string) get_post_meta($attempt->ID, '_cl_status', true);
    // Una sola vez, salvo revocación.
    return in_array($status, ['retry_required', 'revoked_reset_course'], true);
}

function cl_render_exam_frontend($curso_id, $leccion_id, $attempt) {
    $def = get_post_meta($leccion_id, '_cl_exam_definition', true);
    if (!is_array($def) || empty($def['questions'])) {
        return '<div class="cl-exam-empty">Este examen aún no tiene preguntas definidas.</div>';
    }

    $status = $attempt ? (string) get_post_meta($attempt->ID, '_cl_status', true) : 'not_started';
    $can_take = cl_can_user_take_exam($attempt);

    if ($attempt && $status === 'pending_review') {
        return '<div class="cl-exam-state cl-exam-pending"><strong>Examen en revisión</strong>. Ya has enviado el examen y está pendiente de validación.</div>';
    }

    if ($attempt && $status === 'approved') {
        $score = get_post_meta($attempt->ID, '_cl_final_score', true);
        $score = is_numeric($score) ? round((float)$score, 2) : '';
        $auto = get_post_meta($attempt->ID, '_cl_auto_score', true);
        $auto = is_numeric($auto) ? round((float)$auto, 2) : '';

        $html = '<div class="cl-exam-state cl-exam-approved"><strong>Examen aprobado</strong>.';
        if ($score !== '') $html .= ' Nota: <strong>' . esc_html($score) . '%</strong>.';
        if ($auto !== '') $html .= ' (Auto: ' . esc_html($auto) . '%)';
        $html .= '</div>';
        $html .= cl_render_exam_results_readonly($leccion_id, $attempt);
        return $html;
    }

    if (!$can_take) {
        return '<div class="cl-exam-state cl-exam-locked"><strong>Examen bloqueado</strong>. Ya existe un intento enviado.</div>';
    }

    $time_limit = (int) get_post_meta($leccion_id, CL_META_EXAM_TIME_SECONDS, true);

    ob_start();
    ?>
    <div class="cl-exam-intro">
        <p><strong>Examen</strong>: cuando pulses “Iniciar examen” se mostrará el formulario.</p>
        <?php if ($time_limit > 0): ?>
            <p class="cl-exam-time-note"><strong>Importante:</strong> tienes <strong><?php echo esc_html(cl_seconds_to_human_mmss($time_limit)); ?></strong> para realizar el examen. El tiempo empieza al pulsar “Iniciar examen”.</p>
        <?php endif; ?>
        <button type="button" class="cl-btn" id="cl-exam-start">Iniciar examen</button>
    </div>

    <form id="cl-exam-form" class="cl-exam-form" style="display:none;">
        <input type="hidden" name="curso_id" value="<?php echo esc_attr($curso_id); ?>" />
        <input type="hidden" name="leccion_id" value="<?php echo esc_attr($leccion_id); ?>" />
        <input type="hidden" name="exam_session_token" value="" />

        <?php if ($time_limit > 0): ?>
            <div class="cl-exam-timer" data-time="<?php echo esc_attr($time_limit); ?>">
                <div><strong>Tiempo restante:</strong> <span class="cl-exam-countdown"><?php echo esc_html(cl_seconds_to_human_mmss($time_limit)); ?></span></div>
                <button type="button" class="cl-btn cl-exam-finish" style="margin-top:10px;">Finalizar examen</button>
            </div>
        <?php else: ?>
            <button type="button" class="cl-btn cl-exam-finish" style="margin:0 0 12px;">Finalizar examen</button>
        <?php endif; ?>

        <?php foreach ($def['questions'] as $qi => $q): ?>
            <fieldset class="cl-exam-q">
                <legend>
                    <span class="cl-exam-qn"><?php echo esc_html($qi + 1); ?>.</span>
                    <span class="cl-exam-qt"><?php echo wp_kses_post($q['text']); ?></span>
                </legend>

                <?php if (!empty($q['image_id'])): ?>
                    <div class="cl-exam-qimg"><?php echo wp_get_attachment_image((int)$q['image_id'], 'large'); ?></div>
                <?php endif; ?>

                <?php
                    $type = (isset($q['type']) && $q['type'] === 'multi') ? 'multi' : 'single';
                    $input_type = $type === 'multi' ? 'checkbox' : 'radio';
                ?>
                <div class="cl-exam-opts" data-qtype="<?php echo esc_attr($type); ?>">
                    <?php foreach ($q['options'] as $oi => $opt): ?>
                        <label class="cl-exam-opt">
                            <input
                                type="<?php echo esc_attr($input_type); ?>"
                                name="answers[<?php echo esc_attr($qi); ?>]<?php echo $type === 'multi' ? '[]' : ''; ?>"
                                value="<?php echo esc_attr($oi); ?>"
                            />
                            <span><?php echo wp_kses_post($opt['text']); ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </fieldset>
        <?php endforeach; ?>

        <button type="submit" class="cl-btn cl-exam-submit">Enviar examen</button>
        <div class="cl-exam-msg" aria-live="polite"></div>
    </form>
    <?php
    return ob_get_clean();
}

function cl_render_exam_results_readonly($leccion_id, $attempt) {
    $def = get_post_meta($leccion_id, '_cl_exam_definition', true);
    if (!is_array($def) || empty($def['questions'])) return '';

    $answers = get_post_meta($attempt->ID, '_cl_answers', true);
    if (!is_array($answers)) $answers = [];

    ob_start();
    ?>
    <div class="cl-exam-results">
        <h3>Resultados</h3>
        <?php foreach ($def['questions'] as $qi => $q): ?>
            <div class="cl-exam-res-q">
                <div class="cl-exam-res-title"><?php echo esc_html($qi + 1); ?>. <?php echo wp_kses_post($q['text']); ?></div>
                <?php
                    $selected = isset($answers[$qi]) ? (array)$answers[$qi] : [];
                    $correct = [];
                    foreach ($q['options'] as $oi => $opt) {
                        if (!empty($opt['is_correct'])) $correct[] = (string)$oi;
                    }
                ?>
                <ul class="cl-exam-res-opts">
                    <?php foreach ($q['options'] as $oi => $opt): ?>
                        <?php
                            $is_sel = in_array((string)$oi, array_map('strval', $selected), true);
                            $is_cor = in_array((string)$oi, $correct, true);
                            $cls = 'cl-exam-res-opt';
                            if ($is_cor) $cls .= ' is-correct';
                            if ($is_sel) $cls .= ' is-selected';
                        ?>
                        <li class="<?php echo esc_attr($cls); ?>">
                            <?php echo wp_kses_post($opt['text']); ?>
                            <?php if ($is_cor): ?> <strong>(correcta)</strong><?php endif; ?>
                            <?php if ($is_sel && !$is_cor): ?> <em>(tu respuesta)</em><?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endforeach; ?>
    </div>
    <?php
    return ob_get_clean();
}

add_action('wp_ajax_cl_start_exam', function() {
    check_ajax_referer('cl_ajax_nonce', 'nonce');
    if (!is_user_logged_in()) wp_send_json_error(['message' => 'Debes iniciar sesión.'], 401);

    $user_id = get_current_user_id();
    $curso_id = isset($_POST['curso_id']) ? absint($_POST['curso_id']) : 0;
    $leccion_id = isset($_POST['leccion_id']) ? absint($_POST['leccion_id']) : 0;
    if (!$curso_id || !$leccion_id) wp_send_json_error(['message' => 'Datos incompletos.'], 400);

    if (get_post_type($curso_id) !== 'curso-cie' || get_post_type($leccion_id) !== 'lecciones-cie') {
        wp_send_json_error(['message' => 'Curso o lección inválidos.'], 400);
    }
    if ((int)get_post_field('post_parent', $leccion_id) !== (int)$curso_id) {
        wp_send_json_error(['message' => 'La lección no pertenece al curso.'], 400);
    }
    if (cl_get_leccion_tipo($leccion_id) !== 'examen') {
        wp_send_json_error(['message' => 'Esta lección no es un examen.'], 400);
    }

    // Validar sesión / tiempo (si aplica)
    $time_limit = (int) get_post_meta($leccion_id, CL_META_EXAM_TIME_SECONDS, true);
    $duration = 0;
    $started_at = 0;
    if ($time_limit > 0) {
        $token = isset($_POST['exam_session_token']) ? sanitize_text_field(wp_unslash($_POST['exam_session_token'])) : '';
        if ($token === '') wp_send_json_error(['message' => 'Debes iniciar el examen antes de enviarlo.'], 400);
        $session = get_transient('cl_exam_session_' . $token);
        if (!is_array($session)
            || (int)($session['user_id'] ?? 0) !== (int)$user_id
            || (int)($session['curso_id'] ?? 0) !== (int)$curso_id
            || (int)($session['leccion_id'] ?? 0) !== (int)$leccion_id
        ) {
            wp_send_json_error(['message' => 'Sesión de examen inválida o caducada.'], 400);
        }
        $started_at = (int)($session['started_at'] ?? 0);
        $duration = $started_at > 0 ? max(0, time() - $started_at) : 0;
        // Pequeña gracia por latencia
        if ($duration > ($time_limit + 10)) {
            delete_transient('cl_exam_session_' . $token);
            wp_send_json_error(['message' => 'El tiempo del examen ha finalizado.'], 409);
        }
        // Consumir sesión (un único envío)
        delete_transient('cl_exam_session_' . $token);
    }

    $latest = cl_get_latest_exam_attempt($user_id, $curso_id, $leccion_id);
    if (!cl_can_user_take_exam($latest)) {
        wp_send_json_error(['message' => 'Ya has realizado este examen.'], 409);
    }

    $time_limit = (int) get_post_meta($leccion_id, CL_META_EXAM_TIME_SECONDS, true);
    $token = wp_generate_uuid4();
    set_transient('cl_exam_session_' . $token, [
        'user_id' => (int)$user_id,
        'curso_id' => (int)$curso_id,
        'leccion_id' => (int)$leccion_id,
        'started_at' => time(),
        'time_limit' => (int)$time_limit,
    ], 2 * HOUR_IN_SECONDS);

    wp_send_json_success([
        'token' => $token,
        'time_limit' => (int)$time_limit,
    ]);
});

add_action('wp_ajax_cl_submit_exam', function() {
    check_ajax_referer('cl_ajax_nonce', 'nonce');
    if (!is_user_logged_in()) wp_send_json_error(['message' => 'Debes iniciar sesión.'], 401);

    $user_id = get_current_user_id();
    $curso_id = isset($_POST['curso_id']) ? absint($_POST['curso_id']) : 0;
    $leccion_id = isset($_POST['leccion_id']) ? absint($_POST['leccion_id']) : 0;
    if (!$curso_id || !$leccion_id) wp_send_json_error(['message' => 'Datos incompletos.'], 400);

    if (get_post_type($curso_id) !== 'curso-cie' || get_post_type($leccion_id) !== 'lecciones-cie') {
        wp_send_json_error(['message' => 'Curso o lección inválidos.'], 400);
    }
    if ((int)get_post_field('post_parent', $leccion_id) !== (int)$curso_id) {
        wp_send_json_error(['message' => 'La lección no pertenece al curso.'], 400);
    }
    if (cl_get_leccion_tipo($leccion_id) !== 'examen') {
        wp_send_json_error(['message' => 'Esta lección no es un examen.'], 400);
    }

    $latest = cl_get_latest_exam_attempt($user_id, $curso_id, $leccion_id);
    if (!cl_can_user_take_exam($latest)) {
        wp_send_json_error(['message' => 'Ya has realizado este examen.'], 409);
    }

    $def = get_post_meta($leccion_id, '_cl_exam_definition', true);
    if (!is_array($def) || empty($def['questions'])) {
        wp_send_json_error(['message' => 'Examen sin preguntas.'], 400);
    }

    $raw_answers = isset($_POST['answers']) ? $_POST['answers'] : [];
    $answers = cl_normalize_exam_answers($raw_answers, $def);
    $auto_score = cl_calculate_exam_score($answers, $def); // 0-100

    $attempt_id = wp_insert_post([
        'post_type' => 'cl-exam-attempt',
        'post_status' => 'publish',
        'post_title' => sprintf('Examen %d - Usuario %d', $leccion_id, $user_id),
    ], true);

    if (is_wp_error($attempt_id)) {
        wp_send_json_error(['message' => 'No se pudo guardar el intento.'], 500);
    }

    update_post_meta($attempt_id, '_cl_course_id', $curso_id);
    update_post_meta($attempt_id, '_cl_lesson_id', $leccion_id);
    update_post_meta($attempt_id, '_cl_user_id', $user_id);
    update_post_meta($attempt_id, '_cl_answers', $answers);
    update_post_meta($attempt_id, '_cl_auto_score', $auto_score);
    $autoeval = ((int) get_post_meta($curso_id, CL_META_COURSE_AUTOEVAL, true)) === 1;
    update_post_meta($attempt_id, '_cl_status', $autoeval ? 'approved' : 'pending_review');
    update_post_meta($attempt_id, '_cl_submitted_at', current_time('mysql'));
    if (!empty($started_at)) update_post_meta($attempt_id, '_cl_started_at', gmdate('Y-m-d H:i:s', (int)$started_at));
    if (!empty($duration)) update_post_meta($attempt_id, '_cl_duration_seconds', (int)$duration);

    if ($autoeval) {
        update_post_meta($attempt_id, '_cl_final_score', $auto_score);
        cl_mark_course_approved($user_id, $curso_id, $leccion_id);
    }

    cl_notify_admin_exam_submitted($attempt_id);

    wp_send_json_success(['attempt_id' => $attempt_id, 'auto_score' => $auto_score]);
});

function cl_normalize_exam_answers($raw, $def) {
    $answers = [];
    foreach ($def['questions'] as $qi => $q) {
        $type = (isset($q['type']) && $q['type'] === 'multi') ? 'multi' : 'single';
        $opts_count = (isset($q['options']) && is_array($q['options'])) ? count($q['options']) : 0;
        if ($opts_count < 2) continue;

        if ($type === 'multi') {
            $vals = isset($raw[$qi]) ? (array)$raw[$qi] : [];
            $clean = [];
            foreach ($vals as $v) {
                $iv = is_numeric($v) ? (int)$v : -1;
                if ($iv >= 0 && $iv < $opts_count) $clean[] = (string)$iv;
            }
            $clean = array_values(array_unique($clean));
            sort($clean);
            $answers[$qi] = $clean;
        } else {
            $v = isset($raw[$qi]) ? $raw[$qi] : null;
            $iv = is_numeric($v) ? (int)$v : -1;
            $answers[$qi] = ($iv >= 0 && $iv < $opts_count) ? [(string)$iv] : [];
        }
    }
    return $answers;
}

function cl_calculate_exam_score($answers, $def) {
    $total = 0;
    $ok = 0;
    foreach ($def['questions'] as $qi => $q) {
        $total++;
        $selected = isset($answers[$qi]) ? (array)$answers[$qi] : [];
        $correct = [];
        foreach ($q['options'] as $oi => $opt) {
            if (!empty($opt['is_correct'])) $correct[] = (string)$oi;
        }
        sort($correct);
        $sel = array_values(array_unique(array_map('strval', $selected)));
        sort($sel);
        if ($sel === $correct) $ok++;
    }
    if ($total <= 0) return 0;
    return round(($ok / $total) * 100, 2);
}

function cl_notify_admin_exam_submitted($attempt_id) {
    $curso_id = (int)get_post_meta($attempt_id, '_cl_course_id', true);
    $leccion_id = (int)get_post_meta($attempt_id, '_cl_lesson_id', true);
    $user_id = (int)get_post_meta($attempt_id, '_cl_user_id', true);

    $curso = get_post($curso_id);
    $leccion = get_post($leccion_id);
    $user = get_user_by('id', $user_id);

    // Destinatario: seleccionado en curso -> autor del curso -> admin_email
    $to = '';
    $notify_uid = (int) get_post_meta($curso_id, CL_META_COURSE_EXAM_NOTIFY_USER, true);
    if ($notify_uid > 0) {
        $notify_user = get_user_by('id', $notify_uid);
        if ($notify_user && !empty($notify_user->user_email)) $to = $notify_user->user_email;
    }
    if ($to === '' && $curso) {
        $author = get_user_by('id', (int)$curso->post_author);
        if ($author && !empty($author->user_email)) $to = $author->user_email;
    }
    if ($to === '') {
        $to = (string) get_option('admin_email');
    }
    if ($to === '') return;

    $subject = sprintf('Nuevo examen realizado: %s', $curso ? $curso->post_title : ('Curso ' . $curso_id));
    $link = admin_url('admin.php?page=cl_examenes&attempt=' . absint($attempt_id));
    $message = "Un estudiante ha realizado un examen.\n\n";
    $message .= "Curso: " . ($curso ? $curso->post_title : $curso_id) . "\n";
    $message .= "Lección: " . ($leccion ? $leccion->post_title : $leccion_id) . "\n";
    $message .= "Alumno: " . ($user ? ($user->display_name . ' (' . $user->user_login . ')') : $user_id) . "\n\n";
    $message .= "Revisar y validar: " . $link . "\n";

    wp_mail($to, $subject, $message);
}

/* =====================================================
   CORE: ACTUALIZAR PROGRESO + HISTÓRICO
===================================================== */
function cl_update_progress_and_history($user_id, $curso_id, $leccion_id, $tiempo, $tiempo_minimo = 0, $tipo = 'pantalla', $force_complete = false) {
    global $wpdb;

    $user_id = intval($user_id);
    $curso_id = intval($curso_id);
    $leccion_id = intval($leccion_id);
    $tiempo = max(0, intval($tiempo));
    $tiempo_minimo = max(0, intval($tiempo_minimo));
    $tipo = sanitize_key($tipo ?: 'pantalla');

    $tiempos = get_user_meta($user_id,"cl_curso_{$curso_id}_tiempos",true);
    if(!is_array($tiempos)) $tiempos = [];

    $prev = isset($tiempos[$leccion_id]) ? intval($tiempos[$leccion_id]) : 0;
    $updated = false;

    // Solo actualiza si el nuevo tiempo es mayor que el último guardado
    if($tiempo > $prev){
        $tiempos[$leccion_id] = $tiempo;
        update_user_meta($user_id,"cl_curso_{$curso_id}_tiempos",$tiempos);
        $updated = true;

        // Histórico por segundo (INSERT IGNORE)
        $table = cl_get_hist_table_name();
        $start = $prev + 1;
        $end = $tiempo;

        // Evitar loops enormes si el cliente estuvo offline o se saltó ticks
        if(($end - $start) > 600){
            $start = max($start, $end - 600);
        }

        for($s = $start; $s <= $end; $s++){
            $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO {$table} (user_id, curso_id, leccion_id, segundo, tipo, created_at)
                 VALUES (%d, %d, %d, %d, %s, UTC_TIMESTAMP())",
                $user_id, $curso_id, $leccion_id, $s, $tipo
            ));
        }
    }

    // Marcar completada si cumple tiempo mínimo (o si se fuerza al pulsar "Siguiente")
    $completed_now = false;
    $completadas = get_user_meta($user_id,"cl_curso_{$curso_id}_completadas",true);
    if(!is_array($completadas)) $completadas = [];

    $should_complete = ($force_complete === true) || ($tiempo_minimo > 0 && $tiempo >= $tiempo_minimo);
    if($should_complete && !in_array($leccion_id,$completadas)){
        $completadas[] = $leccion_id;
        update_user_meta($user_id,"cl_curso_{$curso_id}_completadas",$completadas);
        $completed_now = true;
    }

    return [
        'updated' => $updated,
        'prev' => $prev,
        'stored' => isset($tiempos[$leccion_id]) ? intval($tiempos[$leccion_id]) : $prev,
        'completed_now' => $completed_now,
    ];
}

/* =====================================================
   AJAX: GUARDAR PROGRESO (TICK POR SEGUNDO)
===================================================== */
add_action('wp_ajax_cl_guardar_progreso_tick', function(){

    check_ajax_referer('cl_ajax_nonce','nonce');

    $user_id    = get_current_user_id();
    $curso_id   = intval($_POST['curso_id'] ?? 0);
    $leccion_id = intval($_POST['leccion_id'] ?? 0);
    $tiempo     = intval($_POST['tiempo'] ?? 0);
    $tiempo_minimo = intval($_POST['tiempo_minimo'] ?? 0);
    $tipo = sanitize_key($_POST['tipo'] ?? 'pantalla');

    if(!$user_id || !$curso_id || !$leccion_id){
        wp_send_json_error(['message' => 'Datos incompletos']);
    }

    $res = cl_update_progress_and_history($user_id, $curso_id, $leccion_id, $tiempo, $tiempo_minimo, $tipo);
    wp_send_json_success($res);
});

/* =====================================================
   AJAX: GUARDAR PROGRESO
===================================================== */
add_action('wp_ajax_cl_guardar_progreso', function(){

    check_ajax_referer('cl_ajax_nonce','nonce');

    $user_id    = get_current_user_id();
    $curso_id   = intval($_POST['curso_id'] ?? 0);
    $leccion_id = intval($_POST['leccion_id'] ?? 0);
    $tiempo     = intval($_POST['tiempo'] ?? 0);
    $tiempo_minimo = intval($_POST['tiempo_minimo'] ?? 0);
    $tipo = sanitize_key($_POST['tipo'] ?? 'pantalla');

    if(!$user_id || !$curso_id || !$leccion_id){
        wp_send_json_error(['message' => 'Datos incompletos']);
    }

    $res = cl_update_progress_and_history($user_id, $curso_id, $leccion_id, $tiempo, $tiempo_minimo, $tipo, true);
    wp_send_json_success($res);
});

/* =====================================================
   ADMIN: PROGRESO USUARIOS
===================================================== */
add_action('admin_menu', function(){
    add_submenu_page(
        'edit.php?post_type=curso-cie',
        'Progreso de usuarios',
        'Progreso de usuarios',
        'manage_options',
        'cl_progreso_usuarios',
        'cl_render_progreso_usuarios'
    );

    add_submenu_page(
        'edit.php?post_type=curso-cie',
        'Exámenes',
        'Exámenes',
        'manage_options',
        'cl_examenes',
        'cl_render_examenes_admin'
    );
});

function cl_render_progreso_usuarios(){
    $cursos = get_posts(['post_type'=>'curso-cie','numberposts'=>-1]);
    $usuarios = get_users(['role__in'=>['cie_new_user','cie_user']]);

    echo '<div class="wrap"><h1>Progreso de usuarios</h1>';

    foreach($cursos as $curso){
        echo '<h2>'.esc_html($curso->post_title).'</h2>';
        echo '<table class="widefat striped"><thead><tr>
                <th>Usuario</th><th>Lecciones completadas</th><th>Tiempo por lección</th><th>Acciones</th>
              </tr></thead><tbody>';

        foreach($usuarios as $user){
            $completadas = get_user_meta($user->ID,"cl_curso_{$curso->ID}_completadas",true);
            $tiempos = get_user_meta($user->ID,"cl_curso_{$curso->ID}_tiempos",true);
            if(!is_array($completadas)) $completadas=[];
            if(!is_array($tiempos)) $tiempos=[];

            if(empty($completadas)) continue;

            $lecciones = cl_get_lecciones_ordenadas($curso->ID);
            $lecciones_text=[];
            foreach($lecciones as $l){
                $estado = in_array($l->ID,$completadas)?'<span class="state-complete">Completada</span>':'<span class="state-progress">En progreso</span>';
                $tiempo = isset($tiempos[$l->ID])?gmdate("H:i:s",$tiempos[$l->ID]):'-';
                $lecciones_text[] = "$estado ($tiempo) ".$l->post_title;
            }

            echo '<tr>';
            echo '<td>'.esc_html($user->display_name).' ('.esc_html($user->user_login).')</td>';
            echo '<td>'.count($completadas).'/'.count($lecciones).'</td>';
            echo '<td>'.implode('<br>',$lecciones_text).'</td>';
            echo '<td>
                    <form method="post" style="display:inline">
                        <input type="hidden" name="cl_borrar_usuario" value="'.esc_attr($user->ID).'">
                        <input type="hidden" name="cl_borrar_curso" value="'.esc_attr($curso->ID).'">
                        <button type="submit" class="button button-secondary" onclick="return confirm(\'¿Seguro que quieres borrar el progreso?\')">Borrar progreso</button>
                    </form>
                  </td>';
            echo '</tr>';
        }

        echo '</tbody></table><br>';
    }

    echo '</div>';

    if(!empty($_POST['cl_borrar_usuario']) && !empty($_POST['cl_borrar_curso'])){
        $uid = intval($_POST['cl_borrar_usuario']);
        $cid = intval($_POST['cl_borrar_curso']);
        delete_user_meta($uid,"cl_curso_{$cid}_completadas");
        delete_user_meta($uid,"cl_curso_{$cid}_actual");
        delete_user_meta($uid,"cl_curso_{$cid}_tiempos");
        delete_user_meta($uid,"cl_curso_{$cid}_aprobado");

        // Borrar histórico
        global $wpdb;
        $table = cl_get_hist_table_name();
        $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE user_id = %d AND curso_id = %d", $uid, $cid));
        echo '<div class="notice notice-success"><p>Progreso borrado correctamente.</p></div>';
        echo '<meta http-equiv="refresh" content="1">';
    }
}

/* =====================================================
   ADMIN: EXÁMENES (revisión / aprobación / revocación)
===================================================== */
function cl_render_examenes_admin() {
    if (!current_user_can('manage_options')) return;

    $attempt_id = isset($_GET['attempt']) ? absint($_GET['attempt']) : 0;

    echo '<div class="wrap"><h1>Exámenes</h1>';

    if ($attempt_id) {
        cl_render_examen_admin_detail($attempt_id);
        echo '</div>';
        return;
    }

    $status = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : 'pending_review';
    $allowed = ['pending_review', 'approved', 'retry_required', 'revoked_reset_course'];
    if (!in_array($status, $allowed, true)) $status = 'pending_review';

    echo '<p>';
    foreach ($allowed as $st) {
        $url = admin_url('admin.php?page=cl_examenes&status=' . urlencode($st));
        $label = ucfirst(str_replace('_', ' ', $st));
        $active = $st === $status ? ' style="font-weight:bold;"' : '';
        echo '<a href="' . esc_url($url) . '"' . $active . '>' . esc_html($label) . '</a> ';
    }
    echo '</p>';

    $attempts = get_posts([
        'post_type' => 'cl-exam-attempt',
        'numberposts' => 50,
        'orderby' => 'date',
        'order' => 'DESC',
        'meta_key' => '_cl_status',
        'meta_value' => $status,
    ]);

    echo '<table class="widefat striped"><thead><tr>';
    echo '<th>Alumno</th><th>Curso</th><th>Lección</th><th>Fecha</th><th>Auto</th><th>Estado</th><th>Acción</th>';
    echo '</tr></thead><tbody>';

    if (empty($attempts)) {
        echo '<tr><td colspan="7">No hay exámenes para este filtro.</td></tr>';
    } else {
        foreach ($attempts as $a) {
            $curso_id = (int)get_post_meta($a->ID, '_cl_course_id', true);
            $leccion_id = (int)get_post_meta($a->ID, '_cl_lesson_id', true);
            $user_id = (int)get_post_meta($a->ID, '_cl_user_id', true);
            $submitted = (string)get_post_meta($a->ID, '_cl_submitted_at', true);
            $auto = get_post_meta($a->ID, '_cl_auto_score', true);
            $st = (string)get_post_meta($a->ID, '_cl_status', true);

            $user = get_user_by('id', $user_id);
            $curso = get_post($curso_id);
            $leccion = get_post($leccion_id);

            $url = admin_url('admin.php?page=cl_examenes&attempt=' . absint($a->ID));
            echo '<tr>';
            echo '<td>' . esc_html($user ? $user->display_name : ('Usuario ' . $user_id)) . '</td>';
            echo '<td>' . esc_html($curso ? $curso->post_title : $curso_id) . '</td>';
            echo '<td>' . esc_html($leccion ? $leccion->post_title : $leccion_id) . '</td>';
            echo '<td>' . esc_html($submitted ?: get_the_date('Y-m-d H:i', $a)) . '</td>';
            echo '<td>' . esc_html(is_numeric($auto) ? (round((float)$auto, 2) . '%') : '-') . '</td>';
            echo '<td>' . esc_html($st) . '</td>';
            echo '<td><a class="button button-primary" href="' . esc_url($url) . '">Revisar</a></td>';
            echo '</tr>';
        }
    }
    echo '</tbody></table>';
    echo '</div>';
}

function cl_render_examen_admin_detail($attempt_id) {
    $attempt = get_post($attempt_id);
    if (!$attempt || $attempt->post_type !== 'cl-exam-attempt') {
        echo '<p>Intento no encontrado.</p>';
        return;
    }

    $curso_id = (int)get_post_meta($attempt_id, '_cl_course_id', true);
    $leccion_id = (int)get_post_meta($attempt_id, '_cl_lesson_id', true);
    $user_id = (int)get_post_meta($attempt_id, '_cl_user_id', true);
    $status = (string)get_post_meta($attempt_id, '_cl_status', true);
    $auto = get_post_meta($attempt_id, '_cl_auto_score', true);
    $final = get_post_meta($attempt_id, '_cl_final_score', true);
    $answers = get_post_meta($attempt_id, '_cl_answers', true);
    if (!is_array($answers)) $answers = [];

    $curso = get_post($curso_id);
    $leccion = get_post($leccion_id);
    $user = get_user_by('id', $user_id);

    // Procesar acciones
    if (!empty($_POST['cl_exam_action']) && check_admin_referer('cl_exam_review_' . $attempt_id)) {
        $action = sanitize_text_field($_POST['cl_exam_action']);
        $final_score = isset($_POST['cl_final_score']) ? floatval($_POST['cl_final_score']) : null;
        $note = isset($_POST['cl_admin_note']) ? wp_kses_post(wp_unslash($_POST['cl_admin_note'])) : '';

        if ($action === 'approve') {
            update_post_meta($attempt_id, '_cl_status', 'approved');
            update_post_meta($attempt_id, '_cl_final_score', is_null($final_score) ? $auto : $final_score);
            update_post_meta($attempt_id, '_cl_admin_note', $note);
            update_post_meta($attempt_id, '_cl_reviewed_by', get_current_user_id());
            update_post_meta($attempt_id, '_cl_reviewed_at', current_time('mysql'));
            cl_mark_course_approved($user_id, $curso_id, $leccion_id);
            echo '<div class="notice notice-success"><p>Examen aprobado y curso marcado como completado/aprobado.</p></div>';
            $status = 'approved';
        } elseif ($action === 'revoke_retry') {
            update_post_meta($attempt_id, '_cl_status', 'retry_required');
            update_post_meta($attempt_id, '_cl_admin_note', $note);
            update_post_meta($attempt_id, '_cl_reviewed_by', get_current_user_id());
            update_post_meta($attempt_id, '_cl_reviewed_at', current_time('mysql'));
            cl_revoke_exam_for_user($user_id, $curso_id, $leccion_id, false);
            echo '<div class="notice notice-warning"><p>Examen revocado. El alumno deberá repetir el examen.</p></div>';
            $status = 'retry_required';
        } elseif ($action === 'revoke_reset') {
            update_post_meta($attempt_id, '_cl_status', 'revoked_reset_course');
            update_post_meta($attempt_id, '_cl_admin_note', $note);
            update_post_meta($attempt_id, '_cl_reviewed_by', get_current_user_id());
            update_post_meta($attempt_id, '_cl_reviewed_at', current_time('mysql'));
            cl_revoke_exam_for_user($user_id, $curso_id, $leccion_id, true);
            echo '<div class="notice notice-warning"><p>Examen revocado y curso reiniciado. El alumno deberá repetir el curso desde cero.</p></div>';
            $status = 'revoked_reset_course';
        }
    }

    echo '<p><a href="' . esc_url(admin_url('admin.php?page=cl_examenes')) . '">← Volver a la lista</a></p>';
    echo '<h2>Detalle del intento</h2>';
    echo '<p><strong>Alumno:</strong> ' . esc_html($user ? $user->display_name : ('Usuario ' . $user_id)) . '</p>';
    echo '<p><strong>Curso:</strong> ' . esc_html($curso ? $curso->post_title : $curso_id) . '</p>';
    echo '<p><strong>Lección:</strong> ' . esc_html($leccion ? $leccion->post_title : $leccion_id) . '</p>';
    echo '<p><strong>Estado:</strong> ' . esc_html($status) . '</p>';
    echo '<p><strong>Auto-score:</strong> ' . esc_html(is_numeric($auto) ? (round((float)$auto, 2) . '%') : '-') . '</p>';
    echo '<p><strong>Nota final:</strong> ' . esc_html(is_numeric($final) ? (round((float)$final, 2) . '%') : '-') . '</p>';

    $def = get_post_meta($leccion_id, '_cl_exam_definition', true);
    if (!is_array($def) || empty($def['questions'])) {
        echo '<p>El examen no tiene definición.</p>';
        return;
    }

    echo '<h3>Respuestas del alumno</h3>';
    echo '<div class="cl-exam-admin-review">';
    foreach ($def['questions'] as $qi => $q) {
        echo '<div class="cl-exam-admin-q">';
        echo '<div><strong>' . esc_html($qi + 1) . '.</strong> ' . wp_kses_post($q['text']) . '</div>';
        if (!empty($q['image_id'])) {
            echo '<div style="margin:8px 0;">' . wp_get_attachment_image((int)$q['image_id'], 'medium') . '</div>';
        }
        $sel = isset($answers[$qi]) ? (array)$answers[$qi] : [];
        $sel = array_map('strval', $sel);
        $cor = [];
        foreach ($q['options'] as $oi => $opt) {
            if (!empty($opt['is_correct'])) $cor[] = (string)$oi;
        }
        sort($cor);
        echo '<ul>';
        foreach ($q['options'] as $oi => $opt) {
            $is_sel = in_array((string)$oi, $sel, true);
            $is_cor = in_array((string)$oi, $cor, true);
            $tag = '';
            if ($is_cor) $tag .= ' <strong>(correcta)</strong>';
            if ($is_sel && !$is_cor) $tag .= ' <em>(seleccionada)</em>';
            if ($is_sel && $is_cor) $tag .= ' <strong>(seleccionada)</strong>';
            echo '<li>' . wp_kses_post($opt['text']) . $tag . '</li>';
        }
        echo '</ul>';
        echo '</div>';
    }
    echo '</div>';

    echo '<h3>Acciones</h3>';
    echo '<form method="post">';
    wp_nonce_field('cl_exam_review_' . $attempt_id);
    echo '<p><label>Nota final (%) <input type="number" step="0.01" name="cl_final_score" value="' . esc_attr(is_numeric($final) ? $final : $auto) . '" /></label></p>';
    echo '<p><label>Nota del profesor<br><textarea name="cl_admin_note" rows="4" style="width:100%;">' . esc_textarea((string)get_post_meta($attempt_id, '_cl_admin_note', true)) . '</textarea></label></p>';
    echo '<p>';
    echo '<button class="button button-primary" type="submit" name="cl_exam_action" value="approve">Aprobar</button> ';
    echo '<button class="button" type="submit" name="cl_exam_action" value="revoke_retry" onclick="return confirm(\'¿Revocar y pedir repetir el examen?\')">Revocar (repetir examen)</button> ';
    echo '<button class="button" type="submit" name="cl_exam_action" value="revoke_reset" onclick="return confirm(\'¿Revocar y reiniciar el curso desde cero?\')">Revocar (reiniciar curso)</button>';
    echo '</p>';
    echo '</form>';
}

function cl_mark_course_approved($user_id, $curso_id, $leccion_id) {
    // Marcar la lección examen como completada si no lo está
    $completadas = get_user_meta($user_id, "cl_curso_{$curso_id}_completadas", true);
    if (!is_array($completadas)) $completadas = [];
    if (!in_array($leccion_id, $completadas, true)) {
        $completadas[] = $leccion_id;
        update_user_meta($user_id, "cl_curso_{$curso_id}_completadas", $completadas);
    }
    update_user_meta($user_id, "cl_curso_{$curso_id}_aprobado", 1);
}

function cl_revoke_exam_for_user($user_id, $curso_id, $leccion_id, $reset_course) {
    if ($reset_course) {
        delete_user_meta($user_id, "cl_curso_{$curso_id}_completadas");
        delete_user_meta($user_id, "cl_curso_{$curso_id}_actual");
        delete_user_meta($user_id, "cl_curso_{$curso_id}_tiempos");
        delete_user_meta($user_id, "cl_curso_{$curso_id}_aprobado");
    } else {
        $completadas = get_user_meta($user_id, "cl_curso_{$curso_id}_completadas", true);
        if (is_array($completadas) && in_array($leccion_id, $completadas, true)) {
            $completadas = array_values(array_diff($completadas, [$leccion_id]));
            update_user_meta($user_id, "cl_curso_{$curso_id}_completadas", $completadas);
        }
        delete_user_meta($user_id, "cl_curso_{$curso_id}_aprobado");
    }

    $user = get_user_by('id', $user_id);
    if ($user && !empty($user->user_email)) {
        $curso = get_post($curso_id);
        $leccion = get_post($leccion_id);
        $subject = 'Examen revocado';
        $msg = "Tu examen ha sido revocado.\n\n";
        if ($curso) $msg .= "Curso: {$curso->post_title}\n";
        if ($leccion) $msg .= "Lección: {$leccion->post_title}\n";
        $msg .= $reset_course ? "\nDebes repetir el curso desde cero.\n" : "\nDebes repetir el examen.\n";
        wp_mail($user->user_email, $subject, $msg);
    }
}

/* -------------------------
   SHORTCODE: ESTADO DEL CURSO
   Uso en loop de cursos
------------------------- */
add_shortcode('cl_estado_curso', function($atts) {

    global $post;

    // Verificar que estamos en un post tipo 'curso-cie'
    if (!$post || $post->post_type !== 'curso-cie') {
        return '';
    }

    $curso_id = $post->ID;
    $user_id  = get_current_user_id();

    // Verificar usuario logueado
    if (!$user_id) {
        return 'Debes iniciar sesión.';
    }

    // Obtener lecciones del curso
    $lecciones = cl_get_lecciones_ordenadas($curso_id);
    $total     = count($lecciones);

    if ($total === 0) {
        return 'Curso sin lecciones.';
    }

    // Obtener lecciones completadas por el usuario
    $completadas = get_user_meta($user_id, "cl_curso_{$curso_id}_completadas", true);
    if (!is_array($completadas)) {
        $completadas = [];
    }

    $completadas_count = count($completadas);
    $porcentaje        = round(($completadas_count / $total) * 100);

    // Determinar estado del curso
    $aprobado = (int) get_user_meta($user_id, "cl_curso_{$curso_id}_aprobado", true);
    if ($completadas_count === 0) {
        $estado = "<span class='state-no-init'>No iniciado</span>";
    } elseif ($completadas_count < $total) {
        $estado = "<span class='state-progress'>En progreso ({$porcentaje}%)</span>";
    } else {
        $estado = $aprobado ? "<span class='state-complete'>Completado y aprobado</span>" : "<span class='state-complete'>Completado (pendiente de aprobación)</span>";
    }

    return $estado;

});

/* =====================================================
   SHORTCODE: FORMULARIO SOLICITUD INSCRIPCIÓN
===================================================== */
function cl_create_enrollment_request_token($user_id, $course_ids) {
    $token = wp_generate_uuid4();
    set_transient('cl_enroll_req_' . $token, [
        'user_id' => (int) $user_id,
        'course_ids' => array_values(array_unique(array_filter(array_map('absint', (array)$course_ids)))),
        'created_at' => time(),
    ], 7 * DAY_IN_SECONDS);
    return $token;
}

add_shortcode('cl_form_inscripcion', function() {
    if (!is_user_logged_in()) return 'Debes iniciar sesión.';
    $user_id = get_current_user_id();

    $cursos = get_posts([
        'post_type' => 'curso-cie',
        'numberposts' => -1,
        'orderby' => 'title',
        'order' => 'ASC',
    ]);

    $eligible = [];
    foreach ($cursos as $c) {
        if (cl_course_access_mode($c->ID) !== 'inscripcion') continue;
        if (cl_is_user_enrolled_in_course($user_id, $c->ID)) continue;
        $eligible[] = $c;
    }

    $notice = '';
    if (!empty($_POST['cl_insc_submit']) && !empty($_POST['cl_insc_nonce']) && wp_verify_nonce($_POST['cl_insc_nonce'], 'cl_insc_submit')) {
        $selected = isset($_POST['cl_courses']) ? (array) $_POST['cl_courses'] : [];
        $selected = array_values(array_unique(array_filter(array_map('absint', $selected))));

        $selected_ok = [];
        foreach ($selected as $cid) {
            if (get_post_type($cid) !== 'curso-cie') continue;
            if (cl_course_access_mode($cid) !== 'inscripcion') continue;
            if (cl_is_user_enrolled_in_course($user_id, $cid)) continue;
            $selected_ok[] = $cid;
        }

        if (empty($selected_ok)) {
            $notice = '<div class="cl-no-access">Selecciona al menos un curso disponible.</div>';
        } else {
            $token = cl_create_enrollment_request_token($user_id, $selected_ok);
            $review_link = admin_url('admin-post.php?action=cl_review_enrollment_request&token=' . urlencode($token));

            $user = get_user_by('id', $user_id);
            $subject = 'Solicitud de inscripción a cursos';
            $msg = "Un usuario ha solicitado inscripción a cursos.\n\n";
            $msg .= "Usuario: " . ($user ? ($user->display_name . ' (' . $user->user_login . ')') : $user_id) . "\n";
            $msg .= "Email: " . ($user ? $user->user_email : '') . "\n\n";
            $msg .= "Cursos solicitados:\n";
            foreach ($selected_ok as $cid) {
                $c = get_post($cid);
                $msg .= "- " . ($c ? $c->post_title : ('Curso ' . $cid)) . " (ID: {$cid})\n";
            }
            $msg .= "\nRevisar solicitud (aprobar/revocar por curso):\n";
            $msg .= $review_link . "\n";

            $admin_email = (string) get_option('admin_email');
            if ($admin_email) wp_mail($admin_email, $subject, $msg);

            $notice = '<div class="cl-no-access" style="border-left-color:#46b450; background:#f1fff3;">Solicitud enviada. Un administrador revisará tu inscripción.</div>';
        }
    }

    if (empty($eligible)) {
        return $notice . '<div class="cl-no-access" style="border-left-color:#ffb900; background:#fffbea;">No hay cursos disponibles para solicitar inscripción.</div>';
    }

    ob_start();
    echo $notice; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    ?>
    <form method="post" class="cl-insc-form">
        <?php wp_nonce_field('cl_insc_submit', 'cl_insc_nonce'); ?>
        <p>Selecciona los cursos en los que quieres inscribirte:</p>
        <div class="cl-insc-list">
            <?php foreach ($eligible as $c): ?>
                <label style="display:block; margin:6px 0;">
                    <input type="checkbox" name="cl_courses[]" value="<?php echo esc_attr($c->ID); ?>" />
                    <?php echo esc_html($c->post_title); ?>
                </label>
            <?php endforeach; ?>
        </div>
        <p style="margin-top:12px;">
            <button type="submit" class="cl-btn" name="cl_insc_submit" value="1">Solicitar inscripción</button>
        </p>
    </form>
    <?php
    return ob_get_clean();
});

function cl_render_enrollment_review_screen($token, $req) {
    $user_id = (int) ($req['user_id'] ?? 0);
    $course_ids = array_values(array_unique(array_filter(array_map('absint', (array)($req['course_ids'] ?? [])))));
    $user = $user_id ? get_user_by('id', $user_id) : null;

    $courses = [];
    foreach ($course_ids as $cid) {
        if (get_post_type($cid) !== 'curso-cie') continue;
        $c = get_post($cid);
        if ($c) $courses[] = $c;
    }

    echo '<div class="wrap">';
    echo '<h1>Revisar solicitud de inscripción</h1>';
    echo '<p><strong>Usuario:</strong> ' . esc_html($user ? ($user->display_name . ' (' . $user->user_login . ')') : ('Usuario ' . $user_id)) . '</p>';
    echo '<p><strong>Email:</strong> ' . esc_html($user ? $user->user_email : '-') . '</p>';
    echo '<hr />';

    if (empty($courses)) {
        echo '<p>No hay cursos válidos en esta solicitud.</p>';
        echo '</div>';
        return;
    }

    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    echo '<input type="hidden" name="action" value="cl_process_enrollment_request" />';
    echo '<input type="hidden" name="token" value="' . esc_attr($token) . '" />';
    wp_nonce_field('cl_process_enrollment_request_' . $token);

    echo '<table class="widefat striped"><thead><tr>';
    echo '<th>Curso</th><th>Acción</th>';
    echo '</tr></thead><tbody>';
    foreach ($courses as $c) {
        $cid = $c->ID;
        echo '<tr>';
        echo '<td>' . esc_html($c->post_title) . '</td>';
        echo '<td>';
        echo '<label style="margin-right:12px;"><input type="radio" name="decision[' . esc_attr($cid) . ']" value="approve" checked /> Aprobar</label>';
        echo '<label><input type="radio" name="decision[' . esc_attr($cid) . ']" value="revoke" /> Revocar</label>';
        echo '</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
    echo '<p style="margin-top:14px;"><button type="submit" class="button button-primary">Guardar</button></p>';
    echo '</form>';
    echo '</div>';
}

add_action('admin_post_cl_review_enrollment_request', function() {
    if (!current_user_can('manage_options')) wp_die('Sin permisos.');
    $token = isset($_GET['token']) ? sanitize_text_field(wp_unslash($_GET['token'])) : '';
    if ($token === '') wp_die('Token inválido.');

    $req = get_transient('cl_enroll_req_' . $token);
    if (!is_array($req) || empty($req['user_id']) || empty($req['course_ids'])) {
        wp_die('Solicitud caducada o inválida.');
    }

    if (!function_exists('wp_admin_css')) {
        require_once ABSPATH . 'wp-admin/includes/admin.php';
    }

    // Render WP Admin header/footer mínimos
    @header('Content-Type: text/html; charset=' . get_option('blog_charset'));
    require_once ABSPATH . 'wp-admin/admin-header.php';
    cl_render_enrollment_review_screen($token, $req);
    require_once ABSPATH . 'wp-admin/admin-footer.php';
    exit;
});

add_action('admin_post_cl_process_enrollment_request', function() {
    if (!current_user_can('manage_options')) wp_die('Sin permisos.');
    $token = isset($_POST['token']) ? sanitize_text_field(wp_unslash($_POST['token'])) : '';
    if ($token === '') wp_die('Token inválido.');
    check_admin_referer('cl_process_enrollment_request_' . $token);

    $req = get_transient('cl_enroll_req_' . $token);
    if (!is_array($req) || empty($req['user_id']) || empty($req['course_ids'])) {
        wp_die('Solicitud caducada o inválida.');
    }
    delete_transient('cl_enroll_req_' . $token);

    $user_id = (int) $req['user_id'];
    $course_ids = array_values(array_unique(array_filter(array_map('absint', (array)$req['course_ids']))));
    $decisions = isset($_POST['decision']) ? (array) $_POST['decision'] : [];

    $approved = [];
    $revoked = [];

    foreach ($course_ids as $cid) {
        if (get_post_type($cid) !== 'curso-cie') continue;
        if (cl_course_access_mode($cid) !== 'inscripcion') continue;

        $decision = isset($decisions[$cid]) ? sanitize_text_field($decisions[$cid]) : 'approve';
        if ($decision !== 'approve') {
            $revoked[] = $cid;
            continue;
        }

        $ids = cl_get_enrolled_user_ids($cid);
        if (!in_array($user_id, $ids, true)) {
            $ids[] = $user_id;
            update_post_meta($cid, CL_META_ENROLLED_USERS, array_values(array_unique($ids)));
        }
        $approved[] = $cid;
    }

    // Email al usuario con cursos aprobados/revocados + links
    $user = get_user_by('id', $user_id);
    if ($user && !empty($user->user_email)) {
        $subject = 'Resultado de tu solicitud de inscripción';
        $msg = "Hemos revisado tu solicitud de inscripción.\n\n";

        if (!empty($approved)) {
            $msg .= "Cursos aprobados:\n";
            foreach ($approved as $cid) {
                $c = get_post($cid);
                $msg .= "- " . ($c ? $c->post_title : ('Curso ' . $cid)) . "\n";
                $msg .= "  " . get_permalink($cid) . "\n";
            }
            $msg .= "\n";
        }

        if (!empty($revoked)) {
            $msg .= "Cursos no aprobados:\n";
            foreach ($revoked as $cid) {
                $c = get_post($cid);
                $msg .= "- " . ($c ? $c->post_title : ('Curso ' . $cid)) . "\n";
                $msg .= "  " . get_permalink($cid) . "\n";
            }
            $msg .= "\n";
        }

        wp_mail($user->user_email, $subject, $msg);
    }

    wp_safe_redirect(admin_url('edit.php?post_type=curso-cie'));
    exit;
});