define(['jquery'], function($) {
    return {
        init: function(userid, kelasid, semester) {
            $(document).on('click', '#btn-edit-catatan', function(e) {
                e.preventDefault();
                const current = $('#catatan-text').text().trim();
                $('#input-catatan').val(current);
                $('#modal-catatan').css('display', 'flex');
            });

            $(document).on('click', '#close-modal', function(e) {
                e.preventDefault();
                $('#modal-catatan').hide();
            });

            $(document).on('click', '#modal-catatan', function(e) {
                if (e.target.id === 'modal-catatan') {
                    $('#modal-catatan').hide();
                }
            });

            $(document).on('click', '#save-catatan', function(e) {
                e.preventDefault();

                const text = $('#input-catatan').val();

                $.ajax({
                    url: M.cfg.wwwroot + '/local/akademikmonitor/pages/walikelas/rapor/ajax.php',
                    method: 'POST',
                    dataType: 'json',
                    data: {
                        sesskey: M.cfg.sesskey,
                        action: 'save_catatan',
                        userid: userid,
                        kelasid: kelasid,
                        semester: semester,
                        catatan: text
                    },
                    success: function(res) {
                        if (res && res.ok) {
                            $('#catatan-text').text(text);
                            $('#modal-catatan').hide();
                            alert(res.message || 'Catatan berhasil disimpan');
                        } else {
                            alert((res && res.message) ? res.message : 'Gagal menyimpan catatan');
                        }
                    },
                    error: function(xhr) {
                        alert('Request gagal: ' + (xhr.responseText || 'unknown error'));
                    }
                });
            });
        }
    };
});