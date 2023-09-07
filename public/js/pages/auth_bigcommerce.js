var baseUrl = $('body').attr('data-url');

function showErrorAndFocus(elem) {
    if (elem.length) {
        elem.next().show();
    }
}

function checkvalue(elem) {
    if (elem.length) {
        if (!elem.val().trim()) {
            return false;
        } else {
            return true;
        }
    }
}

function connectBigCommerce(){
    $('#bigcommMdl').modal('toggle');

    pdata = new FormData($(".bigcommerce_connect_form")[0]);
    showOverlay();
    $('.connect-now').prop('disabled',true);
    $.ajax({
        url: baseUrl + "/ConnectBigCommerceAuth",
        data: pdata,
        dataType: "json",
        async: true,
        type: "post",
        processData: false,
        contentType: false,
        success: function (res) {
            hideOverlay();
            $('.connect-now').prop('disabled',false);
            if (res.status_code == "1") {
                $('#p1_conn_section').hide();
                $('#p1_connection_form').empty();
                successNotify(res.status_text,'Success');
                retrievePlatformAccounts('bigcommerce', 'destination');
            }
            else{
                errorNotify(res.status_text,'Failed');
            }
        },
        error: function (jqXHR) {
            hideOverlay();
            if (jqXHR.status == 500) {
                errorNotify('Internal error: ' + jqXHR.responseText,'Failed');
            }else if (jqXHR.status == 422) {
                if(jqXHR.responseJSON.errors){
                    var err_msg = '';
                    for(var value in Object.values(jqXHR.responseJSON.errors)){
                        if(Array.isArray(value)){
                            err_msg += value[0];
                        }else{
                            err_msg += value;
                        }
                    }
                    errorNotify(`Error: ${err_msg}`,'Failed');
                }else{
                    if(jqXHR.responseJSON.message){
                        errorNotify('Error: ' + jqXHR.responseJSON.message,'Failed');
                    }else{
                        errorNotify('Error: ' + jqXHR.responseText,'Failed');
                    }
                }
            } else {
                errorNotify('Unexpected error Please try again.','Failed');
            }
        }
    });
}

$(document.body).on('click', '#btnSubmitBigcommerce', function() {
    valid = true;
    $('.err_mgs').hide();
    if (!valid) {
        return false;
    }
    $('#bigcommMdl').modal('toggle');
});

$(document.body).on('click', '.btn-close', function() {
    $('#bigcommMdl').modal('toggle');
});