jQuery(document).ready(function($) {
    let page = 1;
    let index = 0;

    $("#dpi-start").on("click", function() {
        page = 1;
        index = 0;
        $("#dpi-log").html("<p>Memulai import...</p>");
        importNext();
    });

    function importNext() {
        const source = $("#dpi-source").val();
        const replace = $("#dpi-replace").val();

        $.post(
            dpi_ajax.ajax_url,
            {
                action: "dpi_import_next",
                page,
                index,
                source,
                replace
            },
            function(res) {
                if (res.success) {
                    $("#dpi-log").append("<p>" + res.data.msg + "</p>");
                    
                    if (res.data.next) {
                        index++;
                        importNext();
                    }
                } 
                else if (res.data && res.data.done) {
                    $("#dpi-log").append("<p><strong>✅ Selesai semua post.</strong></p>");
                } 
                else {
                    $("#dpi-log").append("<p style='color:red;'>" + res.data.msg + "</p>");
                }
            }
        );
    }
});