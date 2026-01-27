jQuery(document).ready(function($){

    // Crear lección AJAX
    $('#cl-btn-crear-leccion').on('click', function(){
        let titulo = $('#cl-nueva-leccion-titulo').val();
        let curso_id = cl_ajax.post_id || $('#post_ID').val();

        if(titulo.length === 0) return alert('Escribe un título');

        $.post(cl_ajax.ajax_url, {
            action: 'cl_crear_leccion',
            titulo: titulo,
            curso_id: curso_id,
            nonce: cl_ajax.nonce
        }, function(res){
            if(res.success){
                // Elimina el item de "No hay lecciones todavía" si existe
                $('#cl-lecciones-list li').filter(function() {
                    return $(this).text().trim() === 'No hay lecciones todavía.';
                }).remove();

                let html = '<li data-id="'+res.data.ID+'"><strong>'+res.data.title+'</strong> <a href="'+res.data.edit_link+'" target="_blank">Editar</a></li>';
                $('#cl-lecciones-list').append(html);
                $('#cl-nueva-leccion-titulo').val('');
            } else {
                alert('Error: '+res.data);
            }
        });
    });

    // Drag & Drop para ordenar
    $('#cl-lecciones-list').sortable({
        update: function(event, ui){
            let orden = $(this).sortable('toArray', {attribute:'data-id'});
            $.post(cl_ajax.ajax_url, {
                action: 'cl_ordenar_lecciones',
                orden: orden,
                nonce: cl_ajax.nonce
            });
        }
    });

    // Eliminar lección
    $('#cl-lecciones-list').on('click', '.cl-btn-eliminar', function(){
        if(!confirm('¿Seguro que quieres eliminar esta lección?')) return;

        let li = $(this).closest('li');
        let leccion_id = $(this).data('id');

        $.post(cl_ajax.ajax_url, {
            action: 'cl_eliminar_leccion',
            leccion_id: leccion_id,
            nonce: cl_ajax.nonce
        }, function(res){
            if(res.success){
                li.remove();

                // Si no quedan lecciones, mostrar mensaje
                if($('#cl-lecciones-list li').length === 0){
                    $('#cl-lecciones-list').html('<li>No hay lecciones todavía.</li>');
                }
            } else {
                alert('Error: '+res.data);
            }
        });
    });

});