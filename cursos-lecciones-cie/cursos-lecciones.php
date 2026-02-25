<?php
/*
Plugin Name: Cursos y Lecciones
Description: Mini academia con cursos y lecciones visuales.
Version: 1.4
Author: Wembleys Studios
*/

if ( ! defined( 'ABSPATH' ) ) exit;

define('CL_CIE_VERSION', '1.6');

/* =====================================================
   META KEYS / CONSTANTES
===================================================== */
define('CL_META_ACCESS_MODE', '_cl_access_mode'); // libre | inscripcion
define('CL_META_ENROLLED_USERS', '_cl_enrolled_users'); // array user IDs
define('CL_META_COURSE_AUTOEVAL', '_cl_course_autoeval'); // 0/1
define('CL_META_COURSE_EXAM_NOTIFY_USER', '_cl_exam_notify_user_id'); // int user id
define('CL_META_EXAM_TIME_SECONDS', '_cl_exam_time_seconds'); // int seconds
define('CL_META_EXAM_MAX_GRADE', '_cl_exam_max_grade'); // float (default 10)
define('CL_META_EXAM_EMAIL_APPROVED_SUBJECT', '_cl_exam_email_approved_subject'); // string
define('CL_META_EXAM_EMAIL_APPROVED_BODY', '_cl_exam_email_approved_body'); // string
define('CL_META_EXAM_EMAIL_REVOKED_SUBJECT', '_cl_exam_email_revoked_subject'); // string
define('CL_META_EXAM_EMAIL_REVOKED_BODY', '_cl_exam_email_revoked_body'); // string

// User meta: cursos solicitados (pendiente de aprobación de inscripción)
define('CL_USER_META_PENDING_ENROLLMENTS', '_cl_pending_enrollments'); // array course IDs
define('CL_USER_META_PENDING_ENROLLMENTS_DATES', '_cl_pending_enrollments_dates'); // map course ID => timestamp
define('CL_OPTION_ENROLLMENT_REQUESTS', 'cl_enrollment_requests'); // token => request payload

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
   EXAM: NOTA MÁXIMA
===================================================== */
function cl_get_exam_max_grade($leccion_id) {
    $v = get_post_meta($leccion_id, CL_META_EXAM_MAX_GRADE, true);
    $max = is_numeric($v) ? (float) $v : 10.0;
    if (!is_finite($max) || $max <= 0) $max = 10.0;
    return $max;
}

function cl_get_exam_email_default_templates($result_type = 'approved') {
    $result_type = $result_type === 'approved' ? 'approved' : 'revoked';
    $defaults = [
        'approved' => [
            'subject' => 'Examen aprobado: {course_name}',
            'body' => "Hola {student_name},\n\nTu examen \"{lesson_name}\" del curso \"{course_name}\" ha sido aprobado.\n\nNota: {grade} / {max_grade}\nEstado: {status}\n{action_message}\n\n{admin_note}\n\nPuedes acceder al curso aquí:\n{course_url}",
        ],
        'revoked' => [
            'subject' => 'Examen suspendido: {course_name}',
            'body' => "Hola {student_name},\n\nTu examen \"{lesson_name}\" del curso \"{course_name}\" ha sido suspendido/revocado.\n\nEstado: {status}\n{action_message}\n\n{admin_note}\n\nPuedes revisar el curso aquí:\n{course_url}",
        ],
    ];
    return $defaults[$result_type];
}

function cl_get_exam_email_template($lesson_id, $result_type = 'approved') {
    $lesson_id = absint($lesson_id);
    $result_type = $result_type === 'approved' ? 'approved' : 'revoked';
    $defaults = cl_get_exam_email_default_templates($result_type);

    $subject_key = $result_type === 'approved'
        ? CL_META_EXAM_EMAIL_APPROVED_SUBJECT
        : CL_META_EXAM_EMAIL_REVOKED_SUBJECT;
    $body_key = $result_type === 'approved'
        ? CL_META_EXAM_EMAIL_APPROVED_BODY
        : CL_META_EXAM_EMAIL_REVOKED_BODY;

    $subject = $lesson_id > 0 ? (string) get_post_meta($lesson_id, $subject_key, true) : '';
    $body = $lesson_id > 0 ? (string) get_post_meta($lesson_id, $body_key, true) : '';
    if (trim($subject) === '') $subject = $defaults['subject'];
    if (trim($body) === '') $body = $defaults['body'];

    return [
        'subject' => $subject,
        'body' => $body,
    ];
}

function cl_replace_exam_email_placeholders($template, $placeholders) {
    $template = (string) $template;
    if ($template === '') return '';
    $replace = [];
    foreach ((array)$placeholders as $k => $v) {
        $replace['{' . $k . '}'] = (string) $v;
    }
    return strtr($template, $replace);
}

function cl_send_exam_result_email($attempt_id, $result_type = 'approved', $admin_note = '') {
    $attempt_id = absint($attempt_id);
    if (!$attempt_id || get_post_type($attempt_id) !== 'cl-exam-attempt') return false;

    $result_type = $result_type === 'approved' ? 'approved' : 'revoked';
    $course_id = (int) get_post_meta($attempt_id, '_cl_course_id', true);
    $lesson_id = (int) get_post_meta($attempt_id, '_cl_lesson_id', true);
    $user_id = (int) get_post_meta($attempt_id, '_cl_user_id', true);
    if (!$user_id) return false;

    $user = get_user_by('id', $user_id);
    if (!$user || empty($user->user_email)) return false;

    $course = $course_id > 0 ? get_post($course_id) : null;
    $lesson = $lesson_id > 0 ? get_post($lesson_id) : null;
    $course_name = $course ? (string) $course->post_title : ('Curso ' . $course_id);
    $lesson_name = $lesson ? (string) $lesson->post_title : ('Lección ' . $lesson_id);

    $grade_data = cl_get_exam_attempt_grade_data($attempt_id);
    $grade_txt = (is_array($grade_data) && is_numeric($grade_data['grade'])) ? cl_format_grade_value($grade_data['grade']) : '-';
    $max_txt = (is_array($grade_data) && is_numeric($grade_data['max_grade'])) ? cl_format_grade_value($grade_data['max_grade']) : '-';

    $status = (string) get_post_meta($attempt_id, '_cl_status', true);
    $status_label = cl_get_exam_attempt_status_label($status);
    $action_message = '';
    if ($status === 'revoked_reset_course') {
        $action_message = 'Debes repetir el curso desde cero.';
    } elseif ($status === 'retry_required') {
        $action_message = 'Debes repetir el examen.';
    } elseif ($result_type === 'approved') {
        $action_message = 'Enhorabuena, has superado el examen.';
    } else {
        $action_message = 'Debes repetir el examen.';
    }

    $admin_note = trim(wp_strip_all_tags((string) $admin_note));
    $admin_note_text = $admin_note !== '' ? "Nota del profesor:\n{$admin_note}" : 'Sin observaciones del profesor.';
    $course_url = $course_id > 0 ? get_permalink($course_id) : '';

    $template = cl_get_exam_email_template($lesson_id, $result_type);
    $placeholders = [
        'student_name' => (string) ($user->display_name ?: $user->user_login),
        'course_name' => $course_name,
        'lesson_name' => $lesson_name,
        'grade' => $grade_txt,
        'max_grade' => $max_txt,
        'status' => $status_label,
        'action_message' => $action_message,
        'admin_note' => $admin_note_text,
        'course_url' => (string) $course_url,
    ];

    $subject = trim(cl_replace_exam_email_placeholders($template['subject'], $placeholders));
    $message = trim(cl_replace_exam_email_placeholders($template['body'], $placeholders));
    if ($subject === '') $subject = cl_get_exam_email_default_templates($result_type)['subject'];
    if ($message === '') $message = cl_get_exam_email_default_templates($result_type)['body'];

    return wp_mail($user->user_email, $subject, $message);
}

/* =====================================================
   CPT: CURSOS
===================================================== */
add_action( 'init', function() {
    register_post_type( 'curso-cie', [
        'label' => 'Cursos',
        'public' => true,
        'menu_icon' => 'dashicons-welcome-learn-more',
        // Soportar Elementor/constructor (editor) + extracto (texto plano).
        'supports' => [ 'title', 'editor', 'excerpt', 'thumbnail' ],
        'show_in_rest' => false,
    ]);
});

/* =====================================================
   CURSO: extracto texto plano (máx 400 caracteres)
===================================================== */
function cl_normalize_plain_text_excerpt($text, $max_chars = 400) {
    $text = is_string($text) ? $text : '';
    $text = wp_strip_all_tags($text, true);
    $text = trim(preg_replace('/\s+/', ' ', $text));
    $max_chars = max(0, (int)$max_chars);
    if ($max_chars > 0) {
        if (function_exists('mb_substr')) {
            $text = mb_substr($text, 0, $max_chars);
        } else {
            $text = substr($text, 0, $max_chars);
        }
    }
    return $text;
}

add_filter('wp_insert_post_data', function($data, $postarr) {
    if (!is_array($data) || empty($data['post_type']) || $data['post_type'] !== 'curso-cie') return $data;
    $data['post_excerpt'] = cl_normalize_plain_text_excerpt($data['post_excerpt'] ?? '', 400);
    return $data;
}, 10, 2);

/* =====================================================
   FRONT: helpers anti-recursión (por si se usan en filtros internos)
===================================================== */
function cl_disable_course_content_override($disabled = true) {
    $GLOBALS['cl_disable_course_content_override'] = $disabled ? 1 : 0;
}

function cl_is_course_content_override_disabled() {
    return !empty($GLOBALS['cl_disable_course_content_override']);
}

// Nota: ya no forzamos el contenido del curso en `the_content`.
// El usuario puede insertar `[cl_boton_comenzar_curso]` y `[cl_leccion_curso]` desde Elementor.

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

// Estado inicial de visibilidad de metaboxes en admin (por si JS falla)
add_filter('postbox_classes_lecciones-cie_cl_leccion_video', function($classes){
    $post_id = isset($_GET['post']) ? absint($_GET['post']) : 0;
    $tipo = $post_id ? cl_get_tipo_de_leccion($post_id) : 'normal';
    if ($tipo !== 'video') $classes[] = 'cl-metabox-hidden';
    return $classes;
});

