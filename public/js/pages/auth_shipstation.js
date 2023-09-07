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

function connectShipstation(){
    $('#ShipstationMdl').modal('toggle');

    pdata = new FormData($(".Shipstation_connect_form")[0]);
    showOverlay();
    $('.connect-now').prop('disabled',true);
    $.ajax({
        url: baseUrl + "/ConnectShipstationAuth",
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
                retrievePlatformAccounts('Shipstation', 'source');
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

$(document.body).on('click', '#btnSubmitShipstation', function() {
    valid = true;
    $('.err_mgs').hide();
    if (!checkvalue($('#account_name'))) {
        showErrorAndFocus($('#account_name'));
        valid = false;
    }
    if (!checkvalue($('#environment'))) {
        showErrorAndFocus($('#environment'));
        valid = false;
    }
    if (!checkvalue($('#api_key'))) {
        showErrorAndFocus($('#api_key'));
        valid = false;
    }
    if (!checkvalue($('#secret_key'))) {
        showErrorAndFocus($('#secret_key'));
        valid = false;
    }
    if (!valid) {
        return false;
    }
    $('#ShipstationMdl').modal('toggle');
});

$(document.body).on('click', '.btn-close', function() {
    $('#ShipstationMdl').modal('toggle');
});