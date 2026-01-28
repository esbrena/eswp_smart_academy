jQuery(function($){
    const $input = $('#cl-exam-definition');
    const $list = $('#cl-exam-questions');
    const $addQ = $('#cl-exam-add-question');

    // Selector de vídeo (WordPress Media Library) en metabox "Contenido de lección"
    (function(){
        const $pick = $('#cl-pick-video');
        const $clear = $('#cl-clear-video');
        const $id = $('#cl-video-attachment-id');
        const $preview = $('#cl-video-preview');
        if(!$pick.length || !$id.length || !$preview.length) return;

        let frame = null;
        $pick.on('click', function(e){
            e.preventDefault();
            if(frame){
                frame.open();
                return;
            }
            frame = wp.media({
                title: 'Selecciona un vídeo',
                button: { text: 'Usar este vídeo' },
                library: { type: 'video' },
                multiple: false
            });
            frame.on('select', function(){
                const attachment = frame.state().get('selection').first().toJSON();
                $id.val(attachment.id);
                const url = attachment.url || '';
                const filename = attachment.filename || ('Adjunto ' + attachment.id);
                const safeUrl = $('<div>').text(url).html();
                const safeName = $('<div>').text(filename).html();
                $preview.html('<a href="'+safeUrl+'" target="_blank" rel="noreferrer">Ver archivo</a> <code style="margin-left:6px;">ID: '+attachment.id+'</code> <span style="margin-left:6px;">'+safeName+'</span>');
            });
            frame.open();
        });

        $clear.on('click', function(e){
            e.preventDefault();
            $id.val('0');
            $preview.html('<em>Sin archivo seleccionado</em>');
        });
    })();

    // Mostrar/ocultar el bloque de examen según tipo de lección (ACF o metabox propio)
    function getLessonType(){
        // Preferir el plugin: select #cl-tipo-de-leccion
        const vPlugin = String($('#cl-tipo-de-leccion').val() || '').toLowerCase().trim();
        if(vPlugin) return vPlugin;

        // Backward: ACF (nuevo) data-name="tipo_de_leccion"
        const $acfNew = $('.acf-field[data-name="tipo_de_leccion"]');
        if($acfNew.length){
            const v = String($acfNew.find('select').val() || $acfNew.find('input:checked').val() || '').toLowerCase().trim();
            if(v) return v;
        }

        // Backward: ACF (antiguo) data-name="tipo_leccion"
        const $acfOld = $('.acf-field[data-name="tipo_leccion"]');
        if($acfOld.length){
            const v = String($acfOld.find('select').val() || $acfOld.find('input:checked').val() || '').toLowerCase().trim();
            if(v) return v;
        }

        return 'normal';
    }

    function applyVisibility(){
        const tipo = getLessonType();
        const $examBox = $('#cl_leccion_examen');
        const $videoBox = $('#cl_leccion_video');

        // Normal: ocultar todo; Video: mostrar video; Examen: mostrar examen
        if(tipo === 'video'){
            if($videoBox.length) $videoBox.show();
            if($examBox.length) $examBox.hide();
        } else if(tipo === 'examen' || tipo === 'exam'){
            if($videoBox.length) $videoBox.hide();
            if($examBox.length) $examBox.show();
        } else {
            if($videoBox.length) $videoBox.hide();
            if($examBox.length) $examBox.hide();
        }
    }

    $(document).on('change', '#cl-tipo-de-leccion', applyVisibility);
    $(document).on('change', '.acf-field[data-name="tipo_de_leccion"] select, .acf-field[data-name="tipo_de_leccion"] input', applyVisibility);
    $(document).on('change', '.acf-field[data-name="tipo_leccion"] select, .acf-field[data-name="tipo_leccion"] input', applyVisibility);
    applyVisibility();

    if(!$input.length || !$list.length) return;

    function safeParse(json){
        try { return JSON.parse(json); } catch(e) { return null; }
    }

    function getState(){
        const parsed = safeParse($input.val());
        if(!parsed || typeof parsed !== 'object') return { questions: [] };
        if(!Array.isArray(parsed.questions)) parsed.questions = [];
        return parsed;
    }

    function setState(state){
        $input.val(JSON.stringify(state));
    }

    function newQuestion(){
        return {
            text: '',
            type: 'single',
            image_id: 0,
            options: [
                { text: '', is_correct: 0 },
                { text: '', is_correct: 0 }
            ]
        };
    }

    function render(){
        const state = getState();
        $list.empty();

        state.questions.forEach((q, qi) => {
            if(!q || typeof q !== 'object') q = newQuestion();
            if(!Array.isArray(q.options)) q.options = [];
            if(q.options.length < 2) q.options = [{text:'',is_correct:0},{text:'',is_correct:0}];

            const $q = $(`
                <div class="cl-exam-qbox" data-qi="${qi}" style="border:1px solid #ddd; padding:12px; margin:0 0 12px; background:#fff;">
                    <div style="display:flex; justify-content:space-between; gap:10px; align-items:center;">
                        <strong>Pregunta ${qi+1}</strong>
                        <button type="button" class="button-link-delete cl-exam-remove-q">Eliminar</button>
                    </div>

                    <p style="margin-top:10px;">
                        <label style="display:block; font-weight:600; margin-bottom:6px;">Texto</label>
                        <textarea class="cl-exam-qtext" rows="3" style="width:100%"></textarea>
                    </p>

                    <p>
                        <label style="font-weight:600;">Tipo</label>
                        <select class="cl-exam-qtype">
                            <option value="single">Single choice</option>
                            <option value="multi">Multi choice</option>
                        </select>
                    </p>

                    <div class="cl-exam-qimgwrap" style="margin:10px 0;">
                        <label style="display:block; font-weight:600; margin-bottom:6px;">Imagen (opcional)</label>
                        <div class="cl-exam-qimgpreview" style="margin-bottom:6px;"></div>
                        <button type="button" class="button cl-exam-pick-img">Seleccionar imagen</button>
                        <button type="button" class="button cl-exam-clear-img">Quitar</button>
                    </div>

                    <div class="cl-exam-optswrap">
                        <label style="display:block; font-weight:600; margin-bottom:6px;">Opciones</label>
                        <div class="cl-exam-opts"></div>
                        <button type="button" class="button cl-exam-add-opt">Añadir opción</button>
                    </div>
                </div>
            `);

            $q.find('.cl-exam-qtext').val(q.text || '');
            $q.find('.cl-exam-qtype').val(q.type === 'multi' ? 'multi' : 'single');

            // Imagen
            const imgId = parseInt(q.image_id || 0, 10) || 0;
            $q.data('image_id', imgId);
            if(imgId){
                $q.find('.cl-exam-qimgpreview').html(`<code>ID adjunto: ${imgId}</code>`);
            } else {
                $q.find('.cl-exam-qimgpreview').html('<em>Sin imagen</em>');
            }

            // Opciones
            const $opts = $q.find('.cl-exam-opts');
            q.options.forEach((opt, oi) => {
                const $opt = $(`
                    <div class="cl-exam-optrow" data-oi="${oi}" style="display:flex; gap:10px; align-items:center; margin:6px 0;">
                        <input type="text" class="cl-exam-opttext" style="flex:1;" placeholder="Texto de la opción" />
                        <label style="white-space:nowrap;">
                            <input type="checkbox" class="cl-exam-optcorrect" /> Correcta
                        </label>
                        <button type="button" class="button-link-delete cl-exam-remove-opt">Eliminar</button>
                    </div>
                `);
                $opt.find('.cl-exam-opttext').val(opt && opt.text ? opt.text : '');
                $opt.find('.cl-exam-optcorrect').prop('checked', !!(opt && opt.is_correct));
                $opts.append($opt);
            });

            $list.append($q);
        });
    }

    function syncFromDom(){
        const state = { questions: [] };
        $list.find('.cl-exam-qbox').each(function(){
            const $q = $(this);
            const type = $q.find('.cl-exam-qtype').val() === 'multi' ? 'multi' : 'single';
            const qObj = {
                text: $q.find('.cl-exam-qtext').val() || '',
                type,
                image_id: parseInt($q.data('image_id') || 0, 10) || 0,
                options: []
            };
            $q.find('.cl-exam-optrow').each(function(){
                const $opt = $(this);
                qObj.options.push({
                    text: $opt.find('.cl-exam-opttext').val() || '',
                    is_correct: $opt.find('.cl-exam-optcorrect').is(':checked') ? 1 : 0
                });
            });

            // Si es single: permitir solo una correcta
            if(type === 'single'){
                let found = false;
                qObj.options = qObj.options.map(o => {
                    if(o.is_correct && !found){ found = true; return o; }
                    if(o.is_correct && found){ return { ...o, is_correct: 0 }; }
                    return o;
                });
            }

            state.questions.push(qObj);
        });
        setState(state);
    }

    // Eventos
    $addQ.on('click', function(){
        const state = getState();
        state.questions.push(newQuestion());
        setState(state);
        render();
    });

    $list.on('input change', 'textarea, input, select', function(){
        // Si marca correcta en single: desmarcar resto visualmente
        const $row = $(this).closest('.cl-exam-qbox');
        const isSingle = $row.find('.cl-exam-qtype').val() !== 'multi';
        if(isSingle && $(this).hasClass('cl-exam-optcorrect') && $(this).is(':checked')){
            $row.find('.cl-exam-optcorrect').not(this).prop('checked', false);
        }
        syncFromDom();
    });

    $list.on('click', '.cl-exam-remove-q', function(){
        $(this).closest('.cl-exam-qbox').remove();
        syncFromDom();
        render();
    });

    $list.on('click', '.cl-exam-add-opt', function(){
        const $q = $(this).closest('.cl-exam-qbox');
        const $opts = $q.find('.cl-exam-opts');
        const oi = $opts.find('.cl-exam-optrow').length;
        const $opt = $(`
            <div class="cl-exam-optrow" data-oi="${oi}" style="display:flex; gap:10px; align-items:center; margin:6px 0;">
                <input type="text" class="cl-exam-opttext" style="flex:1;" placeholder="Texto de la opción" />
                <label style="white-space:nowrap;">
                    <input type="checkbox" class="cl-exam-optcorrect" /> Correcta
                </label>
                <button type="button" class="button-link-delete cl-exam-remove-opt">Eliminar</button>
            </div>
        `);
        $opts.append($opt);
        syncFromDom();
    });

    $list.on('click', '.cl-exam-remove-opt', function(){
        $(this).closest('.cl-exam-optrow').remove();
        syncFromDom();
    });

    $list.on('click', '.cl-exam-pick-img', function(){
        const $q = $(this).closest('.cl-exam-qbox');
        const frame = wp.media({
            title: (window.cl_admin_exam && cl_admin_exam.media_title) ? cl_admin_exam.media_title : 'Selecciona una imagen',
            button: { text: (window.cl_admin_exam && cl_admin_exam.media_button) ? cl_admin_exam.media_button : 'Usar esta imagen' },
            multiple: false
        });
        frame.on('select', function(){
            const attachment = frame.state().get('selection').first().toJSON();
            $q.data('image_id', attachment.id);
            $q.find('.cl-exam-qimgpreview').html(`<code>ID adjunto: ${attachment.id}</code>`);
            syncFromDom();
        });
        frame.open();
    });

    $list.on('click', '.cl-exam-clear-img', function(){
        const $q = $(this).closest('.cl-exam-qbox');
        $q.data('image_id', 0);
        $q.find('.cl-exam-qimgpreview').html('<em>Sin imagen</em>');
        syncFromDom();
    });

    // Primera carga
    const initial = getState();
    if(initial.questions.length === 0){
        // No forzar preguntas por defecto; solo render vacío
    }
    render();
});

