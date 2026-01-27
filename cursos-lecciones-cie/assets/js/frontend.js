(function($){

    let tiempoInicio = Date.now();
    const btnSiguiente = $('#cl-btn-siguiente');
    const btnAnterior  = $('#cl-btn-anterior');

    if(!btnSiguiente.length) return;


    const barra = $('.cl-barra-llenado');


    if(!btnSiguiente.length) return;


    let tiempoMinSegundos = parseInt(btnSiguiente.data('tiempo')) * 60 || 0;


    // Solo bloquear si tiene atributo disabled
    if(btnSiguiente.is(':disabled') && tiempoMinSegundos > 0){
        let tiempoInicio = Date.now();


        let intervalo = setInterval(function(){
        let segundosPasados = Math.floor((Date.now() - tiempoInicio) / 1000);
        let porcentaje = Math.min(100, (segundosPasados / tiempoMinSegundos) * 100);
        barra.css('width', porcentaje + '%');


        if(segundosPasados >= tiempoMinSegundos){
            btnSiguiente.prop('disabled', false);
            clearInterval(intervalo);
        }
    },1000);
    } else {
        // Si ya está desbloqueado, llenar barra
        barra.css('width','100%');
    }

    // 👉 SIGUIENTE (guarda progreso)
    $(document).on('click', '#cl-btn-siguiente', function(){

        const curso   = $(this).data('curso');
        const leccion = $(this).data('leccion');
        const tiempo  = Math.floor((Date.now() - tiempoInicio) / 1000);

        const state = $(this).data('state');
        const nextUrl = $(this).data('next');
        
        if(state == 1) {
            window.location.href = nextUrl;
        } else {

            $.post(cl_ajax.ajax_url, {
                action: 'cl_guardar_progreso',
                nonce: cl_ajax.nonce,
                curso_id: curso,
                leccion_id: leccion,
                tiempo: tiempo
            }, function(res){
                if(res.success){
                    window.location.href = nextUrl;
                } else {
                    alert('Error al guardar el progreso');
                }
            });
        }
    });

    // 👉 ANTERIOR (SOLO NAVEGA)
    $(document).on('click', '#cl-btn-anterior', function(){
        const leccionAnterior = $(this).data('leccion');
        window.location.href = leccionAnterior;
    });

})(jQuery);