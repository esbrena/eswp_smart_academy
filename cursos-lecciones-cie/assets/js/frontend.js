(function($){

    // =============================
    // COMENZAR CURSO
    // =============================
    $(document).on('click', '.cl-btn-start-course, #cl-btn-start-course', function(){
        if(!window.cl_ajax || !cl_ajax.ajax_url) return;
        const $btn = $(this);
        const cursoId = parseInt($btn.data('curso'), 10) || 0;
        const $scope = $btn.closest('.cl-course-action');
        const $msg = $scope.length ? $scope.find('.cl-start-msg').first() : $('.cl-start-msg').first();
        if(!cursoId) return;

        $btn.prop('disabled', true);
        if($msg.length) $msg.text('Iniciando curso...');

        $.post(cl_ajax.ajax_url, {
            action: 'cl_comenzar_curso',
            nonce: cl_ajax.nonce,
            curso_id: cursoId
        }).done(function(res){
            if(res && res.success){
                window.location.reload();
            } else {
                $btn.prop('disabled', false);
                const msg = (res && res.data && res.data.message) ? res.data.message : 'No se pudo iniciar el curso.';
                if($msg.length) $msg.text(msg);
            }
        }).fail(function(xhr){
            $btn.prop('disabled', false);
            let msg = 'No se pudo iniciar el curso.';
            if(xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message){
                msg = xhr.responseJSON.data.message;
            }
            if($msg.length) $msg.text(msg);
        });
    });

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
    const lessonType = String(progressEl.data('lessonType') || 'normal').toLowerCase().trim();
    const isExamLesson = lessonType === 'examen';
    const shouldTrackTime = (tiempoMinSegundos > 0) && !isExamLesson;

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
        if(!shouldTrackTime) return;
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
        if(!shouldTrackTime) return;
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
            if(!shouldTrackTime) return;
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
            if(!shouldTrackTime) return;
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
    let examForceSubmit = false;

    function initExamStepper($form){
        const $steps = $form.find('.cl-exam-step');
        const total = $steps.length;
        if(total <= 0) return;

        let cur = 0;
        $form.data('clExamCur', 0);

        const $prog = $form.find('.cl-exam-progress');
        const $cur = $prog.find('.cl-exam-step-cur');
        const $tot = $prog.find('.cl-exam-step-total');
        if($tot.length) $tot.text(String(total));

        function showStep(idx){
            idx = Math.max(0, Math.min(total - 1, idx));
            cur = idx;
            $form.data('clExamCur', idx);
            $steps.hide().eq(idx).show();
            $form.find('.cl-exam-nav').show();
            $form.find('.cl-exam-review').hide();
            if($cur.length) $cur.text(String(idx + 1));
            updateNav();
        }

        function updateNav(){
            const isFirst = cur === 0;
            const isLast = cur === total - 1;
            $form.find('.cl-exam-prev').prop('disabled', isFirst);
            $form.find('.cl-exam-next').text(isLast ? 'Ir a revisión' : 'Continuar');
        }

        function questionText($step){
            const t = $step.find('.cl-exam-qt').first().text().trim();
            return t || ('Pregunta ' + (parseInt($step.data('step'), 10) + 1));
        }

        function isAnswered($step){
            if($step.find('input[type="radio"]:checked, input[type="checkbox"]:checked').length > 0) return true;
            const txt = String($step.find('textarea').val() || '').trim();
            return txt.length > 0;
        }

        function renderReview(){
            const $review = $form.find('.cl-exam-review');
            const $list = $review.find('.cl-exam-review-list');
            $list.empty();

            $steps.each(function(i){
                const $s = $(this);
                const answered = isAnswered($s);
                const txt = questionText($s);
                const cls = answered ? 'answered' : 'unanswered';
                const badge = answered ? 'Respondida' : 'Sin responder';
                const $row = $(`
                    <div class="cl-exam-review-item ${cls}" data-jump="${i}">
                        <div class="cl-exam-review-title"><strong>${i+1}.</strong> ${$('<div>').text(txt).html()}</div>
                        <div class="cl-exam-review-meta">
                            <span class="cl-exam-review-badge">${badge}</span>
                            <button type="button" class="cl-btn cl-exam-jump">Ir</button>
                        </div>
                    </div>
                `);
                $list.append($row);
            });

            $steps.hide();
            $form.find('.cl-exam-nav').hide();
            $review.show();
            $('html, body').animate({ scrollTop: $review.offset().top - 20 }, 200);
        }

        $(document).off('click.clExamPrev').on('click.clExamPrev', '.cl-exam-prev', function(){
            showStep(cur - 1);
        });

        $(document).off('click.clExamNext').on('click.clExamNext', '.cl-exam-next', function(){
            if(cur >= total - 1){
                renderReview();
            } else {
                showStep(cur + 1);
            }
        });

        $(document).off('click.clExamReviewBtn').on('click.clExamReviewBtn', '.cl-exam-review-btn', function(){
            renderReview();
        });

        $(document).off('click.clExamBack').on('click.clExamBack', '.cl-exam-back-to-questions', function(){
            showStep(cur);
            $('html, body').animate({ scrollTop: $form.offset().top - 20 }, 200);
        });

        $(document).off('click.clExamJump').on('click.clExamJump', '.cl-exam-jump', function(){
            const idx = parseInt($(this).closest('.cl-exam-review-item').data('jump'), 10);
            showStep(isNaN(idx) ? 0 : idx);
            $('html, body').animate({ scrollTop: $form.offset().top - 20 }, 200);
        });

        showStep(0);
    }

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
                examForceSubmit = true;
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
                initExamStepper($form);

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
        const token = String($form.find('input[name="exam_session_token"]').val() || '');

        $msg.text('');
        $btn.prop('disabled', true);

        if(!token){
            $btn.prop('disabled', false);
            alert('Debes iniciar el examen antes de enviarlo.');
            return;
        }

        // Forzar que el envío manual se haga desde la pantalla de revisión
        const $review = $form.find('.cl-exam-review');
        const inReview = $review.is(':visible');
        if(!examForceSubmit && !inReview){
            $btn.prop('disabled', false);
            // Llevar al usuario a revisión en vez de enviar directamente
            $form.find('.cl-exam-review-btn').trigger('click');
            return;
        }

        if(!examForceSubmit){
            const ok = window.confirm('¿Confirmas que quieres enviar el examen? Una vez enviado no podrás modificarlo.');
            if(!ok){
                $btn.prop('disabled', false);
                return;
            }
        }

        const payload = $form.serialize() + '&action=cl_submit_exam&nonce=' + encodeURIComponent(cl_ajax.nonce);

        $.post(cl_ajax.ajax_url, payload)
            .done(function(res){
                if(res && res.success){
                    window.location.reload();
                } else {
                    examForceSubmit = false;
                    $btn.prop('disabled', false);
                    $msg.text((res && res.data && res.data.message) ? res.data.message : 'Error al enviar el examen.');
                }
            })
            .fail(function(xhr){
                examForceSubmit = false;
                $btn.prop('disabled', false);
                let msg = 'Error al enviar el examen.';
                if(xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message){
                    msg = xhr.responseJSON.data.message;
                }
                $msg.text(msg);
            });
    });

})(jQuery);
