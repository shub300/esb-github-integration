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
    if(input != validatedInput){
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

$(document.body).on('change', '.cred', function() {
    if($(this).val()){
        $(this).next('.err_mgs').hide();
    }else{
        $(this).next('.err_mgs').show();
        return false;
    }
});

$(document.body).on('click', '#btnSubmitGunBroker', function() {
    valid = true;
    $('.err_mgs').hide();
    if (!checkvalue($('#dev_key'))) {
        showErrorAndFocus($('#dev_key'));
        valid = false;
    }
    if (!checkvalue($('#username'))) {
        showErrorAndFocus($('#username'));
        valid = false;
    }
    if (!checkvalue($('#password'))) {
        showErrorAndFocus($('#password'));
        valid = false;
    }
    if (!checkvalue($('#env_type'))) {
        showErrorAndFocus($('#env_type'));
        valid = false;
    }
    if (!valid) {
        return false;
    }
    $('#mdlGunBroker').modal('toggle');
});

function connectGunBroker(){
    $('#mdlGunBroker').modal('toggle');

    pdata = new FormData($(".gunbroker_connect_form")[0]);
    showOverlay();
    $('.connect-now').prop('disabled',true);
    $.ajax({
        url: baseUrl + "/ConnectGunBroker",
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
                retrievePlatformAccounts('gunbroker', 'source');
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

$(document.body).on('click', '.btn-close', function() {
    $('#mdlGunBroker').modal('toggle');
});