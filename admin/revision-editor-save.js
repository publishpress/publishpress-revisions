/** Full editor saves must complete successfully before workflow transitions. */
(function (window) {
    'use strict';
    var active = null;
    var metaRequest = null;
    var preparations = [];
    var classicActive = null;
    function prepare() {
        return preparations.reduce(function (chain, callback) { return chain.then(callback); }, Promise.resolve());
    }
    function message() { return window.rvyObjEdit.saveError; }
    function idle() {
        return new Promise(function (resolve, reject) {
            var deadline = Date.now() + 60000;
            function check() {
                var editor = window.wp.data.select('core/editor');
                var boxes = window.wp.data.select('core/edit-post');
                if (!editor.isSavingPost() && !editor.isAutosavingPost()
                    && !(boxes && boxes.isSavingMetaBoxes && boxes.isSavingMetaBoxes())) { resolve(); return; }
                if (Date.now() >= deadline) { reject(new Error(message())); return; }
                window.setTimeout(check, 50);
            }
            check();
        });
    }
    // WordPress catches legacy meta-box failures internally; record them as
    // well so a successful REST post save cannot conceal a failed form save.
    function monitorMetaBoxes() {
        if (monitorMetaBoxes.installed || !window.wp.apiFetch) return;
        monitorMetaBoxes.installed = true;
        window.wp.apiFetch.use(function (options, next) {
            var tracker = metaRequest;
            var matches = tracker && window._wpMetaBoxUrl && options.url === window._wpMetaBoxUrl
                && String(options.method || 'GET').toUpperCase() === 'POST';
            if (matches && options.body && typeof options.body.set === 'function') {
                if (window.rvyObjEdit.postID && typeof options.body.get === 'function'
                    && Number(options.body.get('post_ID')) !== Number(window.rvyObjEdit.postID)) {
                    tracker.failed = true;
                    return Promise.reject(new Error(message()));
                }
                // Let the native meta-box form's completion callback verify
                // builder persistence, without applying the transition there.
                options.body.set('_rvy_editor_save_verify', '1');
                options.body.set('_rvy_editor_transition_nonce', window.rvyObjEdit.fullSaveNonce);
            }
            var result = next(options);
            if (!matches) return result;
            tracker.started++;
            return Promise.resolve(result).then(function (response) {
                if (response && (response.ok === false || (response.url && /\/wp-login\.php(?:[?#]|$)/.test(response.url)))) tracker.failed = true;
                tracker.finished++;
                return response;
            }, function (error) {
                tracker.failed = true; tracker.finished++; throw error;
            });
        });
    }
    function saveBlock() {
        if (active) return active;
        active = idle().then(prepare).then(function () {
            var editor = window.wp.data.select('core/editor');
            if ((window.rvyObjEdit.postID && Number(editor.getCurrentPostId()) !== Number(window.rvyObjEdit.postID))
                || !editor.isEditedPostSaveable() || (editor.isPostSavingLocked && editor.isPostSavingLocked())) throw new Error(message());
            monitorMetaBoxes();
            var tracker = {started:0, finished:0, failed:false};
            metaRequest = tracker;
            var request;
            try { request = window.wp.data.dispatch('core/editor').savePost(); }
            catch (error) { metaRequest = null; throw error; }
            // An idle flag alone is not proof of a successful, full manual save.
            if (!request || typeof request.then !== 'function') { metaRequest = null; throw new Error(message()); }
            var timer;
            var bounded = Promise.race([request, new Promise(function (resolve, reject) {
                timer = window.setTimeout(function () { reject(new Error(message())); }, 60000);
            })]);
            return bounded.then(idle).then(function () {
                var current = window.wp.data.select('core/editor');
                var boxes = window.wp.data.select('core/edit-post');
                if ((window.rvyObjEdit.postID && Number(current.getCurrentPostId()) !== Number(window.rvyObjEdit.postID))
                    || !current.didPostSaveRequestSucceed() || current.isEditedPostDirty()
                    || tracker.failed || tracker.started !== tracker.finished
                    || (boxes && boxes.hasMetaBoxes && boxes.hasMetaBoxes() && !tracker.started)) throw new Error(message());
            }).finally(function () { window.clearTimeout(timer); if (metaRequest === tracker) metaRequest = null; });
        }).finally(function () { active = null; });
        return active;
    }
    function submitClassicForm(operation) {
        var form = document.getElementById('post');
        var save = document.getElementById('save-post');
        if (!form || !save || save.disabled || typeof save.click !== 'function') throw new Error(message());
        if (!form.__rvyTransitionIntent) {
            var clearIntent = function () {
                form.querySelectorAll('[name="_rvy_editor_transition"], [name="_rvy_editor_transition_nonce"]').forEach(function (input) { input.remove(); });
            };
            form.addEventListener('input', function (event) { if (event.isTrusted) clearIntent(); });
            form.addEventListener('change', function (event) { if (event.isTrusted) clearIntent(); });
            save.addEventListener('click', function (event) { if (event.isTrusted) clearIntent(); }, true);
            form.__rvyTransitionIntent = true;
        }
        var target = form.querySelector('[name="post_ID"]');
        if (window.rvyObjEdit.postID && (!target || Number(target.value) !== Number(window.rvyObjEdit.postID))) throw new Error(message());
        if (window.tinymce) window.tinymce.triggerSave();
        // Use the native form, including submit/validation hooks, file inputs,
        // taxonomies and third-party meta boxes. WordPress redirects only after
        // edit_post() and its save hooks finish; the server continues from there.
        var values = {_rvy_editor_transition:operation, _rvy_editor_transition_nonce:window.rvyObjEdit.fullSaveNonce};
        Object.keys(values).forEach(function (name) {
            var input = form.querySelector('[name="' + name + '"]');
            if (!input) { input = document.createElement('input'); input.type = 'hidden'; input.name = name; form.appendChild(input); }
            input.value = values[name];
        });
        save.click();
    }
    function saveClassic(operation) {
        if (classicActive) return classicActive;
        classicActive = prepare().then(function () { submitClassicForm(operation); }).finally(function () { classicActive = null; });
        return classicActive;
    }
    window.RevisionaryEditorSave = {
        block:saveBlock, classic:saveClassic,
        addPreparation:function (callback) { preparations.push(callback); }
    };
})(window);