add_filter('postbox_classes_lecciones-cie_cl_leccion_examen', function($classes){
    $post_id = isset($_GET['post']) ? absint($_GET['post']) : 0;
    $tipo = $post_id ? cl_get_tipo_de_leccion($post_id) : 'normal';
    if ($tipo !== 'examen') $classes[] = 'cl-metabox-hidden';
    return $classes;
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
    if (is_admin()) return;

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

function cl_get_exam_attempt_status_label($status) {
    $status = sanitize_key((string)$status);
    $labels = [
        'pending_review' => 'Pendiente de revisión',
        'approved' => 'Aprobado',
        'auto_approved' => 'Aprobado (auto)',
        'retry_required' => 'Revocado (repetir examen)',
        'revoked_reset_course' => 'Revocado (reiniciar curso)',
    ];
    if (isset($labels[$status])) return $labels[$status];
    if ($status === '') return 'Desconocido';
    return ucfirst(str_replace('_', ' ', $status));
}

function cl_get_courses_exams_relation_data($args = []) {
    $defaults = [
        'post_status' => 'publish',
        'numberposts' => -1,
        'orderby' => 'title',
        'order' => 'ASC',
    ];
    $args = wp_parse_args((array)$args, $defaults);

    $courses = get_posts([
        'post_type' => 'curso-cie',
        'post_status' => $args['post_status'],
        'numberposts' => (int) $args['numberposts'],
        'orderby' => $args['orderby'],
        'order' => $args['order'],
    ]);

    $rows = [];
    foreach ($courses as $course) {
        $exams = [];
        $lessons = cl_get_lecciones_ordenadas($course->ID);

        foreach ($lessons as $lesson) {
            if (cl_get_leccion_tipo($lesson->ID) !== 'examen') continue;
            $def = get_post_meta($lesson->ID, '_cl_exam_definition', true);
            $questions = (is_array($def) && !empty($def['questions']) && is_array($def['questions'])) ? $def['questions'] : [];

            $exams[] = [
                'lesson_id' => (int) $lesson->ID,
                'lesson_title' => (string) $lesson->post_title,
                'lesson_edit_url' => get_edit_post_link($lesson->ID, ''),
                'questions_count' => count($questions),
                'max_grade' => (float) cl_get_exam_max_grade($lesson->ID),
            ];
        }

        $rows[] = [
            'course_id' => (int) $course->ID,
            'course_title' => (string) $course->post_title,
            'course_edit_url' => get_edit_post_link($course->ID, ''),
            'access_mode' => cl_course_access_mode($course->ID),
            'exams_count' => count($exams),
            'exams' => $exams,
        ];
    }

    return $rows;
}

function cl_process_courses_exams_relation_actions($user_id) {
    $notices = [];
    $user_id = absint($user_id);
    if (!$user_id) return $notices;

    if (!empty($_GET['cl_delete_course_progress'])) {
        $course_id = absint($_GET['cl_delete_course_progress']);
        $target_user_id = isset($_GET['cl_progress_user']) ? absint($_GET['cl_progress_user']) : $user_id;
        $nonce = isset($_GET['_cl_progress_nonce']) ? sanitize_text_field(wp_unslash($_GET['_cl_progress_nonce'])) : '';
        $can_manage = current_user_can('manage_options') || $target_user_id === $user_id;
        if (
            $course_id > 0 &&
            $target_user_id > 0 &&
            $can_manage &&
            wp_verify_nonce($nonce, 'cl_delete_course_progress_' . $target_user_id . '_' . $course_id)
        ) {
            cl_delete_user_course_progress($target_user_id, $course_id, true);
            $notices[] = ['type' => 'success', 'text' => 'Progreso borrado correctamente.'];
        } else {
            $notices[] = ['type' => 'warning', 'text' => 'No se pudo borrar el progreso (permiso o nonce inválido).'];
        }
    }

    if (!empty($_GET['cl_delete_course_enrollment'])) {
        $course_id = absint($_GET['cl_delete_course_enrollment']);
        $target_user_id = isset($_GET['cl_enroll_user']) ? absint($_GET['cl_enroll_user']) : $user_id;
        $nonce = isset($_GET['_cl_enroll_nonce']) ? sanitize_text_field(wp_unslash($_GET['_cl_enroll_nonce'])) : '';
        $can_manage = current_user_can('manage_options') || $target_user_id === $user_id;
        if (
            $course_id > 0 &&
            $target_user_id > 0 &&
            $can_manage &&
            wp_verify_nonce($nonce, 'cl_delete_course_enrollment_' . $target_user_id . '_' . $course_id)
        ) {
            $summary = cl_get_course_user_summary($target_user_id, $course_id);
            if (!cl_is_zero_progress_enrollment_summary($summary)) {
                $notices[] = ['type' => 'warning', 'text' => 'Solo puedes borrar la inscripción cuando el estado sea “Inscrito” y el progreso sea 0.'];
            } elseif (cl_remove_user_course_enrollment($target_user_id, $course_id, true)) {
                $notices[] = ['type' => 'success', 'text' => 'Inscripción eliminada correctamente.'];
            } else {
                $notices[] = ['type' => 'warning', 'text' => 'No se pudo eliminar la inscripción.'];
            }
        } else {
            $notices[] = ['type' => 'warning', 'text' => 'No se pudo eliminar la inscripción (permiso o nonce inválido).'];
        }
    }

    if (!empty($_GET['cl_delete_exam_attempt'])) {
        $attempt_id = absint($_GET['cl_delete_exam_attempt']);
        $nonce = isset($_GET['_cl_del_nonce']) ? sanitize_text_field(wp_unslash($_GET['_cl_del_nonce'])) : '';
        if ($attempt_id > 0 && wp_verify_nonce($nonce, 'cl_delete_exam_attempt_' . $attempt_id)) {
            $attempt_user_id = (int) get_post_meta($attempt_id, '_cl_user_id', true);
            $can_delete = current_user_can('manage_options') || $attempt_user_id === $user_id;
            if ($can_delete && get_post_type($attempt_id) === 'cl-exam-attempt') {
                wp_delete_post($attempt_id, true);
                $notices[] = ['type' => 'success', 'text' => 'Examen eliminado correctamente.'];
            } else {
                $notices[] = ['type' => 'warning', 'text' => 'No tienes permisos para eliminar este examen.'];
            }
        }
    }

    if (!empty($_GET['cl_pending_decision']) && !empty($_GET['cl_pending_course']) && !empty($_GET['cl_pending_user'])) {
        $decision = sanitize_key((string) $_GET['cl_pending_decision']);
        $course_id = absint($_GET['cl_pending_course']);
        $target_user_id = absint($_GET['cl_pending_user']);
        $nonce = isset($_GET['_cl_pending_nonce']) ? sanitize_text_field(wp_unslash($_GET['_cl_pending_nonce'])) : '';

        if (!current_user_can('manage_options')) {
            $notices[] = ['type' => 'warning', 'text' => 'No tienes permisos para revisar inscripciones.'];
        } elseif (
            !in_array($decision, ['approve', 'revoke'], true) ||
            $course_id <= 0 ||
            $target_user_id <= 0 ||
            !wp_verify_nonce($nonce, 'cl_pending_decision_' . $target_user_id . '_' . $course_id . '_' . $decision)
        ) {
            $notices[] = ['type' => 'warning', 'text' => 'Acción de inscripción inválida.'];
        } elseif (!cl_is_course_pending_for_user($target_user_id, $course_id)) {
            $notices[] = ['type' => 'warning', 'text' => 'La solicitud ya no está pendiente.'];
        } else {
            $result = cl_apply_enrollment_decisions($target_user_id, [$course_id], [$course_id => $decision], get_current_user_id());
            if ($decision === 'approve') {
                $notices[] = ['type' => 'success', 'text' => 'Inscripción aprobada.'];
            } else {
                $notices[] = ['type' => 'success', 'text' => 'Inscripción revocada.'];
            }
            cl_create_enrollment_request_token($target_user_id, [$course_id], [
                'status' => 'processed',
                'approved' => $result['approved'],
                'revoked' => $result['revoked'],
                'reviewed_by' => get_current_user_id(),
                'reviewed_at' => time(),
                'source' => 'courses_exams_relation',
            ]);
        }
    }

    return $notices;
}

function cl_get_courses_exams_relation($args = []) {
    $defaults = [
        'post_status' => 'publish',
        'numberposts' => -1,
        'orderby' => 'title',
        'order' => 'ASC',
        'format' => 'html', // html | array
        'user_id' => get_current_user_id(),
        'attempts_numberposts' => 100,
        'show_notices' => true,
    ];
    $args = wp_parse_args((array)$args, $defaults);

    if (($args['format'] ?? 'html') === 'array') {
        return cl_get_courses_exams_relation_data($args);
    }

    $user_id = absint($args['user_id']);
    if (!$user_id) return '<div class="cl-no-access">Debes iniciar sesión.</div>';

    $notices = !empty($args['show_notices']) ? cl_process_courses_exams_relation_actions($user_id) : [];

    $courses = get_posts([
        'post_type' => 'curso-cie',
        'post_status' => $args['post_status'],
        'numberposts' => (int) $args['numberposts'],
        'orderby' => $args['orderby'],
        'order' => $args['order'],
    ]);

    $course_rows = [];
    foreach ($courses as $course) {
        $summary = cl_get_course_user_summary($user_id, $course->ID);
        $is_related = !empty($summary['is_enrolled'])
            || !empty($summary['is_pending'])
            || !empty($summary['started'])
            || !empty($summary['approved'])
            || !empty($summary['is_completed'])
            || (int)($summary['completed_lessons'] ?? 0) > 0;
        if (!$is_related) continue;

        $completed_ids = array_map('absint', (array)($summary['completed_lesson_ids'] ?? []));
        $lesson_times = (array)($summary['lesson_times'] ?? []);
        $lesson_lines = [];
        foreach (cl_get_lecciones_ordenadas($course->ID) as $lesson) {
            $lid = (int) $lesson->ID;
            $done = in_array($lid, $completed_ids, true);
            $time_txt = isset($lesson_times[$lid]) ? gmdate('H:i:s', max(0, (int)$lesson_times[$lid])) : '-';
            $state = $done ? '<span class="state-complete">Completada</span>' : '<span class="state-progress">En progreso</span>';
            $lesson_lines[] = $state . ' (' . esc_html($time_txt) . ') ' . esc_html($lesson->post_title);
        }

        $delete_progress_url = wp_nonce_url(
            add_query_arg([
                'cl_delete_course_progress' => (int) $course->ID,
                'cl_progress_user' => (int) $user_id,
            ]),
            'cl_delete_course_progress_' . $user_id . '_' . $course->ID,
            '_cl_progress_nonce'
        );
        $actions = [];
        $actions[] = '<a class="button button-secondary" href="' . esc_url($delete_progress_url) . '" onclick="return confirm(\'¿Seguro que quieres borrar el progreso del curso?\')">Borrar progreso</a>';

        if (cl_is_zero_progress_enrollment_summary($summary)) {
            $delete_enrollment_url = wp_nonce_url(
                add_query_arg([
                    'cl_delete_course_enrollment' => (int) $course->ID,
                    'cl_enroll_user' => (int) $user_id,
                ]),
                'cl_delete_course_enrollment_' . $user_id . '_' . $course->ID,
                '_cl_enroll_nonce'
            );
            $actions[] = '<a class="button button-secondary" href="' . esc_url($delete_enrollment_url) . '" onclick="return confirm(\'¿Seguro que quieres borrar la inscripción del curso?\')">Borrar inscripción</a>';
        }

        $action_html = implode(' ', $actions);

        $course_rows[] = [
            'course' => $course,
            'summary' => $summary,
            'lesson_lines' => $lesson_lines,
            'action_html' => $action_html !== '' ? $action_html : '-',
        ];
    }

    $attempts = get_posts([
        'post_type' => 'cl-exam-attempt',
        'numberposts' => max(1, (int) $args['attempts_numberposts']),
        'orderby' => 'date',
        'order' => 'DESC',
        'meta_query' => [
            [
                'key' => '_cl_user_id',
                'value' => (string) $user_id,
                'compare' => '=',
            ],
        ],
    ]);

    $exam_rows = [];
    foreach ($attempts as $attempt) {
        $attempt_id = (int) $attempt->ID;
        $course_id = (int) get_post_meta($attempt_id, '_cl_course_id', true);
        $lesson_id = (int) get_post_meta($attempt_id, '_cl_lesson_id', true);

        if ($course_id <= 0 && $lesson_id > 0) {
            $course_id = (int) get_post_field('post_parent', $lesson_id);
        }

        $course = $course_id > 0 ? get_post($course_id) : null;
        $lesson = $lesson_id > 0 ? get_post($lesson_id) : null;
        $status = (string) get_post_meta($attempt_id, '_cl_status', true);
        $grade_data = cl_get_exam_attempt_grade_data($attempt_id);
        $grade_txt = '-';
        if (is_array($grade_data) && is_numeric($grade_data['grade'])) {
            $grade_txt = cl_format_grade_value($grade_data['grade']) . ' / ' . cl_format_grade_value($grade_data['max_grade']);
        }
        $submitted = (string) get_post_meta($attempt_id, '_cl_submitted_at', true);
        if ($submitted === '') {
            $submitted = get_the_date('Y-m-d H:i', $attempt);
        }

        $review_url = '';
        if (current_user_can('manage_options')) {
            $review_url = admin_url('admin.php?page=cl_examenes&attempt=' . $attempt_id);
        } elseif ($course_id > 0) {
            $course_url = get_permalink($course_id);
            if ($course_url) $review_url = add_query_arg('show-lecciones', '1', $course_url);
        }

        $delete_url = wp_nonce_url(
            add_query_arg(['cl_delete_exam_attempt' => $attempt_id]),
            'cl_delete_exam_attempt_' . $attempt_id,
            '_cl_del_nonce'
        );

        $actions = [];
        if ($review_url !== '') {
            $actions[] = '<a class="button button-primary" href="' . esc_url($review_url) . '">Revisar</a>';
        }
        $actions[] = '<a class="button button-secondary" href="' . esc_url($delete_url) . '" onclick="return confirm(\'¿Seguro que quieres eliminar este examen?\')">Eliminar</a>';

        $exam_rows[] = [
            'course' => $course,
            'lesson' => $lesson,
            'submitted' => $submitted,
            'grade_txt' => $grade_txt,
            'status' => $status,
            'actions' => implode(' ', $actions),
        ];
    }

    $pending_rows = [];
    $pending_ids = cl_get_user_pending_enrollments($user_id);
    $pending_dates = cl_get_user_pending_enrollments_dates($user_id);
    foreach ($pending_ids as $cid) {
        $course = get_post($cid);
        if (!$course || $course->post_type !== 'curso-cie') continue;
        $ts = isset($pending_dates[$cid]) ? absint($pending_dates[$cid]) : 0;
        $date_txt = $ts > 0 ? date_i18n('Y-m-d H:i', $ts) : '-';
        $actions_html = '-';
        if (current_user_can('manage_options')) {
            $approve_url = wp_nonce_url(
                add_query_arg([
                    'cl_pending_decision' => 'approve',
                    'cl_pending_course' => (int) $cid,
                    'cl_pending_user' => (int) $user_id,
                ]),
                'cl_pending_decision_' . $user_id . '_' . $cid . '_approve',
                '_cl_pending_nonce'
            );
            $revoke_url = wp_nonce_url(
                add_query_arg([
                    'cl_pending_decision' => 'revoke',
                    'cl_pending_course' => (int) $cid,
                    'cl_pending_user' => (int) $user_id,
                ]),
                'cl_pending_decision_' . $user_id . '_' . $cid . '_revoke',
                '_cl_pending_nonce'
            );
            $actions_html =
                '<a class="button button-primary" href="' . esc_url($approve_url) . '" onclick="return confirm(\'¿Aprobar esta inscripción?\')">Aprobar</a> ' .
                '<a class="button button-secondary" href="' . esc_url($revoke_url) . '" onclick="return confirm(\'¿Revocar esta inscripción?\')">Revocar</a>';
        }
        $pending_rows[] = [
            'course' => $course,
            'date' => $date_txt,
            'actions' => $actions_html,
        ];
    }

    ob_start();
    ?>
    <div class="cl-courses-exams-relation">
        <?php foreach ($notices as $notice): ?>
            <?php $notice_class = $notice['type'] === 'success' ? 'updated' : 'notice notice-warning'; ?>
            <div class="<?php echo esc_attr($notice_class); ?>"><p><?php echo esc_html($notice['text']); ?></p></div>
        <?php endforeach; ?>

        <h3>Progreso de cursos</h3>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th>Nombre del curso</th>
                    <th>Estado</th>
                    <th>Lecciones completadas</th>
                    <th>Tiempo por lección</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($course_rows)): ?>
                    <tr><td colspan="5">No hay cursos con actividad.</td></tr>
                <?php else: ?>
                    <?php foreach ($course_rows as $row): ?>
                        <?php
                            $course = $row['course'];
                            $summary = $row['summary'];
                            $title = (string) $course->post_title;
                            $url = get_permalink($course->ID);
                            $course_link = $url ? '<a href="' . esc_url($url) . '">' . esc_html($title) . '</a>' : esc_html($title);
                        ?>
                        <tr>
                            <td><?php echo $course_link; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
                            <td><?php echo cl_get_course_state_html_from_summary($summary); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
                            <td><?php echo esc_html((int)$summary['completed_lessons'] . '/' . (int)$summary['total_lessons']); ?></td>
                            <td><?php echo !empty($row['lesson_lines']) ? implode('<br>', $row['lesson_lines']) : '-'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
                            <td><?php echo $row['action_html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <h3 style="margin-top:22px;">Exámenes</h3>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th>Nombre del curso</th>
                    <th>Lección</th>
                    <th>Fecha</th>
                    <th>Nota</th>
                    <th>Estado</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($exam_rows)): ?>
                    <tr><td colspan="6">No hay exámenes registrados.</td></tr>
                <?php else: ?>
                    <?php foreach ($exam_rows as $row): ?>
                        <?php
                            $course = $row['course'];
                            $lesson = $row['lesson'];
                            $course_title = $course ? (string) $course->post_title : '-';
                            $lesson_title = $lesson ? (string) $lesson->post_title : '-';
                            $course_url = ($course && $course->ID) ? get_permalink($course->ID) : '';
                            $course_html = $course_url ? '<a href="' . esc_url($course_url) . '">' . esc_html($course_title) . '</a>' : esc_html($course_title);
                        ?>
                        <tr>
                            <td><?php echo $course_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
                            <td><?php echo esc_html($lesson_title); ?></td>
                            <td><?php echo esc_html($row['submitted']); ?></td>
                            <td><?php echo esc_html($row['grade_txt']); ?></td>
                            <td><?php echo esc_html(cl_get_exam_attempt_status_label($row['status'])); ?></td>
                            <td><?php echo $row['actions']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <h3 style="margin-top:22px;">Inscripciones pendientes</h3>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th>Nombre del curso</th>
                    <th>Fecha</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($pending_rows)): ?>
                    <tr><td colspan="3">No tienes inscripciones pendientes.</td></tr>
                <?php else: ?>
                    <?php foreach ($pending_rows as $row): ?>
                        <?php
                            $course = $row['course'];
                            $title = (string) $course->post_title;
                            $url = get_permalink($course->ID);
                            $course_html = $url ? '<a href="' . esc_url($url) . '">' . esc_html($title) . '</a>' : esc_html($title);
                        ?>
                        <tr>
                            <td><?php echo $course_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
                            <td><?php echo esc_html($row['date']); ?></td>
                            <td><?php echo $row['actions']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
    return ob_get_clean();
}

/* =====================================================
   ESTADO: CURSO INICIADO (user_meta)
===================================================== */
function cl_course_started_meta_key($curso_id) {
    return 'cl_curso_' . absint($curso_id) . '_started';
}

function cl_has_user_started_course($user_id, $curso_id) {
    $user_id = absint($user_id);
    $curso_id = absint($curso_id);
    if (!$user_id || !$curso_id) return false;
    $v = get_user_meta($user_id, cl_course_started_meta_key($curso_id), true);
    return (int)$v > 0;
}

