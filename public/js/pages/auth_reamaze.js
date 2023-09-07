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

function connectReamaze(){
    $('#reamazeMdl').modal('toggle');

    pdata = new FormData($(".reamaze_connect_form")[0]);
    showOverlay();
    $('.connect-now').prop('disabled',true);
    $.ajax({
        url: baseUrl + "/ConnectReamazeAuth",
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
                successNotify(res.status_text,'Success');
                retrievePlatformAccounts('reamaze', 'destination');
            }
            else{
                errorNotify(res.status_text,'Failed');
            }
        },
        error: function (jqXHR) {
            hideOverlay();
            if (jqXHR.status == 500) {
                errorNotify('Internal error: ' + jqXHR.responseText,'Failed');
            } else {
                errorNotify('Unexpected error Please try again.','Failed');
            }
        }
    });
}

$(document.body).on('click', '#btnSubmitWayfair', function() {
    valid = true;
    $('.err_mgs').hide();
    if (!checkvalue($('#email'))) {
        showErrorAndFocus($('#email'));
        valid = false;
    }
    if (!checkvalue($('#client_key'))) {
        showErrorAndFocus($('#client_key'));
        valid = false;
    }
    if (!checkvalue($('#domain'))) {
        showErrorAndFocus($('#domain'));
        valid = false;
    }
    if (!valid) {
        return false;
    }
    $('#reamazeMdl').modal('toggle');
});

$(document.body).on('click', '.btn-close', function() {
    $('#reamazeMdl').modal('toggle');
});