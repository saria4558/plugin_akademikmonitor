/* eslint-disable complexity */
define([], function() {

    return {
        init: function() {

            const ajaxurl = document.getElementById('am-ajaxurl')?.value || '';
            const sesskey = document.getElementById('am-sesskey')?.value || '';
            const tokenInput = document.getElementById('am-token');
            const statusEl = document.getElementById('am-token-status');

            const modal = document.getElementById('am-modal');
            const mId = document.getElementById('m-id');
            const mLabel = document.getElementById('m-label');
            const mOffset = document.getElementById('m-offset');
            const mTime = document.getElementById('m-time');
            const mEvent = document.getElementById('m-event');

            const recipientsBox = document.getElementById('m-recipients');

            /* ================= STATUS ================= */
            /**
             * @param {string} text
             * @param {boolean} ok
             */
            function showStatus(text, ok) {
                if (!statusEl) {
 return;
}
                statusEl.textContent = text;
                statusEl.style.color = ok ? '#1f7a3b' : '#b42318';
            }

            /* ================= AJAX ================= */

            /**
             * Send AJAX request
             *
             * @param {string} action
             * @param {Object} payload
             * @returns {Promise<Object>}
             */
            async function post(action, payload = {}) {
                const fd = new FormData();
                fd.append('sesskey', sesskey);
                fd.append('action', action);

                Object.keys(payload).forEach(k => {
                    fd.append(k, payload[k]);
                });

                const res = await fetch(ajaxurl, {
                    method: 'POST',
                    body: fd
                });

                return res.json();
            }

            /* ================= MODAL ================= */

            /**
             *
             */
            function openModal() {
                if (modal) {
 modal.style.display = 'flex';
}
            }

            /**
             *
             */
            function closeModal() {
                if (modal) {
 modal.style.display = 'none';
}
            }

            /* ================= RECIPIENTS ================= */

            /**
             *
             * @param {string} str
             */
            function setRecipientsChecked(str) {
                if (!recipientsBox) {
 return;
}

                const current = (str || '')
                    .split(',')
                    .map(s => s.trim())
                    .filter(Boolean);

                recipientsBox
                    .querySelectorAll('input[type="checkbox"]')
                    .forEach(ch => {
                        ch.checked = current.includes(ch.value);
                    });
            }

            /**
             *
             */
            function getRecipientsValue() {
                if (!recipientsBox) {
 return '';
}

                const arr = [];
                recipientsBox
                    .querySelectorAll('input[type="checkbox"]')
                    .forEach(ch => {
                        if (ch.checked) {
 arr.push(ch.value);
}
                    });

                return arr.join(', ');
            }

            /* ================= ACTION HANDLER ================= */

            document.body.addEventListener('click', async function(e) {
                const btn = e.target.closest('[data-action]');
                if (!btn) {
 return;
}

                const action = btn.dataset.action;

                // CHECK TELEGRAM
                if (action === 'check-telegram') {
                    const token = tokenInput?.value.trim() || '';
                    if (!token) {
                        showStatus('Token kosong.', false);
                        return;
                    }

                    showStatus('Mengecek koneksi...', true);
                    const json = await post('check_token', {token});
                    showStatus(json.message || (json.ok ? 'OK' : 'Gagal'), !!json.ok);
                    return;
                }

                // SAVE TELEGRAM
                if (action === 'save-telegram') {
                    const token = tokenInput?.value.trim() || '';
                    if (!token) {
                        showStatus('Token kosong.', false);
                        return;
                    }

                    showStatus('Menyimpan token...', true);
                    const json = await post('save_token', {token, enabled: '1'});
                    showStatus(json.message || (json.ok ? 'Tersimpan' : 'Gagal'), !!json.ok);
                    return;
                }

                // EDIT RULE
                if (action === 'edit-rule') {
                    if (mId) {
 mId.value = btn.dataset.id || '';
}
                    if (mLabel) {
 mLabel.value = btn.dataset.label || '';
}
                    if (mOffset) {
 mOffset.value = btn.dataset.offset || '';
}
                    if (mTime) {
 mTime.value = btn.dataset.time || '07:00:00';
}
                    if (mEvent) {
 mEvent.value = btn.dataset.event || '';
}
                    setRecipientsChecked(btn.dataset.recipients || '');
                    openModal();
                    return;
                }

                // CLOSE MODAL
                if (action === 'close-modal') {
                    closeModal();
                    return;
                }

                // SAVE RULE
                if (action === 'save-rule') {
                    const id = mId?.value || '';
                    const offset = mOffset?.value.trim() || '';
                    const time = mTime?.value.trim() || '';
                    const event = mEvent?.value.trim() || '';
                    const recipients = getRecipientsValue();

                    const json = await post('update_rule', {
                        id, offset, time, event, recipients
                    });

                    if (json.ok) {
                        location.reload();
                    } else {
                        alert(json.message || 'Gagal update rule');
                    }
                    return;
                }

                // TOGGLE RULE
                if (action === 'toggle-rule') {
                    const id = btn.dataset.id;
                    const json = await post('toggle_rule', {id});

                    if (json.ok) {
 location.reload();
} else {
 alert(json.message || 'Gagal toggle');
}

                    return;
                }
            });

            /* ================= CLICK OUTSIDE MODAL ================= */

            modal?.addEventListener('click', function(e) {
                if (e.target === modal) {
 closeModal();
}
            });

        }
    };

});