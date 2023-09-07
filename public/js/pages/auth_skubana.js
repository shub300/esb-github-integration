var baseUrl = $('body').attr('data-url');

function showErrorAndFocus(elem) {
    if (elem.length) {
        elem.next().show();
    }
}
function checkvalue(elem) {
    var input = elem.val();
    var regex = /(<([^>]+)>)/ig;
    var validatedInput = input.replace(regex, "");
    if (input != validatedInput) {
        return false;
    }
    if (elem.length) {
        if (!elem.val().trim()) {
            return false;
        } else {
            return true;
        }
    }
}

function connectSkubana() {
    $('#mdlapp_name').empty();
    $('#mdlSkubana').modal('toggle');
    pdata = new FormData($(".skubana_connect_form")[0]);
    showOverlay();
    $('.connect-now').prop('disabled', true);
    $.ajax({
        url: baseUrl + "/ConnectSkubanaAuth",
        data: pdata,
        dataType: "json",
        async: true,
        type: "post",
        processData: false,
        contentType: false,
        success: function (res) {
            hideOverlay();
            $('.connect-now').prop('disabled', false);
            if (res.status_code == 1) {
                successNotify(res.status_text, 'Success');
                $('#p1_conn_section').hide();
                $('#p1_connection_form').empty();
                retrievePlatformAccounts('skubana', 'source');
            }
            else {
                errorNotify(res.status_text, 'Failed');
            }
        },
        error: function (jqXHR) {
            hideOverlay();
            if (jqXHR.status == 500) {
                errorNotify('Internal error: ' + jqXHR.responseText, 'Failed');
            } else {
                errorNotify('Unexpected error Please try again.', 'Failed');
            }
        }
    });
}

$(document.body).on('click', '#btnSubmitSkubana', function () {
    valid = true;
    $('.err_mgs').hide();
    if (!checkvalue($('#app_name'))) {
        showErrorAndFocus($('#app_name'));
        valid = false;
    } else if (!checkvalue($('#cid'))) {
        showErrorAndFocus($('#cid'));
        valid = false;
    } else if (!checkvalue($('#code'))) {
        showErrorAndFocus($('#code'));
        valid = false;
    }
    if (!valid) {
        return false;
    }
    var mdlapp_name = $('#app_name').val();
    $('#mdlapp_name').html(mdlapp_name);
    $('#mdlSkubana').modal('toggle');
});

$(document.body).on('click', '.btn-close', function () {
    $('#mdlapp_name').empty();
    $('#mdlSkubana').modal('toggle');
});