<?php
/*
Plugin Name: Cursos y Lecciones
Description: Mini academia con cursos y lecciones visuales.
Version: 1.4
Author: Wembleys Studios
*/

if ( ! defined( 'ABSPATH' ) ) exit;

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

    // ACF suele devolver HH:MM:SS (pero a veces HH:MM)
    $parts = explode(':', $time_value);
    $parts = array_map('intval', $parts);

    if (count($parts) === 3) {
        [$h, $m, $s] = $parts;
        return max(0, ($h * 3600) + ($m * 60) + $s);
    }

    if (count($parts) === 2) {
        [$m, $s] = $parts;
        return max(0, ($m * 60) + $s);
    }

    // Formato desconocido
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
        '1.4',
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
        '1.4'
    );
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
   SHORTCODE: CL_LECCION_CURSO
===================================================== */
add_shortcode('cl_leccion_curso', function(){

    if(!is_user_logged_in()) return 'Debes iniciar sesión para ver el curso.';

    global $post;
    if(!$post || $post->post_type !== 'curso-cie') return '';

    $curso_id = $post->ID;
    $user_id  = get_current_user_id();
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
    $video = get_field('video-tracking', $leccion_actual->ID);
    $time = get_field('tiempo_minimo', $leccion_actual->ID);
    $tiempo_minimo_seg = cl_parse_time_to_seconds($time);

    // Tiempo guardado previamente (para no resetear al volver a entrar)
    $tiempos_guardados = get_user_meta($user_id, "cl_curso_{$curso_id}_tiempos", true);
    if(!is_array($tiempos_guardados)) $tiempos_guardados = [];
    $tiempo_guardado_actual = isset($tiempos_guardados[$leccion_actual->ID]) ? intval($tiempos_guardados[$leccion_actual->ID]) : 0;


    $ids = wp_list_pluck($lecciones,'ID');
    $index = array_search($leccion_actual->ID, $ids);

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

                $should_disable_next = (!$leccion_completada && $tiempo_minimo_seg > 0);
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
            ></div>

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
                        <?php echo $should_disable_next ? 'disabled' : ''; ?>>
                        Siguiente lección →
                        </button>
                        <?php endif; ?>
            </div>

        </main>
    </div>
    <?php
    return ob_get_clean();
});

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

        // Borrar histórico
        global $wpdb;
        $table = cl_get_hist_table_name();
        $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE user_id = %d AND curso_id = %d", $uid, $cid));

        echo '<div class="notice notice-success"><p>Progreso borrado correctamente.</p></div>';
        echo '<meta http-equiv="refresh" content="1">';
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
    if ($completadas_count === 0) {
        $estado = "<span class='state-no-init'>No iniciado</span>";
    } elseif ($completadas_count < $total) {
        $estado = "<span class='state-progress'>En progreso ({$porcentaje}%)</span>";
    } else {
        $estado = "<span class='state-complete'>Completado (100%)</span>";
    }

    return $estado;

});