function cl_mark_user_started_course($user_id, $curso_id) {
    $user_id = absint($user_id);
    $curso_id = absint($curso_id);
    if (!$user_id || !$curso_id) return false;
    if (cl_has_user_started_course($user_id, $curso_id)) return true;
    update_user_meta($user_id, cl_course_started_meta_key($curso_id), time());
    return true;
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

function cl_get_user_pending_enrollments($user_id) {
    $user_id = absint($user_id);
    if (!$user_id) return [];
    $ids = get_user_meta($user_id, CL_USER_META_PENDING_ENROLLMENTS, true);
    if (!is_array($ids)) $ids = [];
    $ids = array_values(array_unique(array_map('absint', $ids)));
    return array_values(array_filter($ids));
}

function cl_get_user_pending_enrollments_dates($user_id) {
    $user_id = absint($user_id);
    if (!$user_id) return [];
    $dates = get_user_meta($user_id, CL_USER_META_PENDING_ENROLLMENTS_DATES, true);
    if (!is_array($dates)) $dates = [];
    $out = [];
    foreach ($dates as $course_id => $ts) {
        $cid = absint($course_id);
        $t = absint($ts);
        if ($cid > 0 && $t > 0) {
            $out[$cid] = $t;
        }
    }
    return $out;
}

function cl_add_user_pending_enrollments($user_id, $course_ids) {
    $user_id = absint($user_id);
    if (!$user_id) return;
    $current = cl_get_user_pending_enrollments($user_id);
    $add = array_values(array_unique(array_filter(array_map('absint', (array)$course_ids))));
    $newly_added = array_values(array_diff($add, $current));
    $merged = array_values(array_unique(array_merge($current, $add)));
    update_user_meta($user_id, CL_USER_META_PENDING_ENROLLMENTS, $merged);

    $dates = cl_get_user_pending_enrollments_dates($user_id);
    $now = time();
    foreach ($newly_added as $cid) {
        if (!isset($dates[$cid])) $dates[$cid] = $now;
    }
    foreach ($merged as $cid) {
        if (!isset($dates[$cid])) $dates[$cid] = $now;
    }
    foreach (array_keys($dates) as $cid) {
        if (!in_array((int) $cid, $merged, true)) {
            unset($dates[$cid]);
        }
    }
    update_user_meta($user_id, CL_USER_META_PENDING_ENROLLMENTS_DATES, $dates);
}

function cl_remove_user_pending_enrollments($user_id, $course_ids) {
    $user_id = absint($user_id);
    if (!$user_id) return;
    $current = cl_get_user_pending_enrollments($user_id);
    $remove = array_values(array_unique(array_filter(array_map('absint', (array)$course_ids))));
    if (empty($remove)) return;
    $new = array_values(array_diff($current, $remove));
    update_user_meta($user_id, CL_USER_META_PENDING_ENROLLMENTS, $new);

    $dates = cl_get_user_pending_enrollments_dates($user_id);
    foreach ($remove as $cid) {
        unset($dates[$cid]);
    }
    foreach (array_keys($dates) as $cid) {
        if (!in_array((int) $cid, $new, true)) {
            unset($dates[$cid]);
        }
    }
    update_user_meta($user_id, CL_USER_META_PENDING_ENROLLMENTS_DATES, $dates);
}

function cl_is_course_pending_for_user($user_id, $curso_id) {
    $curso_id = absint($curso_id);
    if (!$curso_id) return false;
    return in_array($curso_id, cl_get_user_pending_enrollments($user_id), true);
}

function cl_delete_user_course_progress($user_id, $curso_id, $delete_history = true) {
    $user_id = absint($user_id);
    $curso_id = absint($curso_id);
    if (!$user_id || !$curso_id) return false;

    delete_user_meta($user_id, "cl_curso_{$curso_id}_completadas");
    delete_user_meta($user_id, "cl_curso_{$curso_id}_actual");
    delete_user_meta($user_id, "cl_curso_{$curso_id}_tiempos");
    delete_user_meta($user_id, "cl_curso_{$curso_id}_aprobado");
    delete_user_meta($user_id, cl_course_started_meta_key($curso_id));

    if (!empty($delete_history)) {
        global $wpdb;
        $table = cl_get_hist_table_name();
        $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE user_id = %d AND curso_id = %d", $user_id, $curso_id));
    }

    return true;
}

function cl_is_zero_progress_enrollment_summary($summary) {
    if (empty($summary['valid'])) return false;
    if (($summary['access_mode'] ?? '') !== 'inscripcion') return false;
    if (empty($summary['is_enrolled']) || !empty($summary['is_pending'])) return false;
    if (!empty($summary['started'])) return false;
    if ((int)($summary['completed_lessons'] ?? 0) !== 0) return false;
    if (!empty($summary['lesson_times'])) return false;
    if (!empty($summary['approved'])) return false;
    return true;
}

function cl_remove_user_course_enrollment($user_id, $curso_id, $clear_progress = true) {
    $user_id = absint($user_id);
    $curso_id = absint($curso_id);
    if (!$user_id || !$curso_id || get_post_type($curso_id) !== 'curso-cie') return false;
    if (cl_course_access_mode($curso_id) !== 'inscripcion') return false;

    $changed = false;
    $ids = cl_get_enrolled_user_ids($curso_id);
    if (in_array($user_id, $ids, true)) {
        $ids = array_values(array_diff($ids, [$user_id]));
        update_post_meta($curso_id, CL_META_ENROLLED_USERS, $ids);
        $changed = true;
    }

    if (cl_is_course_pending_for_user($user_id, $curso_id)) {
        cl_remove_user_pending_enrollments($user_id, [$curso_id]);
        $changed = true;
    }

    if (!empty($clear_progress)) {
        cl_delete_user_course_progress($user_id, $curso_id, true);
    }

    return $changed;
}

function cl_get_course_user_summary($user_id, $curso_id) {
    $user_id = absint($user_id);
    $curso_id = absint($curso_id);
    $summary = [
        'valid' => false,
        'course_id' => $curso_id,
        'access_mode' => 'libre',
        'is_enrolled' => false,
        'is_pending' => false,
        'started' => false,
        'total_lessons' => 0,
        'completed_lessons' => 0,
        'completed_lesson_ids' => [],
        'lesson_times' => [],
        'progress_percent' => 0,
        'has_exam' => false,
        'approved' => false,
        'all_lessons_done' => false,
        'is_completed' => false,
    ];
    if (!$user_id || !$curso_id || get_post_type($curso_id) !== 'curso-cie') return $summary;

    $summary['valid'] = true;
    $summary['access_mode'] = cl_course_access_mode($curso_id);
    $summary['is_enrolled'] = cl_is_user_enrolled_in_course($user_id, $curso_id);
    $summary['is_pending'] = ($summary['access_mode'] === 'inscripcion') ? cl_is_course_pending_for_user($user_id, $curso_id) : false;
    if ($summary['is_enrolled']) $summary['is_pending'] = false;
    $summary['started'] = cl_has_user_started_course($user_id, $curso_id);

    $lecciones = cl_get_lecciones_ordenadas($curso_id);
    $summary['total_lessons'] = count($lecciones);
    $lesson_ids = [];
    foreach ($lecciones as $leccion) {
        $lid = (int) $leccion->ID;
        $lesson_ids[] = $lid;
        if (!$summary['has_exam'] && cl_get_leccion_tipo($lid) === 'examen') {
            $summary['has_exam'] = true;
        }
    }

    $completadas = get_user_meta($user_id, "cl_curso_{$curso_id}_completadas", true);
    if (!is_array($completadas)) $completadas = [];
    $completadas = array_values(array_unique(array_filter(array_map('absint', $completadas))));
    if (!empty($lesson_ids)) {
        $completadas = array_values(array_intersect($completadas, $lesson_ids));
    } else {
        $completadas = [];
    }
    $summary['completed_lesson_ids'] = $completadas;

    $tiempos = get_user_meta($user_id, "cl_curso_{$curso_id}_tiempos", true);
    if (!is_array($tiempos)) $tiempos = [];
    $clean_times = [];
    foreach ($lesson_ids as $lid) {
        if (!isset($tiempos[$lid])) continue;
        $sec = max(0, (int) $tiempos[$lid]);
        if ($sec <= 0) continue;
        $clean_times[(int)$lid] = $sec;
    }
    $summary['lesson_times'] = $clean_times;

    $summary['completed_lessons'] = count($completadas);
    if ($summary['total_lessons'] > 0) {
        $summary['progress_percent'] = (int) round(($summary['completed_lessons'] / $summary['total_lessons']) * 100);
    }
    $summary['approved'] = ((int) get_user_meta($user_id, "cl_curso_{$curso_id}_aprobado", true)) === 1;
    if (
        empty($summary['started']) &&
        (
            $summary['completed_lessons'] > 0 ||
            !empty($summary['lesson_times']) ||
            !empty($summary['approved'])
        )
    ) {
        $summary['started'] = true;
    }
    $summary['all_lessons_done'] = $summary['total_lessons'] > 0 && $summary['completed_lessons'] >= $summary['total_lessons'];
    $summary['is_completed'] = $summary['all_lessons_done'] && (!$summary['has_exam'] || $summary['approved']);

    return $summary;
}

function cl_get_course_state_html_from_summary($summary) {
    if (empty($summary['valid'])) return '';
    if (!empty($summary['is_pending'])) {
        return "<span class='state-progress'>Pendiente de aprobación</span>";
    }
    if (($summary['access_mode'] ?? 'libre') === 'inscripcion' && empty($summary['is_enrolled'])) {
        return "<span class='state-no-init'>No inscrito</span>";
    }
    if ((int) ($summary['total_lessons'] ?? 0) === 0) {
        return 'Curso sin lecciones.';
    }
    if (empty($summary['started'])) {
        if (($summary['access_mode'] ?? 'libre') === 'inscripcion' && !empty($summary['is_enrolled'])) {
            return "<span class='state-progress'>Inscrito</span>";
        }
        return "<span class='state-no-init'>No iniciado</span>";
    }
    if ((int) ($summary['completed_lessons'] ?? 0) === 0) {
        return "<span class='state-progress'>Curso iniciado</span>";
    }
    if (empty($summary['all_lessons_done'])) {
        $percent = (int) ($summary['progress_percent'] ?? 0);
        return "<span class='state-progress'>En progreso ({$percent}%)</span>";
    }
    if (!empty($summary['has_exam']) && empty($summary['approved'])) {
        return "<span class='state-progress'>Pendiente de aprobación</span>";
    }
    if (!empty($summary['has_exam'])) {
        return "<span class='state-complete'>Completado (aprobado)</span>";
    }
    return "<span class='state-complete'>Completado</span>";
}

function cl_get_course_state_html($user_id, $curso_id) {
    $user_id = absint($user_id);
    if (!$user_id) return 'Debes iniciar sesión.';
    return cl_get_course_state_html_from_summary(cl_get_course_user_summary($user_id, $curso_id));
}

function cl_render_start_course_button_html($curso_id, $button_id = '') {
    $curso_id = absint($curso_id);
    if (!$curso_id) return '';
    $button_id = sanitize_key((string) $button_id);
    $button_id_attr = $button_id !== '' ? ' id="' . esc_attr($button_id) . '"' : '';

    ob_start();
    ?>
    <div class="cl-course-action">
        <button type="button" class="cl-btn cl-btn-start-course"<?php echo $button_id_attr; ?> data-curso="<?php echo esc_attr($curso_id); ?>">
            Comenzar curso
        </button>
        <div class="cl-start-msg" aria-live="polite" style="margin-top:10px;"></div>
    </div>
    <?php
    return ob_get_clean();
}

function cl_get_course_action_html_from_summary($summary, $curso_id, $args = []) {
    $curso_id = absint($curso_id);
    if (!$curso_id || empty($summary['valid'])) return '';

    $args = wp_parse_args((array)$args, [
        'show_enroll_button' => true,
        'show_pending_label' => true,
        'show_start_button' => false,
        'show_continue_link' => false,
        'start_button_id' => '',
    ]);

    if (!empty($summary['is_pending'])) {
        if (empty($args['show_pending_label'])) return '';
        return '<span class="cl-tag cl-tag-warn">Pendiente de aprobar inscripción</span>';
    }

    if (empty($summary['is_enrolled'])) {
        if (($summary['access_mode'] ?? 'libre') !== 'inscripcion' || empty($args['show_enroll_button'])) return '';
        $insc_url = home_url('/inscripcion-a-cursos/');
        return '<a class="cl-btn" href="' . esc_url($insc_url) . '">Inscribirse</a>';
    }

    if (!empty($summary['started'])) {
        if (empty($args['show_continue_link'])) return '';
        $course_url = get_permalink($curso_id);
        if (!$course_url) return '';

        if($summary['is_completed'] == 1) {
            return '<a class="cl-btn" href="' . esc_url($course_url) . '?show-lecciones=1">Ver curso</a>';
        }
        return '<a class="cl-btn" href="' . esc_url($course_url) . '?show-lecciones=1">Continuar curso</a>';
    }

    if (empty($args['show_start_button'])) return '';
    return cl_render_start_course_button_html($curso_id, (string)($args['start_button_id'] ?? ''));
}

function cl_get_course_action_html($user_id, $curso_id, $args = []) {
    $user_id = absint($user_id);
    if (!$user_id) return '';
    return cl_get_course_action_html_from_summary(cl_get_course_user_summary($user_id, $curso_id), $curso_id, (array)$args);
}

function cl_get_course_id_from_context($atts = []) {
    $atts = is_array($atts) ? $atts : [];
    $curso_id = isset($atts['course_id']) ? absint($atts['course_id']) : 0;
    if (!$curso_id && isset($atts['curso_id'])) {
        $curso_id = absint($atts['curso_id']);
    }
    if (!$curso_id && is_singular('curso-cie')) {
        $curso_id = (int) get_queried_object_id();
    }
    if (!$curso_id) {
        global $post;
        if ($post && $post->post_type === 'curso-cie') {
            $curso_id = (int) $post->ID;
        }
    }
    return ($curso_id && get_post_type($curso_id) === 'curso-cie') ? $curso_id : 0;
}

/* =====================================================
   AJAX: COMENZAR CURSO (marca "curso iniciado")
===================================================== */
add_action('wp_ajax_cl_comenzar_curso', function() {
    check_ajax_referer('cl_ajax_nonce', 'nonce');
    if (!is_user_logged_in()) wp_send_json_error(['message' => 'Debes iniciar sesión.'], 401);

    $user_id = get_current_user_id();
    $curso_id = isset($_POST['curso_id']) ? absint($_POST['curso_id']) : 0;
    if (!$curso_id || get_post_type($curso_id) !== 'curso-cie') {
        wp_send_json_error(['message' => 'Curso inválido.'], 400);
    }
    if (!cl_is_user_enrolled_in_course($user_id, $curso_id)) {
        wp_send_json_error(['message' => 'No tienes acceso a este curso.'], 403);
    }

    cl_mark_user_started_course($user_id, $curso_id);
    wp_send_json_success(['started' => 1]);
});

/* =====================================================
   CURSO (admin): metaboxes
===================================================== */
function cl_render_curso_lecciones_metabox($post) {
    wp_nonce_field('cl_curso_lecciones_save', 'cl_curso_lecciones_nonce');
    $lecciones = cl_get_lecciones_ordenadas($post->ID);
    $needs_save_first = empty($post->ID) || (isset($post->post_status) && $post->post_status === 'auto-draft');
    ?>
    <div class="cl-curso-lecciones-metabox" data-curso-id="<?php echo esc_attr((int)$post->ID); ?>">
        <?php if ($needs_save_first): ?>
            <p class="description"><strong>Primero guarda el curso</strong> (borrador) para poder añadir lecciones.</p>
        <?php endif; ?>
        <p style="margin:0 0 8px;">
            <label for="cl-nueva-leccion-titulo" style="display:block; font-weight:600; margin-bottom:6px;">Añadir nueva lección</label>
            <input type="text" id="cl-nueva-leccion-titulo" style="width:100%;" placeholder="Título de la lección" <?php echo $needs_save_first ? 'disabled' : ''; ?> />
        </p>
        <p style="margin:0 0 12px;">
            <button type="button" class="button button-primary" id="cl-btn-crear-leccion" <?php echo $needs_save_first ? 'disabled' : ''; ?>>Añadir lección</button>
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
        $html = '<video controls controlslist="nodownload noplaybackrate" disablepictureinpicture preload="metadata" style="max-width:100%; height:auto;">';
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
    $exam_max_grade = cl_get_exam_max_grade($post->ID);
    $show_correct_answers = get_post_meta($post->ID, '_cl_exam_show_correct_answers', true);
    if ($show_correct_answers === '') $show_correct_answers = '1'; // Por defecto mostrar
    $email_tpl_approved = cl_get_exam_email_template($post->ID, 'approved');
    $email_tpl_revoked = cl_get_exam_email_template($post->ID, 'revoked');

    wp_nonce_field('cl_leccion_examen_save', 'cl_leccion_examen_nonce');
    ?>
    <div class="cl-exam-metabox" data-tipo="<?php echo esc_attr($tipo); ?>">
        <p style="margin-top:0;">
            <label style="display:block; font-weight:600; margin-bottom:6px;">Tiempo máximo del examen (minutos)</label>
            <input type="number" min="0" step="1" name="cl_exam_time_minutes" value="<?php echo esc_attr($exam_time_minutes); ?>" style="width:120px;" />
            <span class="description">0 = sin límite.</span>
        </p>

        <p>
            <label style="display:block; font-weight:600; margin-bottom:6px;">Nota máxima del examen</label>
            <input type="number" min="0.1" step="0.1" name="cl_exam_max_grade" value="<?php echo esc_attr($exam_max_grade); ?>" style="width:120px;" />
            <span class="description">Por defecto: 10. La nota se calcula como (puntos_obtenidos / puntos_totales) × nota_máxima.</span>
        </p>

        <p>
            <label>
                <input type="checkbox" name="cl_exam_show_correct_answers" value="1" <?php checked($show_correct_answers, '1'); ?> />
                <strong>Mostrar respuestas correctas al estudiante</strong>
            </label>
            <span class="description" style="display:block; margin-left:22px;">Si está marcado, el estudiante podrá ver las respuestas correctas después de aprobar el examen.</span>
        </p>

        <hr />
        <h3 style="margin:0 0 10px;">Plantillas de email al alumno</h3>
        <p class="description" style="margin-top:0;">
            Variables disponibles: <code>{student_name}</code>, <code>{course_name}</code>, <code>{lesson_name}</code>, <code>{grade}</code>, <code>{max_grade}</code>, <code>{status}</code>, <code>{action_message}</code>, <code>{admin_note}</code>, <code>{course_url}</code>.
        </p>

        <p>
            <label style="display:block; font-weight:600; margin-bottom:6px;">Asunto (aprobado)</label>
            <input type="text" name="cl_exam_email_approved_subject" value="<?php echo esc_attr((string) ($email_tpl_approved['subject'] ?? '')); ?>" style="width:100%;" />
        </p>
        <p>
            <label style="display:block; font-weight:600; margin-bottom:6px;">Mensaje (aprobado)</label>
            <textarea name="cl_exam_email_approved_body" rows="6" style="width:100%;"><?php echo esc_textarea((string) ($email_tpl_approved['body'] ?? '')); ?></textarea>
        </p>

        <p>
            <label style="display:block; font-weight:600; margin-bottom:6px;">Asunto (suspendido/revocado)</label>
            <input type="text" name="cl_exam_email_revoked_subject" value="<?php echo esc_attr((string) ($email_tpl_revoked['subject'] ?? '')); ?>" style="width:100%;" />
        </p>
        <p>
            <label style="display:block; font-weight:600; margin-bottom:6px;">Mensaje (suspendido/revocado)</label>
            <textarea name="cl_exam_email_revoked_body" rows="6" style="width:100%;"><?php echo esc_textarea((string) ($email_tpl_revoked['body'] ?? '')); ?></textarea>
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
            Tipos: <strong>Single choice</strong> (una correcta), <strong>Multi choice</strong> (varias correctas) y <strong>Texto libre</strong> (respuesta abierta). Puedes añadir imagen por pregunta y asignar puntos por pregunta.
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

        // Guardar nota máxima (siempre)
        $max_grade_raw = isset($_POST['cl_exam_max_grade']) ? sanitize_text_field(wp_unslash($_POST['cl_exam_max_grade'])) : '10';
        $max_grade = is_numeric($max_grade_raw) ? (float) $max_grade_raw : 10.0;
        if (!is_finite($max_grade) || $max_grade <= 0) $max_grade = 10.0;
        update_post_meta($post_id, CL_META_EXAM_MAX_GRADE, $max_grade);

        // Guardar opción de mostrar respuestas correctas
        $show_correct_answers = isset($_POST['cl_exam_show_correct_answers']) ? '1' : '0';
        update_post_meta($post_id, '_cl_exam_show_correct_answers', $show_correct_answers);

        // Guardar plantillas de email del resultado (aprobado/suspendido)
        $approved_subject = isset($_POST['cl_exam_email_approved_subject']) ? sanitize_text_field(wp_unslash($_POST['cl_exam_email_approved_subject'])) : '';
        $approved_body = isset($_POST['cl_exam_email_approved_body']) ? sanitize_textarea_field(wp_unslash($_POST['cl_exam_email_approved_body'])) : '';
        $revoked_subject = isset($_POST['cl_exam_email_revoked_subject']) ? sanitize_text_field(wp_unslash($_POST['cl_exam_email_revoked_subject'])) : '';
        $revoked_body = isset($_POST['cl_exam_email_revoked_body']) ? sanitize_textarea_field(wp_unslash($_POST['cl_exam_email_revoked_body'])) : '';

        if ($approved_subject === '') delete_post_meta($post_id, CL_META_EXAM_EMAIL_APPROVED_SUBJECT);
        else update_post_meta($post_id, CL_META_EXAM_EMAIL_APPROVED_SUBJECT, $approved_subject);
        if ($approved_body === '') delete_post_meta($post_id, CL_META_EXAM_EMAIL_APPROVED_BODY);
        else update_post_meta($post_id, CL_META_EXAM_EMAIL_APPROVED_BODY, $approved_body);
        if ($revoked_subject === '') delete_post_meta($post_id, CL_META_EXAM_EMAIL_REVOKED_SUBJECT);
        else update_post_meta($post_id, CL_META_EXAM_EMAIL_REVOKED_SUBJECT, $revoked_subject);
        if ($revoked_body === '') delete_post_meta($post_id, CL_META_EXAM_EMAIL_REVOKED_BODY);
        else update_post_meta($post_id, CL_META_EXAM_EMAIL_REVOKED_BODY, $revoked_body);

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
        $type = isset($q['type']) && in_array($q['type'], ['single', 'multi', 'text'], true) ? $q['type'] : 'single';
        $image_id = isset($q['image_id']) ? absint($q['image_id']) : 0;
        // Si no se define (o viene vacío/0), usar 1 por defecto.
        $raw_points = isset($q['points']) ? $q['points'] : null;
        $points = ($raw_points === null || $raw_points === '') ? 1.0 : (float) $raw_points;
        if (!is_finite($points) || $points <= 0) $points = 1.0;

        if ($text === '') continue;

        if ($type === 'text') {
            $out['questions'][] = [
                'text' => $text,
                'type' => 'text',
                'points' => (float) $points,
                'image_id' => $image_id,
                'options' => [],
            ];
            continue;
        }

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

        if (count($options) < 2) continue;

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
            'points' => (float) $points,
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
            // Importante: asegurar wp.media disponible antes del script (selector de vídeo)
            ['jquery', 'media-editor', 'media-views'],
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
    if (!$curso_id) wp_send_json_error('Primero guarda el curso (borrador) para poder añadir lecciones.');
    if ($titulo === '') wp_send_json_error('Escribe un título');
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
   AL BORRAR / ENVIAR A PAPELERA UN CURSO: gestionar lecciones hijas
===================================================== */
function cl_get_course_child_lessons($curso_id) {
    return get_posts([
        'post_type' => 'lecciones-cie',
        'post_parent' => (int)$curso_id,
        'numberposts' => -1,
        'post_status' => 'any',
        'fields' => 'ids',
    ]);
}

add_action('wp_trash_post', function($post_id) {
    if (get_post_type($post_id) !== 'curso-cie') return;
    $children = cl_get_course_child_lessons($post_id);
    foreach ($children as $lid) {
        if (get_post_type($lid) === 'lecciones-cie') {
            wp_trash_post($lid);
        }
    }
});

add_action('untrash_post', function($post_id) {
    if (get_post_type($post_id) !== 'curso-cie') return;
    $children = cl_get_course_child_lessons($post_id);
    foreach ($children as $lid) {
        if (get_post_type($lid) === 'lecciones-cie') {
            wp_untrash_post($lid);
        }
    }
});

add_action('before_delete_post', function($post_id) {
    if (get_post_type($post_id) !== 'curso-cie') return;
    $children = cl_get_course_child_lessons($post_id);
    foreach ($children as $lid) {
        if (get_post_type($lid) === 'lecciones-cie') {
            wp_delete_post($lid, true);
        }
    }
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

    // Gate: el contenido del curso solo se muestra si el usuario ha iniciado el curso.
    $started = cl_has_user_started_course($user_id, $curso_id);
    if (!$started) {
        return '';
    }

    $lecciones = cl_get_lecciones_ordenadas($curso_id);
    if(empty($lecciones)) return '<div class="cl-no-access" style="border-left-color:#ffb900; background:#fffbea;">No hay lecciones todavía.</div>';

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

    $leccion_tipo = cl_get_leccion_tipo($leccion_actual->ID);
    // Importante: al filtrar contenido de lección/vídeo dentro del curso,
    // desactivamos el override de contenido del curso para evitar recursión.
    $prev_disable = cl_is_course_content_override_disabled();
    cl_disable_course_content_override(true);
    $contenido = apply_filters('the_content', $leccion_actual->post_content);
    cl_disable_course_content_override($prev_disable);
    $video = cl_render_leccion_video_frontend($leccion_actual->ID);
    // En exámenes no aplicamos tiempo mínimo de visualización (el gating es por examen).
    $tiempo_minimo_seg = ($leccion_tipo === 'examen') ? 0 : cl_get_leccion_min_time_seconds($leccion_actual->ID);

    // Tiempo guardado previamente (para no resetear al volver a entrar)
    $tiempos_guardados = get_user_meta($user_id, "cl_curso_{$curso_id}_tiempos", true);
    if(!is_array($tiempos_guardados)) $tiempos_guardados = [];
    $tiempo_guardado_actual = isset($tiempos_guardados[$leccion_actual->ID]) ? intval($tiempos_guardados[$leccion_actual->ID]) : 0;


    $ids = wp_list_pluck($lecciones,'ID');
    $index = array_search($leccion_actual->ID, $ids);

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

            <div class="cl-course-header">
                <a class="cl-course-close" href="<?php echo esc_url(home_url('/')); ?>" aria-label="Cerrar curso">×</a>
            </div>

            <div class="cl-barra-tiempo">
                <div class="cl-barra-llenado" style="width: <?php echo esc_attr($porcentaje_barra); ?>%"></div>
            </div>


            <h2><?php echo esc_html($leccion_actual->post_title); ?></h2>

            <?php if($video): ?>
                <div class="cl-video">
                    <?php
                        $prev_disable = cl_is_course_content_override_disabled();
                        cl_disable_course_content_override(true);
                        echo apply_filters('the_content', $video); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                        cl_disable_course_content_override($prev_disable);
                    ?>
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
                data-lesson-type="<?php echo esc_attr($leccion_tipo); ?>"
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
                        data-lesson-type="<?php echo esc_attr($leccion_tipo); ?>"
                        <?php echo ($should_disable_next_by_time || $disable_next_by_exam) ? 'disabled' : ''; ?>>
                        Siguiente lección →
                        </button>
                        <?php endif; ?>
            </div>

            <?php if(!empty($disable_next_by_exam)): ?>
                <div class="cl-exam-note">
                    <strong>ℹ️ Información:</strong> 
                    <?php if($next_leccion) { ?>
                    Para avanzar a la siguiente lección, primero debes completar el examen y esperar a que sea evaluado por el profesor.
                    <?php } else { ?>
                    Para terminar el curso, primero debes completar el examen y esperar a que sea evaluado por el profesor.       
                   <?php } ?>     
                </div>
            <?php endif; ?>

        </main>
    </div>
    <?php
    return ob_get_clean();
});

/* =====================================================
   SHORTCODE: BOTÓN "COMENZAR CURSO"
   - Disponible en singular y loops/listados
   - Gestiona estados de inscripción/libre acceso
===================================================== */
add_shortcode('cl_boton_comenzar_curso', function() {
    if (!is_user_logged_in()) return '';
    if (is_admin() || wp_doing_ajax()) return '';

    $curso_id = cl_get_course_id_from_context();
    if (!$curso_id || get_post_type($curso_id) !== 'curso-cie') return '';

    $user_id = get_current_user_id();
    return cl_get_course_action_html($user_id, $curso_id, [
        'show_enroll_button' => true,
        'show_pending_label' => true,
        'show_start_button' => true,
        'show_continue_link' => true, // Mantener comportamiento histórico: ocultar al iniciar.
        'start_button_id' => 'cl-btn-start-course',
    ]);
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

function cl_get_latest_finished_exam_attempt_for_user($user_id, $curso_id = 0) {
    $user_id = absint($user_id);
    $curso_id = absint($curso_id);
    if (!$user_id) return null;

    $meta_query = [
        [
            'key' => '_cl_user_id',
            'value' => (string) $user_id,
            'compare' => '=',
        ],
        [
            'key' => '_cl_status',
            'value' => ['approved', 'auto_approved'],
            'compare' => 'IN',
        ],
    ];
    if ($curso_id > 0) {
        $meta_query[] = [
            'key' => '_cl_course_id',
            'value' => (string) $curso_id,
            'compare' => '=',
        ];
    }

    $posts = get_posts([
        'post_type' => 'cl-exam-attempt',
        'numberposts' => 1,
        'orderby' => 'date',
        'order' => 'DESC',
        'meta_query' => $meta_query,
    ]);

    return !empty($posts) ? $posts[0] : null;
}

function cl_get_exam_attempt_grade_data($attempt_id) {
    $attempt_id = absint($attempt_id);
    if (!$attempt_id || get_post_type($attempt_id) !== 'cl-exam-attempt') return null;

    $final_grade = get_post_meta($attempt_id, '_cl_final_grade', true);
    $auto_grade = get_post_meta($attempt_id, '_cl_auto_grade', true);
    $grade = is_numeric($final_grade) ? (float) $final_grade : (is_numeric($auto_grade) ? (float) $auto_grade : null);

    $max_grade = get_post_meta($attempt_id, '_cl_max_grade', true);
    if (!is_numeric($max_grade) || (float) $max_grade <= 0) {
        $leccion_id = (int) get_post_meta($attempt_id, '_cl_lesson_id', true);
        $max_grade = cl_get_exam_max_grade($leccion_id);
    } else {
        $max_grade = (float) $max_grade;
    }

    return [
        'grade' => $grade,
        'max_grade' => (float) $max_grade,
    ];
}

function cl_format_grade_value($value) {
    if (!is_numeric($value)) return '';
    $formatted = number_format((float) $value, 2, '.', '');
    $formatted = rtrim(rtrim($formatted, '0'), '.');
    return $formatted === '' ? '0' : $formatted;
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
        $max_grade = cl_get_exam_max_grade($leccion_id);
        $final_grade = get_post_meta($attempt->ID, '_cl_final_grade', true);
        $final_grade = is_numeric($final_grade) ? round((float)$final_grade, 2) : '';

        $html = '<div class="cl-exam-state cl-exam-approved"><strong>Examen aprobado</strong>.';
        if ($final_grade !== '') $html .= ' Nota: <strong>' . esc_html($final_grade) . ' / ' . esc_html($max_grade) . '</strong>.';
        $html .= '</div>';
        $html .= cl_render_exam_results_readonly($leccion_id, $attempt);
        return $html;
    }

    if (!$can_take) {
        return '<div class="cl-exam-state cl-exam-locked"><strong>Examen bloqueado</strong>. Ya existe un intento enviado.</div>';
    }

    $time_limit = (int) get_post_meta($leccion_id, CL_META_EXAM_TIME_SECONDS, true);
    $q_total = is_array($def['questions']) ? count($def['questions']) : 0;

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

        <div class="cl-exam-progress" data-total="<?php echo esc_attr($q_total); ?>">
            <strong>Pregunta <span class="cl-exam-step-cur">1</span> de <span class="cl-exam-step-total"><?php echo esc_html($q_total); ?></span></strong>
        </div>

        <?php foreach ($def['questions'] as $qi => $q): ?>
            <div class="cl-exam-step" data-step="<?php echo esc_attr($qi); ?>" style="<?php echo $qi === 0 ? '' : 'display:none;'; ?>">
            <fieldset class="cl-exam-q">
                <legend>
                    <span class="cl-exam-qn"><?php echo esc_html($qi + 1); ?>.</span>
                    <span class="cl-exam-qt"><?php echo wp_kses_post($q['text']); ?></span>
                </legend>

                <?php if (!empty($q['image_id'])): ?>
                    <div class="cl-exam-qimg"><?php echo wp_get_attachment_image((int)$q['image_id'], 'large'); ?></div>
                <?php endif; ?>

                <?php
                    $qtype = (isset($q['type']) && in_array($q['type'], ['single', 'multi', 'text'], true)) ? $q['type'] : 'single';
                ?>

                <?php if ($qtype === 'text'): ?>
                    <div class="cl-exam-free">
                        <label class="cl-exam-free-label" style="display:block; font-weight:600; margin:10px 0 6px;">Tu respuesta</label>
                        <textarea name="answers[<?php echo esc_attr($qi); ?>]" rows="5" style="width:100%;"></textarea>
                    </div>
                <?php else: ?>
                    <?php
                        $type = $qtype === 'multi' ? 'multi' : 'single';
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
                <?php endif; ?>
            </fieldset>
            </div>
        <?php endforeach; ?>

        <div class="cl-exam-nav">
            <button type="button" class="cl-btn cl-exam-prev">Anterior</button>
            <button type="button" class="cl-btn cl-exam-next">Continuar</button>
            <button type="button" class="cl-btn cl-exam-review-btn">Revisar respuestas</button>
        </div>

        <div class="cl-exam-review" style="display:none;">
            <h3 style="margin-top:0;">Revisión</h3>
            <div class="cl-exam-review-list"></div>
            <div class="cl-exam-review-actions" style="margin-top:12px;">
                <button type="button" class="cl-btn cl-exam-back-to-questions">Volver a preguntas</button>
                <button type="submit" class="cl-btn cl-exam-submit">Enviar examen</button>
            </div>
        </div>

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

    $max_grade = cl_get_exam_max_grade($leccion_id);
    $final_grade = get_post_meta($attempt->ID, '_cl_final_grade', true);
    if (!is_numeric($final_grade)) $final_grade = get_post_meta($attempt->ID, '_cl_auto_grade', true);

    $final_breakdown = get_post_meta($attempt->ID, '_cl_final_breakdown', true);
    if (!is_array($final_breakdown)) {
        $final_breakdown = get_post_meta($attempt->ID, '_cl_auto_breakdown', true);
    }
    if (!is_array($final_breakdown)) $final_breakdown = [];
    $final_points = get_post_meta($attempt->ID, '_cl_final_points', true);
    if (!is_numeric($final_points)) $final_points = get_post_meta($attempt->ID, '_cl_auto_points', true);
    $total_points = get_post_meta($attempt->ID, '_cl_total_points', true);

    // Verificar si se deben mostrar las respuestas correctas
    $show_correct_answers = get_post_meta($leccion_id, '_cl_exam_show_correct_answers', true);
    if ($show_correct_answers === '') $show_correct_answers = '1'; // Por defecto mostrar

    ob_start();
    ?>
    <div class="cl-exam-results">
        <h3>Resultados</h3>
        <?php if (is_numeric($final_grade)): ?>
            <p class="cl-exam-grade-total"><strong>Nota:</strong> <?php echo esc_html(round((float)$final_grade, 2)); ?> / <?php echo esc_html($max_grade); ?></p>
        <?php endif; ?>
        <?php if (is_numeric($final_points) && is_numeric($total_points) && (float)$total_points > 0): ?>
            <p class="cl-exam-points-total"><strong>Puntos:</strong> <?php echo esc_html(round((float)$final_points, 2)); ?> / <?php echo esc_html(round((float)$total_points, 2)); ?></p>
        <?php endif; ?>
        <?php foreach ($def['questions'] as $qi => $q): ?>
            <div class="cl-exam-res-q">
                <div class="cl-exam-res-title"><?php echo esc_html($qi + 1); ?>. <?php echo wp_kses_post($q['text']); ?></div>
                <?php
                    $qtype = (isset($q['type']) && in_array($q['type'], ['single', 'multi', 'text'], true)) ? $q['type'] : 'single';
                    $max_points = isset($q['points']) ? (float)$q['points'] : 1.0;
                    $earned = isset($final_breakdown[$qi]['earned']) ? (float)$final_breakdown[$qi]['earned'] : null;
                ?>
                <?php if (is_numeric($earned)): ?>
                    <div class="cl-exam-qpoints"><strong>Puntos:</strong> <?php echo esc_html(round($earned, 2)); ?> / <?php echo esc_html(round($max_points, 2)); ?></div>
                <?php endif; ?>

                <?php if ($qtype === 'text'): ?>
                    <?php $txt = isset($answers[$qi]) ? (string)$answers[$qi] : ''; ?>
                    <div class="cl-exam-text-answer">
                        <div class="cl-exam-text-answer-label"><strong>Tu respuesta:</strong></div>
                        <div class="cl-exam-text-answer-body"><?php echo nl2br(esc_html($txt)); ?></div>
                    </div>
                <?php else: ?>
                    <?php
                        $selected = isset($answers[$qi]) ? (array)$answers[$qi] : [];
                        $selected = array_values(array_unique(array_map('strval', $selected)));
                        $correct = [];
                        if (isset($q['options']) && is_array($q['options'])) {
                            foreach ($q['options'] as $oi => $opt) {
                                if (!empty($opt['is_correct'])) $correct[] = (string)$oi;
                            }
                        }
                        $correct = array_values(array_unique($correct));
                    ?>
                    <ul class="cl-exam-res-opts">
                        <?php foreach ($q['options'] as $oi => $opt): ?>
                            <?php
                                $is_sel = in_array((string)$oi, $selected, true);
                                $is_cor = in_array((string)$oi, $correct, true);
                                $cls = 'cl-exam-res-opt';
                                
                                // Solo mostrar clases de correcto/incorrecto si la opción está habilitada
                                if ($show_correct_answers === '1') {
                                    if ($is_cor) $cls .= ' is-correct';
                                    if ($is_sel && !$is_cor) $cls .= ' is-wrong';
                                    if (!$is_sel && $is_cor) $cls .= ' is-missed';
                                }
                                
                                // Siempre mostrar qué respondió el estudiante
                                if ($is_sel) $cls .= ' is-selected';
                            ?>
                            <li class="<?php echo esc_attr($cls); ?>">
                                <?php echo wp_kses_post($opt['text']); ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
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

    // Validar sesión/tiempo si hay límite
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

    $def = get_post_meta($leccion_id, '_cl_exam_definition', true);
    if (!is_array($def) || empty($def['questions'])) {
        wp_send_json_error(['message' => 'Examen sin preguntas.'], 400);
    }

    $raw_answers = isset($_POST['answers']) ? $_POST['answers'] : [];
    $answers = cl_normalize_exam_answers($raw_answers, $def);
    $max_grade = cl_get_exam_max_grade($leccion_id);
    $auto = cl_calculate_exam_auto($answers, $def, $max_grade); // ['percent'=>0-100,'grade'=>0-max, ...]
    $auto_percent = (float) ($auto['percent'] ?? 0);
    $auto_grade = (float) ($auto['grade'] ?? 0);

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
    update_post_meta($attempt_id, '_cl_max_grade', (float) $max_grade);
    update_post_meta($attempt_id, '_cl_auto_score', $auto_percent); // compat (%)
    update_post_meta($attempt_id, '_cl_auto_grade', $auto_grade);
    update_post_meta($attempt_id, '_cl_auto_points', (float) ($auto['earned_points'] ?? 0));
    update_post_meta($attempt_id, '_cl_total_points', (float) ($auto['total_points'] ?? 0));
    update_post_meta($attempt_id, '_cl_auto_breakdown', $auto['breakdown'] ?? []);
    $autoeval = ((int) get_post_meta($curso_id, CL_META_COURSE_AUTOEVAL, true)) === 1;
    update_post_meta($attempt_id, '_cl_status', $autoeval ? 'approved' : 'pending_review');
    update_post_meta($attempt_id, '_cl_submitted_at', current_time('mysql'));
    if (!empty($started_at)) update_post_meta($attempt_id, '_cl_started_at', gmdate('Y-m-d H:i:s', (int)$started_at));
    if (!empty($duration)) update_post_meta($attempt_id, '_cl_duration_seconds', (int)$duration);

    if ($autoeval) {
        update_post_meta($attempt_id, '_cl_final_score', $auto_percent); // compat (%)
        update_post_meta($attempt_id, '_cl_final_grade', $auto_grade);
        update_post_meta($attempt_id, '_cl_final_points', (float) ($auto['earned_points'] ?? 0));
        update_post_meta($attempt_id, '_cl_final_breakdown', $auto['breakdown'] ?? []);
        cl_mark_course_approved($user_id, $curso_id, $leccion_id);
        cl_send_exam_result_email($attempt_id, 'approved', '');
    }

    cl_notify_admin_exam_submitted($attempt_id);

    wp_send_json_success(['attempt_id' => $attempt_id, 'auto_grade' => $auto_grade, 'max_grade' => $max_grade]);
});

function cl_normalize_exam_answers($raw, $def) {
    $answers = [];
    foreach ($def['questions'] as $qi => $q) {
        $type = (isset($q['type']) && in_array($q['type'], ['single', 'multi', 'text'], true)) ? $q['type'] : 'single';

        if ($type === 'text') {
            $v = isset($raw[$qi]) ? (string) $raw[$qi] : '';
            $v = trim(wp_strip_all_tags(wp_unslash($v)));
            $answers[$qi] = $v;
            continue;
        }

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

function cl_calculate_exam_auto($answers, $def, $max_grade = 10.0) {
    $breakdown = [];
    $total_points = 0.0;
    $earned_points = 0.0;
    $max_grade = is_numeric($max_grade) ? (float) $max_grade : 10.0;
    if (!is_finite($max_grade) || $max_grade <= 0) $max_grade = 10.0;

    foreach ($def['questions'] as $qi => $q) {
        $type = (isset($q['type']) && in_array($q['type'], ['single', 'multi', 'text'], true)) ? $q['type'] : 'single';
        $points = isset($q['points']) ? (float) $q['points'] : 1.0;
        if (!is_finite($points) || $points < 0) $points = 1.0;

        $earned = 0.0;
        $correct = [];
        $selected = null;
        $text_answer = '';
        $needs_manual = false;

        if ($type === 'text') {
            $needs_manual = true;
            $text_answer = isset($answers[$qi]) ? (string) $answers[$qi] : '';
            $earned = 0.0;
        } else {
            $selected_arr = isset($answers[$qi]) ? (array)$answers[$qi] : [];
            $selected_arr = array_values(array_unique(array_map('strval', $selected_arr)));
            sort($selected_arr);
            $selected = $selected_arr;

            $correct = [];
            if (isset($q['options']) && is_array($q['options'])) {
                foreach ($q['options'] as $oi => $opt) {
                    if (!empty($opt['is_correct'])) $correct[] = (string)$oi;
                }
            }
            sort($correct);
            $earned = ($selected_arr === $correct) ? $points : 0.0;
        }

        $total_points += $points;
        $earned_points += $earned;

        $breakdown[$qi] = [
            'type' => $type,
            'points' => (float) $points,
            'earned' => (float) $earned,
            'needs_manual' => $needs_manual ? 1 : 0,
            'selected' => $selected,
            'correct' => $correct,
            'text_answer' => $text_answer,
        ];
    }

    $percent = ($total_points > 0) ? round(($earned_points / $total_points) * 100, 2) : 0.0;
    $grade = ($total_points > 0) ? round(($earned_points / $total_points) * $max_grade, 2) : 0.0;
    return [
        'percent' => $percent,
        'grade' => $grade,
        'earned_points' => round($earned_points, 4),
        'total_points' => round($total_points, 4),
        'breakdown' => $breakdown,
    ];
}

function cl_calculate_exam_score($answers, $def) {
    // Backward compat: devolver % (0-100)
    $auto = cl_calculate_exam_auto($answers, $def, 10.0);
    return (float) ($auto['percent'] ?? 0);
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

    // TEMP (solo para pruebas): redirigir envíos a Gmail
    // TODO: eliminar en producción.
    if (strtolower(trim($to)) === 'esther.garcia@wembleystudios.com') {
        $to = 'esther.g.brena@gmail.com';
    }

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

    add_submenu_page(
        'edit.php?post_type=curso-cie',
        'Revisar solicitud de inscripción',
        'Revisar inscripción',
        'manage_options',
        'cl_review_enrollment_request',
        'cl_render_enrollment_review_admin_page'
    );
});

function cl_render_progreso_usuarios(){
    if (!current_user_can('manage_options')) return;

    $notice_html = '';
    if (!empty($_POST['cl_borrar_usuario']) && !empty($_POST['cl_borrar_curso'])) {
        $uid = absint($_POST['cl_borrar_usuario']);
        $cid = absint($_POST['cl_borrar_curso']);
        if (
            $uid > 0 &&
            $cid > 0 &&
            isset($_POST['cl_progress_nonce']) &&
            wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['cl_progress_nonce'])), 'cl_delete_progress_' . $uid . '_' . $cid)
        ) {
            cl_delete_user_course_progress($uid, $cid, true);
            $notice_html = '<div class="notice notice-success"><p>Progreso borrado correctamente.</p></div>';
        }
    }

    if (!empty($_POST['cl_borrar_inscripcion_usuario']) && !empty($_POST['cl_borrar_inscripcion_curso'])) {
        $uid = absint($_POST['cl_borrar_inscripcion_usuario']);
        $cid = absint($_POST['cl_borrar_inscripcion_curso']);
        if (
            $uid > 0 &&
            $cid > 0 &&
            isset($_POST['cl_enrollment_nonce']) &&
            wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['cl_enrollment_nonce'])), 'cl_delete_enrollment_' . $uid . '_' . $cid)
        ) {
            $summary = cl_get_course_user_summary($uid, $cid);
            if (!cl_is_zero_progress_enrollment_summary($summary)) {
                $notice_html = '<div class="notice notice-warning"><p>Solo se puede borrar la inscripción cuando el estado sea “Inscrito” y el progreso sea 0.</p></div>';
            } elseif (cl_remove_user_course_enrollment($uid, $cid, true)) {
                $notice_html = '<div class="notice notice-success"><p>Inscripción eliminada correctamente.</p></div>';
            } else {
                $notice_html = '<div class="notice notice-warning"><p>No se pudo eliminar la inscripción.</p></div>';
            }
        }
    }

    $cursos = get_posts(['post_type'=>'curso-cie','numberposts'=>-1]);
    $usuarios_base = get_users(['role__in'=>['cie_new_user','cie_user']]);
    $usuarios_base_map = [];
    foreach ($usuarios_base as $u) {
        $usuarios_base_map[$u->ID] = $u;
    }

    echo '<div class="wrap"><h1>Progreso de usuarios</h1>';
    echo $notice_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

    foreach($cursos as $curso){
        $lecciones = cl_get_lecciones_ordenadas($curso->ID);
        $usuarios_map = $usuarios_base_map;
        foreach (cl_get_enrolled_user_ids($curso->ID) as $eid) {
            if (isset($usuarios_map[$eid])) continue;
            $u = get_user_by('id', $eid);
            if ($u) $usuarios_map[$eid] = $u;
        }

        echo '<h2>'.esc_html($curso->post_title).'</h2>';
        echo '<table class="widefat striped"><thead><tr>
                <th>Usuario</th><th>Estado</th><th>Lecciones completadas</th><th>Tiempo por lección</th><th>Acciones</th>
              </tr></thead><tbody>';

        foreach($usuarios_map as $user){
            $summary = cl_get_course_user_summary($user->ID, $curso->ID);
            $is_enrolled_inscripcion = (($summary['access_mode'] ?? '') === 'inscripcion') && !empty($summary['is_enrolled']);
            $has_activity = !empty($summary['started'])
                || (int)($summary['completed_lessons'] ?? 0) > 0
                || !empty($summary['approved'])
                || !empty($summary['lesson_times'])
                || $is_enrolled_inscripcion;
            if (!$has_activity) continue;

            $completadas = array_map('absint', (array)($summary['completed_lesson_ids'] ?? []));
            $tiempos = (array)($summary['lesson_times'] ?? []);
            $lecciones_text=[];
            foreach($lecciones as $l){
                $estado = in_array($l->ID,$completadas)?'<span class="state-complete">Completada</span>':'<span class="state-progress">En progreso</span>';
                $tiempo = isset($tiempos[$l->ID]) ? gmdate("H:i:s", max(0, (int)$tiempos[$l->ID])) : '-';
                $lecciones_text[] = $estado . ' (' . esc_html($tiempo) . ') ' . esc_html($l->post_title);
            }

            $estado_curso_html = cl_get_course_state_html_from_summary($summary);

            echo '<tr>';
            echo '<td>'.esc_html($user->display_name).' ('.esc_html($user->user_login).')</td>';
            echo '<td>'.$estado_curso_html.'</td>';
            echo '<td>'.esc_html((int)($summary['completed_lessons'] ?? 0).'/'.(int)($summary['total_lessons'] ?? 0)).'</td>';
            echo '<td>'.implode('<br>',$lecciones_text).'</td>';
            $acciones = [];
            $acciones[] = '<form method="post" style="display:inline">
                    <input type="hidden" name="cl_borrar_usuario" value="'.esc_attr($user->ID).'">
                    <input type="hidden" name="cl_borrar_curso" value="'.esc_attr($curso->ID).'">
                    '.wp_nonce_field('cl_delete_progress_' . absint($user->ID) . '_' . absint($curso->ID), 'cl_progress_nonce', true, false).'
                    <button type="submit" class="button button-secondary" onclick="return confirm(\'¿Seguro que quieres borrar el progreso?\')">Borrar progreso</button>
                </form>';

            if (cl_is_zero_progress_enrollment_summary($summary)) {
                $acciones[] = '<form method="post" style="display:inline; margin-left:6px;">
                        <input type="hidden" name="cl_borrar_inscripcion_usuario" value="'.esc_attr($user->ID).'">
                        <input type="hidden" name="cl_borrar_inscripcion_curso" value="'.esc_attr($curso->ID).'">
                        '.wp_nonce_field('cl_delete_enrollment_' . absint($user->ID) . '_' . absint($curso->ID), 'cl_enrollment_nonce', true, false).'
                        <button type="submit" class="button button-secondary" onclick="return confirm(\'¿Seguro que quieres borrar la inscripción completa de este curso?\')">Borrar inscripción</button>
                    </form>';
            }

            echo '<td>' . implode(' ', $acciones) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table><br>';
    }

    echo '</div>';
}

