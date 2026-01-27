<?php
/*
Plugin Name: Cursos y Lecciones
Description: Mini academia con cursos y lecciones visuales.
Version: 1.3
Author: Wembleys Studios
*/

if ( ! defined( 'ABSPATH' ) ) exit;

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
        '1.3',
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
        '1.3'
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
    $tiempo_minimo = 0;
    // 1. Obtener el valor del campo ACF (formato H:i:s)
    $time = get_field('tiempo_minimo', $leccion_actual->ID); // Asumiendo que devuelve '00:01:00'
        echo $time;
        if( $time ) {
            // 2. Dividir la cadena por los dos puntos
            $time_parts = explode(':', $time);
            $hours = intval($time_parts[0]);
            $minutes = intval($time_parts[1]);
            
            // 3. Convertir todo a minutos
            $total_minutes = ($hours * 60) + $minutes;
            
            // Resultado: 1
            $tiempo_minimo = $total_minutes;
        }


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
            ?>
            <div class="cl-barra-tiempo">
                <div class="cl-barra-llenado" style="width: <?php echo $leccion_completada ? '100%' : '0'; ?>%"></div>
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
                        data-tiempo="<?php echo esc_attr($tiempo_minimo); ?>"
                        data-state="<?php echo esc_attr($leccion_completada); ?>"
                        <?php echo $leccion_completada ? '' : 'disabled'; ?>>
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
   AJAX: GUARDAR PROGRESO
===================================================== */
add_action('wp_ajax_cl_guardar_progreso', function(){

    check_ajax_referer('cl_ajax_nonce','nonce');

    $user_id    = get_current_user_id();
    $curso_id   = intval($_POST['curso_id']);
    $leccion_id = intval($_POST['leccion_id']);
    $tiempo     = intval($_POST['tiempo']);

    if(!$user_id || !$curso_id || !$leccion_id){
        wp_send_json_error();
    }

    $completadas = get_user_meta($user_id,"cl_curso_{$curso_id}_completadas",true);
    if(!is_array($completadas)) $completadas = [];

    if(!in_array($leccion_id,$completadas)){
        $completadas[] = $leccion_id;
        update_user_meta($user_id,"cl_curso_{$curso_id}_completadas",$completadas);
    }

    $tiempos = get_user_meta($user_id,"cl_curso_{$curso_id}_tiempos",true);
    if(!is_array($tiempos)) $tiempos = [];

    if(!isset($tiempos[$leccion_id])){
        $tiempos[$leccion_id] = $tiempo;
        update_user_meta($user_id,"cl_curso_{$curso_id}_tiempos",$tiempos);
    }

    wp_send_json_success();
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