(function($){

    const progressEl = $('#cl-progress-data');
    if(!progressEl.length) return;

    const btnSiguiente = $('#cl-btn-siguiente');
    const barra = $('.cl-barra-llenado');

    const cursoId = parseInt(progressEl.data('curso'), 10) || 0;
    const leccionId = parseInt(progressEl.data('leccion'), 10) || 0;
    const tiempoMinSegundos = parseInt(progressEl.data('tiempo'), 10) || 0;
    const lastSavedInicial = parseInt(progressEl.data('last'), 10) || 0;
    const isVideoLesson = String(progressEl.data('isVideo')) === '1';
    const wasCompleted = String(progressEl.data('state')) === '1';

    let progreso = lastSavedInicial;   // segundos acumulados (no resetea al volver a entrar)
    let maxVisto = lastSavedInicial;   // para limitar el adelanto en vídeo

    let inFlight = false;
    let pending = null; // { tiempo, tipo }
    let lastSent = lastSavedInicial;

    function updateUI(){
        if(!barra.length) return;
        if(tiempoMinSegundos <= 0){
            if(btnSiguiente.length){
                btnSiguiente.prop('disabled', false);
            }
            return; // sin mínimo, no calculamos barra
        }
        const porcentaje = Math.min(100, (progreso / tiempoMinSegundos) * 100);
        barra.css('width', porcentaje + '%');
        if(btnSiguiente.length && progreso >= tiempoMinSegundos){
            btnSiguiente.prop('disabled', false);
        }
    }

    function sendTick(tiempo, tipo){
        tiempo = parseInt(tiempo, 10) || 0;
        if(tiempo <= lastSent) return; // solo si es mayor al último guardado/enviado

        pending = { tiempo, tipo };
        if(inFlight) return;

        inFlight = true;
        const payload = pending;
        pending = null;
        lastSent = payload.tiempo;

        $.post(cl_ajax.ajax_url, {
            action: 'cl_guardar_progreso_tick',
            nonce: cl_ajax.nonce,
            curso_id: cursoId,
            leccion_id: leccionId,
            tiempo: payload.tiempo,
            tiempo_minimo: tiempoMinSegundos,
            tipo: payload.tipo
        }).always(function(){
            inFlight = false;
            if(pending && pending.tiempo > lastSent){
                sendTick(pending.tiempo, pending.tipo);
            }
        });
    }

    // Estado inicial (si ya estaba completada, el botón puede venir habilitado desde PHP)
    if(wasCompleted && barra.length){
        barra.css('width', '100%');
    } else {
        updateUI();
        if(btnSiguiente.length && btnSiguiente.is(':disabled') && tiempoMinSegundos > 0 && progreso >= tiempoMinSegundos){
            btnSiguiente.prop('disabled', false);
        }
    }

    function startScreenTimer(){
        // Para lecciones sin vídeo: cuenta tiempo en pantalla sin resetear
        updateUI();
        setInterval(function(){
            progreso += 1;
            updateUI();
            sendTick(progreso, 'pantalla');
        }, 1000);
    }

    function bindVideoTracking(videoEl){
        if(!videoEl) return false;

        let enforcing = false;

        // Reanudar desde el último tiempo guardado (si aplica)
        videoEl.addEventListener('loadedmetadata', function(){
            const dur = isFinite(videoEl.duration) ? videoEl.duration : 0;
            if(lastSavedInicial > 0 && dur > 0 && lastSavedInicial < dur - 1){
                try { videoEl.currentTime = lastSavedInicial; } catch(e) {}
            }
        }, { once: true });

        function enforceSeek(){
            enforcing = true;
            try { videoEl.currentTime = maxVisto; } catch(e) {}
            setTimeout(function(){ enforcing = false; }, 0);
        }

        videoEl.addEventListener('seeking', function(){
            if(enforcing) return;
            const t = videoEl.currentTime || 0;
            if(t > maxVisto + 0.75){
                enforceSeek();
            }
        });

        videoEl.addEventListener('timeupdate', function(){
            if(videoEl.paused || videoEl.ended || videoEl.seeking) return;
            const t = videoEl.currentTime || 0;
            if(t > maxVisto){
                maxVisto = t;
            }
        });

        // Guardar progreso al reproducir, y cada segundo mientras esté en play
        let intervalId = null;
        function ensureInterval(){
            if(intervalId) return;
            intervalId = setInterval(function(){
                if(videoEl.paused || videoEl.ended || videoEl.seeking) return;
                const t = Math.floor(videoEl.currentTime || 0);
                if(t > progreso){
                    progreso = t;
                    if(t > maxVisto) maxVisto = t;
                    updateUI();
                    sendTick(progreso, 'video');
                }
            }, 1000);
        }

        videoEl.addEventListener('play', function(){
            ensureInterval();
        });

        // Flush al salir
        window.addEventListener('beforeunload', function(){
            const t = Math.floor(videoEl.currentTime || 0);
            if(t > progreso){
                progreso = t;
                updateUI();
                sendTick(progreso, 'video');
            }
        });

        return true;
    }

    if(isVideoLesson){
        const videoEl = document.querySelector('.cl-video video');
        const bound = bindVideoTracking(videoEl);
        if(!bound){
            // Fallback: si el embed no es un <video> (iframe YouTube/Vimeo), al menos cuenta tiempo en pantalla
            startScreenTimer();
        }
    } else {
        startScreenTimer();
    }

    // 👉 SIGUIENTE (no depende de este click para guardar, pero asegura “finalize”)
    $(document).on('click', '#cl-btn-siguiente', function(){
        const nextUrl = $(this).data('next');

        // Intentar guardar un último tick antes de navegar
        const tipo = isVideoLesson ? 'video' : 'pantalla';
        sendTick(progreso, tipo);

        // Si ya venía completada, navega directo
        const state = String($(this).data('state')) === '1';
        if(state){
            window.location.href = nextUrl;
            return;
        }

        $.post(cl_ajax.ajax_url, {
            action: 'cl_guardar_progreso',
            nonce: cl_ajax.nonce,
            curso_id: cursoId,
            leccion_id: leccionId,
            tiempo: progreso,
            tiempo_minimo: tiempoMinSegundos,
            tipo: tipo
        }, function(res){
            if(res && res.success){
                window.location.href = nextUrl;
            } else {
                alert('Error al guardar el progreso');
            }
        });
    });

})(jQuery);