/* =====================================================
   ADMIN: EXÁMENES (revisión / aprobación / revocación)
===================================================== */
function cl_render_examenes_admin() {
    if (!current_user_can('manage_options')) return;

    $notices = [];

    // Procesar eliminación individual
    if (!empty($_GET['cl_delete_attempt']) && !empty($_GET['_wpnonce'])) {
        $delete_id = absint($_GET['cl_delete_attempt']);
        $nonce = sanitize_text_field(wp_unslash($_GET['_wpnonce']));
        if ($delete_id > 0 && wp_verify_nonce($nonce, 'cl_delete_attempt') && get_post_type($delete_id) === 'cl-exam-attempt') {
            wp_delete_post($delete_id, true); // true = borrar definitivamente
            $notices[] = ['type' => 'success', 'text' => 'Examen eliminado correctamente.'];
        }
    }

    // Procesar eliminación en lote
    if (
        (!empty($_POST['cl_bulk_action']) || !empty($_POST['cl_bulk_action_bottom'])) &&
        isset($_POST['cl_exams_bulk_nonce']) &&
        wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['cl_exams_bulk_nonce'])), 'cl_exams_bulk_action')
    ) {
        $bulk_action = sanitize_text_field(wp_unslash($_POST['cl_bulk_action']));
        if ($bulk_action === '' && !empty($_POST['cl_bulk_action_bottom'])) {
            $bulk_action = sanitize_text_field(wp_unslash($_POST['cl_bulk_action_bottom']));
        }
        $ids = isset($_POST['cl_attempt_ids']) ? (array) $_POST['cl_attempt_ids'] : [];
        $ids = array_values(array_unique(array_filter(array_map('absint', $ids))));

        if ($bulk_action === 'delete') {
            if (empty($ids)) {
                $notices[] = ['type' => 'warning', 'text' => 'Selecciona al menos un examen para eliminar.'];
            } else {
                $deleted = 0;
                foreach ($ids as $id) {
                    if (get_post_type($id) !== 'cl-exam-attempt') continue;
                    if (wp_delete_post($id, true)) $deleted++;
                }
                $notices[] = ['type' => 'success', 'text' => sprintf('Se han eliminado %d exámenes.', (int)$deleted)];
            }
        }
    }

    $attempt_id = isset($_GET['attempt']) ? absint($_GET['attempt']) : 0;

    echo '<div class="wrap"><h1>Exámenes</h1>';
    foreach ($notices as $n) {
        $class = $n['type'] === 'success' ? 'notice notice-success' : 'notice notice-warning';
        echo '<div class="' . esc_attr($class) . '"><p>' . esc_html($n['text']) . '</p></div>';
    }

    if ($attempt_id) {
        cl_render_examen_admin_detail($attempt_id);
        echo '</div>';
        return;
    }

    $status = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : 'pending_review';
    $allowed = ['pending_review', 'approved', 'retry_required', 'revoked_reset_course'];
    if (!in_array($status, $allowed, true)) $status = 'pending_review';

    echo '<p>';
    $total = count($allowed);
    $index = 0;
    foreach ($allowed as $st) {
        $index++;
        $url = admin_url('admin.php?page=cl_examenes&status=' . urlencode($st));
        $label = cl_get_exam_attempt_status_label($st);
        $active = $st === $status ? ' style="font-weight:bold;"' : '';
        echo '<a href="' . esc_url($url) . '"' . $active . '>' . esc_html($label) . '</a>';
        if ($index < $total) {
         echo "&ensp;|&ensp;";
        }
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

    echo '<form method="post">';
    wp_nonce_field('cl_exams_bulk_action', 'cl_exams_bulk_nonce');
    echo '<div class="tablenav top"><div class="alignleft actions">';
    echo '<select name="cl_bulk_action">';
    echo '<option value="">Acciones en lote</option>';
    echo '<option value="delete">Eliminar</option>';
    echo '</select> ';
    echo '<button type="submit" class="button action">Aplicar</button>';
    echo '</div></div>';

    echo '<table class="widefat striped"><thead><tr>';
    echo '<td class="manage-column column-cb check-column"><input type="checkbox" id="cl-select-all-attempts" /></td>';
    echo '<th>Alumno</th><th>Curso</th><th>Lección</th><th>Fecha</th><th>Nota</th><th>Estado</th><th>Acción</th>';
    echo '</tr></thead><tbody>';

    if (empty($attempts)) {
        echo '<tr><td colspan="8">No hay exámenes para este filtro.</td></tr>';
    } else {
        foreach ($attempts as $a) {
            $curso_id = (int)get_post_meta($a->ID, '_cl_course_id', true);
            $leccion_id = (int)get_post_meta($a->ID, '_cl_lesson_id', true);
            $user_id = (int)get_post_meta($a->ID, '_cl_user_id', true);
            $submitted = (string)get_post_meta($a->ID, '_cl_submitted_at', true);
            $final_grade = get_post_meta($a->ID, '_cl_final_grade', true);
            $auto_grade = get_post_meta($a->ID, '_cl_auto_grade', true);
            $grade = is_numeric($final_grade) ? $final_grade : $auto_grade;
            $max_grade = get_post_meta($a->ID, '_cl_max_grade', true);
            if (!is_numeric($max_grade) || (float)$max_grade <= 0) $max_grade = 10;
            $st = (string)get_post_meta($a->ID, '_cl_status', true);

            $user = get_user_by('id', $user_id);
            $curso = get_post($curso_id);
            $leccion = get_post($leccion_id);

            $url = admin_url('admin.php?page=cl_examenes&attempt=' . absint($a->ID));
            echo '<tr>';
            echo '<th scope="row" class="check-column"><input type="checkbox" class="cl-attempt-checkbox" name="cl_attempt_ids[]" value="' . esc_attr($a->ID) . '" /></th>';
            echo '<td>' . esc_html($user ? $user->display_name : ('Usuario ' . $user_id)) . '</td>';
            echo '<td>' . esc_html($curso ? $curso->post_title : $curso_id) . '</td>';
            echo '<td>' . esc_html($leccion ? $leccion->post_title : $leccion_id) . '</td>';
            echo '<td>' . esc_html($submitted ?: get_the_date('Y-m-d H:i', $a)) . '</td>';
            echo '<td>' . esc_html(is_numeric($grade) ? (round((float)$grade, 2) . ' / ' . round((float)$max_grade, 2)) : '-') . '</td>';
            echo '<td>' . esc_html(cl_get_exam_attempt_status_label($st)) . '</td>';

            $delete_url = wp_nonce_url(
                admin_url('admin.php?page=cl_examenes&status=' . urlencode($status) . '&cl_delete_attempt=' . absint($a->ID)),
                'cl_delete_attempt'
            );

            echo '<td>';
            echo '<a class="button button-primary" href="' . esc_url($url) . '">Revisar</a> ';
            echo '<a class="button button-secondary" href="' . esc_url($delete_url) . '" onclick="return confirm(\'¿Seguro que quieres eliminar este examen?\')">Eliminar</a>';
            echo '</td>';
            echo '</tr>';
        }
    }
    echo '</tbody></table>';
    echo '<div class="tablenav bottom"><div class="alignleft actions">';
    echo '<select name="cl_bulk_action_bottom">';
    echo '<option value="">Acciones en lote</option>';
    echo '<option value="delete">Eliminar</option>';
    echo '</select> ';
    echo '<button type="button" class="button action" id="cl-apply-bottom-bulk">Aplicar</button>';
    echo '</div></div>';
    echo '</form>';
    ?>
    <script>
        (function(){
            var selectAll = document.getElementById('cl-select-all-attempts');
            var checkboxes = document.querySelectorAll('.cl-attempt-checkbox');
            if (selectAll) {
                selectAll.addEventListener('change', function () {
                    checkboxes.forEach(function (cb) { cb.checked = !!selectAll.checked; });
                });
            }
            var bottomApply = document.getElementById('cl-apply-bottom-bulk');
            if (bottomApply) {
                bottomApply.addEventListener('click', function () {
                    var bottomSelect = document.querySelector('select[name="cl_bulk_action_bottom"]');
                    var topSelect = document.querySelector('select[name="cl_bulk_action"]');
                    if (topSelect && bottomSelect) {
                        topSelect.value = bottomSelect.value;
                        topSelect.form.submit();
                    }
                });
            }
        })();
    </script>
    <?php
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
    $auto_grade = get_post_meta($attempt_id, '_cl_auto_grade', true);
    $final_grade = get_post_meta($attempt_id, '_cl_final_grade', true);
    $answers = get_post_meta($attempt_id, '_cl_answers', true);
    if (!is_array($answers)) $answers = [];

    $curso = get_post($curso_id);
    $leccion = get_post($leccion_id);
    $user = get_user_by('id', $user_id);

    $def = get_post_meta($leccion_id, '_cl_exam_definition', true);
    if (!is_array($def) || empty($def['questions'])) {
        $def = ['questions' => []];
    }

    // Breakdown auto (para precargar puntuación por pregunta)
    $auto_breakdown = get_post_meta($attempt_id, '_cl_auto_breakdown', true);
    if (!is_array($auto_breakdown)) {
        $auto_calc = cl_calculate_exam_auto($answers, $def, cl_get_exam_max_grade($leccion_id));
        $auto_breakdown = is_array($auto_calc['breakdown'] ?? null) ? $auto_calc['breakdown'] : [];
    }

    // Procesar acciones
    if (!empty($_POST['cl_exam_action']) && check_admin_referer('cl_exam_review_' . $attempt_id)) {
        $action = sanitize_text_field($_POST['cl_exam_action']);
        $note = isset($_POST['cl_admin_note']) ? wp_kses_post(wp_unslash($_POST['cl_admin_note'])) : '';

        if ($action === 'approve') {
            // Puntuación por pregunta (si viene), con fallback a auto
            $posted_scores = isset($_POST['cl_q_score']) && is_array($_POST['cl_q_score']) ? $_POST['cl_q_score'] : [];
            $final_breakdown = [];
            $total_points = 0.0;
            $final_points = 0.0;

            foreach ($def['questions'] as $qi => $q) {
                $points = isset($q['points']) ? (float)$q['points'] : 1.0;
                if (!is_finite($points) || $points < 0) $points = 1.0;
                $total_points += $points;

                $auto_earned = isset($auto_breakdown[$qi]['earned']) ? (float)$auto_breakdown[$qi]['earned'] : 0.0;
                $earned = $auto_earned;
                if (isset($posted_scores[$qi])) {
                    $earned = (float) str_replace(',', '.', (string) $posted_scores[$qi]);
                }
                if (!is_finite($earned)) $earned = $auto_earned;
                $earned = max(0.0, min($points, $earned));
                $final_points += $earned;

                $final_breakdown[$qi] = is_array($auto_breakdown[$qi] ?? null) ? $auto_breakdown[$qi] : [];
                $final_breakdown[$qi]['points'] = (float)$points;
                $final_breakdown[$qi]['earned'] = (float)$earned;
            }

            $max_grade = cl_get_exam_max_grade($leccion_id);
            $final_score = ($total_points > 0) ? round(($final_points / $total_points) * 100, 2) : 0.0; // compat (%)
            $final_grade_calc = ($total_points > 0) ? round(($final_points / $total_points) * $max_grade, 2) : 0.0;
            update_post_meta($attempt_id, '_cl_status', 'approved');
            update_post_meta($attempt_id, '_cl_final_score', $final_score);
            update_post_meta($attempt_id, '_cl_final_grade', $final_grade_calc);
            update_post_meta($attempt_id, '_cl_final_points', round($final_points, 4));
            update_post_meta($attempt_id, '_cl_total_points', round($total_points, 4));
            update_post_meta($attempt_id, '_cl_final_breakdown', $final_breakdown);
            update_post_meta($attempt_id, '_cl_admin_note', $note);
            update_post_meta($attempt_id, '_cl_reviewed_by', get_current_user_id());
            update_post_meta($attempt_id, '_cl_reviewed_at', current_time('mysql'));
            cl_mark_course_approved($user_id, $curso_id, $leccion_id);
            cl_send_exam_result_email($attempt_id, 'approved', $note);

            // Cambiar rol si es cie_new_user
            $user_obj = get_user_by('id', $user_id);

            if ($user_obj && in_array('cie_new_user', (array) $user_obj->roles, true)) {
                $user_obj->remove_role('cie_new_user');
                $user_obj->add_role('cie_user');
            }

            echo '<div class="notice notice-success"><p>Examen aprobado y curso marcado como completado/aprobado.</p></div>';
            $status = 'approved';
        } elseif ($action === 'revoke_retry') {
            update_post_meta($attempt_id, '_cl_status', 'retry_required');
            update_post_meta($attempt_id, '_cl_admin_note', $note);
            update_post_meta($attempt_id, '_cl_reviewed_by', get_current_user_id());
            update_post_meta($attempt_id, '_cl_reviewed_at', current_time('mysql'));
            cl_revoke_exam_for_user($user_id, $curso_id, $leccion_id, false, $attempt_id, $note);
            echo '<div class="notice notice-warning"><p>Examen revocado. El alumno deberá repetir el examen.</p></div>';
            $status = 'retry_required';
        } elseif ($action === 'revoke_reset') {
            update_post_meta($attempt_id, '_cl_status', 'revoked_reset_course');
            update_post_meta($attempt_id, '_cl_admin_note', $note);
            update_post_meta($attempt_id, '_cl_reviewed_by', get_current_user_id());
            update_post_meta($attempt_id, '_cl_reviewed_at', current_time('mysql'));
            cl_revoke_exam_for_user($user_id, $curso_id, $leccion_id, true, $attempt_id, $note);
            echo '<div class="notice notice-warning"><p>Examen revocado y curso reiniciado. El alumno deberá repetir el curso desde cero.</p></div>';
            $status = 'revoked_reset_course';
        }
    }

    echo '<p><a href="' . esc_url(admin_url('admin.php?page=cl_examenes')) . '">← Volver a la lista</a></p>';
    echo '<h2>Detalle del examen</h2>';
    echo '<p><strong>Alumno:</strong> ' . esc_html($user ? $user->display_name : ('Usuario ' . $user_id)) . '</p>';
    echo '<p><strong>Curso:</strong> ' . esc_html($curso ? $curso->post_title : $curso_id) . '</p>';
    echo '<p><strong>Lección:</strong> ' . esc_html($leccion ? $leccion->post_title : $leccion_id) . '</p>';
    echo '<p><strong>Estado:</strong> ' . esc_html($status) . '</p>';
    $max_grade = cl_get_exam_max_grade($leccion_id);
    $grade = is_numeric($final_grade) ? $final_grade : $auto_grade;
    echo '<div class="cl-bloque-nota"><p class="cl-nota-final"><strong>Nota:</strong> ' . esc_html(is_numeric($grade) ? (round((float)$grade, 2) . ' / ' . $max_grade) : '-') . '</p></div>';

    echo '<h3>Respuestas del alumno</h3>';
    echo '<div class="cl-exam-admin-review">';
    foreach ($def['questions'] as $qi => $q) {
        echo '<div class="cl-exam-admin-q">';
        echo '<div><strong>' . esc_html($qi + 1) . '.</strong> ' . wp_kses_post($q['text']) . '</div>';
        if (!empty($q['image_id'])) {
            echo '<div style="margin:8px 0;">' . wp_get_attachment_image((int)$q['image_id'], 'medium') . '</div>';
        }
        $qtype = (isset($q['type']) && in_array($q['type'], ['single', 'multi', 'text'], true)) ? $q['type'] : 'single';
        $points = isset($q['points']) ? (float)$q['points'] : 1.0;
        if (!is_finite($points) || $points < 0) $points = 1.0;

        $auto_earned = isset($auto_breakdown[$qi]['earned']) ? (float)$auto_breakdown[$qi]['earned'] : 0.0;

        echo '<div class="cl-exam-admin-points">';
        echo '<span class="cl-exam-admin-points-max"><strong>Puntos:</strong> ' . esc_html(round($auto_earned, 2)) . ' / ' . esc_html(round($points, 2)) . ' (auto)</span>';
        echo '</div>';

        if ($qtype === 'text') {
            $txt = isset($answers[$qi]) ? (string)$answers[$qi] : '';
            echo '<div class="cl-exam-admin-text">';
            echo '<div><strong>Respuesta del alumno:</strong></div>';
            echo '<div class="cl-exam-admin-text-body">' . nl2br(esc_html($txt)) . '</div>';
            echo '</div>';
        } else {
            $sel = isset($answers[$qi]) ? (array)$answers[$qi] : [];
            $sel = array_values(array_unique(array_map('strval', $sel)));
            $cor = [];
            if (isset($q['options']) && is_array($q['options'])) {
                foreach ($q['options'] as $oi => $opt) {
                    if (!empty($opt['is_correct'])) $cor[] = (string)$oi;
                }
            }
            $cor = array_values(array_unique($cor));
            echo '<ul class="cl-exam-admin-opts">';
            foreach ($q['options'] as $oi => $opt) {
                $is_sel = in_array((string)$oi, $sel, true);
                $is_cor = in_array((string)$oi, $cor, true);
                $cls = 'cl-exam-admin-opt';
                if ($is_cor) $cls .= ' is-correct';
                if ($is_sel) $cls .= ' is-selected';
                if ($is_sel && !$is_cor) $cls .= ' is-wrong';
                if (!$is_sel && $is_cor) $cls .= ' is-missed';
                echo '<li class="' . esc_attr($cls) . '">' . wp_kses_post($opt['text']) . '</li>';
            }
            echo '</ul>';
        }
        echo '</div>';
    }
    echo '</div>';

    echo '<h3>Acciones</h3>';
    echo '<form method="post">';
    wp_nonce_field('cl_exam_review_' . $attempt_id);
    echo '<p class="description">Puedes ajustar la puntuación por pregunta. La nota final se recalcula automáticamente al aprobar.</p>';
    echo '<div class="cl-exam-admin-score-grid">';
    foreach ($def['questions'] as $qi => $q) {
        $points = isset($q['points']) ? (float)$q['points'] : 1.0;
        if (!is_finite($points) || $points < 0) $points = 1.0;
        $auto_earned = isset($auto_breakdown[$qi]['earned']) ? (float)$auto_breakdown[$qi]['earned'] : 0.0;
        echo '<div class="cl-exam-admin-score-row">';
        echo '<div class="cl-exam-admin-score-label"><strong>' . esc_html($qi + 1) . '.</strong> ' . esc_html(wp_strip_all_tags($q['text'])) . '</div>';
        echo '<div class="cl-exam-admin-score-input">';
        echo '<label>Puntos obtenidos ';
        echo '<input type="number" step="0.25" min="0" max="' . esc_attr($points) . '" name="cl_q_score[' . esc_attr($qi) . ']" value="' . esc_attr($auto_earned) . '" style="width:110px;" />';
        echo ' / ' . esc_html(round($points, 2)) . '</label>';
        echo '</div>';
        echo '</div>';
    }
    echo '</div>';

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

function cl_revoke_exam_for_user($user_id, $curso_id, $leccion_id, $reset_course, $attempt_id = 0, $admin_note = '') {
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

    $attempt_id = absint($attempt_id);
    if ($attempt_id > 0 && cl_send_exam_result_email($attempt_id, 'revoked', $admin_note)) {
        return;
    }

    // Fallback legacy si no hay intento válido (compatibilidad).
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
    $curso_id = cl_get_course_id_from_context((array)$atts);
    if (!$curso_id) return '';
    return cl_get_course_state_html(get_current_user_id(), $curso_id);

});

