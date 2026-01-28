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
    const examLocked = String(progressEl.data('examLock')) === '1';

    let progreso = lastSavedInicial;   // segundos acumulados (no resetea al volver a entrar)
    let maxVisto = lastSavedInicial;   // para limitar el adelanto en vídeo

    let inFlight = false;
    let pending = null; // { tiempo, tipo }
    let lastSent = lastSavedInicial;

    function updateUI(){
        if(!barra.length) return;
        if(tiempoMinSegundos <= 0){
            if(btnSiguiente.length){
                if(!examLocked){
                    btnSiguiente.prop('disabled', false);
                }
            }
            return; // sin mínimo, no calculamos barra
        }
        const porcentaje = Math.min(100, (progreso / tiempoMinSegundos) * 100);
        barra.css('width', porcentaje + '%');
        if(btnSiguiente.length && !examLocked && progreso >= tiempoMinSegundos){
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
        if(btnSiguiente.length && !examLocked && btnSiguiente.is(':disabled') && tiempoMinSegundos > 0 && progreso >= tiempoMinSegundos){
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
        let msgTimeoutId = null;
        let $seekMsg = null;

        function ensureSeekMsg(){
            if($seekMsg && $seekMsg.length) return $seekMsg;
            const $wrap = $('.cl-video');
            if(!$wrap.length) return null;
            $seekMsg = $wrap.find('.cl-video-seek-msg');
            if($seekMsg.length) return $seekMsg;
            $seekMsg = $('<div class="cl-video-seek-msg" style="display:none;"></div>');
            $wrap.append($seekMsg);
            return $seekMsg;
        }

        function showSeekBlockedMessage(){
            const $m = ensureSeekMsg();
            if(!$m) return;
            $m.text('No puedes adelantar el vídeo hasta ver esa parte.').stop(true, true).fadeIn(150);
            if(msgTimeoutId) clearTimeout(msgTimeoutId);
            msgTimeoutId = setTimeout(function(){
                $m.fadeOut(200);
            }, 5000);
        }

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
                showSeekBlockedMessage();
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
        if(String($(this).data('examLock')) === '1'){
            return;
        }
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

    // =============================
    // EXAMEN
    // =============================
    let examTimerId = null;
    function stopExamTimer(){
        if(examTimerId){
            clearInterval(examTimerId);
            examTimerId = null;
        }
    }

    function startCountdown($timer){
        stopExamTimer();
        const $cd = $timer.find('.cl-exam-countdown');
        let remaining = parseInt($timer.data('time'), 10) || 0;
        if(remaining <= 0 || !$cd.length) return;

        function fmt(sec){
            sec = Math.max(0, parseInt(sec, 10) || 0);
            const m = Math.floor(sec / 60);
            const s = sec % 60;
            const mm = String(m).padStart(2,'0');
            const ss = String(s).padStart(2,'0');
            return mm + ':' + ss;
        }

        $cd.text(fmt(remaining));
        examTimerId = setInterval(function(){
            remaining -= 1;
            $cd.text(fmt(remaining));
            if(remaining <= 0){
                stopExamTimer();
                // Bloquear inputs y auto-enviar
                const $form = $('#cl-exam-form');
                $form.find('input, textarea, select, button').prop('disabled', true);
                // Re-habilitar submit para poder enviar
                $form.find('.cl-exam-submit').prop('disabled', false);
                $form.trigger('submit');
            }
        }, 1000);
    }

    $(document).on('click', '#cl-exam-start', function(){
        const $btn = $(this);
        $btn.prop('disabled', true);

        $.post(cl_ajax.ajax_url, {
            action: 'cl_start_exam',
            nonce: cl_ajax.nonce,
            curso_id: cursoId,
            leccion_id: leccionId
        }).done(function(res){
            if(res && res.success && res.data && res.data.token){
                const $form = $('#cl-exam-form');
                $form.find('input[name="exam_session_token"]').val(res.data.token);

                $('.cl-exam-intro').hide();
                $form.show();

                const $timer = $form.find('.cl-exam-timer');
                if($timer.length){
                    startCountdown($timer);
                }

                $('html, body').animate({ scrollTop: $form.offset().top - 20 }, 300);
            } else {
                $btn.prop('disabled', false);
                alert((res && res.data && res.data.message) ? res.data.message : 'No se pudo iniciar el examen.');
            }
        }).fail(function(xhr){
            $btn.prop('disabled', false);
            let msg = 'No se pudo iniciar el examen.';
            if(xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message){
                msg = xhr.responseJSON.data.message;
            }
            alert(msg);
        });
    });

    $(document).on('click', '.cl-exam-finish', function(){
        const $form = $('#cl-exam-form');
        const token = String($form.find('input[name="exam_session_token"]').val() || '');
        if(!token){
            alert('Debes iniciar el examen antes de finalizarlo.');
            return;
        }
        $form.trigger('submit');
    });

    $(document).on('submit', '#cl-exam-form', function(e){
        e.preventDefault();
        stopExamTimer();

        const $form = $(this);
        const $btn = $form.find('.cl-exam-submit');
        const $msg = $form.find('.cl-exam-msg');

        $msg.text('');
        $btn.prop('disabled', true);

        const payload = $form.serialize() + '&action=cl_submit_exam&nonce=' + encodeURIComponent(cl_ajax.nonce);

        $.post(cl_ajax.ajax_url, payload)
            .done(function(res){
                if(res && res.success){
                    window.location.reload();
                } else {
                    $btn.prop('disabled', false);
                    $msg.text((res && res.data && res.data.message) ? res.data.message : 'Error al enviar el examen.');
                }
            })
            .fail(function(xhr){
                $btn.prop('disabled', false);
                let msg = 'Error al enviar el examen.';
                if(xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message){
                    msg = xhr.responseJSON.data.message;
                }
                $msg.text(msg);
            });
    });

})(jQuery);