/* =====================================================
   SHORTCODE: ESTADÍSTICAS DE CURSOS DEL USUARIO
   Muestra el número de cursos inscritos y completados
   Uso: [cl_cursos_stats]
===================================================== */
add_shortcode('cl_cursos_stats', function($atts) {
    if (!is_user_logged_in()) {
        return '';
    }

    $user_id = get_current_user_id();
    
    // Obtener todos los cursos
    $all_courses = get_posts([
        'post_type' => 'curso-cie',
        'posts_per_page' => -1,
        'post_status' => 'publish',
    ]);

    $enrolled_count = 0;
    $completed_count = 0;

    foreach ($all_courses as $course) {
        $summary = cl_get_course_user_summary($user_id, $course->ID);
        if (!empty($summary['is_enrolled'])) {
            $enrolled_count++;
            if (!empty($summary['is_completed'])) {
                $completed_count++;
            }
        }
    }

    $atts = shortcode_atts([
        'format' => 'full', // full, enrolled, completed
    ], (array)$atts, 'cl_cursos_stats');

    $format = isset($atts['format']) ? $atts['format'] : 'full';

    ob_start();
    ?>
    <div class="cl-cursos-stats">
        <?php if ($format === 'full' || $format === 'enrolled'): ?>
            <div class="cl-stat-item">
                <span class="cl-stat-label">Cursos inscritos</span><br/>
                <span class="cl-stat-value"><?php echo esc_html($enrolled_count); ?></span>
            </div>
        <?php endif; ?>
        <?php if ($format === 'full' || $format === 'completed'): ?>
            <div class="cl-stat-item">
                <span class="cl-stat-label">Cursos completados</span><br/>
                <span class="cl-stat-value"><?php echo esc_html($completed_count); ?></span>
            </div>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
});

function cl_render_course_cards_inline($courses, $summary_map, $show_actions = false) {
    if (empty($courses)) {
        return '<p class="cl-inline-tabs-empty">No hay cursos para mostrar.</p>';
    }

    ob_start();
    ?>
    <div class="cl-inline-course-list">
        <?php foreach ($courses as $course): ?>
            <?php
                $cid = (int) $course->ID;
                $summary = $summary_map[$cid] ?? null;
                if (!is_array($summary)) $summary = cl_get_course_user_summary(get_current_user_id(), $cid);
                $estado = cl_get_course_state_html_from_summary($summary);
                $accion = '';
                if ($show_actions) {
                    $accion = cl_get_course_action_html_from_summary($summary, $cid, [
                        'show_enroll_button' => true,
                        'show_pending_label' => true,
                        'show_start_button' => false, // El botón de comenzar se reserva para [cl_boton_comenzar_curso]. OJO!!
                        'show_continue_link' => true,
                    ]);
                }

                $excerpt = trim((string) get_post_field('post_excerpt', $cid));
                if ($excerpt === '') {
                    $excerpt = wp_trim_words(wp_strip_all_tags((string) $course->post_content), 26, '...');
                }
            ?>
            <article class="cl-inline-course-card">
                <h4 class="cl-inline-course-title">
                    <a href="<?php echo esc_url(get_permalink($cid)); ?>"><?php echo esc_html($course->post_title); ?></a>
                </h4>
                <?php if ($excerpt !== ''): ?>
                    <p class="cl-inline-course-excerpt"><?php echo esc_html($excerpt); ?></p>
                <?php endif; ?>
                <?php if ($estado !== ''): ?>
                    <div class="cl-inline-course-status"><?php echo $estado; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
                <?php endif; ?>
                <?php if ($accion !== ''): ?>
                    <div class="cl-inline-course-cta"><?php echo $accion; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </div>
    <?php
    return ob_get_clean();
}

function cl_render_tabs_cursos_shortcode($atts = []) {
    if (!is_user_logged_in()) return 'Debes iniciar sesión.';
    $user_id = get_current_user_id();

    $atts = shortcode_atts([
        'show_actions' => '0',
    ], (array)$atts, 'cl_tabs_cursos');
    $show_actions = in_array(strtolower((string)$atts['show_actions']), ['1', 'true', 'yes', 'si', 'sí'], true);

    $courses = get_posts([
        'post_type' => 'curso-cie',
        'numberposts' => -1,
        'post_status' => 'publish',
        'orderby' => 'title',
        'order' => 'ASC',
    ]);

    $summary_map = [];
    $enrolled_courses = [];
    $completed_courses = [];

    foreach ($courses as $course) {
        $cid = (int) $course->ID;
        $summary = cl_get_course_user_summary($user_id, $cid);
        $summary_map[$cid] = $summary;

        if (!empty($summary['is_enrolled'])) {
            $enrolled_courses[] = $course;
        }
        if (!empty($summary['is_completed'])) {
            $completed_courses[] = $course;
        }
    }

    static $instance = 0;
    static $assets_printed = false;
    $instance++;

    $uid = 'cl-inline-tabs-' . $instance;
    $tab_ids = [
        'enrolled' => $uid . '-tab-enrolled',
        'completed' => $uid . '-tab-completed',
        'all' => $uid . '-tab-all',
    ];
    $panel_ids = [
        'enrolled' => $uid . '-panel-enrolled',
        'completed' => $uid . '-panel-completed',
        'all' => $uid . '-panel-all',
    ];

    ob_start();

    if (!$assets_printed) {
        $assets_printed = true;
        ?>
        <style>
            .cl-inline-tabs{margin:18px 0;}
            .cl-inline-tabs-nav{display:flex;gap:8px;flex-wrap:wrap;margin:0 0 14px;}
            .cl-inline-tabs-btn{border:1px solid #d1d5db;background:#fff;color:#111827;padding:8px 12px;border-radius:8px;cursor:pointer;font-weight:600;}
            .cl-inline-tabs-btn[aria-selected="true"]{background:#111827;color:#fff;border-color:#111827;}
            .cl-inline-tabs-panel{border:1px solid #e5e7eb;border-radius:10px;background:#fff;padding:14px;}
            .cl-inline-course-list{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:12px;}
            .cl-inline-course-card{border:1px solid #eef0f2;border-radius:10px;padding:12px;background:#fafafa;}
            .cl-inline-course-title{margin:0 0 8px;font-size:16px;line-height:1.3;}
            .cl-inline-course-title a{text-decoration:none;}
            .cl-inline-course-excerpt{margin:0 0 10px;font-size:14px;line-height:1.4;color:#374151;}
            .cl-inline-course-status{margin:0 0 10px;font-size:13px;}
            .cl-inline-tabs-empty{margin:0;color:#6b7280;}
            .cl-inline-course-cta .cl-start-msg{font-size:13px;}
        </style>
        <script>
            (function(){
                if (window.clInlineTabsReady) return;
                window.clInlineTabsReady = true;

                function activate(root, panelId){
                    var buttons = root.querySelectorAll('[data-cl-tab-btn]');
                    var panels = root.querySelectorAll('[data-cl-tab-panel]');
                    buttons.forEach(function(btn){
                        var active = btn.getAttribute('data-cl-tab-btn') === panelId;
                        btn.setAttribute('aria-selected', active ? 'true' : 'false');
                        btn.setAttribute('tabindex', active ? '0' : '-1');
                    });
                    panels.forEach(function(panel){
                        panel.hidden = panel.id !== panelId;
                    });
                }

                document.addEventListener('click', function(ev){
                    var btn = ev.target.closest('[data-cl-tab-btn]');
                    if (!btn) return;
                    var root = btn.closest('[data-cl-inline-tabs]');
                    if (!root) return;
                    activate(root, btn.getAttribute('data-cl-tab-btn'));
                });

                document.addEventListener('keydown', function(ev){
                    var btn = ev.target.closest('[data-cl-tab-btn]');
                    if (!btn) return;
                    var root = btn.closest('[data-cl-inline-tabs]');
                    if (!root) return;

                    var keys = ['ArrowRight', 'ArrowLeft', 'Home', 'End'];
                    if (keys.indexOf(ev.key) === -1) return;

                    var buttons = Array.prototype.slice.call(root.querySelectorAll('[data-cl-tab-btn]'));
                    var idx = buttons.indexOf(btn);
                    if (idx === -1) return;

                    var next = idx;
                    if (ev.key === 'ArrowRight') next = (idx + 1) % buttons.length;
                    if (ev.key === 'ArrowLeft') next = (idx - 1 + buttons.length) % buttons.length;
                    if (ev.key === 'Home') next = 0;
                    if (ev.key === 'End') next = buttons.length - 1;

                    ev.preventDefault();
                    var nextBtn = buttons[next];
                    nextBtn.focus();
                    activate(root, nextBtn.getAttribute('data-cl-tab-btn'));
                });

                document.querySelectorAll('[data-cl-inline-tabs]').forEach(function(root){
                    var selected = root.querySelector('[data-cl-tab-btn][aria-selected="true"]') || root.querySelector('[data-cl-tab-btn]');
                    if (!selected) return;
                    activate(root, selected.getAttribute('data-cl-tab-btn'));
                });
            })();
        </script>
        <?php
    }
    ?>
    <div class="cl-inline-tabs" data-cl-inline-tabs id="<?php echo esc_attr($uid); ?>">
        <div class="cl-inline-tabs-nav" role="tablist" aria-label="Cursos">
            <button type="button" class="cl-inline-tabs-btn" role="tab"
                    id="<?php echo esc_attr($tab_ids['enrolled']); ?>"
                    data-cl-tab-btn="<?php echo esc_attr($panel_ids['enrolled']); ?>"
                    aria-controls="<?php echo esc_attr($panel_ids['enrolled']); ?>"
                    aria-selected="true" tabindex="0">Mis cursos inscritos</button>
            <button type="button" class="cl-inline-tabs-btn" role="tab"
                    id="<?php echo esc_attr($tab_ids['completed']); ?>"
                    data-cl-tab-btn="<?php echo esc_attr($panel_ids['completed']); ?>"
                    aria-controls="<?php echo esc_attr($panel_ids['completed']); ?>"
                    aria-selected="false" tabindex="-1">Cursos completados</button>
            <button type="button" class="cl-inline-tabs-btn" role="tab"
                    id="<?php echo esc_attr($tab_ids['all']); ?>"
                    data-cl-tab-btn="<?php echo esc_attr($panel_ids['all']); ?>"
                    aria-controls="<?php echo esc_attr($panel_ids['all']); ?>"
                    aria-selected="false" tabindex="-1">Todos los cursos</button>
        </div>

        <section class="cl-inline-tabs-panel" role="tabpanel"
                 id="<?php echo esc_attr($panel_ids['enrolled']); ?>"
                 data-cl-tab-panel
                 aria-labelledby="<?php echo esc_attr($tab_ids['enrolled']); ?>">
            <?php echo cl_render_course_cards_inline($enrolled_courses, $summary_map, $show_actions); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
        </section>

        <section class="cl-inline-tabs-panel" role="tabpanel"
                 id="<?php echo esc_attr($panel_ids['completed']); ?>"
                 data-cl-tab-panel
                 aria-labelledby="<?php echo esc_attr($tab_ids['completed']); ?>"
                 hidden>
            <?php echo cl_render_course_cards_inline($completed_courses, $summary_map, $show_actions); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
        </section>

        <section class="cl-inline-tabs-panel" role="tabpanel"
                 id="<?php echo esc_attr($panel_ids['all']); ?>"
                 data-cl-tab-panel
                 aria-labelledby="<?php echo esc_attr($tab_ids['all']); ?>"
                 hidden>
            <?php echo cl_render_course_cards_inline($courses, $summary_map, $show_actions); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
        </section>
    </div>
    <?php

    return ob_get_clean();
}

add_shortcode('cl_tabs_cursos', 'cl_render_tabs_cursos_shortcode');
add_shortcode('cl_cursos_tabs', 'cl_render_tabs_cursos_shortcode');

function cl_render_nota_ultimo_examen_shortcode($atts = []) {
    if (!is_user_logged_in()) return 'Debes iniciar sesión.';
    $atts = shortcode_atts([
        'empty' => 'Sin exámenes finalizados.',
        'show_max' => '1',
        'show_course' => '1',
    ], (array)$atts, 'cl_nota_ultimo_examen');

    $attempt = cl_get_latest_finished_exam_attempt_for_user(get_current_user_id());
    if (!$attempt) return (string) $atts['empty'];

    $grade_data = cl_get_exam_attempt_grade_data($attempt->ID);
    if (!is_array($grade_data) || !is_numeric($grade_data['grade'])) return (string) $atts['empty'];

    $grade_txt = cl_format_grade_value($grade_data['grade']);
    $output = $grade_txt;
    if (($atts['show_max'] ?? '1') === '1') {
        $max_txt = cl_format_grade_value($grade_data['max_grade']);
        $output = $grade_txt . ' / ' . $max_txt;
    }

    if (($atts['show_course'] ?? '1') === '1') {
        $course_id = (int) get_post_meta($attempt->ID, '_cl_course_id', true);
        if (!$course_id) {
            $lesson_id = (int) get_post_meta($attempt->ID, '_cl_lesson_id', true);
            if ($lesson_id > 0) {
                $course_id = (int) get_post_field('post_parent', $lesson_id);
            }
        }
        if ($course_id > 0) {
            $course = get_post($course_id);
            $course_url = get_permalink($course_id);
            if ($course && $course_url) {
                $output .= ' - <a href="' . esc_url($course_url) . '">' . esc_html($course->post_title) . '</a>';
            }
        }
    }

    return $output;
}

add_shortcode('cl_nota_ultimo_examen', 'cl_render_nota_ultimo_examen_shortcode');
add_shortcode('cl_nota_ultimo_examen_finalizado', 'cl_render_nota_ultimo_examen_shortcode');

function cl_render_nota_curso_actual_shortcode($atts = []) {

    if (!is_user_logged_in()) {
        return 'Debes iniciar sesión.';
    }

    $attempt = cl_get_latest_finished_exam_attempt_for_user(get_current_user_id());
    if (!$attempt) {
        return 'Sin exámenes finalizados.';
    }

    $status = get_post_meta($attempt->ID, '_cl_status', true);
    if (!in_array($status, ['approved', 'auto_approved', 'finished'], true)) {
        return 'Sin exámenes aprobados.';
    }

    $grade_data = cl_get_exam_attempt_grade_data($attempt->ID);
    if (!is_array($grade_data) || !is_numeric($grade_data['grade'])) {
        return 'Sin nota válida.';
    }

    $grade_txt = cl_format_grade_value($grade_data['grade']);
    $max_txt   = cl_format_grade_value($grade_data['max_grade']);

    // Intentar obtener curso directamente del attempt
$course_id = (int) get_post_meta($attempt->ID, '_cl_course_id', true);

// Si no existe, intentar obtenerlo desde la lección
if (!$course_id) {
    $lesson_id = (int) get_post_meta($attempt->ID, '_cl_lesson_id', true);
    if ($lesson_id) {
        $course_id = (int) get_post_meta($lesson_id, '_cl_course_id', true);
    }
}

// Si sigue sin existir, salir mostrando solo la nota
if (!$course_id) {
    return $grade_txt . ' / ' . $max_txt;
}

$course = get_post($course_id);
if (!$course) {
    return $grade_txt . ' / ' . $max_txt;
}

$course_url = get_permalink($course_id);

return $grade_txt . ' / ' . $max_txt .
       ' – <a href="' . esc_url($course_url) . '">' .
       esc_html($course->post_title) .
       '</a>';
}

add_shortcode('cl_nota_curso_actual', 'cl_render_nota_curso_actual_shortcode');
add_shortcode('cl_nota_current_course', 'cl_render_nota_curso_actual_shortcode');

function cl_get_enrollment_review_admin_url($token) {
    $token = sanitize_text_field((string)$token);
    $url = admin_url('admin.php?page=cl_review_enrollment_request');
    if ($token === '') return $url;
    return add_query_arg('token', rawurlencode($token), $url);
}

function cl_normalize_enrollment_request_payload($req) {
    $req = is_array($req) ? $req : [];
    $user_id = absint($req['user_id'] ?? 0);
    $course_ids = array_values(array_unique(array_filter(array_map('absint', (array)($req['course_ids'] ?? [])))));
    $status = sanitize_key((string)($req['status'] ?? 'pending'));
    if (!in_array($status, ['pending', 'processed'], true)) $status = 'pending';

    return [
        'user_id' => $user_id,
        'course_ids' => $course_ids,
        'created_at' => absint($req['created_at'] ?? time()),
        'status' => $status,
        'approved' => array_values(array_unique(array_filter(array_map('absint', (array)($req['approved'] ?? []))))),
        'revoked' => array_values(array_unique(array_filter(array_map('absint', (array)($req['revoked'] ?? []))))),
        'reviewed_at' => absint($req['reviewed_at'] ?? 0),
        'reviewed_by' => absint($req['reviewed_by'] ?? 0),
        'source' => sanitize_key((string)($req['source'] ?? 'form_request')),
    ];
}

function cl_get_enrollment_requests_store() {
    $store = get_option(CL_OPTION_ENROLLMENT_REQUESTS, []);
    if (!is_array($store)) $store = [];
    $out = [];
    foreach ($store as $token => $req) {
        $token = sanitize_text_field((string)$token);
        if ($token === '') continue;
        $norm = cl_normalize_enrollment_request_payload($req);
        if ($norm['user_id'] <= 0 || empty($norm['course_ids'])) continue;
        $out[$token] = $norm;
    }
    return $out;
}

function cl_save_enrollment_requests_store($store) {
    $clean = [];
    if (is_array($store)) {
        foreach ($store as $token => $req) {
            $token = sanitize_text_field((string)$token);
            if ($token === '') continue;
            $norm = cl_normalize_enrollment_request_payload($req);
            if ($norm['user_id'] <= 0 || empty($norm['course_ids'])) continue;
            $clean[$token] = $norm;
        }
    }

    if (count($clean) > 300) {
        uasort($clean, function($a, $b) {
            $ta = max((int)($a['reviewed_at'] ?? 0), (int)($a['created_at'] ?? 0));
            $tb = max((int)($b['reviewed_at'] ?? 0), (int)($b['created_at'] ?? 0));
            return $tb <=> $ta;
        });
        $clean = array_slice($clean, 0, 300, true);
    }

    update_option(CL_OPTION_ENROLLMENT_REQUESTS, $clean, false);
}

function cl_get_enrollment_request_by_token($token) {
    $token = sanitize_text_field((string)$token);
    if ($token === '') return null;

    $store = cl_get_enrollment_requests_store();
    if (isset($store[$token])) return $store[$token];

    // Compatibilidad con tokens antiguos guardados en transient.
    $legacy = get_transient('cl_enroll_req_' . $token);
    if (is_array($legacy) && !empty($legacy['user_id']) && !empty($legacy['course_ids'])) {
        $req = cl_normalize_enrollment_request_payload($legacy);
        $store[$token] = $req;
        cl_save_enrollment_requests_store($store);
        return $req;
    }

    return null;
}

function cl_set_enrollment_request($token, $req) {
    $token = sanitize_text_field((string)$token);
    if ($token === '') return;
    $store = cl_get_enrollment_requests_store();
    $store[$token] = cl_normalize_enrollment_request_payload($req);
    cl_save_enrollment_requests_store($store);
}

function cl_mark_enrollment_request_processed($token, $approved, $revoked, $reviewed_by = 0) {
    $token = sanitize_text_field((string)$token);
    if ($token === '') return;
    $req = cl_get_enrollment_request_by_token($token);
    if (!is_array($req)) return;
    $req['status'] = 'processed';
    $req['approved'] = array_values(array_unique(array_filter(array_map('absint', (array)$approved))));
    $req['revoked'] = array_values(array_unique(array_filter(array_map('absint', (array)$revoked))));
    $req['reviewed_at'] = time();
    $req['reviewed_by'] = absint($reviewed_by);
    cl_set_enrollment_request($token, $req);
}

function cl_send_enrollment_result_email($user_id, $approved, $revoked) {
    $user_id = absint($user_id);
    $approved = array_values(array_unique(array_filter(array_map('absint', (array)$approved))));
    $revoked = array_values(array_unique(array_filter(array_map('absint', (array)$revoked))));
    if ($user_id <= 0) return;

    $user = get_user_by('id', $user_id);
    if (!$user || empty($user->user_email)) return;

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

function cl_apply_enrollment_decisions($user_id, $course_ids, $decisions = [], $reviewed_by = 0) {
    $user_id = absint($user_id);
    $reviewed_by = absint($reviewed_by);
    $course_ids = array_values(array_unique(array_filter(array_map('absint', (array)$course_ids))));

    $approved = [];
    $revoked = [];

    foreach ($course_ids as $cid) {
        if (get_post_type($cid) !== 'curso-cie') continue;
        if (cl_course_access_mode($cid) !== 'inscripcion') continue;

        $decision = isset($decisions[$cid]) ? sanitize_text_field((string)$decisions[$cid]) : 'approve';
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

    cl_remove_user_pending_enrollments($user_id, array_merge($approved, $revoked));
    if (!empty($approved) || !empty($revoked)) {
        cl_send_enrollment_result_email($user_id, $approved, $revoked);
    }

    return [
        'approved' => $approved,
        'revoked' => $revoked,
        'reviewed_by' => $reviewed_by,
    ];
}

function cl_get_pending_enrollment_request_token_for_user($user_id) {
    $user_id = absint($user_id);
    if ($user_id <= 0) return '';
    $pending = cl_get_user_pending_enrollments($user_id);
    if (empty($pending)) return '';
    sort($pending);

    $store = cl_get_enrollment_requests_store();
    foreach ($store as $token => $req) {
        if ((int)($req['user_id'] ?? 0) !== $user_id) continue;
        if (($req['status'] ?? 'pending') !== 'pending') continue;
        $req_courses = array_values(array_unique(array_filter(array_map('absint', (array)($req['course_ids'] ?? [])))));
        sort($req_courses);
        if ($req_courses === $pending) return (string) $token;
    }

    $dates = cl_get_user_pending_enrollments_dates($user_id);
    $created_at = time();
    if (!empty($dates)) {
        $created_at = min(array_map('absint', array_values($dates)));
        if ($created_at <= 0) $created_at = time();
    }
    return cl_create_enrollment_request_token($user_id, $pending, [
        'created_at' => $created_at,
        'source' => 'pending_index',
    ]);
}

/* =====================================================
   SHORTCODE: FORMULARIO SOLICITUD INSCRIPCIÓN
===================================================== */
function cl_create_enrollment_request_token($user_id, $course_ids, $args = []) {
    $user_id = absint($user_id);
    $course_ids = array_values(array_unique(array_filter(array_map('absint', (array)$course_ids))));
    if ($user_id <= 0 || empty($course_ids)) return '';

    $args = wp_parse_args((array)$args, [
        'token' => '',
        'created_at' => time(),
        'status' => 'pending',
        'approved' => [],
        'revoked' => [],
        'reviewed_at' => 0,
        'reviewed_by' => 0,
        'source' => 'form_request',
    ]);

    $token = sanitize_text_field((string)$args['token']);
    if ($token === '') $token = wp_generate_uuid4();
    cl_set_enrollment_request($token, [
        'user_id' => $user_id,
        'course_ids' => $course_ids,
        'created_at' => absint($args['created_at']),
        'status' => sanitize_key((string)$args['status']),
        'approved' => (array)$args['approved'],
        'revoked' => (array)$args['revoked'],
        'reviewed_at' => absint($args['reviewed_at']),
        'reviewed_by' => absint($args['reviewed_by']),
        'source' => sanitize_key((string)$args['source']),
    ]);

    // Compatibilidad con enlaces antiguos.
    set_transient('cl_enroll_req_' . $token, [
        'user_id' => $user_id,
        'course_ids' => $course_ids,
        'created_at' => absint($args['created_at']),
    ], 7 * DAY_IN_SECONDS);

    return $token;
}

add_shortcode('cl_form_inscripcion', function($atts) {
    if (!is_user_logged_in()) return 'Debes iniciar sesión.';
    $user_id = get_current_user_id();

    $atts = shortcode_atts([
        'to' => '',
    ], (array)$atts, 'cl_form_inscripcion');
    $custom_to = sanitize_email((string)($atts['to'] ?? ''));
    $notify_to = (is_email($custom_to)) ? $custom_to : (string) get_option('admin_email');

    $cursos = get_posts([
        'post_type' => 'curso-cie',
        'numberposts' => -1,
        'orderby' => 'title',
        'order' => 'ASC',
    ]);

    $pending = cl_get_user_pending_enrollments($user_id);

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
            // Marcar como "pendiente" en el usuario
            cl_add_user_pending_enrollments($user_id, $selected_ok);

            $token = cl_create_enrollment_request_token($user_id, $selected_ok);
            $review_link = cl_get_enrollment_review_admin_url($token);

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

            if ($notify_to) wp_mail($notify_to, $subject, $msg);

            $notice = '<div class="cl-no-access" style="border-left-color:#46b450; background:#f1fff3;">Solicitud enviada. Un administrador revisará tu inscripción.</div>';
        }
    }

    $has_insc_courses = false;
    foreach ($cursos as $c) {
        if (cl_course_access_mode($c->ID) === 'inscripcion') { $has_insc_courses = true; break; }
    }
    if (!$has_insc_courses) {
        return $notice . '<div class="cl-no-access" style="border-left-color:#ffb900; background:#fffbea;">No hay cursos disponibles para solicitar inscripción.</div>';
    }

    ob_start();
    echo $notice; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    ?>
    <form method="post" class="cl-insc-form">
        <?php wp_nonce_field('cl_insc_submit', 'cl_insc_nonce'); ?>
        <p>Selecciona los cursos en los que quieres inscribirte:</p>
        <div class="cl-insc-list">
            <?php
                foreach ($cursos as $c):
                    if (cl_course_access_mode($c->ID) !== 'inscripcion') continue;
                    $cid = (int)$c->ID;
                    $is_enrolled = cl_is_user_enrolled_in_course($user_id, $cid);
                    $is_pending = !$is_enrolled && in_array($cid, $pending, true);
            ?>
                <div class="cl-insc-item" style="display:flex; gap:10px; align-items:center; justify-content:space-between; border:1px solid #e6e6e6; border-radius:10px; padding:10px 12px; margin:8px 0; background:#fff;">
                    <label style="display:flex; gap:10px; align-items:center; margin:0;">
                        <?php if (!$is_enrolled && !$is_pending): ?>
                            <input type="checkbox" name="cl_courses[]" value="<?php echo esc_attr($cid); ?>" />
                        <?php endif; ?>
                        <span><?php echo esc_html($c->post_title); ?></span>
                    </label>
                    <?php if ($is_enrolled): ?>
                        <span class="cl-tag cl-tag-ok">Inscrito</span>
                    <?php elseif ($is_pending): ?>
                        <span class="cl-tag cl-tag-warn">Pendiente de aprobación</span>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <p style="margin-top:12px;">
            <button type="submit" class="cl-btn" name="cl_insc_submit" value="1">Solicitar inscripción</button>
        </p>
    </form>
    <?php
    return ob_get_clean();
});

function cl_get_enrollment_request_status_label($status) {
    $status = sanitize_key((string)$status);
    if ($status === 'processed') return 'Procesada';
    return 'Pendiente';
}

function cl_render_enrollment_review_index_admin_page($notice_html = '') {
    $users = get_users(['fields' => ['ID']]);
    foreach ($users as $u) {
        cl_get_pending_enrollment_request_token_for_user((int)$u->ID);
    }

    $store = cl_get_enrollment_requests_store();
    $pending_rows = [];
    $history_rows = [];

    foreach ($store as $token => $req) {
        $user_id = (int)($req['user_id'] ?? 0);
        if ($user_id <= 0) continue;
        $user = get_user_by('id', $user_id);
        $course_ids = array_values(array_unique(array_filter(array_map('absint', (array)($req['course_ids'] ?? [])))));
        if (empty($course_ids)) continue;

        if (($req['status'] ?? 'pending') === 'pending') {
            $current_pending = cl_get_user_pending_enrollments($user_id);
            $course_ids = array_values(array_intersect($course_ids, $current_pending));
            if (empty($course_ids)) continue;
        }

        $course_titles = [];
        foreach ($course_ids as $cid) {
            $c = get_post($cid);
            if ($c && $c->post_type === 'curso-cie') $course_titles[] = (string)$c->post_title;
        }
        if (empty($course_titles)) continue;

        $row = [
            'token' => $token,
            'user' => $user,
            'user_id' => $user_id,
            'courses_html' => implode('<br>', array_map('esc_html', $course_titles)),
            'created_at' => (int)($req['created_at'] ?? 0),
            'reviewed_at' => (int)($req['reviewed_at'] ?? 0),
            'reviewed_by' => (int)($req['reviewed_by'] ?? 0),
            'approved' => array_values(array_unique(array_filter(array_map('absint', (array)($req['approved'] ?? []))))),
            'revoked' => array_values(array_unique(array_filter(array_map('absint', (array)($req['revoked'] ?? []))))),
            'status' => (string)($req['status'] ?? 'pending'),
        ];

        if ($row['status'] === 'pending') $pending_rows[] = $row;
        else $history_rows[] = $row;
    }

    usort($pending_rows, function($a, $b) {
        return (int)$b['created_at'] <=> (int)$a['created_at'];
    });
    usort($history_rows, function($a, $b) {
        $ta = max((int)$a['reviewed_at'], (int)$a['created_at']);
        $tb = max((int)$b['reviewed_at'], (int)$b['created_at']);
        return $tb <=> $ta;
    });

    echo '<div class="wrap"><h1>Revisar solicitud de inscripción</h1>';
    if ($notice_html !== '') {
        echo $notice_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }
    if (!empty($_GET['processed'])) {
        echo '<div class="notice notice-success"><p>Solicitud revisada correctamente.</p></div>';
    }

    echo '<h2>Solicitudes pendientes</h2>';
    echo '<table class="widefat striped"><thead><tr>';
    echo '<th>Usuario</th><th>Email</th><th>Cursos</th><th>Fecha</th><th>Acciones</th>';
    echo '</tr></thead><tbody>';
    if (empty($pending_rows)) {
        echo '<tr><td colspan="5">No hay solicitudes pendientes.</td></tr>';
    } else {
        foreach ($pending_rows as $row) {
            $user = $row['user'];
            $review_url = cl_get_enrollment_review_admin_url($row['token']);
            echo '<tr>';
            echo '<td>' . esc_html($user ? ($user->display_name . ' (' . $user->user_login . ')') : ('Usuario ' . $row['user_id'])) . '</td>';
            echo '<td>' . esc_html($user ? $user->user_email : '-') . '</td>';
            echo '<td>' . $row['courses_html'] . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            echo '<td>' . esc_html(!empty($row['created_at']) ? date_i18n('Y-m-d H:i', (int)$row['created_at']) : '-') . '</td>';
            echo '<td><a class="button button-primary" href="' . esc_url($review_url) . '">Revisar</a></td>';
            echo '</tr>';
        }
    }
    echo '</tbody></table>';

    echo '<h2 style="margin-top:24px;">Historial</h2>';
    echo '<table class="widefat striped"><thead><tr>';
    echo '<th>Fecha</th><th>Usuario</th><th>Cursos</th><th>Resultado</th><th>Revisor</th><th>Acciones</th>';
    echo '</tr></thead><tbody>';
    if (empty($history_rows)) {
        echo '<tr><td colspan="6">Sin solicitudes procesadas todavía.</td></tr>';
    } else {
        foreach ($history_rows as $row) {
            $user = $row['user'];
            $reviewer = $row['reviewed_by'] > 0 ? get_user_by('id', $row['reviewed_by']) : null;
            $review_url = cl_get_enrollment_review_admin_url($row['token']);

            $result_parts = [];
            if (!empty($row['approved'])) $result_parts[] = 'Aprobados: ' . count($row['approved']);
            if (!empty($row['revoked'])) $result_parts[] = 'Revocados: ' . count($row['revoked']);
            if (empty($result_parts)) $result_parts[] = cl_get_enrollment_request_status_label($row['status']);

            $when = max((int)$row['reviewed_at'], (int)$row['created_at']);
            echo '<tr>';
            echo '<td>' . esc_html($when > 0 ? date_i18n('Y-m-d H:i', $when) : '-') . '</td>';
            echo '<td>' . esc_html($user ? ($user->display_name . ' (' . $user->user_login . ')') : ('Usuario ' . $row['user_id'])) . '</td>';
            echo '<td>' . $row['courses_html'] . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            echo '<td>' . esc_html(implode(' | ', $result_parts)) . '</td>';
            echo '<td>' . esc_html($reviewer ? $reviewer->display_name : '-') . '</td>';
            echo '<td><a class="button" href="' . esc_url($review_url) . '">Ver detalle</a></td>';
            echo '</tr>';
        }
    }
    echo '</tbody></table>';
    echo '</div>';
}

function cl_render_enrollment_review_screen($token, $req) {
    $token = sanitize_text_field((string)$token);
    $req = cl_normalize_enrollment_request_payload($req);
    $user_id = (int) ($req['user_id'] ?? 0);
    $status = (string) ($req['status'] ?? 'pending');
    $course_ids = array_values(array_unique(array_filter(array_map('absint', (array)($req['course_ids'] ?? [])))));
    $user = $user_id ? get_user_by('id', $user_id) : null;

    if ($status === 'pending') {
        $course_ids = array_values(array_intersect($course_ids, cl_get_user_pending_enrollments($user_id)));
    }

    $courses = [];
    foreach ($course_ids as $cid) {
        if (get_post_type($cid) !== 'curso-cie') continue;
        $c = get_post($cid);
        if ($c) $courses[] = $c;
    }

    echo '<div class="wrap">';
    echo '<h1>Revisar solicitud de inscripción</h1>';
    if (!empty($_GET['processed'])) {
        echo '<div class="notice notice-success"><p>Solicitud revisada correctamente.</p></div>';
    }
    echo '<p><a href="' . esc_url(cl_get_enrollment_review_admin_url('')) . '">← Volver a solicitudes</a></p>';
    echo '<p><strong>Estado:</strong> ' . esc_html(cl_get_enrollment_request_status_label($status)) . '</p>';
    echo '<p><strong>Usuario:</strong> ' . esc_html($user ? ($user->display_name . ' (' . $user->user_login . ')') : ('Usuario ' . $user_id)) . '</p>';
    echo '<p><strong>Email:</strong> ' . esc_html($user ? $user->user_email : '-') . '</p>';
    if (!empty($req['created_at'])) {
        echo '<p><strong>Solicitado:</strong> ' . esc_html(date_i18n('Y-m-d H:i', (int)$req['created_at'])) . '</p>';
    }
    if (!empty($req['reviewed_at'])) {
        $reviewer = !empty($req['reviewed_by']) ? get_user_by('id', (int)$req['reviewed_by']) : null;
        echo '<p><strong>Revisado:</strong> ' . esc_html(date_i18n('Y-m-d H:i', (int)$req['reviewed_at'])) . ' por ' . esc_html($reviewer ? $reviewer->display_name : '-') . '</p>';
    }
    echo '<hr />';

    if (empty($courses)) {
        echo '<p>No hay cursos pendientes para esta solicitud.</p>';
        if (!empty($req['approved']) || !empty($req['revoked'])) {
            echo '<p><strong>Resumen:</strong></p><ul>';
            if (!empty($req['approved'])) echo '<li>Aprobados: ' . esc_html(count((array)$req['approved'])) . '</li>';
            if (!empty($req['revoked'])) echo '<li>Revocados: ' . esc_html(count((array)$req['revoked'])) . '</li>';
            echo '</ul>';
        }
        echo '</div>';
        return;
    }

    if ($status !== 'pending') {
        echo '<p>Esta solicitud ya fue procesada. Puedes revisarla desde el historial.</p>';
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

function cl_render_enrollment_review_admin_page() {
    if (!current_user_can('manage_options')) {
        wp_die('Sin permisos.');
    }

    $token = isset($_GET['token']) ? sanitize_text_field(wp_unslash($_GET['token'])) : '';
    if ($token === '') {
        cl_render_enrollment_review_index_admin_page();
        return;
    }

    $req = cl_get_enrollment_request_by_token($token);
    if (!is_array($req) || empty($req['user_id']) || empty($req['course_ids'])) {
        cl_render_enrollment_review_index_admin_page('<div class="notice notice-warning"><p>Solicitud caducada o inválida.</p></div>');
        return;
    }

    cl_render_enrollment_review_screen($token, $req);
}

add_action('admin_post_cl_review_enrollment_request', function() {
    if (!current_user_can('manage_options')) wp_die('Sin permisos.');
    $token = isset($_GET['token']) ? sanitize_text_field(wp_unslash($_GET['token'])) : '';
    wp_safe_redirect(cl_get_enrollment_review_admin_url($token));
    exit;
});

add_action('admin_post_cl_process_enrollment_request', function() {
    if (!current_user_can('manage_options')) wp_die('Sin permisos.');
    $token = isset($_POST['token']) ? sanitize_text_field(wp_unslash($_POST['token'])) : '';
    if ($token === '') wp_die('Token inválido.');
    check_admin_referer('cl_process_enrollment_request_' . $token);

    $req = cl_get_enrollment_request_by_token($token);
    if (!is_array($req) || empty($req['user_id']) || empty($req['course_ids'])) {
        wp_die('Solicitud caducada o inválida.');
    }

    $user_id = (int) ($req['user_id'] ?? 0);
    $course_ids = array_values(array_unique(array_filter(array_map('absint', (array)($req['course_ids'] ?? [])))));
    $course_ids = array_values(array_intersect($course_ids, cl_get_user_pending_enrollments($user_id)));
    $decisions = isset($_POST['decision']) ? (array) $_POST['decision'] : [];
    $result = cl_apply_enrollment_decisions($user_id, $course_ids, $decisions, get_current_user_id());

    cl_mark_enrollment_request_processed($token, $result['approved'], $result['revoked'], get_current_user_id());
    delete_transient('cl_enroll_req_' . $token);

    wp_safe_redirect(add_query_arg('processed', '1', cl_get_enrollment_review_admin_url($token)));
    exit;
});
